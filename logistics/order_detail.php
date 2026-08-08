<?php
$page_title = 'Order Details - Logistics Center';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/inventory_helper.php';

lc_require_login();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . LC_BASE . '/orders.php'); exit; }


// 상태 변경 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lc_verify_csrf();
    lc_require_staff();

    $action = $_POST['action'] ?? '';
    $uid    = lc_current_user_id();
    $now    = date('Y-m-d H:i:s');

    try {
        $conn = get_lc_db();

        if ($action === 'approve') {
            // 대책 B: 승인 시점에 FEFO 재고 차감 + lot/원가 확정.
            // 승인된(확정) 주문이 즉시 가용재고에서 빠져 다른 지점의 중복 주문을 방지한다.
            $conn->autocommit(false);
            $st = $conn->prepare(
                "UPDATE lc_orders SET status='approved', approved_by=?, approved_at=? WHERE id=? AND status='pending'"
            );
            $st->bind_param('isi', $uid, $now, $id);
            $st->execute();
            $approved = $st->affected_rows > 0;
            $st->close();

            if (!$approved) {
                $conn->rollback();
                lc_set_flash('error', 'Only pending orders can be approved (it may already be processed).');
            } else {
                lc_allocate_order_stock($conn, $id, true);
                // 원가 확정 후 총액 재계산 (lc_order_items.total_amount STORED 컬럼 자동 반영)
                $conn->query(
                    "UPDATE lc_orders SET total_amount=(SELECT COALESCE(SUM(total_amount),0) FROM lc_order_items WHERE order_id=$id) WHERE id=$id"
                );
                $conn->commit();
                lc_set_flash('success', 'Order approved. Inventory deducted and cost finalized.');
            }

        } elseif ($action === 'ship') {
            // 대책 B: 재고는 승인 시 이미 차감됨 → 출고는 상태 전환만 수행한다.
            $st = $conn->prepare(
                "UPDATE lc_orders SET status='shipped', shipped_at=? WHERE id=? AND status='approved'"
            );
            $st->bind_param('si', $now, $id);
            $st->execute();
            if ($st->affected_rows > 0) {
                lc_set_flash('success', 'Outbound processing complete.');
            } else {
                lc_set_flash('error', 'Only approved orders can be processed for outbound.');
            }
            $st->close();

        } elseif ($action === 'edit_items') {
            // 배달 완료(delivered) 전(pending/approved/shipped) 주문은 물류센터 직원이 단가/수량 수정 가능.
            // 슈퍼어드민은 배달 완료 후에도 단품별 수정/삭제 가능 (재고 오차 보정 등)
            $st = $conn->prepare("SELECT status FROM lc_orders WHERE id = ?");
            $st->bind_param('i', $id); $st->execute();
            $cur = $st->get_result()->fetch_assoc(); $st->close();

            $editable = $cur && (
                in_array($cur['status'], ['pending', 'approved', 'shipped'], true)
                || (lc_is_super_admin() && $cur['status'] === 'delivered')
            );

            if (!$editable) {
                lc_set_flash('error', 'Only orders before delivery can be modified (super admins can also modify delivered orders).');
            } else {
                // pending: 재고 미차감. approved/shipped/delivered: 승인 시 이미 차감된 상태 → 복원 후 재차감.
                $stock_deducted = $cur['status'] !== 'pending';
                $item_ids  = $_POST['item_id']  ?? [];
                $quantities = $_POST['item_qty'] ?? [];
                $prices    = $_POST['item_price'] ?? [];
                $units     = $_POST['item_unit'] ?? [];

                $conn->autocommit(false);
                if ($stock_deducted) { lc_restore_order_stock($conn, $id); }
                $has_items = false;
                $upd = $conn->prepare("UPDATE lc_order_items SET quantity = ?, unit_price = ?, order_unit = ? WHERE id = ? AND order_id = ?");
                $del = $conn->prepare("DELETE FROM lc_order_items WHERE id = ? AND order_id = ?");

                foreach ($item_ids as $k => $item_id) {
                    $item_id = (int)$item_id;
                    $qty     = (int)($quantities[$k] ?? 0);
                    $price   = is_numeric($prices[$k] ?? null) ? round((float)$prices[$k], 2) : 0.0;
                    if ($price < 0) { $price = 0.0; }
                    $unit    = (strtoupper($units[$k] ?? 'PCS') === 'BOX') ? 'BOX' : 'PCS';
                    if ($qty > 0) {
                        $upd->bind_param('idsii', $qty, $price, $unit, $item_id, $id);
                        $upd->execute();
                        $has_items = true;
                    } else {
                        $del->bind_param('ii', $item_id, $id);
                        $del->execute();
                    }
                }
                $upd->close(); $del->close();

                if (!$has_items) {
                    $conn->rollback();
                    lc_set_flash('error', 'At least 1 product must remain. Use order cancellation for full cancellation.');
                } else {
                    // 재고 차감 상태였던 주문만: 변경된 수량으로 재차감(단가는 수동 입력값 유지 → $set_cost=false)
                    if ($stock_deducted) { lc_allocate_order_stock($conn, $id, false); }
                    $conn->query(
                        "UPDATE lc_orders SET total_amount =
                         (SELECT COALESCE(SUM(total_amount),0) FROM lc_order_items WHERE order_id = $id)
                         WHERE id = $id"
                    );
                    $conn->commit();
                    lc_set_flash('success', 'Order quantity updated.');
                }
            }

        } elseif ($action === 'add_item') {
            // 어떤 상태에서든 품목 추가. 상태에 맞춰 재고 차감을 자동 처리한다.
            // - pending/cancelled: 차감 없이 항목만 추가 (승인 시 일괄 차감/원가 확정)
            // - approved/cancel_requested/shipped/delivered: 추가 항목만 FEFO 차감 + 원가 확정
            $product_id = (int)($_POST['product_id'] ?? 0);
            $qty        = max(0, (int)($_POST['quantity'] ?? 0));

            $stx = $conn->prepare("SELECT status FROM lc_orders WHERE id = ? AND deleted_at IS NULL");
            $stx->bind_param('i', $id); $stx->execute();
            $cur = $stx->get_result()->fetch_assoc(); $stx->close();

            if (!$cur) {
                lc_set_flash('error', 'Order not found.');
            } elseif ($product_id <= 0 || $qty <= 0) {
                lc_set_flash('error', 'Please select a product and enter a quantity of 1 or more.');
            } else {
                $stp = $conn->prepare("SELECT unit, pieces_per_box FROM lc_products WHERE id = ?");
                $stp->bind_param('i', $product_id); $stp->execute();
                $prod = $stp->get_result()->fetch_assoc(); $stp->close();

                if (!$prod) {
                    lc_set_flash('error', 'Product not found.');
                } else {
                    $deduct_statuses = ['approved', 'cancel_requested', 'shipped', 'delivered'];
                    $need_deduct = in_array($cur['status'], $deduct_statuses, true);
                    $order_unit  = $prod['unit'] ?: 'PCS';
                    $ppb         = max(1, (int)($prod['pieces_per_box'] ?? 1));

                    $conn->autocommit(false);
                    try {
                        $unit_price = 0.0; // pending/cancelled: 승인 시 확정. deduct 상태는 아래에서 평균원가로 갱신.
                        $ins = $conn->prepare(
                            "INSERT INTO lc_order_items (order_id, product_id, quantity, order_unit, pieces_per_box, unit_price)
                             VALUES (?,?,?,?,?,?)"
                        );
                        $ins->bind_param('iiisid', $id, $product_id, $qty, $order_unit, $ppb, $unit_price);
                        $ins->execute();
                        $new_item_id = $conn->insert_id;
                        $ins->close();

                        if ($need_deduct) {
                            // 추가 항목만 FEFO 차감 (음수 재고 허용) + lot 기록 + 평균원가 단가 확정
                            $lots = lc_fifo_ship_allow_negative($conn, $product_id, $qty);
                            $ins_lot = $conn->prepare(
                                "INSERT INTO lc_order_item_lots (order_item_id, inventory_id, inbound_id, quantity, cost_price)
                                 VALUES (?,?,?,?,?)"
                            );
                            foreach ($lots as $lot) {
                                $ins_lot->bind_param('iiiid',
                                    $new_item_id, $lot['inventory_id'], $lot['inbound_id'], $lot['quantity'], $lot['cost_price']);
                                $ins_lot->execute();
                            }
                            $ins_lot->close();

                            $total_cost = array_sum(array_map(fn($l) => $l['quantity'] * $l['cost_price'], $lots));
                            $total_qty  = array_sum(array_column($lots, 'quantity'));
                            $avg_cost   = $total_qty > 0 ? round($total_cost / $total_qty, 4) : 0.0;
                            $up = $conn->prepare("UPDATE lc_order_items SET unit_price = ? WHERE id = ?");
                            $up->bind_param('di', $avg_cost, $new_item_id);
                            $up->execute(); $up->close();
                        }

                        // 주문 총액 재계산 (total_amount STORED 컬럼 합산)
                        $conn->query(
                            "UPDATE lc_orders SET total_amount=(SELECT COALESCE(SUM(total_amount),0) FROM lc_order_items WHERE order_id=$id) WHERE id=$id"
                        );
                        $conn->commit();
                        lc_set_flash('success', 'Item added.' . ($need_deduct ? ' Inventory deducted (FEFO).' : ''));
                    } catch (Exception $e) {
                        $conn->rollback();
                        lc_set_flash('error', 'Failed to add item: ' . $e->getMessage());
                    }
                }
            }

        } elseif ($action === 'deliver') {
            $st = $conn->prepare(
                "UPDATE lc_orders SET status='delivered', delivered_at=? WHERE id=? AND status='shipped'"
            );
            $st->bind_param('si', $now, $id);
            $st->execute();
            lc_set_flash('success', 'Delivery completed.');

        } elseif ($action === 'revert_delivery') {
            // 배송완료(delivered) 주문을 승인(approved) 상태로 되돌린다.
            // 대책 B: approved 상태도 재고가 차감된 상태이므로 재고/ lot은 그대로 유지하고 상태만 되돌린다.
            // CENTER 소속 관리자/점장(센터장)만 가능
            if (!lc_is_admin()) {
                lc_set_flash('error', 'Access denied.');
            } else {
                $st = $conn->prepare(
                    "UPDATE lc_orders SET status='approved', shipped_at=NULL, delivered_at=NULL WHERE id=? AND status='delivered'"
                );
                $st->bind_param('i', $id);
                $st->execute();
                if ($st->affected_rows > 0) {
                    lc_set_flash('success', 'Delivery reverted. The order returned to Approved status (inventory remains deducted).');
                } else {
                    lc_set_flash('error', 'Only delivered orders can be reverted.');
                }
                $st->close();
            }

        } elseif ($action === 'cancel') {
            // 대책 B: 승인된 주문 취소 시 차감(예약) 재고를 복원. pending 주문은 lot이 없어 무동작.
            $conn->autocommit(false);
            $st = $conn->prepare(
                "UPDATE lc_orders SET status='cancelled' WHERE id=? AND status IN ('pending','approved')"
            );
            $st->bind_param('i', $id);
            $st->execute();
            if ($st->affected_rows > 0) {
                $st->close();
                lc_restore_order_stock($conn, $id);
                $conn->commit();
                lc_set_flash('success', 'Order cancelled.');
            } else {
                $st->close();
                $conn->rollback();
                lc_set_flash('error', 'This order cannot be cancelled.');
            }

        } elseif ($action === 'approve_cancel') {
            // 대책 B: 취소요청 승인(=주문 취소) 시 차감(예약) 재고를 복원.
            $conn->autocommit(false);
            $st = $conn->prepare(
                "UPDATE lc_orders SET status='cancelled' WHERE id=? AND status='cancel_requested'"
            );
            $st->bind_param('i', $id);
            $st->execute();
            if ($st->affected_rows > 0) {
                $st->close();
                lc_restore_order_stock($conn, $id);
                $conn->commit();
                lc_set_flash('success', 'Cancellation request approved.');
            } else {
                $st->close();
                $conn->rollback();
                lc_set_flash('error', 'No cancellation request to approve.');
            }

        } elseif ($action === 'reject_cancel') {
            $st = $conn->prepare(
                "UPDATE lc_orders SET status='approved' WHERE id=? AND status='cancel_requested'"
            );
            $st->bind_param('i', $id);
            $st->execute();
            lc_set_flash('success', 'Cancellation request rejected. Order restored to approved status.');

        } elseif ($action === 'soft_delete') {
            if (!lc_is_admin()) {
                lc_set_flash('error', 'Access denied.');
            } else {
                $st = $conn->prepare(
                    "UPDATE lc_orders SET deleted_at=?, deleted_by=? WHERE id=? AND status IN ('cancelled','delivered') AND deleted_at IS NULL"
                );
                $st->bind_param('sii', $now, $uid, $id);
                $st->execute();
                if ($st->affected_rows > 0) {
                    lc_set_flash('success', "Order #" . str_pad($id, 4, '0', STR_PAD_LEFT) . " moved to trash. You can restore it from the Deleted tab in the order list.");
                } else {
                    lc_set_flash('error', 'Only cancelled or delivered orders can be deleted.');
                }
                $st->close();
            }

        } elseif ($action === 'restore') {
            if (!lc_is_admin()) {
                lc_set_flash('error', 'Access denied.');
            } else {
                $st = $conn->prepare(
                    "UPDATE lc_orders SET deleted_at=NULL, deleted_by=NULL WHERE id=? AND deleted_at IS NOT NULL"
                );
                $st->bind_param('i', $id);
                $st->execute();
                if ($st->affected_rows > 0) {
                    lc_set_flash('success', "Order #" . str_pad($id, 4, '0', STR_PAD_LEFT) . " restored.");
                } else {
                    lc_set_flash('error', 'This order is not deleted.');
                }
                $st->close();
            }
        }

        $conn->close();
    } catch (Exception $e) {
        if (isset($conn)) { $conn->rollback(); $conn->close(); }
        lc_set_flash('error', 'DB Error: ' . $e->getMessage());
    }

    $redirect = in_array($action, ['cancel', 'approve_cancel', 'soft_delete'])
        ? LC_BASE . '/orders.php'
        : LC_BASE . "/order_detail.php?id=$id";
    header("Location: $redirect");
    exit;
}

// 주문 정보 조회
try {
    $conn = get_lc_db();

    $st = $conn->prepare(
        "SELECT o.*, s.name AS store_name,
                u1.full_name AS created_by_name,
                u2.full_name AS approved_by_name,
                u3.full_name AS deleted_by_name
         FROM lc_orders o
         LEFT JOIN stores s ON o.store_id = s.id
         LEFT JOIN users u1 ON o.created_by = u1.id
         LEFT JOIN users u2 ON o.approved_by = u2.id
         LEFT JOIN users u3 ON o.deleted_by = u3.id
         WHERE o.id = ?"
    );
    $st->bind_param('i', $id);
    $st->execute();
    $order = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$order) {
        $conn->close();
        lc_set_flash('error', 'Order not found.');
        header('Location: ' . LC_BASE . '/orders.php'); exit;
    }

    // 점포 담당자는 본인 점포 주문만 조회
    if (lc_is_store_user() && $order['store_id'] != lc_current_store_id()) {
        $conn->close();
        lc_set_flash('error', 'Access denied.');
        header('Location: ' . LC_BASE . '/orders.php'); exit;
    }

    // 삭제(휴지통)된 주문은 관리자만 조회 가능
    if ($order['deleted_at'] && !lc_is_admin()) {
        $conn->close();
        lc_set_flash('error', 'Order not found.');
        header('Location: ' . LC_BASE . '/orders.php'); exit;
    }

    // 주문 상세 항목
    $items = $conn->query(
        "SELECT oi.*, CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS product_name,
                p.name_en, p.name_ko, p.unit, p.capacity, p.pieces_per_box AS product_ppb,
                b.name_en AS brand_name, b.name_ko AS brand_name_ko,
                COALESCE(p.barcode_unit, p.barcode_box, p.barcode_logistics) AS barcode
         FROM lc_order_items oi
         JOIN lc_products p ON oi.product_id = p.id
         LEFT JOIN lc_brands b ON p.brand_id = b.id
         WHERE oi.order_id = $id"
    )->fetch_all(MYSQLI_ASSOC);

    // 차감 완료 주문(승인/출고/배송): lot별 유통기한 + 원가 + 위치 내역
    $lots_by_item = [];
    if (in_array($order['status'], ['approved', 'cancel_requested', 'shipped', 'delivered'])) {
        $item_ids_csv = implode(',', array_column($items, 'id') ?: [0]);
        $lot_rows = $conn->query(
            "SELECT oll.order_item_id, oll.quantity, oll.cost_price,
                    IFNULL(inv.lot_number, '') AS lot_number,
                    inv.expiry_date, inv.storage_location
             FROM lc_order_item_lots oll
             JOIN lc_inventory inv ON oll.inventory_id = inv.id
             WHERE oll.order_item_id IN ($item_ids_csv)
             ORDER BY inv.expiry_date ASC, oll.id ASC"
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($lot_rows as $lr) {
            $lots_by_item[$lr['order_item_id']][] = $lr;
        }
    }

    // 미승인(pending) 주문: FEFO 피킹 미리보기 (승인 후에는 실제 lot 표시)
    $picking_preview = [];
    if ($order['status'] === 'pending' && lc_is_staff()) {
        foreach ($items as $item) {
            $preview = lc_fefo_preview($conn, (int)$item['product_id'], (int)$item['quantity']);
            $picking_preview[$item['id']] = $preview;
        }
    }

    $conn->close();
} catch (Exception $e) {
    lc_set_flash('error', 'DB Error: ' . $e->getMessage());
    header('Location: ' . LC_BASE . '/orders.php'); exit;
}
?>

<div class="flex items-center gap-3 mb-6 no-print">
    <a href="<?php echo LC_BASE; ?>/orders.php" class="text-gray-400 hover:text-gray-600"><i class="fas fa-arrow-left"></i></a>
    <h2 class="text-xl font-bold text-gray-900">
        Order #<?php echo str_pad($order['id'], 4, '0', STR_PAD_LEFT); ?>
    </h2>
    <span class="text-sm px-3 py-1 rounded-full font-medium <?php echo lc_status_class($order['status']); ?>">
        <?php echo lc_status_label($order['status']); ?>
    </span>
    <?php if ($order['status'] === 'delivered' && !empty($order['delivered_at'])): ?>
    <div class="flex items-center gap-1 text-sm text-green-700 font-medium">
        <i class="fas fa-check-circle"></i>Delivery confirmed: <?php echo htmlspecialchars($order['delivered_at']); ?>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($order['deleted_at'])): ?>
<div class="flex items-center justify-between gap-3 mb-6 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-sm text-red-700 no-print">
    <div>
        <i class="fas fa-trash mr-2"></i>This order was moved to trash on <?php echo htmlspecialchars($order['deleted_at']); ?>
        <?php if (!empty($order['deleted_by_name'])): ?> by <?php echo htmlspecialchars($order['deleted_by_name']); ?><?php endif; ?>.
    </div>
    <form method="post" class="inline">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">
        <input type="hidden" name="action" value="restore">
        <button type="submit" onclick="return confirm('Restore this order?')"
                class="px-4 py-1.5 bg-red-600 text-white text-sm font-medium rounded-lg hover:bg-red-700 whitespace-nowrap">
            <i class="fas fa-undo mr-1"></i>Restore
        </button>
    </form>
</div>
<?php endif; ?>

<!-- 인쇄 전용 헤더 -->
<div class="print-only" style="display:none;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;padding-bottom:12px;border-bottom:2px solid #000;">
        <div>
            <div style="font-size:1.4rem;font-weight:700;">Delivery Receipt</div>
            <div style="font-size:0.9rem;font-weight:600;margin-top:4px;"><?php echo htmlspecialchars($order['store_name'] ?? '-'); ?></div>
            <div style="font-size:0.85rem;margin-top:4px;">
                Order #<?php echo str_pad($order['id'], 4, '0', STR_PAD_LEFT); ?>
                &nbsp;·&nbsp;
                Order Date: <?php echo htmlspecialchars($order['order_date'] . ' ' . date('H:i', strtotime($order['created_at']))); ?>
            </div>
        </div>
        <div style="text-align:right;font-size:0.8rem;color:#555;">
            <div>Printed: <?php echo date('Y-m-d H:i'); ?></div>
            <div>Home K Mart — Logistics Center</div>
        </div>
    </div>
</div>

<style>
/* 바코드: 자연 크기로 렌더(축소 금지) → 막대 간격 유지되어 스캔 가능.
   JsBarcode가 px 단위 width/height 를 직접 지정하므로 별도 크기 강제는 하지 않는다. */
.barcode-svg { display: block; margin: 0 auto; max-width: 100%; }
</style>
<style>
/* 브랜드: 화면에서는 별도 컬럼, 출력 시 상품명 앞 [브랜드]로 표시 */
.print-brand { display: none; }

@media print {
    /* 사이드바, 헤더, 버튼 숨김 */
    .no-print, #editMode, script { display: none !important; }
    .print-only { display: block !important; }

    /* 출력 시 Brand 컬럼(3번째)은 너비 0으로 접되 컬럼 수(10개)는 유지 → Total colspan 정렬 보존.
       상품명 앞 [브랜드]로 표시 */
    #viewMode th:nth-child(3), #viewMode td:nth-child(3) {
        width: 0 !important; max-width: 0 !important; min-width: 0 !important;
        padding: 0 !important; border-left: 0 !important; border-right: 0 !important;
        overflow: hidden !important; white-space: nowrap !important; font-size: 0 !important;
    }
    .print-brand { display: inline !important; }

    /* 출력 시 모든 글자 검정색 (흐린 회색/색상 방지) */
    body, body * { color: #000 !important; }

    /* 페이지 여백 + 페이지 번호 (표준: Firefox/PDF 엔진에서 동작) */
    @page {
        margin: 12mm 10mm;
        @bottom-center {
            content: "Page " counter(page) " / " counter(pages);
            font-size: 9px;
            color: #000;
        }
    }
    body { font-size: 10px !important; }
    table { font-size: 9px !important; }
    th, td { padding: 3px 6px !important; }
    .text-sm { font-size: 9px !important; }
    .text-xs { font-size: 8px !important; }
    .text-base, .text-lg, .text-xl { font-size: 11px !important; }

    /* 레이아웃 재구성: 사이드바 제거 */
    .flex.h-screen { display: block !important; }
    .hidden.md\\:flex { display: none !important; }
    .flex-col.flex-1 { overflow: visible !important; }
    main { padding: 0 !important; }

    /* 카드 스타일 단순화 */
    .bg-white { background: #fff !important; }
    .rounded-lg { border-radius: 4px !important; }
    .shadow { box-shadow: none !important; }

    /* 테이블 테두리 */
    table { border-collapse: collapse !important; width: 100% !important; table-layout: fixed !important; }
    th, td { border: 1px solid #ccc !important; padding: 3px 6px !important; word-break: break-word; }
    thead { background: #f3f4f6 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

    /* 행이 페이지 경계에서 잘리면 통째로 다음 페이지로 출력 */
    #viewMode tr { page-break-inside: avoid !important; break-inside: avoid !important; }
    /* 페이지마다 테이블 헤더 반복 */
    #viewMode thead { display: table-header-group !important; }

    /* Order Items 컬럼 너비 고정 (#·Barcode·[Brand숨김]·Product·Qty·Unit·PKG·Expiry·UnitPrice·Subtotal) */
    #viewMode th:nth-child(1), #viewMode td:nth-child(1) { width: 34px !important; text-align: center; white-space: nowrap !important; word-break: normal !important; }
    #viewMode th:nth-child(2), #viewMode td:nth-child(2) { width: 140px !important; }
    #viewMode th:nth-child(4), #viewMode td:nth-child(4) { width: 230px !important; white-space: normal !important; word-break: break-word !important; overflow: visible !important; }
    #viewMode th:nth-child(5), #viewMode td:nth-child(5) { width: 36px !important; text-align: center; }
    #viewMode th:nth-child(6), #viewMode td:nth-child(6) { width: 32px !important; text-align: center; }
    #viewMode th:nth-child(7), #viewMode td:nth-child(7) { width: 28px !important; text-align: center; }
    #viewMode th:nth-child(8), #viewMode td:nth-child(8) { width: 80px !important; text-align: center; }
    #viewMode th:nth-child(9), #viewMode td:nth-child(9) { width: 50px !important; text-align: right; }
    #viewMode th:nth-child(10), #viewMode td:nth-child(10) { width: 70px !important; text-align: right; }

    /* picking preview 숨김 (내부용) */
    .print-only { display: block !important; }

    /* 출력 시 바코드: 자연 크기 유지(축소 금지). EAN13 기준 약 34mm 폭으로 스캔 가능 */
    .barcode-svg { display: block; margin: 0 auto; max-width: 100%; }

    /* 출력 시 바코드 숫자 폰트 확대 */
    .barcode-number { font-size: 12px !important; }

    /* 출력 시 Qty 컬럼 강조: 연한 빨강 배경 + 값 확대 (thead 배경 규칙보다 클래스 우선) */
    .qty-head { background: #fecaca !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    .qty-cell { font-size: 13px !important; font-weight: 700 !important; background: #fef2f2 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

    /* 결제(서명)란: 검정 테두리 + 서명 공간 확보 */
    #signOffBox { margin-top: 24px !important; padding: 0 1px !important; page-break-inside: avoid; }
    #signOffBox table { border-collapse: collapse !important; width: 100% !important; table-layout: fixed !important; border: 1px solid #000 !important; }
    #signOffBox th, #signOffBox td { border: 1px solid #000 !important; color: #000 !important; box-sizing: border-box !important; }
    #signOffBox .sign-space { height: 46px !important; }
}
</style>

<!-- Order Information -->
<div class="gap-3 mb-4 no-print" style="display:grid; grid-template-columns:3fr 7fr;">
    <div class="bg-white rounded-lg border border-gray-200 p-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-3">Order Information</h3>
        <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
            <div>
                <dt class="text-xs text-gray-400 mb-0.5">Store</dt>
                <dd class="font-medium text-gray-800 truncate"><?php echo htmlspecialchars($order['store_name'] ?? '-'); ?></dd>
            </div>
            <div>
                <dt class="text-xs text-gray-400 mb-0.5">Order Date</dt>
                <dd class="text-gray-700"><?php echo htmlspecialchars($order['order_date'] . ' ' . date('H:i', strtotime($order['created_at']))); ?></dd>
            </div>
            <div>
                <dt class="text-xs text-gray-400 mb-0.5">Ordered By</dt>
                <dd class="text-gray-700 truncate"><?php echo htmlspecialchars($order['created_by_name'] ?? '-'); ?></dd>
            </div>
            <div>
                <dt class="text-xs text-gray-400 mb-0.5">Order Total</dt>
                <dd class="font-bold text-gray-900"><?php echo number_format($order['total_amount'], 2); ?></dd>
            </div>
            <?php if ($order['notes']): ?>
            <div class="col-span-2">
                <dt class="text-xs text-gray-400 mb-0.5">Notes</dt>
                <dd class="text-gray-700"><?php echo htmlspecialchars($order['notes']); ?></dd>
            </div>
            <?php endif; ?>
        </dl>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-4 no-print">
        <h3 class="text-sm font-semibold text-gray-700 mb-3">Processing History</h3>
        <?php
            $steps = [
                ['label' => 'Received',  'time' => $order['created_at'],   'done' => true],
                ['label' => 'Approved',  'time' => $order['approved_at'],  'done' => (bool)$order['approved_at'],  'sub' => $order['approved_by_name'] ?? ''],
                ['label' => 'Shipped',   'time' => $order['shipped_at'],   'done' => (bool)$order['shipped_at']],
                ['label' => 'Delivered', 'time' => $order['delivered_at'], 'done' => (bool)$order['delivered_at']],
            ];
            // 색상 기준: 지나간 완료 단계 = 연한 블루, 현재 최종 도달 단계만 = 보라색
            $lastDone = -1;
            foreach ($steps as $i => $s) { if ($s['done']) $lastDone = $i; }
        ?>
        <div class="flex items-start">
            <?php foreach ($steps as $i => $s): ?>
                <?php if ($i > 0): ?>
                <div class="<?php echo $s['done'] ? 'bg-gray-400' : 'bg-gray-200'; ?>" style="flex:1 1 0; height:2px; margin-top:13px;"></div>
                <?php endif; ?>
                <div class="flex flex-col items-center text-center" style="flex:0 0 auto; width:58px;">
                    <?php if ($s['done']): ?>
                    <?php $isFinal = ($i === $lastDone); ?>
                    <span class="text-white rounded-full inline-flex items-center justify-center" style="width:28px; height:28px; background-color:<?php echo $isFinal ? '#7c3aed' : '#60a5fa'; ?>;">
                        <i class="fas fa-check" style="font-size:11px;"></i>
                    </span>
                    <?php else: ?>
                    <span class="bg-gray-100 text-gray-400 border border-gray-300 rounded-full inline-flex items-center justify-center" style="width:28px; height:28px; font-size:12px;">
                        <?php echo $i + 1; ?>
                    </span>
                    <?php endif; ?>
                    <p class="text-xs font-medium mt-1.5 leading-tight <?php echo $s['done'] ? 'text-gray-800' : 'text-gray-400'; ?>"><?php echo $s['label']; ?></p>
                    <?php if ($s['done'] && $s['time']): ?>
                    <p class="text-gray-400 leading-tight mt-0.5" style="font-size:10px;"<?php echo !empty($s['sub']) ? ' title="' . htmlspecialchars($s['sub']) . '"' : ''; ?>><?php echo date('m/d H:i', strtotime($s['time'])); ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Order Items -->
<?php
    // 단가/수량 수정 가능 여부: 물류직원/관리자는 배달 완료(delivered) 전까지, 슈퍼어드민은 배달 완료 후에도 가능
    // (재고 실사 오차 등으로 이미 배달된 주문의 단품을 사후 정정해야 하는 경우 대응)
    $can_edit_items = lc_is_staff() && empty($order['deleted_at']) && (
        in_array($order['status'], ['pending', 'approved', 'shipped'], true)
        || (lc_is_super_admin() && $order['status'] === 'delivered')
    );
?>
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-6">
    <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
        <h3 class="text-sm font-semibold text-gray-700">
            Order Items
            <span style="margin-left:6px;">Order #<?php echo str_pad($order['id'], 4, '0', STR_PAD_LEFT); ?></span>
        </h3>
        <div class="flex items-center gap-2 no-print">
            <?php if (lc_is_staff() && empty($order['deleted_at'])): ?>
            <button type="button" onclick="openAddItemModal()"
                    class="inline-flex items-center px-3 py-1.5 bg-teal-600 text-white text-xs font-medium rounded-lg hover:bg-teal-700 whitespace-nowrap"
                    style="color:#fff;">
                <i class="fas fa-plus mr-1"></i>Add Item
            </button>
            <?php endif; ?>
            <?php if ($order['status'] === 'pending' && lc_is_staff()): ?>
            <form method="post" class="inline-block">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">
                <input type="hidden" name="action" value="approve">
                <button type="submit" onclick="return confirm('Approve this order? Inventory will be deducted (reserved) now.')"
                        class="inline-flex items-center px-3 py-1.5 bg-blue-600 text-white text-xs font-medium rounded-lg hover:bg-blue-700 whitespace-nowrap"
                        style="color:#fff;">
                    <i class="fas fa-check mr-1"></i>Approve
                </button>
            </form>
            <?php endif; ?>
            <?php if ($order['status'] === 'approved' && lc_is_staff()): ?>
            <form method="post" class="inline-block">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">
                <input type="hidden" name="action" value="ship">
                <button type="submit" onclick="return confirm('Process outbound? (Inventory was already deducted at approval.)')"
                        class="inline-flex items-center px-3 py-1.5 bg-purple-600 text-white text-xs font-medium rounded-lg hover:bg-purple-700 whitespace-nowrap"
                        style="color:#fff;">
                    <i class="fas fa-truck mr-1"></i>Process Outbound
                </button>
            </form>
            <?php endif; ?>
            <button onclick="window.print()" class="inline-flex items-center px-3 py-1.5 text-xs border border-gray-300 rounded-lg text-gray-600 hover:bg-gray-50 transition-colors">
                <i class="fas fa-print mr-1"></i>Print
            </button>
            <?php if ($can_edit_items): ?>
            <button type="button" id="editToggleBtn" onclick="toggleItemEdit()"
                    class="text-xs px-3 py-1.5 border border-gray-300 rounded-lg text-gray-600 hover:bg-gray-50 transition-colors">
                <i class="fas fa-edit mr-1"></i>Modify Quantity
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- 조회 모드 -->
    <div id="viewMode">
        <table class="w-full text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="px-2 py-3 text-center text-xs text-gray-500 font-medium" style="width:40px;white-space:nowrap;">#</th>
                <th class="px-3 py-3 text-left text-xs text-gray-500 font-medium" style="width:140px;">Barcode</th>
                <th class="px-3 py-3 text-left text-xs text-gray-500 font-medium" style="width:120px;">Brand</th>
                <th class="px-3 py-3 text-left text-xs text-gray-500 font-medium">Product Name</th>
                <th class="px-2 py-3 text-center text-xs text-gray-500 font-medium qty-head" style="width:80px;font-size:16px;font-weight:700;background:#fecaca;-webkit-print-color-adjust:exact;print-color-adjust:exact;">Qty</th>
                <th class="px-2 py-3 text-center text-xs text-gray-500 font-medium" style="width:80px;">Unit</th>
                <th class="px-2 py-3 text-center text-xs text-gray-500 font-medium" style="width:80px;">PKG</th>
                <th class="px-2 py-3 text-center text-xs text-gray-500 font-medium" style="width:130px;">Expiry</th>
                <th class="px-2 py-3 text-right text-xs text-gray-500 font-medium whitespace-nowrap" style="width:120px;">Unit Price</th>
                <th class="px-3 py-3 text-right text-xs text-gray-500 font-medium" style="width:130px;">Subtotal</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php $row_no = 0; foreach ($items as $item): $row_no++; ?>
            <tr>
                <td class="px-2 py-2 text-center text-xs text-gray-500" style="white-space:nowrap;"><?php echo $row_no; ?></td>
                <td class="px-3 py-2 text-center" style="line-height:1;">
                    <?php if (!empty($item['barcode'])):
                        $bc = trim((string)$item['barcode']);
                    ?>
                    <svg class="barcode-svg" data-sku="<?php echo htmlspecialchars($bc); ?>"></svg>
                    <div class="font-mono text-sm text-gray-600 barcode-number" style="margin-top:2px;line-height:1;"><?php echo htmlspecialchars($bc); ?></div>
                    <?php else: ?>
                    <span class="text-gray-300">-</span>
                    <?php endif; ?>
                </td>
                <td class="px-3 py-2 text-sm text-gray-600">
                    <?php if (!empty($item['brand_name'])): ?>
                    <div><?php echo htmlspecialchars($item['brand_name']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($item['brand_name_ko'])): ?>
                    <div class="text-xs text-gray-500" style="margin-top:1px;"><?php echo htmlspecialchars($item['brand_name_ko']); ?></div>
                    <?php endif; ?>
                    <?php if (empty($item['brand_name']) && empty($item['brand_name_ko'])): ?>
                    <span class="text-gray-300">-</span>
                    <?php endif; ?>
                </td>
                <?php $row_cap = trim((string)($item['capacity'] ?? '')); ?>
                <td class="px-4 py-2 font-medium text-gray-900" style="line-height:1.2;">
                    <div><?php if (!empty($item['brand_name'])): ?><span class="print-brand">[<?php echo htmlspecialchars($item['brand_name']); ?>] </span><?php endif; ?><?php echo htmlspecialchars($item['name_en'] . ($row_cap !== '' ? ' ' . $row_cap : '')); ?></div>
                    <?php if (!empty($item['name_ko'])): ?>
                    <div class="text-xs text-gray-500" style="margin-top:1px;"><?php if (!empty($item['brand_name_ko'])): ?><span class="print-brand">[<?php echo htmlspecialchars($item['brand_name_ko']); ?>] </span><?php endif; ?><?php echo htmlspecialchars($item['name_ko'] . ($row_cap !== '' ? ' ' . $row_cap : '')); ?></div>
                    <?php endif; ?>
                </td>
                <?php
                    $row_unit = !empty($item['order_unit']) ? $item['order_unit'] : ($item['unit'] ?? '-');
                    $row_ppb  = (int)($item['pieces_per_box'] ?? 0) ?: (int)($item['product_ppb'] ?? 0);
                ?>
                <td class="px-4 py-3 text-center font-bold text-lg qty-cell" style="background:#fef2f2;-webkit-print-color-adjust:exact;print-color-adjust:exact;"><?php echo number_format($item['quantity']); ?></td>
                <td class="px-3 py-2 text-center text-xs font-semibold text-gray-700"><?php echo htmlspecialchars($row_unit); ?></td>
                <td class="px-3 py-2 text-center text-xs text-gray-600"><?php echo $row_ppb ?: '-'; ?></td>
                <?php
                    // 출고 lot 또는 picking preview 데이터 결정
                    $expiry_rows = !empty($lots_by_item[$item['id']]) ? $lots_by_item[$item['id']] : [];
                    $pick_rows   = !empty($picking_preview[$item['id']]) ? $picking_preview[$item['id']] : [];
                    $display_rows = $expiry_rows ?: $pick_rows;
                ?>
                <td class="px-4 py-3 text-xs text-center">
                    <?php if ($display_rows): ?>
                    <div class="space-y-1">
                        <?php foreach ($display_rows as $row): ?>
                        <?php
                            $expiry = $row['expiry_date'] ?? null;
                            $cls    = $expiry ? lc_expiry_class($expiry) : 'text-gray-500';
                            $qty    = $row['quantity'];
                        ?>
                        <div class="<?php echo $cls; ?>">
                            <?php echo $expiry ? date('Y-m-d', strtotime($expiry)) : '-'; ?>
                            <span class="text-gray-400 ml-1">×<?php echo $qty; ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <span class="text-gray-300">-</span>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-right text-gray-600">
                    <?php if ($expiry_rows): ?>
                    <div class="space-y-1">
                        <?php foreach ($expiry_rows as $row): ?>
                        <div><?php echo number_format($row['cost_price'], 2); ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <?php echo number_format($item['unit_price'], 2); ?>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-right font-bold">
                    <?php if ($expiry_rows): ?>
                    <div class="space-y-1">
                        <?php foreach ($expiry_rows as $row): ?>
                        <div><?php echo number_format($row['cost_price'] * $row['quantity'], 2); ?></div>
                        <?php endforeach; ?>
                        <div class="border-t border-gray-200 pt-1 mt-1"><?php echo number_format(array_sum(array_map(fn($r) => $r['cost_price'] * $r['quantity'], $expiry_rows)), 2); ?></div>
                    </div>
                    <?php else: ?>
                    <?php echo number_format($item['unit_price'] * $item['quantity'], 2); ?>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <tr class="bg-gray-50">
                <td colspan="9" class="px-4 py-3 text-right text-sm font-semibold text-gray-700">Total</td>
                <td class="px-4 py-3 text-right text-base font-bold text-gray-900"><?php echo number_format($order['total_amount'], 2); ?></td>
            </tr>
            </tbody>
        </table>
    </div>

    <!-- Edit mode (approved: 단가/수량 수정) -->
    <?php if ($can_edit_items): ?>
    <div id="editMode" class="hidden">
        <form method="post" id="editItemsForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">
            <input type="hidden" name="action" value="edit_items">
            <table class="w-full text-sm">
                <thead class="bg-amber-50"><tr>
                    <th class="px-2 py-3 text-center text-xs text-amber-700 font-medium" style="width:40px;white-space:nowrap;">#</th>
                    <th class="px-3 py-3 text-left text-xs text-amber-700 font-medium" style="width:140px;">Barcode</th>
                    <th class="px-3 py-3 text-left text-xs text-amber-700 font-medium" style="width:120px;">Brand</th>
                    <th class="px-3 py-3 text-left text-xs text-amber-700 font-medium">Product Name</th>
                    <th class="px-2 py-3 text-center text-xs text-amber-700 font-medium" style="width:70px;">Qty</th>
                    <th class="px-2 py-3 text-center text-xs text-amber-700 font-medium" style="width:70px;">Unit</th>
                    <th class="px-4 py-3 text-right text-xs text-amber-700 font-medium">Unit Price</th>
                    <th class="px-4 py-3 text-right text-xs text-amber-700 font-medium w-32">Modify Quantity</th>
                    <th class="px-4 py-3 text-center text-xs text-amber-700 font-medium w-16">Delete</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                <?php $erow = 0; foreach ($items as $item): $erow++; ?>
                <?php $erow_cap = trim((string)($item['capacity'] ?? '')); ?>
                <?php $erow_unit = !empty($item['order_unit']) ? $item['order_unit'] : ($item['unit'] ?? '-'); ?>
                <tr id="editRow_<?php echo $item['id']; ?>">
                    <td class="px-2 py-3 text-center text-xs text-gray-500" style="white-space:nowrap;">
                        <input type="hidden" name="item_id[]" value="<?php echo $item['id']; ?>">
                        <?php echo $erow; ?>
                    </td>
                    <td class="px-3 py-3 text-center" style="line-height:1;">
                        <?php if (!empty($item['barcode'])): $ebc = trim((string)$item['barcode']); ?>
                        <svg class="barcode-svg" data-sku="<?php echo htmlspecialchars($ebc); ?>"></svg>
                        <div class="font-mono text-sm text-gray-600 barcode-number" style="margin-top:2px;line-height:1;"><?php echo htmlspecialchars($ebc); ?></div>
                        <?php else: ?><span class="text-gray-300">-</span><?php endif; ?>
                    </td>
                    <td class="px-3 py-3 text-sm text-gray-600">
                        <?php if (!empty($item['brand_name'])): ?><div><?php echo htmlspecialchars($item['brand_name']); ?></div><?php endif; ?>
                        <?php if (!empty($item['brand_name_ko'])): ?><div class="text-xs text-gray-500" style="margin-top:1px;"><?php echo htmlspecialchars($item['brand_name_ko']); ?></div><?php endif; ?>
                        <?php if (empty($item['brand_name']) && empty($item['brand_name_ko'])): ?><span class="text-gray-300">-</span><?php endif; ?>
                    </td>
                    <td class="px-3 py-3 font-medium text-gray-900" style="line-height:1.2;">
                        <div><?php echo htmlspecialchars($item['name_en'] . ($erow_cap !== '' ? ' ' . $erow_cap : '')); ?></div>
                        <?php if (!empty($item['name_ko'])): ?><div class="text-xs text-gray-500" style="margin-top:1px;"><?php echo htmlspecialchars($item['name_ko'] . ($erow_cap !== '' ? ' ' . $erow_cap : '')); ?></div><?php endif; ?>
                    </td>
                    <td class="px-2 py-3 text-center font-bold text-gray-900"><?php echo number_format($item['quantity']); ?></td>
                    <td class="px-2 py-3 text-center">
                        <?php $erow_unit_uc = strtoupper($erow_unit) === 'BOX' ? 'BOX' : 'PCS'; ?>
                        <select name="item_unit[]"
                                class="w-20 border border-gray-300 rounded-lg px-2 py-1.5 text-sm text-center bg-white
                                       focus:outline-none focus:ring-2 focus:ring-amber-400">
                            <option value="BOX" <?php echo $erow_unit_uc === 'BOX' ? 'selected' : ''; ?>>BOX</option>
                            <option value="PCS" <?php echo $erow_unit_uc === 'PCS' ? 'selected' : ''; ?>>PCS</option>
                        </select>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <input type="number" name="item_price[]"
                               id="price_<?php echo $item['id']; ?>"
                               value="<?php echo $item['unit_price']; ?>"
                               min="0" step="0.01"
                               class="w-24 border border-gray-300 rounded-lg px-2 py-1.5 text-sm text-right
                                      focus:outline-none focus:ring-2 focus:ring-amber-400">
                    </td>
                    <td class="px-4 py-3 text-right">
                        <input type="number" name="item_qty[]"
                               id="qty_<?php echo $item['id']; ?>"
                               value="<?php echo $item['quantity']; ?>"
                               min="0" step="1"
                               class="w-24 border border-gray-300 rounded-lg px-2 py-1.5 text-sm text-right
                                      focus:outline-none focus:ring-2 focus:ring-amber-400">
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button type="button"
                                onclick="setQtyZero(<?php echo $item['id']; ?>)"
                                class="text-red-400 hover:text-red-600 text-xs px-2 py-1 rounded hover:bg-red-50">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="px-4 py-3 border-t border-amber-100 bg-amber-50 flex items-center gap-3">
                <p class="text-xs text-amber-700 flex-1">
                    <i class="fas fa-info-circle mr-1"></i>Quantity 0 or delete: Remove that product. At least 1 item must remain.
                    <?php if (in_array($order['status'], ['shipped', 'delivered'], true)): ?>
                    <br><i class="fas fa-triangle-exclamation mr-1"></i>This order was already <?php echo $order['status']; ?>. Saving will re-adjust already-deducted inventory to match the new quantities.
                    <?php endif; ?>
                </p>
                <button type="button" onclick="toggleItemEdit()"
                        class="px-4 py-2 bg-white border border-gray-300 text-gray-600 text-sm rounded-lg hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit" onclick="return confirm('Do you want to save these changes?')"
                        class="px-4 py-2 bg-amber-500 text-white text-sm font-semibold rounded-lg hover:bg-amber-600">
                    <i class="fas fa-save mr-1"></i>Save
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<!-- 인쇄 전용: 결제(서명)란 — Prepared / Received -->
<?php $prepared_by = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? ''); ?>
<div id="signOffBox" class="print-only" style="display:none;">
    <table style="width:100%; border-collapse:collapse; table-layout:fixed;">
        <thead>
            <tr>
                <th style="width:50%; text-align:center; font-weight:700; padding:6px;">Prepared by</th>
                <th style="width:50%; text-align:center; font-weight:700; padding:6px;">Received by</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="padding:8px 10px; vertical-align:top;">
                    <div>Name: <strong><?php echo htmlspecialchars($prepared_by); ?></strong></div>
                    <div class="sign-space"></div>
                    <div style="text-align:right;">Signature: _____________________</div>
                </td>
                <td style="padding:8px 10px; vertical-align:top;">
                    <div>Name: _____________________</div>
                    <div class="sign-space"></div>
                    <div style="text-align:right;">Signature: _____________________</div>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<script>
function toggleItemEdit() {
    var view = document.getElementById('viewMode');
    var edit = document.getElementById('editMode');
    var btn  = document.getElementById('editToggleBtn');
    var isEdit = !edit.classList.contains('hidden');
    if (isEdit) {
        edit.classList.add('hidden');
        view.classList.remove('hidden');
        btn.innerHTML = '<i class="fas fa-edit mr-1"></i>Modify Quantity';
    } else {
        view.classList.add('hidden');
        edit.classList.remove('hidden');
        btn.innerHTML = '<i class="fas fa-times mr-1"></i>Cancel';
    }
}
function setQtyZero(itemId) {
    document.getElementById('qty_' + itemId).value = 0;
    document.getElementById('editRow_' + itemId).style.opacity = '0.4';
}
</script>

<!-- 액션 버튼 (물류직원/관리자만) -->
<?php if (lc_is_staff()): ?>
<div class="flex gap-3 flex-wrap no-print">
    <?php if ($order['status'] === 'pending'): ?>
    <form method="post" class="inline">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">
        <input type="hidden" name="action" value="approve">
        <button type="submit" onclick="return confirm('Approve this order? Inventory will be deducted (reserved) now.')"
                class="px-5 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700">
            <i class="fas fa-check mr-2"></i>Approve
        </button>
    </form>
    <form method="post" class="inline">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">
        <input type="hidden" name="action" value="cancel">
        <button type="submit" onclick="return confirm('Do you want to cancel this order?')"
                class="px-5 py-2 bg-red-100 text-red-700 text-sm font-medium rounded-lg hover:bg-red-200">
            <i class="fas fa-times mr-2"></i>Cancel
        </button>
    </form>
    <?php elseif ($order['status'] === 'approved'): ?>
    <form method="post" class="inline">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">
        <input type="hidden" name="action" value="ship">
        <button type="submit" onclick="return confirm('Process outbound? (Inventory was already deducted at approval.)')"
                class="px-5 py-2 bg-purple-600 text-white text-sm font-medium rounded-lg hover:bg-purple-700">
            <i class="fas fa-truck mr-2"></i>Process Outbound
        </button>
    </form>
    <form method="post" class="inline">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">
        <input type="hidden" name="action" value="cancel">
        <button type="submit" onclick="return confirm('Do you want to cancel this approved order?')"
                class="px-5 py-2 bg-red-100 text-red-700 text-sm font-medium rounded-lg hover:bg-red-200">
            <i class="fas fa-times mr-2"></i>Cancel
        </button>
    </form>
    <?php elseif ($order['status'] === 'cancel_requested'): ?>
    <div class="w-full bg-orange-50 border border-orange-200 rounded-lg px-4 py-3 mb-3 text-sm text-orange-800">
        <i class="fas fa-exclamation-circle mr-2"></i>Store requested cancellation. Approving will cancel the order.
    </div>
    <form method="post" class="inline">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">
        <input type="hidden" name="action" value="approve_cancel">
        <button type="submit" onclick="return confirm('Approve the cancellation request? The order will be cancelled.')"
                class="px-5 py-2 bg-red-600 text-white text-sm font-medium rounded-lg hover:bg-red-700">
            <i class="fas fa-check mr-2"></i>Approve Cancellation
        </button>
    </form>
    <form method="post" class="inline">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">
        <input type="hidden" name="action" value="reject_cancel">
        <button type="submit" onclick="return confirm('Reject the cancellation request and restore to approved status?')"
                class="px-5 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200">
            <i class="fas fa-undo mr-2"></i>Reject Cancellation
        </button>
    </form>
    <?php elseif ($order['status'] === 'shipped'): ?>
    <form method="post" class="inline">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">
        <input type="hidden" name="action" value="deliver">
        <button type="submit" onclick="return confirm('Mark as delivery completed?')"
                class="px-5 py-2 bg-green-600 text-white text-sm font-medium rounded-lg hover:bg-green-700">
            <i class="fas fa-check-double mr-2"></i>Delivery Completed
        </button>
    </form>
    <?php endif; ?>
    <?php if ($order['status'] === 'delivered' && lc_is_admin()): ?>
    <form method="post" class="inline">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">
        <input type="hidden" name="action" value="revert_delivery">
        <button type="submit" onclick="return confirm('Revert this order to Approved status? Inventory deducted at outbound will be restored.')"
                class="px-5 py-2 bg-orange-100 text-orange-700 text-sm font-medium rounded-lg hover:bg-orange-200">
            <i class="fas fa-undo mr-2"></i>Revert to Approved
        </button>
    </form>
    <?php endif; ?>
    <?php if (in_array($order['status'], ['cancelled', 'delivered']) && empty($order['deleted_at']) && lc_is_admin()): ?>
    <form method="post" class="inline">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">
        <input type="hidden" name="action" value="soft_delete">
        <button type="submit" onclick="return confirm('Move this order to trash? It can be restored later from the Deleted tab in the order list.')"
                class="px-5 py-2 bg-red-100 text-red-700 text-sm font-medium rounded-lg hover:bg-red-200">
            <i class="fas fa-trash mr-2"></i>Delete
        </button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- 바코드 렌더링 (JsBarcode) -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
(function() {
    // admin/price_change_history.php 의 generatePriceCardBarcodes 와 동일한 방식.
    // 자릿수만으로 심볼로지 판별(체크디지트 검증 X) → 13자리는 무조건 EAN13으로 출력.
    // 바코드는 자연 크기로 렌더(축소 금지) → 막대 간격 유지되어 스캔 가능.
    function renderBarcodes() {
        if (typeof JsBarcode === 'undefined') return;
        var common = { displayValue: false, height: 20, margin: 0, lineColor: '#000', background: '#fff' };
        document.querySelectorAll('.barcode-svg').forEach(function(svg) {
            var sku = svg.getAttribute('data-sku');
            if (!sku) return;
            function draw(value, opts) {
                JsBarcode(svg, value, Object.assign({}, common, opts));
            }
            try {
                if (/^\d{13}$/.test(sku)) {
                    draw(sku, { format: 'EAN13', width: 1.2 });
                } else if (/^\d{12}$/.test(sku)) {
                    draw('0' + sku, { format: 'EAN13', width: 1.2 }); // UPC-A → EAN13
                } else if (/^\d{8}$/.test(sku)) {
                    draw(sku, { format: 'EAN8', width: 1.6 });
                } else {
                    draw(sku, { format: 'CODE128', width: 1.2 });
                }
            } catch (e) {
                try { draw(sku, { format: 'CODE128', width: 1.2 }); }
                catch (e2) { /* 인코딩 불가 코드는 무시 */ }
            }
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderBarcodes);
    } else {
        renderBarcodes();
    }
})();
</script>

<?php if (lc_is_staff() && empty($order['deleted_at'])): ?>
<?php $add_deducts = in_array($order['status'], ['approved', 'cancel_requested', 'shipped', 'delivered'], true); ?>
<!-- 품목 추가 모달 -->
<div id="addItemModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 no-print">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg mx-4">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
            <h3 class="text-base font-semibold text-gray-900"><i class="fas fa-plus text-teal-600 mr-2"></i>Add Item</h3>
            <button type="button" onclick="closeAddItemModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <form method="post" class="px-6 py-5 space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">
            <input type="hidden" name="action" value="add_item">
            <input type="hidden" name="product_id" id="addItemProductId" value="">

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Product <span class="text-red-500">*</span></label>
                <div class="relative">
                    <input type="text" id="addItemSearch" autocomplete="off" placeholder="Search barcode or product name..."
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <div id="addItemDropdown" class="hidden absolute z-20 top-full left-0 right-0 mt-1"></div>
                </div>
                <p id="addItemSelected" class="hidden mt-1 text-sm text-teal-700 font-medium"></p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Quantity <span class="text-red-500">*</span></label>
                <input type="number" name="quantity" id="addItemQty" min="1" value="1"
                       class="w-32 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>

            <div class="text-xs <?php echo $add_deducts ? 'text-orange-700 bg-orange-50 border border-orange-200' : 'text-gray-500 bg-gray-50 border border-gray-200'; ?> rounded-lg px-3 py-2">
                <i class="fas fa-info-circle mr-1"></i>
                <?php if ($add_deducts): ?>
                This order's stock is already deducted. The added item will be deducted from inventory now (FEFO), and its cost will be finalized automatically.
                <?php else: ?>
                The item will be added to the order. Inventory will be deducted and cost finalized when the order is approved.
                <?php endif; ?>
            </div>

            <div class="flex gap-3 pt-1">
                <button type="button" onclick="closeAddItemModal()"
                        class="flex-1 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
                <button type="submit" id="addItemSubmit" disabled
                        class="flex-1 py-2 bg-teal-600 text-white rounded-lg text-sm font-semibold hover:bg-teal-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                    <i class="fas fa-plus mr-1"></i>Add
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    var LC_BASE = '<?php echo LC_BASE; ?>';
    var searchEl = document.getElementById('addItemSearch');
    var dropEl   = document.getElementById('addItemDropdown');
    var pidEl    = document.getElementById('addItemProductId');
    var selEl    = document.getElementById('addItemSelected');
    var submitEl = document.getElementById('addItemSubmit');
    var qtyEl    = document.getElementById('addItemQty');
    var timer = null;
    var lastQuery = '';             // 마지막 검색어 (IME commit 중복 검색 방지)
    var mouseSelectEnabled = false; // 실제 포인터 이동 전까지 hover 선택 무시
    var _results = [];
    var _rows = [];
    var _activeIdx = -1;
    var _scrollWrap = null;

    function escHtml(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

    window.openAddItemModal = function() {
        pidEl.value = ''; searchEl.value = ''; selEl.classList.add('hidden'); selEl.textContent = '';
        lastQuery = ''; submitEl.disabled = true; qtyEl.value = 1;
        dropEl.innerHTML = ''; dropEl.classList.add('hidden');
        _results = []; _rows = []; _activeIdx = -1;
        document.getElementById('addItemModal').classList.remove('hidden');
        setTimeout(function() { searchEl.focus(); }, 50);
    };
    window.closeAddItemModal = function() {
        document.getElementById('addItemModal').classList.add('hidden');
    };

    function selectProduct(p) {
        pidEl.value = p.id;
        selEl.textContent = '✓ ' + p.name_en + (p.name_ko ? ' (' + p.name_ko + ')' : '') + (p.unit ? ' [' + p.unit + ']' : '');
        selEl.classList.remove('hidden');
        submitEl.disabled = false;
        searchEl.value = p.name_en + (p.name_ko ? ' (' + p.name_ko + ')' : '');
        lastQuery = searchEl.value.trim();
        dropEl.classList.add('hidden');
        qtyEl.focus(); qtyEl.select();
    }

    // 패널 내부에서만 스크롤 (윈도우는 건드리지 않음)
    function scrollRowIntoView(tr) {
        if (!_scrollWrap) return;
        var wr = _scrollWrap.getBoundingClientRect();
        var rr = tr.getBoundingClientRect();
        if (rr.top < wr.top) _scrollWrap.scrollTop -= (wr.top - rr.top);
        else if (rr.bottom > wr.bottom) _scrollWrap.scrollTop += (rr.bottom - wr.bottom);
    }

    function setActiveRow(idx) {
        _rows.forEach(function(tr, i) {
            var numBadge = tr.querySelector('.row-num');
            var addBadge = tr.querySelector('.add-badge');
            if (i === idx) {
                tr.style.backgroundColor = '#0f766e'; // teal-700
                tr.querySelectorAll('p, span:not(.add-badge):not(.row-num)').forEach(function(el) { el.style.color = 'rgba(255,255,255,0.9)'; });
                if (numBadge) { numBadge.style.backgroundColor = 'rgba(255,255,255,0.25)'; numBadge.style.color = '#fff'; }
                if (addBadge) { addBadge.style.backgroundColor = '#fff'; addBadge.style.color = '#0f766e'; }
                scrollRowIntoView(tr);
            } else {
                tr.style.backgroundColor = '';
                tr.querySelectorAll('p, span:not(.add-badge):not(.row-num)').forEach(function(el) { el.style.color = ''; });
                if (numBadge) { numBadge.style.backgroundColor = ''; numBadge.style.color = ''; }
                if (addBadge) { addBadge.style.backgroundColor = ''; addBadge.style.color = ''; }
            }
        });
        _activeIdx = idx;
    }

    // inbound_add.php 의 showMulti 와 동일한 결과 패널
    function showResults(prods) {
        _results = prods || [];
        _rows = [];
        _activeIdx = -1;
        dropEl.innerHTML = '';

        var panel = document.createElement('div');
        panel.className = 'bg-white border-2 border-teal-300 rounded-xl shadow-lg overflow-hidden';

        var header = document.createElement('div');
        header.className = 'flex items-center justify-between px-4 py-2.5 bg-teal-600 text-white';
        header.innerHTML =
            '<span class="font-semibold text-sm"><i class="fas fa-boxes mr-2"></i>' + _results.length + ' products found</span>' +
            '<span class="text-xs text-teal-200 flex items-center gap-1.5">' +
            '<kbd class="px-1.5 py-0.5 bg-teal-700 rounded text-xs">↑↓</kbd> Move' +
            '<kbd class="px-1.5 py-0.5 bg-teal-700 rounded text-xs ml-1">Enter</kbd> Add' +
            '<kbd class="px-1.5 py-0.5 bg-teal-700 rounded text-xs ml-1">Esc</kbd> Close' +
            '</span>';
        panel.appendChild(header);

        if (!_results.length) {
            var empty = document.createElement('div');
            empty.className = 'px-4 py-4 text-sm text-gray-400 text-center';
            empty.textContent = 'No results';
            panel.appendChild(empty);
            dropEl.appendChild(panel);
            dropEl.classList.remove('hidden');
            return;
        }

        var tableWrap = document.createElement('div');
        tableWrap.className = 'max-h-64 overflow-y-auto';
        var table = document.createElement('table');
        table.className = 'w-full';

        _results.forEach(function(p, idx) {
            var tr = document.createElement('tr');
            tr.className = 'multi-row border-b border-gray-100 cursor-pointer transition-all duration-100';
            tr.innerHTML =
                '<td class="px-1.5 py-3 w-8 text-center">' +
                    '<span class="row-num inline-flex items-center justify-center w-6 h-6 rounded-full bg-gray-100 text-gray-500 text-xs font-bold">' + (idx + 1) + '</span>' +
                '</td>' +
                '<td class="px-3 py-3">' +
                    '<p class="font-semibold text-gray-900 text-sm leading-tight">' + escHtml(p.name_en) + '</p>' +
                    (p.name_ko ? '<p class="text-xs text-gray-500 mt-0.5">' + escHtml(p.name_ko) + '</p>' : '') +
                    (p.barcode_unit ? '<p class="text-xs text-gray-400 font-mono mt-0.5"><i class="fas fa-barcode mr-1"></i>' + escHtml(p.barcode_unit) + '</p>' : '') +
                '</td>' +
                '<td class="px-3 py-3 w-16">' +
                    '<span class="inline-block px-2 py-0.5 bg-gray-100 text-gray-600 text-xs rounded-full font-medium">' + escHtml(p.unit || '') + '</span>' +
                '</td>' +
                '<td class="px-3 py-3 w-20 text-right">' +
                    '<span class="add-badge inline-flex items-center gap-1 px-3 py-1 bg-teal-600 text-white text-xs font-semibold rounded-lg">' +
                    '<i class="fas fa-plus text-xs"></i>Add</span>' +
                '</td>';
            tr.addEventListener('click', function() { selectProduct(p); });
            tr.addEventListener('mouseenter', function() { if (mouseSelectEnabled) setActiveRow(idx); });
            table.appendChild(tr);
            _rows.push(tr);
        });

        // 실제 포인터 이동이 있을 때만 hover 선택 활성화 (키보드 탐색/스크롤 가짜 mouseenter 차단)
        tableWrap.addEventListener('mousemove', function() { mouseSelectEnabled = true; });
        tableWrap.appendChild(table);
        panel.appendChild(tableWrap);
        dropEl.appendChild(panel);
        dropEl.classList.remove('hidden');

        _scrollWrap = tableWrap;
        mouseSelectEnabled = false;
        setActiveRow(0);
    }

    function search(q) {
        // 같은 검색어의 패널이 이미 떠 있으면 재검색/재렌더 생략 (IME commit 중복 input 대응)
        if (q === lastQuery && !dropEl.classList.contains('hidden')) return;
        lastQuery = q;
        fetch(LC_BASE + '/ajax/search_product_by_barcode.php?barcode=' + encodeURIComponent(q))
            .then(function(r) { return r.json(); })
            .then(function(data) { showResults(data.success && data.products ? data.products : []); })
            .catch(function() { showResults([]); });
    }

    // 실시간 검색 (한글 조합 중에도 input마다 디바운스 검색)
    searchEl.addEventListener('input', function() {
        var q = searchEl.value.trim();
        pidEl.value = ''; submitEl.disabled = true; selEl.classList.add('hidden');
        if (timer) clearTimeout(timer);
        if (q.length < 2) { dropEl.classList.add('hidden'); return; }
        timer = setTimeout(function() { search(q); }, 300);
    });

    // ↑↓ 이동, Enter 선택, Esc 닫기 (isComposing 가드 없음 — inbound_add.php와 동일)
    searchEl.addEventListener('keydown', function(e) {
        var open = !dropEl.classList.contains('hidden') && _results.length > 0;
        if (open && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
            e.preventDefault();
            mouseSelectEnabled = false;
            var next = _activeIdx + (e.key === 'ArrowDown' ? 1 : -1);
            next = Math.max(0, Math.min(_results.length - 1, next));
            setActiveRow(next);
            return;
        }
        if (e.key === 'Enter') {
            if (open) {
                e.preventDefault();
                var pick = _results[_activeIdx >= 0 ? _activeIdx : 0];
                if (pick) selectProduct(pick);
            }
        } else if (e.key === 'Escape') {
            if (open) { e.preventDefault(); dropEl.classList.add('hidden'); }
        }
    });

    searchEl.addEventListener('blur', function() { setTimeout(function() { dropEl.classList.add('hidden'); }, 150); });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && dropEl.classList.contains('hidden')) closeAddItemModal();
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
