<?php
// Design Ref: §4 — branch-outbound API (action 기반 dispatch, distribute_to_stores.php 패턴)
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/inventory_helper.php';
require_once __DIR__ . '/../lib/unit_helper.php'; // Design Ref: box-pcs-unit §4.3
header('Content-Type: application/json; charset=utf-8');

kw_require_staff();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// 점포 목록 조회
if ($action === 'get_stores') {
    try {
        $conn = get_lc_db();
        $stores = $conn->query("SELECT id, name FROM stores ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
        $conn->close();
        echo json_encode(['success' => true, 'stores' => $stores]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// 상품 재고 및 평균원가 조회 (장바구니 미리보기용)
// Design Ref: box-pcs-unit §4.2 — 단위별(BOX/PCS) 재고·유효단가 분리 응답
if ($action === 'get_product_stock') {
    $product_id = (int)($_GET['product_id'] ?? 0);
    if (!$product_id) { echo json_encode(['success' => false, 'message' => 'Invalid product']); exit; }
    try {
        $conn = get_lc_db();
        $st = $conn->prepare(
            "SELECT p.id, CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS name,
                    p.unit, GREATEST(1, IFNULL(p.pieces_per_box, 1)) AS pieces_per_box
             FROM kw_products p
             WHERE p.id = ?"
        );
        $st->bind_param('i', $product_id);
        $st->execute();
        $product = $st->get_result()->fetch_assoc();
        $st->close();

        if ($product) {
            $stock = kw_get_stock_by_unit($conn, $product_id);
            $cost  = kw_get_avg_cost_by_unit($conn, $product_id);
            // Design Ref: pack-unit §5 — BOX/PACK/PCS 단위별 재고·평균원가 반환
            $product['box_stock']     = $stock[LC_UNIT_BOX];
            $product['pack_stock']    = $stock[LC_UNIT_PACK];
            $product['pcs_stock']     = $stock[LC_UNIT_PCS];
            $product['box_avg_cost']  = round($cost[LC_UNIT_BOX], 4);
            $product['pack_avg_cost'] = round($cost[LC_UNIT_PACK], 4);
            $product['pcs_avg_cost']  = round($cost[LC_UNIT_PCS], 4);
            // 하위 호환 필드
            $product['total_stock'] = $stock[LC_UNIT_BOX] + $stock[LC_UNIT_PACK] + $stock[LC_UNIT_PCS];
            $product['avg_cost']    = $product['pcs_avg_cost'] ?: ($product['pack_avg_cost'] ?: $product['box_avg_cost']);
        }
        $conn->close();
        echo json_encode(['success' => true, 'product' => $product]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// FEFO 피킹 미리보기 (위치/유통기한, 유통기한 빠른 순)
// Design Ref: box-pcs-unit §4.2 — 단위 필터 + suggest_break(FR-06)
if ($action === 'get_fefo_preview') {
    $product_id = (int)($_GET['product_id'] ?? 0);
    $qty        = (int)($_GET['qty'] ?? 0);
    $unit       = kw_valid_unit($_GET['unit'] ?? '', LC_UNIT_PCS);
    if (!$product_id || $qty <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid request']); exit; }
    try {
        $conn = get_lc_db();
        // Plan SC-5 — 동일 단위 lot에서만 피킹
        $preview = kw_unit_fefo_preview_allow_negative($conn, $product_id, $qty, $unit);
        $conn->close();
        echo json_encode([
            'success' => true,
            'picks' => $preview['picks'],
            'shortfall' => $preview['shortfall'],
            'suggest_break' => $preview['suggest_break'],
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// 출고 등록
if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    kw_verify_csrf();

    $store_id = (int)($_POST['store_id'] ?? 0);
    $notes    = trim($_POST['notes'] ?? '');
    $items    = json_decode($_POST['items'] ?? '[]', true);

    if (!$store_id) { echo json_encode(['success' => false, 'message' => 'Please select the destination store.']); exit; }
    if (!is_array($items) || empty($items)) { echo json_encode(['success' => false, 'message' => 'The cart is empty.']); exit; }

    $cart = [];
    foreach ($items as $it) {
        $pid = (int)($it['product_id'] ?? 0);
        $qty = (int)($it['quantity'] ?? 0);
        $unit = kw_valid_unit($it['unit'] ?? '', LC_UNIT_PCS); // Design §4.2 — items에 unit 필드
        if ($pid > 0 && $qty > 0) $cart[] = ['product_id' => $pid, 'quantity' => $qty, 'unit' => $unit];
    }
    if (empty($cart)) { echo json_encode(['success' => false, 'message' => 'No valid products/quantities.']); exit; }

    try {
        $conn = get_lc_db();
        $conn->autocommit(false);

        $uid   = kw_current_user_id();
        $today = date('Y-m-d');

        // Plan FR-06: kw_orders 1건(status='shipped') 생성, total_amount는 처리 후 갱신
        $ins_order = $conn->prepare(
            "INSERT INTO kw_orders (order_date, store_id, status, total_amount, notes, created_by, shipped_at)
             VALUES (?, ?, 'shipped', 0, ?, ?, NOW())"
        );
        $ins_order->bind_param('sisi', $today, $store_id, $notes, $uid);
        $ins_order->execute();
        $order_id = $conn->insert_id;
        $ins_order->close();

        $ins_item = $conn->prepare(
            "INSERT INTO kw_order_items (order_id, product_id, quantity, order_unit, pieces_per_box, unit_price)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $ins_lot = $conn->prepare(
            "INSERT INTO kw_order_item_lots (order_item_id, inventory_id, inbound_id, quantity, cost_price)
             VALUES (?, ?, ?, ?, ?)"
        );

        $ppb_map = kw_bo_ppb_map($conn, array_column($cart, 'product_id'));

        $total_amount = 0.0;
        foreach ($cart as $line) {
            // Design Ref: box-pcs-unit §4.3 / Plan SC-5 — 동일 단위 lot에서만 FEFO 차감 (음수 허용)
            $deductions = kw_unit_fifo_ship_allow_negative($conn, $line['product_id'], $line['quantity'], $line['unit']);

            $total_cost = 0.0;
            foreach ($deductions as $d) {
                $total_cost += $d['quantity'] * $d['cost_price'];
            }
            // Design Ref: §3.2 — unit_price = 가중평균 cost_price (마진 0)
            $unit_price = round($total_cost / $line['quantity'], 4);

            $line_ppb = $ppb_map[$line['product_id']] ?? 1;
            $ins_item->bind_param('iiisid', $order_id, $line['product_id'], $line['quantity'], $line['unit'], $line_ppb, $unit_price);
            $ins_item->execute();
            $order_item_id = $conn->insert_id;

            foreach ($deductions as $d) {
                $ins_lot->bind_param('iiiid', $order_item_id, $d['inventory_id'], $d['inbound_id'], $d['quantity'], $d['cost_price']);
                $ins_lot->execute();
            }

            $total_amount += $line['quantity'] * $unit_price;
        }

        $ins_item->close();
        $ins_lot->close();

        $upd_order = $conn->prepare("UPDATE kw_orders SET total_amount = ? WHERE id = ?");
        $upd_order->bind_param('di', $total_amount, $order_id);
        $upd_order->execute();
        $upd_order->close();

        $conn->commit();
        $conn->close();

        echo json_encode(['success' => true, 'order_id' => $order_id]);
    } catch (Exception $e) {
        if (isset($conn)) { $conn->rollback(); $conn->close(); }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────────
// Design Ref: §3, §4 — Draft 워크플로우 액션 (save/get/update/delete/ship_draft)
// ─────────────────────────────────────────────────────────────────

// 예상 단위별 평균원가 조회 (draft 저장 시 unit_price 예상가)
// Design Ref: box-pcs-unit §3.2 — 유효단가 기준, 요청 단위 lot의 가중평균
function kw_bo_avg_cost(mysqli $conn, int $product_id, string $unit = LC_UNIT_PCS): float {
    $cost = kw_get_avg_cost_by_unit($conn, $product_id);
    return (float)($cost[kw_valid_unit($unit)] ?? 0);
}

// 상품별 ppb 스냅샷 맵 (kw_order_items.pieces_per_box 기록용)
function kw_bo_ppb_map(mysqli $conn, array $product_ids): array {
    $product_ids = array_values(array_unique(array_map('intval', $product_ids)));
    if (empty($product_ids)) return [];
    $in = implode(',', $product_ids);
    $map = [];
    $res = $conn->query("SELECT id, GREATEST(1, IFNULL(pieces_per_box, 1)) AS ppb FROM kw_products WHERE id IN ($in)");
    foreach ($res->fetch_all(MYSQLI_ASSOC) as $r) $map[(int)$r['id']] = (int)$r['ppb'];
    return $map;
}

// items JSON 파싱 + 검증 → [['product_id'=>..,'quantity'=>..,'unit'=>..], ...]
function kw_bo_parse_items(string $json): array {
    $items = json_decode($json, true);
    if (!is_array($items)) return [];
    $cart = [];
    foreach ($items as $it) {
        $pid = (int)($it['product_id'] ?? 0);
        $qty = (int)($it['quantity'] ?? 0);
        $unit = kw_valid_unit($it['unit'] ?? '', LC_UNIT_PCS);
        if ($pid > 0 && $qty > 0) $cart[] = ['product_id' => $pid, 'quantity' => $qty, 'unit' => $unit];
    }
    return $cart;
}

// draft 품목 일괄 삽입 (예상가 기준), 예상 total 반환
function kw_bo_insert_items(mysqli $conn, int $order_id, array $cart): float {
    $ins = $conn->prepare(
        "INSERT INTO kw_order_items (order_id, product_id, quantity, order_unit, pieces_per_box, unit_price)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $ppb_map = kw_bo_ppb_map($conn, array_column($cart, 'product_id'));
    $total = 0.0;
    foreach ($cart as $line) {
        $price = kw_bo_avg_cost($conn, $line['product_id'], $line['unit']);
        $ppb = $ppb_map[$line['product_id']] ?? 1;
        $ins->bind_param('iiisid', $order_id, $line['product_id'], $line['quantity'], $line['unit'], $ppb, $price);
        $ins->execute();
        $total += $line['quantity'] * $price;
    }
    $ins->close();
    return $total;
}

// Plan SC-1: 저장 시 재고 차감 없음 — kw_orders(status='draft') + items만 기록
if ($action === 'save_draft' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    kw_verify_csrf();

    $store_id = (int)($_POST['store_id'] ?? 0);
    $notes    = trim($_POST['notes'] ?? '');
    $cart     = kw_bo_parse_items($_POST['items'] ?? '[]');

    if (!$store_id) { echo json_encode(['success' => false, 'message' => 'Please select the destination store.']); exit; }
    if (empty($cart)) { echo json_encode(['success' => false, 'message' => 'Please add products to ship.']); exit; }

    try {
        $conn = get_lc_db();
        $conn->autocommit(false);

        $uid   = kw_current_user_id();
        $today = date('Y-m-d');

        $st = $conn->prepare(
            "INSERT INTO kw_orders (order_date, store_id, status, total_amount, notes, created_by)
             VALUES (?, ?, 'draft', 0, ?, ?)"
        );
        $st->bind_param('sisi', $today, $store_id, $notes, $uid);
        $st->execute();
        $draft_id = $conn->insert_id;
        $st->close();

        $total = kw_bo_insert_items($conn, $draft_id, $cart);

        $upd = $conn->prepare("UPDATE kw_orders SET total_amount = ? WHERE id = ?");
        $upd->bind_param('di', $total, $draft_id);
        $upd->execute();
        $upd->close();

        $conn->commit();
        $conn->close();
        echo json_encode(['success' => true, 'draft_id' => $draft_id]);
    } catch (Exception $e) {
        if (isset($conn)) { $conn->rollback(); $conn->close(); }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// 수정 모드 진입 시 장바구니 복원용
if ($action === 'get_draft') {
    $draft_id = (int)($_GET['draft_id'] ?? 0);
    if (!$draft_id) { echo json_encode(['success' => false, 'message' => 'Invalid draft']); exit; }
    try {
        $conn = get_lc_db();

        $st = $conn->prepare("SELECT id, store_id, notes, status FROM kw_orders WHERE id = ?");
        $st->bind_param('i', $draft_id);
        $st->execute();
        $order = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$order || $order['status'] !== 'draft') {
            $conn->close();
            echo json_encode(['success' => false, 'message' => 'This shipment is not in pending status.']);
            exit;
        }

        $st = $conn->prepare(
            "SELECT oi.product_id, oi.quantity,
                    COALESCE(oi.order_unit, 'PCS') AS order_unit,
                    p.name_en, p.name_ko, p.unit, p.capacity, p.pieces_per_box,
                    p.barcode_unit, p.barcode_box, p.barcode_logistics,
                    b.name_en AS brand_en, b.name_ko AS brand_ko
             FROM kw_order_items oi
             LEFT JOIN kw_products p ON oi.product_id = p.id
             LEFT JOIN kw_brands b ON p.brand_id = b.id
             WHERE oi.order_id = ?
             ORDER BY oi.id ASC"
        );
        $st->bind_param('i', $draft_id);
        $st->execute();
        $items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        $conn->close();

        echo json_encode(['success' => true, 'draft' => [
            'id' => (int)$order['id'],
            'store_id' => (int)$order['store_id'],
            'notes' => $order['notes'],
            'items' => $items
        ]]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Plan SC-3: draft 수정 — 품목 전체 삭제 후 재삽입 (Design §3.2)
if ($action === 'update_draft' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    kw_verify_csrf();

    $draft_id = (int)($_POST['draft_id'] ?? 0);
    $store_id = (int)($_POST['store_id'] ?? 0);
    $notes    = trim($_POST['notes'] ?? '');
    $cart     = kw_bo_parse_items($_POST['items'] ?? '[]');

    if (!$draft_id || !$store_id) { echo json_encode(['success' => false, 'message' => 'Invalid request.']); exit; }
    if (empty($cart)) { echo json_encode(['success' => false, 'message' => 'Please add products to ship.']); exit; }

    try {
        $conn = get_lc_db();
        $conn->autocommit(false);

        // status='draft' 검증 (shipped 건 수정 차단)
        $st = $conn->prepare("SELECT status FROM kw_orders WHERE id = ? FOR UPDATE");
        $st->bind_param('i', $draft_id);
        $st->execute();
        $order = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$order || $order['status'] !== 'draft') {
            $conn->rollback(); $conn->close();
            echo json_encode(['success' => false, 'message' => 'Only shipments in pending status can be edited.']);
            exit;
        }

        $del = $conn->prepare("DELETE FROM kw_order_items WHERE order_id = ?");
        $del->bind_param('i', $draft_id);
        $del->execute();
        $del->close();

        $total = kw_bo_insert_items($conn, $draft_id, $cart);

        $upd = $conn->prepare("UPDATE kw_orders SET store_id = ?, notes = ?, total_amount = ? WHERE id = ?");
        $upd->bind_param('isdi', $store_id, $notes, $total, $draft_id);
        $upd->execute();
        $upd->close();

        $conn->commit();
        $conn->close();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        if (isset($conn)) { $conn->rollback(); $conn->close(); }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Plan SC-4: draft 삭제 가능 / shipped 삭제 불가
if ($action === 'delete_draft' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    kw_verify_csrf();

    $draft_id = (int)($_POST['draft_id'] ?? 0);
    if (!$draft_id) { echo json_encode(['success' => false, 'message' => 'Invalid request.']); exit; }

    try {
        $conn = get_lc_db();
        $conn->autocommit(false);

        $st = $conn->prepare("SELECT status FROM kw_orders WHERE id = ? FOR UPDATE");
        $st->bind_param('i', $draft_id);
        $st->execute();
        $order = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$order || $order['status'] !== 'draft') {
            $conn->rollback(); $conn->close();
            echo json_encode(['success' => false, 'message' => 'Only shipments in pending status can be deleted.']);
            exit;
        }

        $del = $conn->prepare("DELETE FROM kw_order_items WHERE order_id = ?");
        $del->bind_param('i', $draft_id);
        $del->execute();
        $del->close();

        $del2 = $conn->prepare("DELETE FROM kw_orders WHERE id = ?");
        $del2->bind_param('i', $draft_id);
        $del2->execute();
        $del2->close();

        $conn->commit();
        $conn->close();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        if (isset($conn)) { $conn->rollback(); $conn->close(); }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Plan SC-5: 최종 출고 — 이 시점에만 FEFO 차감. Design §3.4 조건부 UPDATE로 이중 출고 방지
if ($action === 'ship_draft' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    kw_verify_csrf();

    $draft_id = (int)($_POST['draft_id'] ?? 0);
    if (!$draft_id) { echo json_encode(['success' => false, 'message' => 'Invalid request.']); exit; }

    try {
        $conn = get_lc_db();
        $conn->autocommit(false);

        // 조건부 UPDATE: status='draft'일 때만 전환 → affected_rows=0이면 이미 처리된 건
        $st = $conn->prepare("UPDATE kw_orders SET status = 'shipped', shipped_at = NOW() WHERE id = ? AND status = 'draft'");
        $st->bind_param('i', $draft_id);
        $st->execute();
        $claimed = $st->affected_rows;
        $st->close();

        if ($claimed === 0) {
            $conn->rollback(); $conn->close();
            echo json_encode(['success' => false, 'message' => 'This shipment has already been processed or does not exist.']);
            exit;
        }

        $st = $conn->prepare("SELECT id, product_id, quantity, COALESCE(order_unit, 'PCS') AS order_unit FROM kw_order_items WHERE order_id = ?");
        $st->bind_param('i', $draft_id);
        $st->execute();
        $items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();

        if (empty($items)) {
            $conn->rollback(); $conn->close();
            echo json_encode(['success' => false, 'message' => 'No items to ship.']);
            exit;
        }

        $upd_item = $conn->prepare("UPDATE kw_order_items SET unit_price = ? WHERE id = ?");
        $ins_lot  = $conn->prepare(
            "INSERT INTO kw_order_item_lots (order_item_id, inventory_id, inbound_id, quantity, cost_price)
             VALUES (?, ?, ?, ?, ?)"
        );

        $total_amount = 0.0;
        foreach ($items as $line) {
            $pid  = (int)$line['product_id'];
            $qty  = (int)$line['quantity'];
            $unit = kw_valid_unit($line['order_unit'] ?? '', LC_UNIT_PCS);

            // Design §3.4 / Plan SC-5 — 출고 시점 재고 기준, 동일 단위 lot에서만 FEFO 차감 (음수 허용)
            $deductions = kw_unit_fifo_ship_allow_negative($conn, $pid, $qty, $unit);

            $total_cost = 0.0;
            foreach ($deductions as $d) {
                $total_cost += $d['quantity'] * $d['cost_price'];
            }
            $unit_price = round($total_cost / $qty, 4);

            $upd_item->bind_param('di', $unit_price, $line['id']);
            $upd_item->execute();

            foreach ($deductions as $d) {
                $ins_lot->bind_param('iiiid', $line['id'], $d['inventory_id'], $d['inbound_id'], $d['quantity'], $d['cost_price']);
                $ins_lot->execute();
            }

            $total_amount += $qty * $unit_price;
        }
        $upd_item->close();
        $ins_lot->close();

        $upd = $conn->prepare("UPDATE kw_orders SET total_amount = ? WHERE id = ?");
        $upd->bind_param('di', $total_amount, $draft_id);
        $upd->execute();
        $upd->close();

        $conn->commit();
        $conn->close();
        echo json_encode(['success' => true, 'order_id' => $draft_id]);
    } catch (Exception $e) {
        if (isset($conn)) { $conn->rollback(); $conn->close(); }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
