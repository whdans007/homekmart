<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('navigation.wholesale_sales') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// Check wholesale sales permission
if (!has_permission('wholesale_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

$errors = [];
$success_message = '';
$stores = [];
$edit_mode = false;
$edit_sale_id = 0;
$edit_data = null;

// 수정 모드 확인
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_sale_id = (int)$_GET['edit'];
    $edit_mode = true;
}

// Get store list (for super_admin) and load edit data
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 반품 기능 마이그레이션 적용 여부 확인 (하위 호환, Design Ref: wholesale-sales-return.design.md)
    try {
        $has_return_status = $pdo->query("SHOW COLUMNS FROM wholesale_sales LIKE 'return_status'")->rowCount() > 0;
    } catch (PDOException $e) {
        $has_return_status = false;
    }
    try {
        $has_returned_qty_column = $pdo->query("SHOW COLUMNS FROM wholesale_sale_items LIKE 'returned_quantity'")->rowCount() > 0;
    } catch (PDOException $e) {
        $has_returned_qty_column = false;
    }

    // Load existing data in edit mode
    if ($edit_mode && $edit_sale_id > 0) {
        // Join sales info with customer info
        $edit_sql = "
            SELECT
                ws.*,
                wc.name as customer_name,
                wc.phone as customer_phone,
                wc.address as customer_address,
                wc.discount_rate as customer_discount_rate
            FROM wholesale_sales ws
            LEFT JOIN wholesale_customers wc ON ws.customer_id = wc.id
            WHERE ws.id = ?
        ";
        
        // 권한 확인 (super_admin이 아닌 경우 본인 점포 데이터만)
        if ($_SESSION['role'] !== 'super_admin') {
            $edit_sql .= " AND ws.store_id = " . (int)$current_store_id;
        }
        
        $edit_stmt = $pdo->prepare($edit_sql);
        $edit_stmt->execute([$edit_sale_id]);
        $edit_data = $edit_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$edit_data) {
            $errors[] = t('wholesale.edit_not_found');
            $edit_mode = false;
        } else {
            // KIMS MALL (킴스몰) 지점 ID 고정 (현재 점포 원가가 0일 때 폴백)
            $kims_store_id = 6;
            $kims_cost_box   = "(SELECT kb.cost_price FROM wholesale_products kb WHERE kb.product_id = p.id AND kb.store_id = {$kims_store_id} AND kb.is_active = 1 LIMIT 1)";
            $kims_cost_piece = "(SELECT kp.cost_price_piece FROM wholesale_products kp WHERE kp.product_id = p.id AND kp.store_id = {$kims_store_id} AND kp.is_active = 1 LIMIT 1)";

            // Get sale items (도매상품 전용 상품명 우선 사용)
            $items_sql = "
                SELECT
                    wsi.product_id,
                    wsi.quantity,
                    wsi.unit_price,
                    wsi.total_price,
                    wsi.sale_unit,
                    wsi.remarks,
                    wsi.custom_product_name,
                    wsi.custom_cost_price,
                    p.sku as sku,
                    COALESCE(wp.wholesale_name_ko, p.name_ko, wsi.custom_product_name) as name_ko,
                    COALESCE(wp.wholesale_name_en, p.name_en, wsi.custom_product_name) as name_en,
                    wp.wholesale_price,
                    COALESCE(wp.wholesale_price_piece, 0) as wholesale_price_piece,
                    COALESCE(p.pieces_per_box, wp.min_quantity, 1) as min_quantity,
                    COALESCE(wsi.custom_cost_price, NULLIF(wp.cost_price,0), {$kims_cost_box}, 0) as cost_price,
                    COALESCE(NULLIF(wp.cost_price_piece,0), {$kims_cost_piece}, 0) as cost_price_piece,
                    COALESCE(inv.selling_price, 0) as selling_price
                FROM wholesale_sale_items wsi
                LEFT JOIN products p ON wsi.product_id = p.id
                LEFT JOIN wholesale_products wp ON wp.product_id = p.id AND wp.store_id = ?
                LEFT JOIN inventory inv ON inv.product_id = p.id AND inv.store_id = ?
                WHERE wsi.sale_id = ?
                ORDER BY wsi.sort_order ASC, wsi.id ASC
            ";

            $items_stmt = $pdo->prepare($items_sql);
            $items_stmt->execute([$edit_data['store_id'], $edit_data['store_id'], $edit_sale_id]);
            $edit_data['items'] = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($edit_data['items'] as &$__item) {
                if (empty($__item['sku'])) { $__item['sku'] = t('wholesale.manual_sku_label'); }
            }
            unset($__item);
        }
    }
    
} catch (PDOException $e) {
    $errors[] = t('wholesale.db_connection_error') . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $store_id = $current_store_id; // 점포는 접속자 점포로 고정
    $sale_date = $_POST['sale_date'] ?? date('Y-m-d');
    $cart_items = json_decode($_POST['cart_items'] ?? '[]', true);
    if (!is_array($cart_items)) { $cart_items = []; }
    $is_edit = isset($_POST['edit_sale_id']) && is_numeric($_POST['edit_sale_id']);
    $edit_sale_id_post = $is_edit ? (int)$_POST['edit_sale_id'] : 0;

    // 반품 항목 (신규 등록 시에만 사용 — 예전 전표는 절대 수정하지 않고, 이 신규 전표에서만 차감됨)
    $return_items = (!$is_edit) ? json_decode($_POST['return_items'] ?? '[]', true) : [];
    if (!is_array($return_items)) { $return_items = []; }
    $return_reason = trim($_POST['return_reason'] ?? '');

    if (empty($customer_id)) {
        $errors[] = t('wholesale.customer_required_error');
    }

    if (empty($cart_items) && empty($return_items)) {
        $errors[] = t('wholesale.products_required_error');
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            $total_amount = array_sum(array_column($cart_items, 'total_price'));
            
            if ($is_edit && $edit_sale_id_post > 0) {
                // 수정 모드 - 기존 데이터 업데이트
                
                // 권한 확인
                $check_sql = "SELECT id" . ($has_return_status ? ", return_status" : "") . " FROM wholesale_sales WHERE id = ?";
                if ($_SESSION['role'] !== 'super_admin') {
                    $check_sql .= " AND store_id = " . (int)$current_store_id;
                }
                $check_stmt = $pdo->prepare($check_sql);
                $check_stmt->execute([$edit_sale_id_post]);
                $check_row = $check_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$check_row) {
                    throw new Exception(t('wholesale.edit_permission_denied'));
                }

                // FR-09: 반품(차감) 항목이 포함된 전표 자체는 수정 불가 (수정 시 반품 반영분이 유실됨)
                if ($has_return_status && ($check_row['return_status'] ?? 'none') !== 'none') {
                    throw new Exception(t('wholesale.return_included_cannot_edit'));
                }

                // FR-09: 이 판매 건의 품목이 이후 다른 전표에서 반품 처리된 경우, 품목이 delete-and-reinsert 되므로 수정 자체를 차단
                // (반품 이력의 sale_item_id FK가 무효화되는 것을 방지, Design Ref: wholesale-sales-return.design.md §6.2)
                if ($has_returned_qty_column) {
                    $ret_check_stmt = $pdo->prepare("SELECT COUNT(*) FROM wholesale_sale_items WHERE sale_id = ? AND returned_quantity > 0");
                    $ret_check_stmt->execute([$edit_sale_id_post]);
                    if ((int)$ret_check_stmt->fetchColumn() > 0) {
                        throw new Exception(t('wholesale.items_returned_elsewhere_cannot_edit'));
                    }
                }

                // Update sale record
                $update_stmt = $pdo->prepare("
                    UPDATE wholesale_sales 
                    SET customer_id = ?, store_id = ?, sale_date = ?, total_amount = ?, final_amount = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $update_stmt->execute([$customer_id, $store_id, $sale_date, $total_amount, $total_amount, $edit_sale_id_post]);
                
                // Delete existing sale items
                $delete_stmt = $pdo->prepare("DELETE FROM wholesale_sale_items WHERE sale_id = ?");
                $delete_stmt->execute([$edit_sale_id_post]);
                
                // Add new sale items
                foreach ($cart_items as $sort_index => $item) {
                    $is_manual = empty($item['product_id']);
                    $product_id = $is_manual ? null : (int)$item['product_id'];
                    $custom_name = $is_manual ? trim($item['name_ko'] ?? $item['name_en'] ?? '') : null;
                    // 원가는 판매단위(박스/낱개)에 맞는 값을 custom_cost_price로 저장 (UI에서 수정된 원가 반영)
                    $cost_val = (($item['sale_unit'] ?? 'box') === 'piece')
                        ? ($item['cost_price_piece'] ?? $item['cost_price'] ?? 0)
                        : ($item['cost_price'] ?? 0);
                    $custom_cost = ($cost_val > 0) ? (float)$cost_val : null;

                    $item_stmt = $pdo->prepare("
                        INSERT INTO wholesale_sale_items (sale_id, product_id, custom_product_name, custom_cost_price, quantity, unit_price, total_price, sale_unit, remarks, sort_order, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $item_stmt->execute([
                        $edit_sale_id_post,
                        $product_id,
                        $custom_name,
                        $custom_cost,
                        $item['quantity'],
                        $item['unit_price'],
                        $item['total_price'],
                        $item['sale_unit'] ?? 'box',
                        $item['remarks'] ?? '',
                        (int)$sort_index
                    ]);
                }
                
                $sale_id = $edit_sale_id_post;
                $success_msg = t('wholesale.sale_updated_successfully');
                
            } else {
                // Register new sale (반품 항목이 있으면 이 신규 전표에 반품으로 등록되고, 이 전표의 total_amount에서 차감됨.
                // 예전 판매 전표는 어떤 경우에도 수정하지 않음 — Design Ref: wholesale-sales-return.design.md Revision Note)

                // ── 반품 항목 검증 (원본 품목 잠금 + 잔여수량/거래처/점포 스코프 재검증) ──
                $return_targets = [];
                $return_total = 0.0;

                if (!empty($return_items) && $has_return_status && $has_returned_qty_column) {
                    foreach ($return_items as $req) {
                        $ret_sale_item_id = (int)($req['sale_item_id'] ?? 0);
                        $req_qty = round((float)($req['quantity'] ?? 0), 2);
                        if ($ret_sale_item_id <= 0 || $req_qty <= 0) {
                            continue;
                        }

                        $ri_stmt = $pdo->prepare("
                            SELECT wsi.id, wsi.product_id, wsi.quantity, wsi.returned_quantity, wsi.unit_price, wsi.sale_unit,
                                   ws.customer_id, ws.store_id,
                                   p.pieces_per_box
                            FROM wholesale_sale_items wsi
                            JOIN wholesale_sales ws ON wsi.sale_id = ws.id
                            LEFT JOIN products p ON wsi.product_id = p.id
                            WHERE wsi.id = ?
                            FOR UPDATE
                        ");
                        $ri_stmt->execute([$ret_sale_item_id]);
                        $ri = $ri_stmt->fetch(PDO::FETCH_ASSOC);

                        if (!$ri) {
                            throw new Exception(t('wholesale.return_item_not_found'));
                        }
                        if ((int)$ri['customer_id'] !== $customer_id) {
                            throw new Exception(t('wholesale.return_item_wrong_customer'));
                        }
                        if ($_SESSION['role'] !== 'super_admin' && (int)$ri['store_id'] !== (int)$current_store_id) {
                            throw new Exception(t('wholesale.return_permission_denied'));
                        }
                        $remaining = round((float)$ri['quantity'] - (float)$ri['returned_quantity'], 2);
                        if ($req_qty > $remaining) {
                            throw new Exception(t('wholesale.return_quantity_exceeds'));
                        }

                        $amount = round($req_qty * (float)$ri['unit_price'], 2);
                        $return_total += $amount;
                        $return_targets[] = [
                            'sale_item_id' => $ret_sale_item_id,
                            'quantity' => $req_qty,
                            'unit_price' => (float)$ri['unit_price'],
                            'amount' => $amount,
                            'product_id' => $ri['product_id'],
                            'sale_unit' => $ri['sale_unit'],
                            'pieces_per_box' => $ri['pieces_per_box'],
                            'original_store_id' => $ri['store_id'],
                        ];
                    }
                }
                $return_total = round($return_total, 2);

                $cart_total = array_sum(array_column($cart_items, 'total_price'));
                $total_amount = round($cart_total - $return_total, 2);
                $return_status_value = 'none';
                if ($return_total > 0) {
                    // 'partial' = 신규구매+반품 혼합 전표, 'full' = 반품 전용(신규구매 없음) 전표
                    $return_status_value = empty($cart_items) ? 'full' : 'partial';
                }

                $sale_cols = "customer_id, store_id, user_id, sale_date, total_amount, final_amount";
                $sale_placeholders = "?, ?, ?, ?, ?, ?";
                $sale_params = [$customer_id, $store_id, $_SESSION['user_id'], $sale_date, $total_amount, $total_amount];
                if ($has_return_status) {
                    $sale_cols .= ", returned_amount, return_status";
                    $sale_placeholders .= ", ?, ?";
                    $sale_params[] = $return_total;
                    $sale_params[] = $return_status_value;
                }

                $sale_stmt = $pdo->prepare("
                    INSERT INTO wholesale_sales ({$sale_cols}, status, created_at)
                    VALUES ({$sale_placeholders}, 'confirmed', NOW())
                ");
                $sale_stmt->execute($sale_params);
                $sale_id = $pdo->lastInsertId();

                // Add sale items
                foreach ($cart_items as $sort_index => $item) {
                    $is_manual = empty($item['product_id']);
                    $product_id = $is_manual ? null : (int)$item['product_id'];
                    $custom_name = $is_manual ? trim($item['name_ko'] ?? $item['name_en'] ?? '') : null;
                    // 원가는 판매단위(박스/낱개)에 맞는 값을 custom_cost_price로 저장 (UI에서 수정된 원가 반영)
                    $cost_val = (($item['sale_unit'] ?? 'box') === 'piece')
                        ? ($item['cost_price_piece'] ?? $item['cost_price'] ?? 0)
                        : ($item['cost_price'] ?? 0);
                    $custom_cost = ($cost_val > 0) ? (float)$cost_val : null;

                    $item_stmt = $pdo->prepare("
                        INSERT INTO wholesale_sale_items (sale_id, product_id, custom_product_name, custom_cost_price, quantity, unit_price, total_price, sale_unit, remarks, sort_order, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $item_stmt->execute([
                        $sale_id,
                        $product_id,
                        $custom_name,
                        $custom_cost,
                        $item['quantity'],
                        $item['unit_price'],
                        $item['total_price'],
                        $item['sale_unit'] ?? 'box',
                        $item['remarks'] ?? '',
                        (int)$sort_index
                    ]);
                }

                // ── 반품 항목 등록: 이 신규 전표(sale_id)에 반품 기록을 남기고, 원본 전표는 건드리지 않음 ──
                if (!empty($return_targets)) {
                    $return_header_stmt = $pdo->prepare("
                        INSERT INTO wholesale_sale_returns (sale_id, reason, total_amount, processed_by, created_at)
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $return_header_stmt->execute([$sale_id, $return_reason !== '' ? $return_reason : null, $return_total, $_SESSION['user_id']]);
                    $return_id = $pdo->lastInsertId();

                    $return_item_stmt = $pdo->prepare("
                        INSERT INTO wholesale_sale_return_items (return_id, sale_item_id, quantity, unit_price, amount, restocked, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ");
                    // 원본 품목의 반품 누적 수량만 갱신 (원본 전표의 total_amount/final_amount 등은 변경하지 않음)
                    $update_returned_qty_stmt = $pdo->prepare("
                        UPDATE wholesale_sale_items SET returned_quantity = returned_quantity + ? WHERE id = ?
                    ");
                    $upsert_inventory_stmt = $pdo->prepare("
                        INSERT INTO inventory (product_id, store_id, quantity)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
                    ");
                    $log_transaction_stmt = $pdo->prepare("
                        INSERT INTO inventory_transactions (inventory_id, user_id, transaction_type, quantity_change, remarks, transaction_date)
                        SELECT id, ?, '반품', ?, ?, NOW() FROM inventory WHERE product_id = ? AND store_id = ?
                    ");

                    foreach ($return_targets as $rt) {
                        $is_registered_product = !empty($rt['product_id']);
                        $return_item_stmt->execute([
                            $return_id, $rt['sale_item_id'], $rt['quantity'], $rt['unit_price'], $rt['amount'], $is_registered_product ? 1 : 0
                        ]);
                        $update_returned_qty_stmt->execute([$rt['quantity'], $rt['sale_item_id']]);

                        if ($is_registered_product) {
                            $pieces_per_box = (int)($rt['pieces_per_box'] ?? 1);
                            if ($pieces_per_box <= 0) { $pieces_per_box = 1; }
                            $restock_qty = ($rt['sale_unit'] === 'box') ? round($rt['quantity'] * $pieces_per_box) : round($rt['quantity']);

                            $upsert_inventory_stmt->execute([$rt['product_id'], $rt['original_store_id'], $restock_qty]);
                            $log_transaction_stmt->execute([
                                $_SESSION['user_id'], $restock_qty,
                                "신규 판매등록시 반품 처리 (Return ID: {$return_id}, Sale ID: {$sale_id})",
                                $rt['product_id'], $rt['original_store_id']
                            ]);
                        }
                    }
                }

                $success_msg = t('wholesale.sale_registered_successfully');
            }

            // ── 거래처 도매 예외가 자동 학습 (체크박스 선택 시) ──
            // 기본가와 다른 단가만 해당 거래처 예외가로 upsert. 등록 도매상품만 대상(수기 항목 제외).
            if (!empty($_POST['save_cust_prices']) && $customer_id > 0) {
                try {
                    $has_cp = (bool)$pdo->query("SHOW TABLES LIKE 'wholesale_customer_prices'")->fetchColumn();
                    if ($has_cp) {
                        $find_wp  = $pdo->prepare("SELECT id, wholesale_price, COALESCE(wholesale_price_piece,0) AS wholesale_price_piece FROM wholesale_products WHERE product_id = ? AND store_id = ? AND is_active = 1 LIMIT 1");
                        $up_box   = $pdo->prepare("INSERT INTO wholesale_customer_prices (customer_id, wholesale_product_id, wholesale_price) VALUES (?,?,?) ON DUPLICATE KEY UPDATE wholesale_price = VALUES(wholesale_price), updated_at = NOW()");
                        $up_piece = $pdo->prepare("INSERT INTO wholesale_customer_prices (customer_id, wholesale_product_id, wholesale_price_piece) VALUES (?,?,?) ON DUPLICATE KEY UPDATE wholesale_price_piece = VALUES(wholesale_price_piece), updated_at = NOW()");
                        foreach ($cart_items as $item) {
                            if (empty($item['product_id'])) continue;            // 수기 항목 제외
                            $unit = (float)($item['unit_price'] ?? 0);
                            if ($unit <= 0) continue;
                            $find_wp->execute([(int)$item['product_id'], $store_id]);
                            $wprow = $find_wp->fetch(PDO::FETCH_ASSOC);
                            if (!$wprow) continue;                               // 등록 도매상품만
                            $is_piece = (($item['sale_unit'] ?? 'box') === 'piece');
                            $base = $is_piece ? (float)$wprow['wholesale_price_piece'] : (float)$wprow['wholesale_price'];
                            if (abs($unit - $base) < 0.005) continue;            // 기본가와 동일하면 예외 저장 안 함
                            if ($is_piece) { $up_piece->execute([$customer_id, (int)$wprow['id'], $unit]); }
                            else           { $up_box->execute([$customer_id, (int)$wprow['id'], $unit]); }
                        }
                    }
                } catch (PDOException $e) {
                    // 가격 학습 실패는 판매 저장을 막지 않음
                    error_log("Customer price learn failed: " . $e->getMessage());
                }
            }

            $pdo->commit();
            
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => $success_msg
            ];
            
            // 미리보기 페이지로 리다이렉트
            header("Location: wholesale_sale_preview.php?id=$sale_id");
            exit;
            
        } catch (Exception $e) {
            $pdo->rollback();
            $errors[] = t('wholesale.processing_error_prefix') . $e->getMessage();
        }
    }
}

// 플래시 메시지 표시
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

?>

<div class="w-full px-3 py-3">

    <?php if (isset($flash)): ?>
        <div class="mb-3 px-4 py-2 rounded-md flex items-center gap-2 text-sm
            <?php echo $flash['type'] === 'error' ? 'bg-red-50 border border-red-200 text-red-700' : 'bg-green-50 border border-green-200 text-green-700'; ?>">
            <i class="fas <?php echo $flash['type'] === 'error' ? 'fa-exclamation-triangle' : 'fa-check-circle'; ?>"></i>
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="mb-3 px-4 py-2 bg-red-50 border border-red-200 rounded-md text-sm text-red-700">
            <i class="fas fa-exclamation-triangle mr-1"></i>
            <?php echo implode(' / ', array_map('htmlspecialchars', $errors)); ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="wholesale-sales-form">

        <!-- 타이틀 + 액션 버튼 -->
        <div class="flex items-center justify-between mb-3">
            <h1 class="text-base font-semibold text-gray-800">
                <i class="fas fa-handshake mr-1 text-primary-500"></i>
                <?php echo $edit_mode ? t('wholesale.sales_edit') : t('navigation.wholesale_sales'); ?>
            </h1>
            <div class="flex gap-2">
                <?php if (!$edit_mode): ?>
                <button type="button" id="return_register_btn"
                        class="px-4 py-2 text-sm font-medium bg-orange-500 text-white rounded-md hover:bg-orange-600 disabled:bg-gray-300 disabled:cursor-not-allowed whitespace-nowrap"
                        disabled>
                    <i class="fas fa-undo mr-1"></i><?php echo t('wholesale.return_register_btn'); ?>
                </button>
                <?php endif; ?>
                <button type="submit" id="complete_sale_btn"
                        class="px-4 py-2 text-sm font-medium bg-green-500 text-white rounded-md hover:bg-green-600 disabled:bg-gray-300 disabled:cursor-not-allowed whitespace-nowrap"
                        disabled>
                    <i class="fas fa-save mr-1"></i>
                    <?php echo $edit_mode ? t('common.complete_edit') : t('common.save'); ?>
                </button>
                <a href="wholesale_sales_list.php"
                   class="px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-md hover:bg-gray-50 whitespace-nowrap">
                    <i class="fas fa-times mr-1"></i><?php echo t('common.cancel'); ?>
                </a>
            </div>
        </div>

        <!-- Row 1: 거래처 / 점포 / 판매일자 / 마진율 -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-2">

            <!-- 거래처 -->
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">
                    <?php echo t('wholesale.customer_label'); ?> <span class="text-red-500">*</span>
                </label>
                <div class="flex gap-1">
                    <div class="relative flex-1">
                        <input type="text" id="customer_search"
                               class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                               placeholder="<?php echo t('wholesale.customer_search_placeholder'); ?>"
                               autocomplete="off">
                        <div id="customer_search_results"
                             class="absolute z-10 w-full bg-white border border-gray-300 rounded-md shadow-lg mt-1 max-h-60 overflow-y-auto hidden"></div>
                    </div>
                    <button type="button" id="customer_search_btn"
                            class="px-2 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600">
                        <i class="fas fa-search text-sm"></i>
                    </button>
                </div>
                <input type="hidden" name="customer_id" id="customer_id" value="">
            </div>

            <!-- 점포 (접속자 점포로 고정) -->
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1"><?php echo t('common.store'); ?></label>
                <div class="px-3 py-2 text-sm bg-gray-50 border border-gray-200 rounded-md text-gray-700 font-medium">
                    <?php echo htmlspecialchars($current_store_name); ?>
                </div>
                <input type="hidden" name="store_id" id="store_id" value="<?php echo $current_store_id; ?>">
            </div>

            <!-- 판매일자 -->
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1"><?php echo t('wholesale.sale_date_label'); ?></label>
                <input type="date" name="sale_date" id="sale_date" value="<?php echo date('Y-m-d'); ?>"
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500">
            </div>

            <!-- 마진율 -->
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1"><?php echo t('wholesale.margin_rate_label'); ?></label>
                <div class="flex items-center gap-1">
                    <input type="number" id="margin_rate"
                           class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                           value="15" min="0" max="100" step="0.1">
                    <span class="text-sm text-gray-500 whitespace-nowrap">%</span>
                </div>
            </div>
        </div>

        <!-- 선택된 거래처 표시 (인라인, 컴팩트) -->
        <div id="selected_customer" class="hidden mb-2 px-3 py-2 bg-blue-50 border border-blue-200 rounded-md flex items-center justify-between">
            <div class="flex items-center gap-3 min-w-0">
                <i class="fas fa-building text-blue-400 flex-shrink-0"></i>
                <span class="text-sm font-medium text-blue-900 truncate" id="selected_customer_name"></span>
                <span class="text-xs text-blue-600 truncate" id="selected_customer_info"></span>
            </div>
            <div class="flex items-center gap-2 ml-2 flex-shrink-0">
                <button type="button" id="existing_products_btn"
                        class="px-2 py-1 text-xs font-medium text-blue-700 bg-white border border-blue-300 rounded-md hover:bg-blue-100 whitespace-nowrap">
                    <i class="fas fa-history mr-1"></i><?php echo t('wholesale.existing_products_btn'); ?>
                </button>
                <button type="button" id="clear_customer_selection" class="text-blue-400 hover:text-blue-600">
                    <i class="fas fa-times text-xs"></i>
                </button>
            </div>
        </div>

        <!-- 거래처 미선택 안내 -->
        <?php $customer_preselected = ($edit_mode && !empty($edit_data['customer_id'])); ?>
        <p id="product_search_hint"
           class="text-xs text-amber-600 mb-1 <?php echo $customer_preselected ? 'hidden' : ''; ?>">
            <i class="fas fa-info-circle mr-1"></i><?php echo t('wholesale.select_customer_first_hint'); ?>
        </p>

        <!-- Row 2: 상품 검색 + 수기 입력 버튼 -->
        <div class="flex gap-2 mb-2">
            <div class="relative flex-1">
                <input type="text" id="product_search"
                       class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500 disabled:bg-gray-100 disabled:cursor-not-allowed"
                       placeholder="<?php echo t('wholesale.product_search_placeholder'); ?>"
                       autocomplete="off"
                       <?php echo $customer_preselected ? '' : 'disabled'; ?>>
                <div id="product_search_results"
                     class="absolute z-10 w-full bg-white border border-gray-300 rounded-md shadow-lg mt-1 max-h-96 overflow-y-auto hidden"></div>
            </div>
            <button type="button" id="product_search_btn"
                    class="px-3 py-2 text-sm bg-blue-500 text-white rounded-md hover:bg-blue-600 whitespace-nowrap disabled:bg-gray-300 disabled:cursor-not-allowed"
                    <?php echo $customer_preselected ? '' : 'disabled'; ?>>
                <i class="fas fa-search mr-1"></i><?php echo t('common.search'); ?>
            </button>
            <button type="button" id="toggle_manual_entry"
                    class="px-3 py-2 text-sm font-medium text-indigo-700 bg-indigo-50 border border-indigo-200 rounded-md hover:bg-indigo-100 whitespace-nowrap disabled:opacity-50 disabled:cursor-not-allowed"
                    <?php echo $customer_preselected ? '' : 'disabled'; ?>>
                <i class="fas fa-pencil-alt mr-1"></i><?php echo t('wholesale.manual_entry_btn'); ?>
            </button>
        </div>

        <!-- 수기 입력 폼 (접이식) -->
        <div id="manual_entry_form" class="hidden mb-3 p-3 bg-gray-50 border border-indigo-200 rounded-md">
            <div class="flex flex-wrap items-end gap-2">
                <div class="flex-1 min-w-[140px]">
                    <label class="block text-xs font-medium text-gray-600 mb-1"><?php echo t('wholesale.product_name'); ?> <span class="text-red-500">*</span></label>
                    <input type="text" id="manual_product_name"
                           class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                           placeholder="<?php echo t('wholesale.product_name_placeholder'); ?>">
                </div>
                <div class="w-28">
                    <label class="block text-xs font-medium text-gray-600 mb-1"><?php echo t('wholesale.cost_price'); ?></label>
                    <input type="number" id="manual_cost_price"
                           class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                           placeholder="0" min="0" step="0.01">
                </div>
                <div class="w-28">
                    <label class="block text-xs font-medium text-gray-600 mb-1"><?php echo t('common.quantity'); ?>(Weight)</label>
                    <input type="number" id="manual_quantity"
                           class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                           value="1" min="0.01" step="0.01">
                </div>
                <div class="w-28">
                    <label class="block text-xs font-medium text-gray-600 mb-1"><?php echo t('wholesale.wholesale_sale_price'); ?> <span class="text-red-500">*</span></label>
                    <input type="number" id="manual_wholesale_price"
                           class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                           placeholder="0" min="0" step="0.01">
                </div>
                <button type="button" id="add_manual_item_btn"
                        class="px-4 py-2 text-sm font-medium bg-indigo-600 text-white rounded-md hover:bg-indigo-700 whitespace-nowrap">
                    <i class="fas fa-plus mr-1"></i><?php echo t('wholesale.add_to_cart'); ?>
                </button>
                <button type="button" id="cancel_manual_entry"
                        class="px-4 py-2 text-sm text-gray-600 bg-white border border-gray-300 rounded-md hover:bg-gray-50 whitespace-nowrap">
                    <?php echo t('common.cancel'); ?>
                </button>
            </div>
        </div>

        <!-- Row 3: 장바구니 -->
        <div id="cart_empty" class="text-center text-gray-400 py-16">
            <i class="fas fa-shopping-cart text-3xl mb-2"></i>
            <p class="text-sm"><?php echo t('wholesale.cart_empty'); ?></p>
        </div>

        <div id="cart_items" class="hidden">
            <div class="cart-table-wrapper overflow-x-auto border rounded-md">
                <table class="w-full cart-table text-sm">
                    <thead class="bg-gray-50 border-b">
                        <tr>
                            <th class="px-2 py-2 text-left text-xs font-semibold text-gray-600"><?php echo t('product.sku'); ?></th>
                            <th class="px-2 py-2 text-left text-xs font-semibold text-gray-600"><?php echo t('product.name'); ?></th>
                            <th class="px-2 py-2 text-center text-xs font-semibold text-gray-600"><?php echo t('wholesale.box_packaging'); ?></th>
                            <th class="px-2 py-2 text-center text-xs font-semibold text-gray-600"><?php echo t('wholesale.cost_price'); ?></th>
                            <th class="px-2 py-2 text-center text-xs font-semibold text-gray-600"><?php echo t('wholesale.selling_price'); ?></th>
                            <th class="px-2 py-2 text-center text-xs font-semibold text-gray-600"><?php echo t('add_wholesale_product.sale_unit_label'); ?></th>
                            <th class="px-2 py-2 text-center text-xs font-semibold text-gray-600"><?php echo t('wholesale.wholesale_sale_price'); ?></th>
                            <th class="px-2 py-2 text-center text-xs font-semibold text-gray-600"><?php echo t('wholesale.quantity'); ?></th>
                            <th class="px-2 py-2 text-right text-xs font-semibold text-gray-600"><?php echo t('common.total'); ?></th>
                            <th class="px-2 py-2 text-center text-xs font-semibold text-gray-600"><?php echo t('common.delete'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="cart_list"></tbody>
                </table>
            </div>
            <div class="mt-3 flex justify-between items-center gap-3 flex-wrap">
                <span class="text-sm text-gray-500"><span id="cart_count">0</span><?php echo t('common.items'); ?></span>
                <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer select-none">
                    <input type="checkbox" name="save_cust_prices" id="save_cust_prices" value="1" checked class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                    <span><i class="fas fa-tags text-primary-500 mr-1"></i><?php echo t('wholesale.save_cust_prices_label'); ?> <span class="text-gray-400"><?php echo t('wholesale.save_cust_prices_hint'); ?></span></span>
                </label>
                <div class="text-right">
                    <div class="text-base font-semibold text-gray-900">
                        <?php echo t('wholesale.cart_total_label'); ?>: <span id="cart_total" class="text-primary-600">0</span>
                    </div>
                    <div id="return_total_row" class="text-sm font-medium text-orange-600 hidden"><?php echo t('wholesale.return_deduction_label'); ?>: -<span id="return_items_total">0</span></div>
                    <div id="grand_total_row" class="text-base font-bold text-gray-900 hidden"><?php echo t('wholesale.grand_total_label'); ?>: <span id="grand_total_amount" class="text-primary-700">0</span></div>
                </div>
            </div>
        </div>

        <!-- 반품 항목 (신규 판매등록과 함께 등록하는 반품) -->
        <div id="return_items_section" class="hidden mt-3 border border-orange-200 rounded-md bg-orange-50 p-3">
            <h3 class="text-sm font-semibold text-orange-700 mb-2"><i class="fas fa-undo mr-1"></i><?php echo t('wholesale.return_items_heading'); ?></h3>
            <table class="w-full text-sm mb-2">
                <thead>
                    <tr>
                        <th class="px-2 py-1 text-left text-xs font-medium text-gray-500"><?php echo t('wholesale.return_col_sale_date'); ?></th>
                        <th class="px-2 py-1 text-left text-xs font-medium text-gray-500"><?php echo t('wholesale.product_name'); ?></th>
                        <th class="px-2 py-1 text-center text-xs font-medium text-gray-500"><?php echo t('wholesale.return_col_quantity'); ?></th>
                        <th class="px-2 py-1 text-right text-xs font-medium text-gray-500"><?php echo t('wholesale.return_col_amount'); ?></th>
                        <th class="px-2 py-1 text-center text-xs font-medium text-gray-500"></th>
                    </tr>
                </thead>
                <tbody id="return_items_body"></tbody>
            </table>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1"><?php echo t('wholesale.return_reason_label'); ?></label>
                <input type="text" name="return_reason" id="return_reason_input"
                       class="w-full px-3 py-1.5 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                       placeholder="<?php echo t('wholesale.return_reason_placeholder'); ?>">
            </div>
        </div>

        <input type="hidden" name="cart_items" id="cart_items_input" value="">
        <input type="hidden" name="return_items" id="return_items_input" value="">
        <?php if ($edit_mode): ?>
            <input type="hidden" name="edit_sale_id" value="<?php echo $edit_sale_id; ?>">
        <?php endif; ?>
    </form>
</div>

<!-- Customer list modal -->
<div id="customer-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[70vh] flex flex-col">
            <div class="flex items-center justify-between p-4 border-b border-gray-200">
                <h3 class="text-base font-medium text-gray-900">
                    <i class="fas fa-users mr-2 text-blue-500"></i>
                    <?php echo t('wholesale.customer_list'); ?>
                </h3>
                <button type="button" id="close-customer-modal" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>
            <div class="flex-1 overflow-hidden">
                <div class="p-3 border-b border-gray-200">
                    <input type="text" id="modal-customer-search" 
                           class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                           placeholder="<?php echo t('wholesale.customer_filter_placeholder'); ?>">
                </div>
                <div id="customer-list" class="flex-1 overflow-y-auto p-3 space-y-2 max-h-80">
                    <!-- 거래처 목록이 여기에 표시됩니다 -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 신규 거래처 등록 모달 -->
<div id="new-customer-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-[60]">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-lg w-full">
            <div class="flex items-center justify-between p-4 border-b border-gray-200">
                <h3 class="text-base font-medium text-gray-900">
                    <i class="fas fa-plus-circle mr-2 text-green-500"></i>
                    <?php echo t('wholesale.new_customer_title'); ?>
                </h3>
                <button type="button" id="close-new-customer-modal" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>
            <div class="p-4">
                <div id="new-customer-form-errors" class="hidden mb-4 p-3 bg-red-50 border border-red-200 rounded-md">
                    <p class="text-sm text-red-700" id="new-customer-error-text"></p>
                </div>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <?php echo t('wholesale.business_name'); ?> <span class="text-red-500">*</span>
                        </label>
                        <div class="flex gap-2">
                            <input type="text" id="new_customer_name"
                                   class="flex-1 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                   placeholder="<?php echo t('wholesale.business_name_placeholder'); ?>">
                            <button type="button" id="transliterate_btn"
                                    class="px-3 py-2 bg-indigo-500 text-white text-sm rounded-md hover:bg-indigo-600 whitespace-nowrap">
                                <i class="fas fa-language mr-1"></i>
                                <?php echo t('wholesale.transliterate_btn'); ?>
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-gray-500"><?php echo t('wholesale.transliterate_hint'); ?></p>
                    </div>
                    <div id="transliterated_name_section" class="hidden">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <?php echo t('wholesale.english_pronunciation'); ?>
                        </label>
                        <input type="text" id="new_customer_name_en"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                               placeholder="<?php echo t('wholesale.english_pronunciation_placeholder'); ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo t('wholesale.phone_label'); ?></label>
                        <input type="tel" id="new_customer_phone"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                               placeholder="010-0000-0000">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo t('wholesale.address'); ?></label>
                        <textarea id="new_customer_address" rows="2"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                  placeholder="<?php echo t('wholesale.address_placeholder'); ?>"></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo t('wholesale.customer_memo'); ?></label>
                        <textarea id="new_customer_memo" rows="2"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                  placeholder="<?php echo t('wholesale.memo_placeholder'); ?>"></textarea>
                    </div>
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" id="cancel-new-customer"
                            class="px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                        <?php echo t('common.cancel'); ?>
                    </button>
                    <button type="button" id="save-new-customer"
                            class="px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-md hover:bg-green-700">
                        <i class="fas fa-save mr-1"></i>
                        <?php echo t('common.save'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 상품 목록 모달 -->
<div id="product-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-h-[70vh] flex flex-col">
            <div class="flex items-center justify-between p-4 border-b border-gray-200">
                <h3 class="text-base font-medium text-gray-900">
                    <i class="fas fa-box mr-2 text-green-500"></i>
                    <?php echo t('wholesale.product_list_title'); ?>
                </h3>
                <button type="button" id="close-product-modal" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>
            <div class="flex-1 overflow-hidden">
                <div class="p-3 border-b border-gray-200">
                    <input type="text" id="modal-product-search" 
                           class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                           placeholder="<?php echo t('wholesale.product_filter_placeholder'); ?>">
                </div>
                <div id="product-list" class="flex-1 overflow-y-auto p-3 space-y-2 max-h-80">
                    <!-- 상품 목록이 여기에 표시됩니다 -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 상품 타입 선택 모달 -->
<div id="product-type-selection-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl">
            <div class="flex items-center justify-between p-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">
                    <i class="fas fa-box-open mr-2 text-blue-500"></i>
                    <?php echo t('wholesale.select_product'); ?>
                </h3>
                <button type="button" id="close-type-selection-modal" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div class="p-6">
                <p class="text-sm text-gray-600 mb-4">
                    <?php echo t('wholesale.product_type_question'); ?>
                </p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- 도매상품 옵션 -->
                    <div id="wholesale-option" class="border-2 border-blue-500 rounded-lg p-4 cursor-pointer hover:bg-blue-50 transition">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="font-medium text-gray-900"><?php echo t('wholesale.registered_product_title'); ?></h4>
                            <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded"><?php echo t('wholesale.recommended_badge'); ?></span>
                        </div>
                        <div class="text-sm text-gray-600 space-y-1">
                            <div><?php echo t('wholesale.product_name'); ?>: <span id="modal-wholesale-name" class="font-medium"></span></div>
                            <div><?php echo t('wholesale.wholesale_price'); ?>: <span id="modal-wholesale-price" class="font-medium text-blue-600"></span><?php echo t('common.currency'); ?></div>
                            <div class="text-xs text-gray-500 mt-2"><?php echo t('wholesale.sell_at_registered_price'); ?></div>
                        </div>
                    </div>

                    <!-- 인벤토리 상품 옵션 -->
                    <div id="inventory-option" class="border-2 border-gray-300 rounded-lg p-4 cursor-pointer hover:bg-gray-50 transition">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="font-medium text-gray-900"><?php echo t('wholesale.inventory_product_title'); ?></h4>
                            <span class="px-2 py-1 bg-gray-100 text-gray-600 text-xs rounded"><?php echo t('wholesale.general_badge'); ?></span>
                        </div>
                        <div class="text-sm text-gray-600 space-y-1">
                            <div><?php echo t('wholesale.product_name'); ?>: <span id="modal-inventory-name" class="font-medium"></span></div>
                            <div><?php echo t('wholesale.cost_price'); ?>: <span id="modal-inventory-cost" class="font-medium"></span><?php echo t('common.currency'); ?></div>
                            <div><?php echo t('wholesale.selling_price'); ?>: <span id="modal-inventory-price" class="font-medium text-green-600"></span><?php echo t('common.currency'); ?></div>
                            <div class="text-xs text-gray-500 mt-2"><?php echo t('wholesale.sell_at_margin'); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 기존 납품상품 리스트 모달 (거래처에 이전에 납품했던 상품을 조회하여 바로 장바구니에 담기) -->
<div id="existing-products-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-3xl w-full max-h-[85vh] flex flex-col">
            <div class="flex items-center justify-between p-4 border-b border-gray-200">
                <h3 class="text-base font-medium text-gray-900">
                    <i class="fas fa-history mr-2 text-blue-500"></i>
                    <?php echo t('wholesale.existing_products_title'); ?>
                    <span class="text-sm font-normal text-gray-500 ml-2" id="existing-products-customer-name"></span>
                </h3>
                <button type="button" id="close-existing-products-modal" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-lg"></i></button>
            </div>
            <div class="flex-1 overflow-y-auto p-4">
                <p class="text-xs text-gray-500 mb-2"><?php echo t('wholesale.existing_products_desc'); ?></p>
                <div class="mb-2">
                    <input type="text" id="existing-products-search"
                           class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                           placeholder="<?php echo t('wholesale.existing_products_search_placeholder'); ?>"
                           autocomplete="off">
                </div>
                <div id="existing-products-loading" class="text-center text-sm text-gray-400 py-6"><?php echo t('wholesale.return_history_loading'); ?></div>
                <div id="existing-products-empty" class="hidden text-center text-sm text-gray-400 py-6"><?php echo t('wholesale.existing_products_empty'); ?></div>
                <div id="existing-products-no-match" class="hidden text-center text-sm text-gray-400 py-6"><?php echo t('wholesale.existing_products_no_match'); ?></div>
                <table id="existing-products-table" class="hidden w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500"><?php echo t('wholesale.return_col_sale_date'); ?></th>
                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500"><?php echo t('wholesale.return_col_sku'); ?></th>
                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500"><?php echo t('wholesale.product_name'); ?></th>
                            <th class="px-2 py-2 text-center text-xs font-medium text-gray-500"><?php echo t('wholesale.js_wholesale_box_label'); ?></th>
                            <th class="px-2 py-2 text-center text-xs font-medium text-gray-500"><?php echo t('wholesale.js_wholesale_piece_label'); ?></th>
                            <th class="px-2 py-2 text-center text-xs font-medium text-gray-500"></th>
                        </tr>
                    </thead>
                    <tbody id="existing-products-body"></tbody>
                </table>
                <div id="existing-products-pagination" class="hidden mt-3 flex items-center justify-between">
                    <span class="text-xs text-gray-500" id="existing-products-page-info"></span>
                    <div class="flex items-center gap-2">
                        <button type="button" id="existing-products-prev-btn" class="px-3 py-1 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed"><?php echo t('common.previous'); ?></button>
                        <button type="button" id="existing-products-next-btn" class="px-3 py-1 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed"><?php echo t('common.next'); ?></button>
                    </div>
                </div>
            </div>
            <div class="p-4 border-t border-gray-200 flex items-center justify-end">
                <button type="button" id="close-existing-products-modal-btn2" class="px-4 py-1.5 text-sm font-medium text-white bg-gray-600 rounded-md hover:bg-gray-700"><?php echo t('common.close'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- 반품등록 모달 (거래처의 최근 판매 이력에서 반품할 품목을 선택 — 실제 반품 등록은 판매등록 저장 시 함께 처리됨) -->
<div id="return-register-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[85vh] flex flex-col">
            <div class="flex items-center justify-between p-4 border-b border-gray-200">
                <h3 class="text-base font-medium text-gray-900"><i class="fas fa-undo mr-2 text-orange-500"></i><?php echo t('wholesale.return_select_items_title'); ?><span class="text-sm font-normal text-gray-500 ml-2" id="return-register-customer-name"></span></h3>
                <button type="button" id="close-return-register-modal" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-lg"></i></button>
            </div>
            <div class="flex-1 overflow-y-auto p-4">
                <p class="text-xs text-gray-500 mb-2"><?php echo t('wholesale.return_select_items_desc'); ?></p>
                <div id="return-history-loading" class="text-center text-sm text-gray-400 py-6"><?php echo t('wholesale.return_history_loading'); ?></div>
                <div id="return-history-empty" class="hidden text-center text-sm text-gray-400 py-6"><?php echo t('wholesale.return_history_empty'); ?></div>
                <table id="return-history-table" class="hidden w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500"><?php echo t('wholesale.return_col_sale_date'); ?></th>
                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500"><?php echo t('wholesale.return_col_sku'); ?></th>
                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500"><?php echo t('wholesale.product_name'); ?></th>
                            <th class="px-2 py-2 text-center text-xs font-medium text-gray-500"><?php echo t('wholesale.return_col_returnable'); ?></th>
                            <th class="px-2 py-2 text-center text-xs font-medium text-gray-500"><?php echo t('wholesale.return_col_unit_price'); ?></th>
                            <th class="px-2 py-2 text-center text-xs font-medium text-gray-500"></th>
                        </tr>
                    </thead>
                    <tbody id="return-history-body"></tbody>
                </table>
            </div>
            <div class="p-4 border-t border-gray-200 flex items-center justify-end">
                <button type="button" id="close-return-register-modal-btn2" class="px-4 py-1.5 text-sm font-medium text-white bg-gray-600 rounded-md hover:bg-gray-700"><?php echo t('common.close'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
// PHP 데이터를 JavaScript로 전달
const editMode = <?php echo json_encode($edit_mode); ?>;
const editData = <?php echo json_encode($edit_data); ?>;

// JavaScript translations object
const translations = {
    loading: '<?php echo addslashes(t("wholesale.js_loading")); ?>',
    no_customers: '<?php echo addslashes(t("wholesale.js_no_customers")); ?>',
    customer_load_error: '<?php echo addslashes(t("wholesale.js_customer_load_error")); ?>',
    no_products: '<?php echo addslashes(t("wholesale.js_no_products")); ?>',
    product_load_error: '<?php echo addslashes(t("wholesale.js_product_load_error")); ?>',
    no_phone: '<?php echo addslashes(t("wholesale.js_no_phone")); ?>',
    wholesale_badge: '<?php echo addslashes(t("wholesale.js_wholesale_badge")); ?>',
    wholesale_suffix: '<?php echo addslashes(t("wholesale.js_wholesale_suffix")); ?>',
    minimum_prefix: '<?php echo addslashes(t("wholesale.js_minimum_prefix")); ?>',
    delivery_placeholder: '<?php echo addslashes(t("wholesale.js_delivery_placeholder")); ?>',
    error_customers: '<?php echo addslashes(t("wholesale.js_error_customers")); ?>',
    error_products: '<?php echo addslashes(t("wholesale.js_error_products")); ?>',
    box_unit: '<?php echo addslashes(t("purchase.box_unit")); ?>',
    piece_unit: '<?php echo addslashes(t("purchase.piece_unit")); ?>',
    pieces: '<?php echo addslashes(t("wholesale.pieces")); ?>',
    currency: '<?php echo addslashes(t("common.currency")); ?>',
    remove_confirm: '<?php echo addslashes(t("wholesale.js_remove_confirm")); ?>',
    invalid_price: '<?php echo addslashes(t("wholesale.js_invalid_price")); ?>',
    invalid_margin: '<?php echo addslashes(t("wholesale.js_invalid_margin")); ?>',
    select_cost: '<?php echo addslashes(t("wholesale.js_select_cost")); ?>',
    product_added: '<?php echo addslashes(t("wholesale.js_product_added")); ?>',
    product_removed: '<?php echo addslashes(t("wholesale.js_product_removed")); ?>',
    price_updated: '<?php echo addslashes(t("wholesale.js_price_updated")); ?>',
    no_image: '<?php echo addslashes(t("wholesale.js_no_image")); ?>',
    product_name_required: '<?php echo addslashes(t("wholesale.js_product_name_required")); ?>',
    wholesale_price_required: '<?php echo addslashes(t("wholesale.js_wholesale_price_required")); ?>',
    quantity_min: '<?php echo addslashes(t("wholesale.js_quantity_min")); ?>',
    manual_added: '<?php echo addslashes(t("wholesale.js_manual_added")); ?>',
    customer_registered: '<?php echo addslashes(t("wholesale.js_customer_registered")); ?>',
    product_not_found_barcode: '<?php echo addslashes(t("wholesale.js_product_not_found_barcode")); ?>',
    barcode_error: '<?php echo addslashes(t("wholesale.js_barcode_error")); ?>',
    store_required: '<?php echo addslashes(t("wholesale.js_store_required")); ?>',
    margin_range: '<?php echo addslashes(t("wholesale.js_margin_range")); ?>',
    qty_increased: '<?php echo addslashes(t("wholesale.js_qty_increased")); ?>',
    unregistered_margin: '<?php echo addslashes(t("wholesale.js_unregistered_margin")); ?>',
    wholesale_registered_success: '<?php echo addslashes(t("wholesale.js_wholesale_registered_success")); ?>',
    register_error: '<?php echo addslashes(t("wholesale.js_register_error")); ?>',
    server_error: '<?php echo addslashes(t("wholesale.js_server_error")); ?>',
    search_no_results: '<?php echo addslashes(t("wholesale.js_search_no_results")); ?>',
    register_as_new_customer: '<?php echo addslashes(t("wholesale.js_register_as_new_customer")); ?>',
    wholesale_product_badge: '<?php echo addslashes(t("wholesale.js_wholesale_product_badge")); ?>',
    unregistered_badge: '<?php echo addslashes(t("wholesale.js_unregistered_badge")); ?>',
    last_sale_info: '<?php echo addslashes(t("wholesale.js_last_sale_info")); ?>',
    box_count_label: '<?php echo addslashes(t("wholesale.js_box_count_label")); ?>',
    cost_piece_label: '<?php echo addslashes(t("wholesale.js_cost_piece_label")); ?>',
    cost_box_label: '<?php echo addslashes(t("wholesale.js_cost_box_label")); ?>',
    margin_label: '<?php echo addslashes(t("wholesale.js_margin_label")); ?>',
    wholesale_piece_label: '<?php echo addslashes(t("wholesale.js_wholesale_piece_label")); ?>',
    wholesale_box_label: '<?php echo addslashes(t("wholesale.js_wholesale_box_label")); ?>',
    product_summary_line: '<?php echo addslashes(t("wholesale.js_product_summary_line")); ?>',
    selling_price_basis: '<?php echo addslashes(t("wholesale.js_selling_price_basis")); ?>',
    no_piece_price: '<?php echo addslashes(t("wholesale.js_no_piece_price")); ?>',
    registering: '<?php echo addslashes(t("wholesale.js_registering")); ?>',
    manual_sku_label: '<?php echo addslashes(t("wholesale.manual_sku_label")); ?>',
    new_customer_title: '<?php echo addslashes(t("wholesale.new_customer_title")); ?>',
    business_name_required: '<?php echo addslashes(t("wholesale.js_business_name_required")); ?>',
    saving: '<?php echo addslashes(t("wholesale.js_saving")); ?>',
    save_label: '<?php echo addslashes(t("common.save")); ?>',
    save_error_generic: '<?php echo addslashes(t("common.save_error")); ?>',
    return_added_badge: '<?php echo addslashes(t("wholesale.js_return_added_badge")); ?>',
    return_add_btn: '<?php echo addslashes(t("wholesale.js_return_add_btn")); ?>',
    return_added_notice: '<?php echo addslashes(t("wholesale.js_return_added_notice")); ?>',
    response_parse_error: '<?php echo addslashes(t("wholesale.js_response_parse_error")); ?>',
    search_error: '<?php echo addslashes(t("wholesale.js_search_error")); ?>'
};

document.addEventListener('DOMContentLoaded', function() {
    let cart = [];
    const customerSearch = document.getElementById('customer_search');
    const customerSearchResults = document.getElementById('customer_search_results');
    const selectedCustomer = document.getElementById('selected_customer');
    const customerId = document.getElementById('customer_id');
    const clearCustomerSelection = document.getElementById('clear_customer_selection');

    const productSearch = document.getElementById('product_search');
    const productSearchResults = document.getElementById('product_search_results');

    const cartEmpty = document.getElementById('cart_empty');
    const cartItems = document.getElementById('cart_items');
    const cartList = document.getElementById('cart_list');
    const cartTotal = document.getElementById('cart_total');
    const cartItemsInput = document.getElementById('cart_items_input');
    const completeSaleBtn = document.getElementById('complete_sale_btn');

    // 숫자 표시: 소수는 소숫점 둘째자리까지(끝자리 0 제거), 정수는 정수로 표시
    function fmtNum(v) {
        const n = Number(v) || 0;
        if (Number.isInteger(n)) return n.toLocaleString();
        return (Math.round(n * 100) / 100).toLocaleString(undefined, { maximumFractionDigits: 2 });
    }

    let searchTimeout;

    // 모달 관련 요소들
    const customerModal = document.getElementById('customer-modal');
    const productModal = document.getElementById('product-modal');
    const closeCustomerModal = document.getElementById('close-customer-modal');
    const closeProductModal = document.getElementById('close-product-modal');
    const customerSearchBtn = document.getElementById('customer_search_btn');
    const productSearchBtn = document.getElementById('product_search_btn');
    const customerList = document.getElementById('customer-list');
    const productList = document.getElementById('product-list');
    const modalCustomerSearch = document.getElementById('modal-customer-search');
    const modalProductSearch = document.getElementById('modal-product-search');
    
    // 거래처 검색
    customerSearch.addEventListener('input', function() {
        const query = this.value.trim();
        
        clearTimeout(searchTimeout);
        
        if (query.length < 2) {
            customerSearchResults.classList.add('hidden');
            return;
        }
        
        searchTimeout = setTimeout(function() {
            searchCustomers(query);
        }, 300);
    });
    
    // 거래처 검색 버튼 클릭 - 모달 표시
    customerSearchBtn.addEventListener('click', function(e) {
        e.preventDefault(); // 폼 제출 방지
        const query = customerSearch.value.trim();
        if (query.length === 0) {
            // 빈 검색어일 때 전체 목록을 모달로 표시
            showCustomerModal();
        } else {
            // 검색어가 있으면 검색 실행
            searchCustomers(query);
        }
    });
    
    // 상품 검색
    productSearch.addEventListener('input', function() {
        const query = this.value.trim();

        clearTimeout(searchTimeout);

        if (query.length < 2) {
            productSearchResults.classList.add('hidden');
            return;
        }

        searchTimeout = setTimeout(function() {
            searchProducts(query);
        }, 300);
    });

    // 상품 검색창에서 엔터키 입력 시 처리
    productSearch.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            // 디바운스 타이머 취소 + 드롭다운 즉시 닫기
            clearTimeout(searchTimeout);
            productSearchResults.classList.add('hidden');

            const query = this.value.trim();

            if (query.length === 0) {
                showProductModal();
                return;
            }

            searchProductByBarcode(query);
        }
    });

    // 거래처 검색창에서 엔터키 입력 시 폼 제출 방지
    customerSearch.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const query = this.value.trim();
            if (query.length >= 2) {
                searchCustomers(query);
            } else if (query.length === 0) {
                showCustomerModal();
            }
        }
    });
    
    // 상품 검색 버튼 클릭
    productSearchBtn.addEventListener('click', function(e) {
        e.preventDefault();
        const query = productSearch.value.trim();
        if (query.length === 0) {
            showProductModal();
        } else {
            // 검색 실행 (드롭다운 결과 표시)
            searchProducts(query);
        }
    });
    
    // 거래처 선택 취소
    clearCustomerSelection.addEventListener('click', function() {
        customerId.value = '';
        selectedCustomer.classList.add('hidden');
        customerSearch.value = '';
        updateSaleButton();
    });
    
    // 모달 닫기 이벤트
    closeCustomerModal.addEventListener('click', function() {
        customerModal.classList.add('hidden');
    });
    
    closeProductModal.addEventListener('click', function() {
        productModal.classList.add('hidden');
    });
    
    // 모달 외부 클릭시 닫기
    customerModal.addEventListener('click', function(e) {
        if (e.target === customerModal) {
            customerModal.classList.add('hidden');
        }
    });
    
    productModal.addEventListener('click', function(e) {
        if (e.target === productModal) {
            productModal.classList.add('hidden');
        }
    });
    
    // 바코드 스캔 후 수량 입력란 포커스
    function focusCartQuantity(index) {
        setTimeout(function() {
            const rows = document.querySelectorAll('#cart_list tr');
            if (rows[index]) {
                const qtyInput = rows[index].querySelector('.quantity-controls input[type="number"]');
                if (qtyInput) {
                    qtyInput.focus();
                    qtyInput.select();
                }
            }
        }, 50);
    }

    // 수량 입력란에서 Enter → 상품 검색창으로 복귀
    cartList.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && e.target.matches('.quantity-controls input[type="number"]')) {
            e.preventDefault();
            productSearch.value = '';
            productSearch.focus();
        }
    });

    // ESC 키로 모달 닫기
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            customerModal.classList.add('hidden');
            productModal.classList.add('hidden');
            document.getElementById('manual_entry_form').classList.add('hidden');
        }
    });

    // 수기 입력 토글
    document.getElementById('toggle_manual_entry').addEventListener('click', function() {
        const form = document.getElementById('manual_entry_form');
        form.classList.toggle('hidden');
        if (!form.classList.contains('hidden')) {
            document.getElementById('manual_product_name').focus();
        }
    });

    document.getElementById('cancel_manual_entry').addEventListener('click', function() {
        document.getElementById('manual_entry_form').classList.add('hidden');
        clearManualForm();
    });

    document.getElementById('add_manual_item_btn').addEventListener('click', function() {
        addManualItemToCart();
    });

    ['manual_product_name', 'manual_cost_price', 'manual_wholesale_price', 'manual_quantity'].forEach(function(id) {
        document.getElementById(id).addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                addManualItemToCart();
            }
        });
    });

    // 수기 입력: Cost Price / Quantity / Margin Rate 입력 시 도매가(Wholesale Price) 자동 계산
    // 공식: 도매가(합계) = 원가 × (1 + 마진율/100) × 수량(무게)  (Margin Rate 포함, 합계 금액 표시)
    let manualWholesaleEdited = false; // 사용자가 도매가를 직접 수정하면 자동 계산 중단
    function recalcManualWholesalePrice() {
        if (manualWholesaleEdited) return;
        const cost = parseFloat(document.getElementById('manual_cost_price').value) || 0;
        const qty = parseFloat(document.getElementById('manual_quantity').value) || 0;
        const marginEl = document.getElementById('margin_rate');
        const margin = marginEl ? (parseFloat(marginEl.value) || 0) : 0;
        const wpEl = document.getElementById('manual_wholesale_price');
        if (cost > 0) {
            const unit = cost * (1 + margin / 100);
            wpEl.value = Math.round(unit * (qty > 0 ? qty : 1) * 100) / 100; // 소숫점 둘째자리까지
        }
    }
    document.getElementById('manual_cost_price').addEventListener('input', recalcManualWholesalePrice);
    document.getElementById('manual_quantity').addEventListener('input', recalcManualWholesalePrice);
    document.getElementById('margin_rate').addEventListener('input', recalcManualWholesalePrice);

    // 마진율 기억: 이전에 사용했던 값을 localStorage에 저장/복원
    (function rememberMarginRate() {
        const MARGIN_KEY = 'wholesale_margin_rate';
        const marginInput = document.getElementById('margin_rate');
        if (!marginInput) return;

        // 페이지 로드 시 저장된 값 복원
        const saved = localStorage.getItem(MARGIN_KEY);
        if (saved !== null && saved !== '' && !isNaN(parseFloat(saved))) {
            marginInput.value = saved;
            recalcManualWholesalePrice();
        }

        // 값이 바뀔 때마다 저장
        marginInput.addEventListener('change', function() {
            const v = parseFloat(this.value);
            if (!isNaN(v) && v >= 0 && v <= 100) {
                localStorage.setItem(MARGIN_KEY, this.value);
            }
        });
    })();

    // 도매가를 직접 입력하면 자동 계산을 멈추고, 비우면 다시 자동 계산
    document.getElementById('manual_wholesale_price').addEventListener('input', function() {
        manualWholesaleEdited = this.value.trim() !== '';
    });

    function clearManualForm() {
        document.getElementById('manual_product_name').value = '';
        document.getElementById('manual_cost_price').value = '';
        document.getElementById('manual_wholesale_price').value = '';
        document.getElementById('manual_quantity').value = '1';
        manualWholesaleEdited = false; // 자동 계산 재개
    }

    function addManualItemToCart() {
        const name = document.getElementById('manual_product_name').value.trim();
        const costPrice = Math.round((parseFloat(document.getElementById('manual_cost_price').value) || 0) * 100) / 100;
        // Wholesale Price 필드는 "합계 금액"을 의미 → 단가 = 합계 / 수량 (소숫점 둘째자리까지)
        const totalAmount = Math.round((parseFloat(document.getElementById('manual_wholesale_price').value) || 0) * 100) / 100;
        const quantity = Math.round((parseFloat(document.getElementById('manual_quantity').value) || 1) * 100) / 100;
        const unitPrice = quantity > 0 ? Math.round((totalAmount / quantity) * 100) / 100 : totalAmount;

        if (!name) {
            showNotification(translations.product_name_required, 'error');
            document.getElementById('manual_product_name').focus();
            return;
        }

        if (totalAmount <= 0) {
            showNotification(translations.wholesale_price_required, 'error');
            document.getElementById('manual_wholesale_price').focus();
            return;
        }

        if (quantity <= 0) {
            showNotification(translations.quantity_min, 'error');
            return;
        }

        cart.push({
            product_id: null,
            is_manual: true,
            sku: translations.manual_sku_label,
            name_ko: name,
            name_en: name,
            unit_price: unitPrice,
            quantity: quantity,
            total_price: totalAmount,
            min_quantity: 1,
            wholesale_price: unitPrice,
            wholesale_price_piece: 0,
            cost_price: costPrice,
            selling_price: 0
        });

        updateCart();
        clearManualForm();
        document.getElementById('manual_entry_form').classList.add('hidden');
        showNotification(translations.manual_added, 'success');
    }

    // 모달 내 검색 기능
    modalCustomerSearch.addEventListener('input', function() {
        const query = this.value.toLowerCase();
        const items = customerList.querySelectorAll('.modal-customer-item');
        
        items.forEach(function(item) {
            const name = item.dataset.name.toLowerCase();
            const phone = (item.dataset.phone || '').toLowerCase();
            const address = (item.dataset.address || '').toLowerCase();
            
            if (name.includes(query) || phone.includes(query) || address.includes(query)) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
            }
        });
    });
    
    modalProductSearch.addEventListener('input', function() {
        const query = this.value.toLowerCase();
        const items = productList.querySelectorAll('.modal-product-item');
        
        items.forEach(function(item) {
            const name = (item.dataset.nameEn || '').toLowerCase() + ' ' + (item.dataset.nameKo || '').toLowerCase();
            const sku = item.dataset.sku.toLowerCase();
            
            if (name.includes(query) || sku.includes(query)) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
            }
        });
    });
    
    // 검색 결과 외부 클릭시 닫기
    document.addEventListener('click', function(e) {
        if (!customerSearch.contains(e.target) && !customerSearchResults.contains(e.target)) {
            customerSearchResults.classList.add('hidden');
        }
        if (!productSearch.contains(e.target) && !productSearchResults.contains(e.target)) {
            productSearchResults.classList.add('hidden');
        }
    });
    
    function searchCustomers(query) {
        console.log('거래처 검색 시작:', query);
        
        fetch('ajax_search_wholesale_customers.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'q=' + encodeURIComponent(query) + '&limit=10&customer_id=' + ((document.getElementById('customer_id') || {}).value || 0)
        })
        .then(response => {
            console.log('Response status:', response.status);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text();
        })
        .then(text => {
            console.log('Raw response:', text);
            try {
                const data = JSON.parse(text);
                console.log('Parsed data:', data);
                
                if (data.success && data.customers && data.customers.length > 0) {
                    console.log('고객 수:', data.customers.length);
                    displayCustomerResults(data.customers, query);
                } else {
                    console.log('검색 실패 또는 결과 없음:', data.message);
                    customerSearchResults.innerHTML = `
                        <div class="p-3 text-sm text-gray-500">${translations.search_no_results}</div>
                        <div class="p-2 border-t border-gray-100">
                            <button type="button" class="open-new-customer-modal w-full flex items-center justify-center gap-2 px-3 py-2 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md hover:bg-green-100"
                                    data-name="${escapeAttr(query)}">
                                <i class="fas fa-plus-circle"></i>
                                ${translations.register_as_new_customer.replace('{name}', escapeHtml(query))}
                            </button>
                        </div>`;
                    customerSearchResults.classList.remove('hidden');
                    bindNewCustomerBtns();
                }
            } catch (parseError) {
                console.error('JSON 파싱 오류:', parseError);
                console.log('원본 응답:', text);
                customerSearchResults.innerHTML = '<div class="p-3 text-sm text-red-500">' + translations.response_parse_error + parseError.message + '</div>';
                customerSearchResults.classList.remove('hidden');
            }
        })
        .catch(error => {
            console.error('네트워크 오류:', error);
            customerSearchResults.innerHTML = '<div class="p-3 text-sm text-red-500">' + translations.search_error + error.message + '</div>';
            customerSearchResults.classList.remove('hidden');
        });
    }
    
    function searchProducts(query) {
        fetch('ajax_search_wholesale_products.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'q=' + encodeURIComponent(query) + '&limit=10&customer_id=' + ((document.getElementById('customer_id') || {}).value || 0)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.products) {
                displayProductResults(data.products);
            } else {
                productSearchResults.innerHTML = '<div class="p-3 text-sm text-gray-500">' + translations.search_no_results + '</div>';
                productSearchResults.classList.remove('hidden');
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
    }
    
    function displayCustomerResults(customers, query) {
        let html = '';
        customers.forEach(function(customer) {
            html += `
                <div class="p-3 hover:bg-gray-100 cursor-pointer border-b border-gray-100 last:border-b-0 customer-item"
                     data-id="${customer.id}"
                     data-name="${escapeAttr(customer.name)}"
                     data-phone="${escapeAttr(customer.phone || '')}"
                     data-address="${escapeAttr(customer.address || '')}"
                     data-discount-rate="${escapeAttr(customer.discount_rate ?? '')}">
                    <div class="font-medium text-gray-900">${escapeHtml(customer.name)}</div>
                    <div class="text-sm text-gray-600">${escapeHtml(customer.phone || '')} ${escapeHtml(customer.address || '')}</div>
                </div>
            `;
        });

        html += `
            <div class="p-2 border-t border-gray-100">
                <button type="button" class="open-new-customer-modal w-full flex items-center justify-center gap-2 px-3 py-2 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md hover:bg-green-100"
                        data-name="${escapeAttr(query || '')}">
                    <i class="fas fa-plus-circle"></i>
                    ${translations.new_customer_title}
                </button>
            </div>`;

        customerSearchResults.innerHTML = html;
        customerSearchResults.classList.remove('hidden');

        document.querySelectorAll('.customer-item').forEach(function(item) {
            item.addEventListener('click', function() {
                selectCustomer(this);
            });
        });
        bindNewCustomerBtns();
    }
    
    function displayProductResults(products) {
        let html = '';
        // 가격 포맷 (0/null → '-')
        const fmtPrice = function(v) { return (v && Number(v) > 0) ? Number(v).toLocaleString() : '-'; };
        products.forEach(function(product) {
            // 등록 상태 확인
            const isRegistered = product.status === 'registered';

            // 이 거래처에 대한 과거 판매 이력 (검색 결과에 바로 노출, 별도 팝업 불필요)
            const lastSaleHtml = product.last_sale_date
                ? `<div class="text-xs text-purple-700 bg-purple-50 border border-purple-200 rounded px-2 py-0.5 mt-1 inline-flex items-center gap-1">
                       <i class="fas fa-history"></i>${translations.last_sale_info
                           .replace('{date}', product.last_sale_date)
                           .replace('{price}', fmtPrice(product.last_sale_price))}
                   </div>`
                : '';

            // 도매 SKU들 처리
            let displaySkus = '';
            if (product.wholesale_skus) {
                try {
                    const skuArray = JSON.parse(product.wholesale_skus);
                    displaySkus = Array.isArray(skuArray) ? skuArray.join(', ') : product.sku;
                } catch (e) {
                    displaySkus = product.sku;
                }
            } else {
                displaySkus = product.sku;
            }
            
            if (isRegistered) {
                // 등록된 도매상품
                html += `
                    <div class="p-3 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-b-0 product-item"
                         data-id="${product.id}"
                         data-sku="${displaySkus}"
                         data-name-ko="${product.display_name_ko || ''}"
                         data-name-en="${product.display_name_en || ''}"
                         data-wholesale-price="${product.wholesale_price}"
                         data-wholesale-price-piece="${product.wholesale_price_piece || 0}"
                         data-min-quantity="${product.min_quantity}"
                         data-cost-price="${product.cost_price || 0}"
                         data-cost-box="${product.wp_cost_box || 0}"
                         data-cost-piece="${product.wp_cost_piece || 0}"
                         data-selling-price="${product.selling_price || 0}"
                         data-registered="true">
                        <div class="font-medium text-gray-900">
                            ${product.display_name_en || product.display_name_ko || 'N/A'}
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 ml-2">${translations.wholesale_product_badge}</span>
                        </div>
                        <div class="text-sm text-gray-600">${product.display_name_ko && product.display_name_en && product.display_name_ko !== product.display_name_en ? product.display_name_ko : ''}</div>
                        <div class="text-xs text-gray-500 mt-1">SKU: ${displaySkus} | ${translations.box_count_label.replace('{count}', product.min_quantity || 1)}</div>
                        <div class="text-xs text-gray-600 mt-1 flex flex-wrap gap-x-3 gap-y-0.5">
                            <span>${translations.cost_piece_label}: ${fmtPrice(product.wp_cost_piece)}</span>
                            <span>${translations.cost_box_label}: ${fmtPrice(product.wp_cost_box)}</span>
                            <span>${translations.margin_label}: ${Number(product.wp_margin_rate || 0).toFixed(1)}%</span>
                            <span class="text-blue-700 font-medium">${translations.wholesale_piece_label}: ${fmtPrice(product.wholesale_price_piece)}</span>
                            <span class="text-blue-700 font-medium">${translations.wholesale_box_label}: ${fmtPrice(product.wholesale_price)}</span>
                        </div>
                        ${lastSaleHtml}
                    </div>
                `;
            } else {
                // 미등록 일반상품 - 현재 마진율로 제안가 계산
                const marginRateInput = document.getElementById('margin_rate');
                const currentMarginRate = marginRateInput ? parseFloat(marginRateInput.value) : 15.0;
                const costPrice = parseFloat(product.cost_price) || 0;
                const suggestedPrice = Math.round(costPrice * (1 + currentMarginRate / 100) * 100) / 100;

                html += `
                    <div class="p-3 hover:bg-yellow-50 cursor-pointer border-b border-gray-100 last:border-b-0 product-item bg-yellow-50 border-l-4 border-l-yellow-400"
                         data-id="${product.id}"
                         data-sku="${product.sku}"
                         data-name-ko="${product.display_name_ko || ''}"
                         data-name-en="${product.display_name_en || ''}"
                         data-cost-price="${costPrice}"
                         data-selling-price="${product.selling_price || 0}"
                         data-suggested-price="${suggestedPrice}"
                         data-min-quantity="${product.product_pieces_per_box || 1}"
                         data-registered="false">
                        <div class="font-medium text-gray-900">
                            ${product.display_name_en || product.display_name_ko || 'N/A'}
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800 ml-2">${translations.unregistered_badge}</span>
                        </div>
                        <div class="text-sm text-gray-600">${product.display_name_ko && product.display_name_en && product.display_name_ko !== product.display_name_en ? product.display_name_ko : ''}</div>
                        <div class="text-xs text-gray-500 mt-1">
                            ${translations.product_summary_line
                                .replace('{sku}', product.sku)
                                .replace('{cost}', costPrice.toLocaleString() + translations.currency)
                                .replace('{rate}', currentMarginRate)
                                .replace('{price}', suggestedPrice.toLocaleString() + translations.currency)
                                .replace('{box}', product.product_pieces_per_box || 1)}
                        </div>
                        ${lastSaleHtml}
                    </div>
                `;
            }
        });

        productSearchResults.innerHTML = html;
        productSearchResults.classList.remove('hidden');

        // 모든 상품 선택 이벤트 바인딩 (등록/미등록 모두)
        document.querySelectorAll('.product-item').forEach(function(item) {
            item.addEventListener('click', function() {
                addToCartFromSearch(this);
            });
        });
    }

    // 검색 결과에서 장바구니에 추가
    function addToCartFromSearch(item) {
        const isRegistered = item.dataset.registered === 'true';

        const productData = {
            product_id: item.dataset.id,
            sku: item.dataset.sku,
            name_ko: item.dataset.nameKo,
            name_en: item.dataset.nameEn,
            min_quantity: parseInt(item.dataset.minQuantity) || 1,
            is_registered: isRegistered
        };

        if (isRegistered) {
            // 등록된 도매상품
            productData.wholesale_price = parseFloat(item.dataset.wholesalePrice);
            productData.wholesale_price_piece = parseFloat(item.dataset.wholesalePricePiece) || 0;
            productData.cost_box = parseFloat(item.dataset.costBox) || 0;     // 원가(박스)
            productData.cost_piece = parseFloat(item.dataset.costPiece) || 0; // 원가(낱개)
            productData.cost_price = parseFloat(item.dataset.costPrice) || 0;       // 인벤토리 원가 (도매원가 0일 때 폴백)
            productData.selling_price = parseFloat(item.dataset.sellingPrice) || 0; // 소매 판매가
            productData.margin_rate = 0; // 등록 상품은 마진율 표시 안함
        } else {
            // 미등록 일반상품
            const marginRateInput = document.getElementById('margin_rate');
            const marginRate = marginRateInput ? parseFloat(marginRateInput.value) : 15.0;
            const costPrice = parseFloat(item.dataset.costPrice) || 0;
            const sellingPrice = parseFloat(item.dataset.sellingPrice) || 0;

            if (costPrice <= 0 && sellingPrice > 0) {
                // 원가가 0이면 판매가(소매가)의 90%(10% 할인)를 도매가로 사용
                productData.wholesale_price = Math.round(sellingPrice * 0.9);
            } else {
                // 원가가 있으면 현재 마진율 기준 제안가 사용
                productData.wholesale_price = parseFloat(item.dataset.suggestedPrice);
            }
            productData.wholesale_price_piece = 0;
            productData.cost_price = costPrice;
            productData.selling_price = sellingPrice;
            productData.margin_rate = marginRate;
        }

        addProductToCartDirect(productData);
        productSearchResults.classList.add('hidden');
        productSearch.value = '';
        productSearch.focus();
    }
    
    // 거래처의 할인율을 마진율 입력란에 반영 (거래처 선택 시 자동 로드)
    function applyCustomerDiscountRate(rate) {
        const marginInput = document.getElementById('margin_rate');
        if (!marginInput) return;
        const val = parseFloat(rate);
        if (isNaN(val) || val < 0 || val > 100) return;
        marginInput.value = val;
        marginInput.dispatchEvent(new Event('change'));
        recalcManualWholesalePrice();
    }

    function selectCustomer(item) {
        const id = item.dataset.id;
        const name = item.dataset.name;
        const phone = item.dataset.phone;
        const address = item.dataset.address;

        customerId.value = id;

        document.getElementById('selected_customer_name').textContent = name;
        document.getElementById('selected_customer_info').textContent = `${phone} ${address}`;

        selectedCustomer.classList.remove('hidden');
        customerSearchResults.classList.add('hidden');
        customerSearch.value = name;
        applyCustomerDiscountRate(item.dataset.discountRate);
        updateSaleButton();
    }
    
    function addToCart(item) {
        const productId = item.dataset.id;
        const sku = item.dataset.sku;
        const nameKo = item.dataset.nameKo;
        const nameEn = item.dataset.nameEn;
        const wholesalePrice = parseFloat(item.dataset.wholesalePrice);
        const wholesalePricePiece = parseFloat(item.dataset.wholesalePricePiece) || 0;
        const minQuantity = parseInt(item.dataset.minQuantity) || 1;
        const costPrice = parseFloat(item.dataset.costPrice) || 0;
        const sellingPrice = parseFloat(item.dataset.sellingPrice) || 0;


        // 이미 장바구니에 있는지 확인
        const existingIndex = cart.findIndex(item => item.product_id == productId);

        if (existingIndex >= 0) {
            cart[existingIndex].quantity += 1; // 판매수량은 1개씩 증가
            cart[existingIndex].total_price = cart[existingIndex].quantity * cart[existingIndex].unit_price;
        } else {
            cart.push({
                product_id: productId,
                sku: sku, // 이미 도매 SKU들이 처리되어 전달됨
                name_ko: nameKo, // 도매 상품명 또는 기본 상품명
                name_en: nameEn, // 도매 상품명 또는 기본 상품명
                unit_price: wholesalePrice,
                quantity: 1, // 기본 판매수량은 1개
                total_price: wholesalePrice * 1,
                min_quantity: minQuantity, // 박스포장수량 정보 (표시용)
                wholesale_price: wholesalePrice, // 박스 판매가
                wholesale_price_piece: wholesalePricePiece, // 낱개 판매가
                cost_price: costPrice,
                selling_price: sellingPrice
            });
        }

        updateCart();
        productSearchResults.classList.add('hidden');
        productSearch.value = '';
    }

    // 바코드로 상품 추가
    function addProductByBarcode(barcode) {
        if (!barcode || barcode.trim() === '') {
            showNotification(translations.product_not_found_barcode, 'error');
            return;
        }

        // 현재 점포 ID 가져오기
        const storeIdElement = document.getElementById('store_id');
        let storeId = null;

        if (storeIdElement) {
            storeId = storeIdElement.value; // super_admin인 경우
        } else {
            storeId = <?php echo json_encode($current_store_id); ?>; // 일반 사용자인 경우
        }

        if (!storeId) {
            showNotification(translations.store_required, 'error');
            return;
        }

        // 마진율 가져오기
        const marginRateInput = document.getElementById('margin_rate');
        const marginRate = marginRateInput ? parseFloat(marginRateInput.value) : 15.0;

        // 마진율 유효성 검사
        if (marginRate < 0 || marginRate > 100) {
            showNotification(translations.margin_range, 'error');
            return;
        }

        // AJAX로 바코드 검색 (거래처 예외가 적용)
        fetch(`ajax_get_wholesale_product_by_barcode.php?barcode=${encodeURIComponent(barcode)}&store_id=${storeId}&margin_rate=${marginRate}&customer_id=${(document.getElementById('customer_id') || {}).value || 0}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const product = data.data;

                    // 장바구니에 추가
                    const existingIndex = cart.findIndex(item => item.product_id == product.product_id);
                    let targetIndex;

                    if (existingIndex >= 0) {
                        cart[existingIndex].quantity += 1;
                        cart[existingIndex].total_price = cart[existingIndex].quantity * cart[existingIndex].unit_price;
                        showNotification(translations.qty_increased, 'success');
                        targetIndex = existingIndex;
                    } else {
                        cart.push({
                            product_id: product.product_id,
                            sku: product.sku,
                            name_ko: product.name_ko,
                            name_en: product.name_en,
                            unit_price: product.wholesale_price,
                            quantity: 1,
                            total_price: product.wholesale_price * 1,
                            min_quantity: product.min_quantity,
                            wholesale_price: product.wholesale_price,
                            wholesale_price_piece: product.wholesale_price_piece
                        });

                        if (!product.is_registered) {
                            showNotification(translations.unregistered_margin.replace('{rate}', product.margin_rate), 'info');
                        } else {
                            showNotification(translations.product_added, 'success');
                        }
                        targetIndex = cart.length - 1;
                    }

                    updateCart();
                    productSearch.value = '';
                    focusCartQuantity(targetIndex);
                } else {
                    showNotification(data.message || translations.product_not_found_barcode, 'error');
                    productSearch.value = '';
                    productSearch.focus();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification(translations.barcode_error, 'error');
                productSearch.value = '';
                productSearch.focus();
            });
    }

    // 통합 상품 검색 함수 (바코드 또는 상품명/SKU)
    function searchProductByBarcode(query) {
        // 현재 점포 ID 가져오기
        const storeIdElement = document.getElementById('store_id');
        let storeId = storeIdElement ? storeIdElement.value : <?php echo json_encode($current_store_id); ?>;

        if (!storeId) {
            showNotification(translations.store_required, 'error');
            return;
        }

        // 마진율 가져오기
        const marginRateInput = document.getElementById('margin_rate');
        const marginRate = marginRateInput ? parseFloat(marginRateInput.value) : 15.0;

        // 먼저 바코드로 검색 시도 (거래처 예외가 적용)
        fetch(`ajax_get_wholesale_product_by_barcode.php?barcode=${encodeURIComponent(query)}&store_id=${storeId}&margin_rate=${marginRate}&customer_id=${(document.getElementById('customer_id') || {}).value || 0}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 바코드로 상품을 찾았으면 처리
                    const product = data.data;
                    let cartIdx;

                    if (product.is_registered) {
                        cartIdx = addProductToCartDirect(product);
                    } else {
                        cartIdx = addToCart(product, parseFloat(product.wholesale_price));
                    }

                    productSearch.value = '';
                    // 바코드 스캔 성공: 수량 입력란에 포커스 (모달 표시된 경우 제외)
                    if (cartIdx >= 0) {
                        focusCartQuantity(cartIdx);
                    } else {
                        productSearch.focus();
                    }
                } else {
                    showNotification(translations.product_not_found_barcode + ': ' + escapeHtml(query), 'error');
                    productSearch.value = '';
                    productSearch.focus();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification(translations.barcode_error, 'error');
                productSearch.value = '';
                productSearch.focus();
            });
    }

    // 상품을 바로 장바구니에 추가하는 함수 (cart 인덱스 반환, 모달 표시 시 -1)
    function addProductToCartDirect(product) {
        const costPrice = parseFloat(product.cost_price) || 0;
        const sellingPrice = parseFloat(product.selling_price) || 0;

        if (product.is_registered && (costPrice > 0 || sellingPrice > 0)) {
            showProductTypeSelectionModal(product);
            return -1;
        }

        return addToCart(product, product.wholesale_price);
    }

    // 실제 장바구니 추가 함수 (추가/수정된 cart 인덱스를 반환)
    function addToCart(product, price) {
        const existingIndex = cart.findIndex(item => item.product_id == product.product_id);
        let targetIndex;

        // 이 거래처에 대한 과거 납품 이력이 있으면 알림 메시지에 함께 표시
        const fmtPrice = function(v) { return (v && Number(v) > 0) ? Number(v).toLocaleString() : '-'; };
        const lastSaleSuffix = product.last_sale_date
            ? ' / ' + translations.last_sale_info.replace('{date}', product.last_sale_date).replace('{price}', fmtPrice(product.last_sale_price))
            : '';

        if (existingIndex >= 0) {
            cart[existingIndex].quantity += 1;
            cart[existingIndex].total_price = cart[existingIndex].quantity * cart[existingIndex].unit_price;
            showNotification(translations.qty_increased, 'success');
            targetIndex = existingIndex;
        } else {
            cart.push({
                product_id: product.product_id,
                sku: product.sku,
                name_ko: product.name_ko,
                name_en: product.name_en,
                unit_price: price,
                quantity: 1,
                total_price: price * 1,
                min_quantity: product.min_quantity,
                wholesale_price: price,
                wholesale_price_piece: product.wholesale_price_piece || 0,
                cost_price: product.cost_box || product.cost_price || 0,        // 원가(박스)
                cost_price_piece: product.cost_piece || 0,                      // 원가(낱개)
                selling_price: product.selling_price || 0,
                sale_unit: 'box'  // 기본 판매단위는 박스 (단가·원가가 박스 기준 값이므로 일치시킴)
            });

            if (!product.is_registered) {
                showNotification(translations.unregistered_margin.replace('{rate}', product.margin_rate) + lastSaleSuffix, 'info');
            } else {
                showNotification(translations.product_added + lastSaleSuffix, 'success');
            }
            targetIndex = cart.length - 1;
        }

        updateCart();
        return targetIndex;
    }

    // 상품 타입 선택 모달 표시
    function showProductTypeSelectionModal(product) {
        const modal = document.getElementById('product-type-selection-modal');
        const marginRateInput = document.getElementById('margin_rate');
        const marginRate = marginRateInput ? parseFloat(marginRateInput.value) : 15.0;

        // 원가 기반 판매가 계산 (소숫점 이하 무조건 올림)
        const costPrice = parseFloat(product.cost_price) || 0;
        const sellingPrice = parseFloat(product.selling_price) || 0;
        let inventoryPrice;

        if (costPrice <= 0) {
            // 원가가 0이면 판매가의 7% 할인
            inventoryPrice = Math.ceil(sellingPrice * 0.93);
        } else {
            // 원가가 있으면 원가 + 마진율
            inventoryPrice = Math.ceil(costPrice * (1 + marginRate / 100));
        }

        // 모달 데이터 채우기
        document.getElementById('modal-wholesale-name').textContent = product.name_ko;
        document.getElementById('modal-wholesale-price').textContent = parseFloat(product.wholesale_price).toLocaleString();

        document.getElementById('modal-inventory-name').textContent = product.name_ko;
        document.getElementById('modal-inventory-cost').textContent = costPrice > 0 ? costPrice.toLocaleString() : translations.selling_price_basis;
        document.getElementById('modal-inventory-price').textContent = inventoryPrice.toLocaleString();

        // 모달 표시
        modal.classList.remove('hidden');

        // 이벤트 리스너 설정 (중복 방지를 위해 기존 리스너 제거)
        const wholesaleOption = document.getElementById('wholesale-option');
        const inventoryOption = document.getElementById('inventory-option');
        const closeBtn = document.getElementById('close-type-selection-modal');

        // 새로운 이벤트 리스너 (클로저로 product 정보 유지)
        const wholesaleHandler = () => {
            modal.classList.add('hidden');
            addToCart(product, parseFloat(product.wholesale_price));
        };

        const inventoryHandler = () => {
            modal.classList.add('hidden');
            const modifiedProduct = {...product, wholesale_price: inventoryPrice, is_registered: false, margin_rate: marginRate};
            addToCart(modifiedProduct, inventoryPrice);
        };

        const closeHandler = () => {
            modal.classList.add('hidden');
        };

        // 기존 리스너 제거 후 새로 추가
        wholesaleOption.replaceWith(wholesaleOption.cloneNode(true));
        inventoryOption.replaceWith(inventoryOption.cloneNode(true));
        closeBtn.replaceWith(closeBtn.cloneNode(true));

        // 새로운 요소에 리스너 추가
        document.getElementById('wholesale-option').addEventListener('click', wholesaleHandler);
        document.getElementById('inventory-option').addEventListener('click', inventoryHandler);
        document.getElementById('close-type-selection-modal').addEventListener('click', closeHandler);
    }
    
    function updateCart() {
        if (cart.length === 0) {
            cartEmpty.classList.remove('hidden');
            cartItems.classList.add('hidden');
        } else {
            cartEmpty.classList.add('hidden');
            cartItems.classList.remove('hidden');
            
            let html = '';
            cart.forEach(function(item, index) {
                // 수기 입력 제외 상품: 도매판매가(단가)는 소수점 이하 무조건 올림
                if (!item.is_manual) {
                    item.unit_price = Math.ceil(Number(item.unit_price) || 0);
                    item.total_price = item.quantity * item.unit_price;
                }
                html += `
                    <tr class="border-b hover:bg-gray-50">
                        <!-- SKU -->
                        <td class="px-2 py-3 text-xs font-mono text-gray-700 font-medium sku-column">${item.sku}</td>
                        
                        <!-- 상품명 (영문 위, 한글 아래) -->
                        <td class="px-2 py-3 product-name">
                            <div class="text-sm font-medium text-gray-900" title="${item.name_en || '-'}">${item.name_en || '-'}</div>
                            ${item.name_ko && item.name_ko !== item.name_en ? 
                                `<div class="text-sm text-gray-600 mt-1" title="${item.name_ko}">${item.name_ko}</div>` : 
                                ''
                            }
                        </td>
                        
                        <!-- 박스포장수량 -->
                        <td class="px-2 py-3 text-center">
                            <div class="text-sm font-medium text-gray-700">
                                ${item.min_quantity}${translations.pieces}
                            </div>
                        </td>

                        <!-- 원가 (판매단위 기준: 박스→박스원가, 낱개→낱개원가) - 수정 가능 -->
                        <td class="px-2 py-3 text-center">
                            <input type="number"
                                   class="w-full px-2 py-1 text-sm border border-gray-300 rounded text-center focus:outline-none focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
                                   value="${item.sale_unit === 'piece' ? (item.cost_price_piece || 0) : (item.cost_price || 0)}"
                                   min="0"
                                   step="0.01"
                                   onchange="updateCostPrice(${index}, this.value)"
                                   style="min-width: 80px;">
                        </td>

                        <!-- 판매가 (소매 판매가, 참고용) -->
                        <td class="px-2 py-3 text-center">
                            <div class="text-sm text-gray-700">
                                ${item.selling_price ? fmtNum(item.selling_price) : '-'}
                            </div>
                        </td>

                        <!-- 판매단위 (BOX/PCS 토글) -->
                        <td class="px-2 py-3 text-center">
                            <div class="inline-flex rounded border border-gray-300 overflow-hidden text-xs">
                                <button type="button" onclick="setCartUnit(${index},'box')"
                                        class="px-2 py-1 font-bold ${item.sale_unit === 'piece' ? 'bg-white text-gray-400' : 'bg-amber-500 text-white'}">BOX</button>
                                <button type="button" onclick="setCartUnit(${index},'piece')"
                                        class="px-2 py-1 font-bold ${item.sale_unit === 'piece' ? 'bg-blue-500 text-white' : 'bg-white text-gray-400'}">PCS</button>
                            </div>
                        </td>

                        <!-- 도매판매가 -->
                        <td class="px-2 py-3 text-center">
                            <input type="number"
                                   class="w-full px-2 py-1 text-sm border border-gray-300 rounded text-center focus:outline-none focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
                                   value="${item.unit_price}"
                                   min="0"
                                   step="0.01"
                                   onchange="updateUnitPrice(${index}, this.value)"
                                   style="min-width: 80px;">
                        </td>

                        <!-- 수량 -->
                        <td class="px-2 py-3 text-center">
                            <div class="flex items-center justify-center space-x-1 quantity-controls">
                                <button type="button" onclick="updateQuantity(${index}, -1)"
                                        class="w-6 h-6 text-xs bg-gray-200 hover:bg-gray-300 rounded flex items-center justify-center">
                                    <i class="fas fa-minus"></i>
                                </button>
                                <input type="number"
                                       value="${item.quantity}"
                                       class="w-16 text-sm text-center border border-gray-300 rounded px-1 py-1"
                                       min="${item.is_manual ? '0.01' : '1'}"
                                       step="${item.is_manual ? '0.01' : '1'}"
                                       onchange="setQuantity(${index}, this.value)">
                                <button type="button" onclick="updateQuantity(${index}, 1)"
                                        class="w-6 h-6 text-xs bg-gray-200 hover:bg-gray-300 rounded flex items-center justify-center">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </td>
                        
                        <!-- 합계 -->
                        <td class="px-2 py-3 text-right">
                            <div class="text-sm font-semibold text-primary-600">
                                ${fmtNum(item.total_price)}
                            </div>
                        </td>

                        <!-- 삭제버튼 -->
                        <td class="px-2 py-3 text-center">
                            <button type="button" onclick="removeFromCart(${index})"
                                    class="text-red-400 hover:text-red-600 w-6 h-6 rounded hover:bg-red-50 flex items-center justify-center">
                                <i class="fas fa-times text-xs"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            cartList.innerHTML = html;

            const total = cart.reduce((sum, item) => sum + item.total_price, 0);
            cartTotal.textContent = fmtNum(total);
            const cartCountEl = document.getElementById('cart_count');
            if (cartCountEl) cartCountEl.textContent = cart.length;
        }

        cartItemsInput.value = JSON.stringify(cart);
        if (window.updateGrandTotal) window.updateGrandTotal();
        updateSaleButton();
    }
    
    window.removeFromCart = function(index) {
        cart.splice(index, 1);
        updateCart();
    };
    
    window.updateQuantity = function(index, change) {
        cart[index].quantity += change;
        if (cart[index].quantity <= 0) {
            cart.splice(index, 1);
        } else {
            cart[index].total_price = cart[index].quantity * cart[index].unit_price;
        }
        updateCart();
    };

    window.setQuantity = function(index, newQuantity) {
        // 수기 항목(무게)은 소숫점 둘째자리까지, 그 외(박스/등록상품)는 정수
        const isManual = !!cart[index].is_manual;
        let quantity = parseFloat(newQuantity) || 0;
        quantity = isManual ? Math.round(quantity * 100) / 100 : Math.round(quantity);
        if (quantity <= 0) {
            showNotification(translations.quantity_min, 'error');
            updateCart(); // 이전 값으로 복원
            return;
        }
        cart[index].quantity = quantity;
        cart[index].total_price = Math.round(cart[index].quantity * cart[index].unit_price * 100) / 100;
        updateCart();
    };

    window.updateUnitPrice = function(index, newPrice) {
        const price = parseFloat(newPrice) || 0;
        cart[index].unit_price = price;
        cart[index].total_price = cart[index].quantity * price;
        updateCart();
    };

    // 원가(Cost Price) 수정 - 판매단위(박스/낱개)에 맞는 원가 필드 갱신
    window.updateCostPrice = function(index, newCost) {
        const cost = parseFloat(newCost) || 0;
        if (cart[index].sale_unit === 'piece') {
            cart[index].cost_price_piece = cost;
        } else {
            cart[index].cost_price = cost;
        }
        updateCart();
    };

    // 판매단위(박스/낱개) 선택 → 해당 단위의 도매판매가를 적용
    window.setCartUnit = function(index, unit) {
        const it = cart[index];
        it.sale_unit = (unit === 'piece') ? 'piece' : 'box';
        const boxPrice = parseFloat(it.wholesale_price) || 0;
        const piecePrice = parseFloat(it.wholesale_price_piece) || 0;

        if (it.sale_unit === 'piece') {
            if (piecePrice > 0) {
                it.unit_price = piecePrice;
            } else {
                showNotification(translations.no_piece_price, 'info');
            }
        } else {
            if (boxPrice > 0) {
                it.unit_price = boxPrice;
            }
        }
        it.total_price = it.quantity * it.unit_price;
        updateCart();
    };
    
    function updateSaleButton() {
        const hasCustomer = customerId.value !== '';
        const hasItems = cart.length > 0 || (typeof returnCart !== 'undefined' && returnCart.length > 0);
        completeSaleBtn.disabled = !hasCustomer || !hasItems;

        // 거래처를 먼저 선택(등록)해야 상품 검색/추가가 가능하도록 제어
        productSearch.disabled = !hasCustomer;
        productSearchBtn.disabled = !hasCustomer;
        const manualToggleBtn = document.getElementById('toggle_manual_entry');
        if (manualToggleBtn) manualToggleBtn.disabled = !hasCustomer;
        const returnRegisterBtn = document.getElementById('return_register_btn');
        if (returnRegisterBtn) returnRegisterBtn.disabled = !hasCustomer;
        const searchHint = document.getElementById('product_search_hint');
        if (searchHint) searchHint.classList.toggle('hidden', hasCustomer);
        if (!hasCustomer) {
            productSearchResults.classList.add('hidden');
            document.getElementById('manual_entry_form').classList.add('hidden');
        }
    }
    
    // 거래처 모달 표시 및 전체 목록 로드
    function showCustomerModal() {
        customerModal.classList.remove('hidden');
        modalCustomerSearch.value = '';
        loadAllCustomers();
    }
    
    // 상품 모달 표시 및 전체 목록 로드
    function showProductModal() {
        productModal.classList.remove('hidden');
        modalProductSearch.value = '';
        loadAllProducts();
    }
    
    // 전체 거래처 목록 로드
    function loadAllCustomers() {
        customerList.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin mr-2"></i>' + translations.loading + '</div>';
        
        fetch('ajax_search_wholesale_customers.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'q=&limit=100&show_all=1' // 전체 목록 요청
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.customers) {
                displayModalCustomerList(data.customers);
            } else {
                customerList.innerHTML = '<div class="text-center py-4 text-gray-500">' + translations.no_customers + '</div>';
            }
        })
        .catch(error => {
            console.error(translations.error_customers, error);
            customerList.innerHTML = '<div class="text-center py-4 text-red-500">' + translations.customer_load_error + '</div>';
        });
    }
    
    // 전체 상품 목록 로드
    function loadAllProducts() {
        productList.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin mr-2"></i>' + translations.loading + '</div>';
        
        fetch('ajax_search_wholesale_products.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'q=&limit=100&show_all=1&customer_id=' + ((document.getElementById('customer_id') || {}).value || 0) // 전체 목록 요청
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.products) {
                displayModalProductList(data.products);
            } else {
                productList.innerHTML = '<div class="text-center py-4 text-gray-500">' + translations.no_products + '</div>';
            }
        })
        .catch(error => {
            console.error(translations.error_products, error);
            productList.innerHTML = '<div class="text-center py-4 text-red-500">' + translations.product_load_error + '</div>';
        });
    }
    
    // 모달 거래처 목록 표시
    function displayModalCustomerList(customers) {
        let html = `
            <div class="mb-2">
                <button type="button" class="open-new-customer-modal w-full flex items-center justify-center gap-2 px-3 py-2 text-sm font-medium text-green-700 bg-green-50 border-2 border-green-300 rounded-md hover:bg-green-100"
                        data-name="">
                    <i class="fas fa-plus-circle text-base"></i>
                    ${translations.new_customer_title}
                </button>
            </div>`;

        customers.forEach(function(customer) {
            html += `
                <div class="modal-customer-item p-3 border border-gray-200 rounded-md hover:bg-blue-50 cursor-pointer transition-colors duration-200"
                     data-id="${customer.id}"
                     data-name="${escapeAttr(customer.name)}"
                     data-phone="${escapeAttr(customer.phone || '')}"
                     data-address="${escapeAttr(customer.address || '')}"
                     data-discount-rate="${escapeAttr(customer.discount_rate ?? '')}">
                    <div class="flex items-center justify-between">
                        <div class="flex-1 min-w-0">
                            <div class="font-medium text-gray-900 text-sm truncate">${escapeHtml(customer.name)}</div>
                            <div class="text-xs text-gray-600 mt-1 truncate">
                                <i class="fas fa-phone mr-1"></i>${escapeHtml(customer.phone || translations.no_phone)}
                            </div>
                            ${customer.address ? `<div class="text-xs text-gray-500 truncate mt-1">
                                <i class="fas fa-map-marker-alt mr-1"></i>${escapeHtml(customer.address)}
                            </div>` : ''}
                        </div>
                        <div class="text-blue-500 ml-2">
                            <i class="fas fa-chevron-right text-sm"></i>
                        </div>
                    </div>
                </div>
            `;
        });

        customerList.innerHTML = html;

        customerList.querySelectorAll('.modal-customer-item').forEach(function(item) {
            item.addEventListener('click', function() {
                selectCustomerFromModal(this);
            });
        });
        bindNewCustomerBtns();
    }
    
    // 모달 상품 목록 표시
    function displayModalProductList(products) {
        let html = '';
        const fmtPrice = function(v) { return (v && Number(v) > 0) ? Number(v).toLocaleString() : '-'; };
        products.forEach(function(product) {
            // 도매 SKU들 처리
            let displaySkus = '';
            if (product.wholesale_skus) {
                try {
                    const skuArray = JSON.parse(product.wholesale_skus);
                    displaySkus = Array.isArray(skuArray) ? skuArray.join(', ') : product.sku;
                } catch (e) {
                    displaySkus = product.sku;
                }
            } else {
                displaySkus = product.sku;
            }
            
            html += `
                <div class="modal-product-item p-3 border border-gray-200 rounded-md hover:bg-green-50 cursor-pointer transition-colors duration-200"
                     data-id="${product.id}"
                     data-sku="${displaySkus}"
                     data-name-ko="${product.display_name_ko || ''}"
                     data-name-en="${product.display_name_en || ''}"
                     data-wholesale-price="${product.wholesale_price}"
                     data-wholesale-price-piece="${product.wholesale_price_piece || 0}"
                     data-min-quantity="${product.min_quantity}"
                     data-cost-price="${product.cost_price || 0}"
                     data-cost-box="${product.wp_cost_box || 0}"
                     data-cost-piece="${product.wp_cost_piece || 0}"
                     data-selling-price="${product.selling_price || 0}">
                    <div class="flex items-center justify-between">
                        <div class="flex-1 min-w-0">
                            <div class="font-medium text-gray-900 text-sm truncate">
                                ${product.display_name_en || product.display_name_ko || 'N/A'}
                                ${product.display_name_en !== product.name_en || product.display_name_ko !== product.name_ko ? 
                                    '<span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 ml-1">' + translations.wholesale_badge + '</span>' : ''}
                            </div>
                            ${product.display_name_ko && product.display_name_en && product.display_name_ko !== product.display_name_en ? 
                                `<div class="text-xs text-gray-600 mt-1 truncate">${product.display_name_ko}</div>` : ''}
                            <div class="text-xs text-gray-500 mt-1 flex flex-wrap gap-2">
                                <span><i class="fas fa-barcode mr-1"></i>${displaySkus}</span>
                                <span><i class="fas fa-box mr-1"></i>${translations.minimum_prefix}${product.min_quantity || 1}</span>
                                ${product.memo ? `<span class="text-amber-700"><i class="fas fa-sticky-note mr-1"></i>${product.memo}</span>` : ''}
                            </div>
                            <div class="text-xs text-gray-600 mt-1 flex flex-wrap gap-x-3 gap-y-0.5">
                                <span>${translations.cost_piece_label}: ${fmtPrice(product.wp_cost_piece)}</span>
                                <span>${translations.cost_box_label}: ${fmtPrice(product.wp_cost_box)}</span>
                                <span>${translations.margin_label}: ${Number(product.wp_margin_rate || 0).toFixed(1)}%</span>
                                <span class="text-blue-700 font-medium">${translations.wholesale_piece_label}: ${fmtPrice(product.wholesale_price_piece)}</span>
                                <span class="text-blue-700 font-medium">${translations.wholesale_box_label}: ${fmtPrice(product.wholesale_price)}</span>
                            </div>
                        </div>
                        <div class="text-green-500 ml-2">
                            <i class="fas fa-plus-circle text-lg"></i>
                        </div>
                    </div>
                </div>
            `;
        });
        
        productList.innerHTML = html;
        
        // 클릭 이벤트 추가
        productList.querySelectorAll('.modal-product-item').forEach(function(item) {
            item.addEventListener('click', function() {
                addToCartFromModal(this);
            });
        });
    }
    
    // 모달에서 거래처 선택
    function selectCustomerFromModal(item) {
        const id = item.dataset.id;
        const name = item.dataset.name;
        const phone = item.dataset.phone;
        const address = item.dataset.address;
        
        // 기존 selectCustomer 함수와 동일한 로직
        customerId.value = id;
        document.getElementById('selected_customer_name').textContent = name;
        document.getElementById('selected_customer_info').textContent = `${phone} ${address}`;
        selectedCustomer.classList.remove('hidden');
        customerSearch.value = name;
        applyCustomerDiscountRate(item.dataset.discountRate);
        updateSaleButton();
        
        // 모달 닫기
        customerModal.classList.add('hidden');
    }
    
    // 모달에서 상품을 장바구니에 추가
    function addToCartFromModal(item) {
        const productId = item.dataset.id;
        const sku = item.dataset.sku;
        const nameKo = item.dataset.nameKo;
        const nameEn = item.dataset.nameEn;
        const wholesalePrice = parseFloat(item.dataset.wholesalePrice);
        const wholesalePricePiece = parseFloat(item.dataset.wholesalePricePiece) || 0;
        const minQuantity = parseInt(item.dataset.minQuantity) || 1;
        
        
        // 기존 addToCart 함수와 동일한 로직
        const existingIndex = cart.findIndex(item => item.product_id == productId);
        
        if (existingIndex >= 0) {
            cart[existingIndex].quantity += 1; // 판매수량은 1개씩 증가
            cart[existingIndex].total_price = cart[existingIndex].quantity * cart[existingIndex].unit_price;
        } else {
            const costBox = parseFloat(item.dataset.costBox) || 0;
            const costPiece = parseFloat(item.dataset.costPiece) || 0;
            const costPrice = parseFloat(item.dataset.costPrice) || 0; // 인벤토리 원가 (폴백)
            const sellingPrice = parseFloat(item.dataset.sellingPrice) || 0;

            cart.push({
                product_id: productId,
                sku: sku,
                name_ko: nameKo,
                name_en: nameEn,
                unit_price: wholesalePrice,
                quantity: 1, // 기본 판매수량은 1개
                total_price: wholesalePrice * 1,
                min_quantity: minQuantity, // 박스포장수량 정보 (표시용)
                wholesale_price: wholesalePrice, // 박스 판매가
                wholesale_price_piece: wholesalePricePiece, // 낱개 판매가
                cost_price: costBox || costPrice,        // 원가(박스), 없으면 인벤토리 원가
                cost_price_piece: costPiece, // 원가(낱개)
                selling_price: sellingPrice,
                sale_unit: 'box'  // 기본 판매단위는 박스 (단가·원가가 박스 기준 값이므로 일치시킴)
            });
        }

        updateCart();

        // 모달 닫기
        productModal.classList.add('hidden');
    }
    
    // 수정 모드인 경우 기존 데이터로 폼 초기화
    function initializeEditMode() {
        if (!editMode || !editData) return;
        
        // 거래처 정보 설정
        if (editData.customer_id) {
            customerId.value = editData.customer_id;
            customerSearch.value = editData.customer_name || '';
            
            // 선택된 거래처 표시 (기존 span 요소 재사용)
            if (selectedCustomer && editData.customer_name) {
                document.getElementById('selected_customer_name').textContent = editData.customer_name;
                document.getElementById('selected_customer_info').textContent =
                    [editData.customer_phone, editData.customer_address].filter(Boolean).join(' ');
                selectedCustomer.classList.remove('hidden');
            }

            applyCustomerDiscountRate(editData.customer_discount_rate);
        }
        
        // 점포 선택 설정 (super_admin인 경우)
        const storeSelect = document.getElementById('store_id');
        if (storeSelect && editData.store_id) {
            storeSelect.value = editData.store_id;
        }
        
        // 판매 날짜 설정
        const saleDateInput = document.getElementById('sale_date');
        if (saleDateInput && editData.sale_date) {
            saleDateInput.value = editData.sale_date;
        }
        
        // 장바구니 항목들 복원
        if (editData.items && editData.items.length > 0) {
            cart = [];
            editData.items.forEach(function(item) {
                const isManual = !item.product_id;
                cart.push({
                    product_id: item.product_id || null,
                    is_manual: isManual,
                    sku: item.sku || translations.manual_sku_label,
                    name_ko: item.name_ko,
                    name_en: item.name_en,
                    unit_price: parseFloat(item.unit_price),
                    quantity: isManual ? (Math.round((parseFloat(item.quantity) || 0) * 100) / 100) : parseInt(item.quantity),
                    total_price: parseFloat(item.total_price),
                    min_quantity: isManual ? 1 : (parseInt(item.min_quantity) || 1),
                    wholesale_price: parseFloat(item.wholesale_price) || parseFloat(item.unit_price) || 0,
                    wholesale_price_piece: parseFloat(item.wholesale_price_piece) || 0,
                    cost_price: parseFloat(item.cost_price) || 0,
                    cost_price_piece: parseFloat(item.cost_price_piece) || 0,
                    selling_price: parseFloat(item.selling_price) || 0,
                    sale_unit: (item.sale_unit === 'piece') ? 'piece' : 'box'
                });
            });
            
            updateCart();
        }

        updateSaleButton();
    }

    // 페이지 로드 시 수정 모드 초기화
    if (editMode) {
        initializeEditMode();
    }

    // 도매상품 자동 등록 함수 정의
    window.registerAsWholesaleProduct = function(productId, productName, event) {
        event.stopPropagation(); // 이벤트 버블링 방지
        
        const button = event.target.closest('.register-wholesale-btn');
        const originalText = button.innerHTML;
        
        // 버튼 비활성화 및 로딩 표시
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>' + translations.registering;
        
        // 현재 점포 ID 가져오기
        const storeIdElement = document.getElementById('store_id');
        let storeId = null;
        
        if (storeIdElement) {
            storeId = storeIdElement.value; // super_admin인 경우
        } else {
            storeId = <?php echo json_encode($current_store_id); ?>; // 일반 사용자인 경우
        }
        
        // AJAX 요청으로 도매상품 등록
        fetch('ajax_add_wholesale_product_quick.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `product_id=${productId}&store_id=${storeId}&margin_rate=15.0`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // 성공 시 알림 표시
                showNotification(translations.wholesale_registered_success, 'success');
                
                // 검색 결과 새로고침
                const currentQuery = document.getElementById('product_search').value;
                if (currentQuery.trim().length >= 2) {
                    setTimeout(() => {
                        searchProducts(currentQuery);
                    }, 500);
                }
            } else {
                // 실패 시 오류 메시지 표시
                showNotification(data.message || translations.register_error, 'error');
                
                // 버튼 복원
                button.disabled = false;
                button.innerHTML = originalText;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification(translations.register_error, 'error');
            
            // 버튼 복원
            button.disabled = false;
            button.innerHTML = originalText;
        });
    };

    // ─── 신규 거래처 등록 모달 ───────────────────────────────────────

    const newCustomerModal = document.getElementById('new-customer-modal');

    function openNewCustomerModal(prefillName) {
        document.getElementById('new_customer_name').value = prefillName || '';
        document.getElementById('new_customer_phone').value = '';
        document.getElementById('new_customer_address').value = '';
        document.getElementById('new_customer_memo').value = '';
        document.getElementById('new_customer_name_en').value = '';
        document.getElementById('transliterated_name_section').classList.add('hidden');
        document.getElementById('new-customer-form-errors').classList.add('hidden');
        customerModal.classList.add('hidden');
        customerSearchResults.classList.add('hidden');
        newCustomerModal.classList.remove('hidden');
        document.getElementById('new_customer_name').focus();
    }

    function closeNewCustomerModal() {
        newCustomerModal.classList.add('hidden');
    }

    document.getElementById('close-new-customer-modal').addEventListener('click', closeNewCustomerModal);
    document.getElementById('cancel-new-customer').addEventListener('click', closeNewCustomerModal);

    newCustomerModal.addEventListener('click', function(e) {
        if (e.target === newCustomerModal) closeNewCustomerModal();
    });

    // 번역 버튼: 한글 → 영어 발음 변환
    document.getElementById('transliterate_btn').addEventListener('click', function() {
        const korean = document.getElementById('new_customer_name').value.trim();
        if (!korean) return;
        const romanized = koreanToRoman(korean);
        document.getElementById('new_customer_name_en').value = romanized;
        document.getElementById('transliterated_name_section').classList.remove('hidden');
    });

    // 저장 버튼
    document.getElementById('save-new-customer').addEventListener('click', function() {
        const name    = document.getElementById('new_customer_name').value.trim();
        const phone   = document.getElementById('new_customer_phone').value.trim();
        const address = document.getElementById('new_customer_address').value.trim();
        const memo    = document.getElementById('new_customer_memo').value.trim();

        const errBox  = document.getElementById('new-customer-form-errors');
        const errText = document.getElementById('new-customer-error-text');

        if (!name) {
            errText.textContent = translations.business_name_required;
            errBox.classList.remove('hidden');
            return;
        }
        errBox.classList.add('hidden');

        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>' + translations.saving;

        fetch('ajax_add_wholesale_customer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `name=${encodeURIComponent(name)}&phone=${encodeURIComponent(phone)}&address=${encodeURIComponent(address)}&memo=${encodeURIComponent(memo)}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeNewCustomerModal();
                // 등록된 거래처를 바로 선택
                const c = data.customer;
                customerId.value = c.id;
                document.getElementById('selected_customer_name').textContent = c.name;
                document.getElementById('selected_customer_info').textContent = `${c.phone || ''} ${c.address || ''}`.trim();
                selectedCustomer.classList.remove('hidden');
                customerSearch.value = c.name;
                updateSaleButton();
                showNotification(translations.customer_registered, 'success');
            } else {
                errText.textContent = data.message || translations.save_error_generic;
                errBox.classList.remove('hidden');
            }
        })
        .catch(() => {
            errText.textContent = translations.server_error;
            errBox.classList.remove('hidden');
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save mr-1"></i>' + translations.save_label;
        });
    });

    // 신규 등록 버튼 바인딩 (동적으로 추가되는 버튼들)
    function bindNewCustomerBtns() {
        document.querySelectorAll('.open-new-customer-modal').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                openNewCustomerModal(this.dataset.name || '');
            });
        });
    }

    // ESC 키로 신규 거래처 모달 닫기
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            newCustomerModal.classList.add('hidden');
        }
    });

    // ─── 한글 → 영어 발음 변환 (국어 로마자 표기법) ─────────────────

    function koreanToRoman(str) {
        const ONSET  = ['g','kk','n','d','tt','r','m','b','pp','s','ss','','j','jj','ch','k','t','p','h'];
        const VOWEL  = ['a','ae','ya','yae','eo','e','yeo','ye','o','wa','wae','oe','yo','u','wo','we','wi','yu','eu','ui','i'];
        const CODA   = ['','k','kk','ks','n','nj','nh','l','lk','lm','lb','ls','lt','lp','lh','m','p','ps','s','ss','ng','j','ch','k','t','p','h'];

        let result = '';
        for (let i = 0; i < str.length; i++) {
            const code = str.charCodeAt(i);
            if (code >= 0xAC00 && code <= 0xD7A3) {
                const offset = code - 0xAC00;
                const onset  = Math.floor(offset / (21 * 28));
                const vowel  = Math.floor((offset % (21 * 28)) / 28);
                const coda   = offset % 28;
                result += ONSET[onset] + VOWEL[vowel] + CODA[coda];
            } else if ((code >= 0x41 && code <= 0x5A) || (code >= 0x61 && code <= 0x7A) || (code >= 0x30 && code <= 0x39)) {
                result += str[i];
            } else if (code === 0x20) {
                result += ' ';
            }
        }
        return result.toUpperCase();
    }

    // ─── XSS 방지 헬퍼 ──────────────────────────────────────────────

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function escapeAttr(str) {
        return String(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // 알림 표시 함수 정의
    window.showNotification = function(message, type = 'info') {
        // 기존 알림 제거
        const existingNotification = document.querySelector('.notification-toast');
        if (existingNotification) {
            existingNotification.remove();
        }
        
        // 새 알림 생성
        const notification = document.createElement('div');
        notification.className = `notification-toast fixed top-4 right-4 px-6 py-3 rounded-lg shadow-lg z-50 transform transition-all duration-300 ease-in-out ${
            type === 'success' ? 'bg-green-500 text-white' : 
            type === 'error' ? 'bg-red-500 text-white' : 
            'bg-blue-500 text-white'
        }`;
        notification.innerHTML = `
            <div class="flex items-center">
                <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'} mr-2"></i>
                <span>${message}</span>
            </div>
        `;
        
        document.body.appendChild(notification);
        
        // 3초 후 자동 제거
        setTimeout(() => {
            notification.style.transform = 'translateX(100%)';
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 300);
        }, 3000);
    };

    // ── 반품등록: 거래처 최근 판매이력에서 반품 품목을 선택해 "현재 작성 중인 전표"에 담음 ──
    // 예전 판매 전표는 절대 수정하지 않으며, 저장 시 이 전표의 total_amount에서 반품 금액이 차감됨
    const returnRegisterBtn = document.getElementById('return_register_btn');
    const returnRegisterModal = document.getElementById('return-register-modal');
    const returnRegisterCustomerName = document.getElementById('return-register-customer-name');
    const returnHistoryLoading = document.getElementById('return-history-loading');
    const returnHistoryEmpty = document.getElementById('return-history-empty');
    const returnHistoryTable = document.getElementById('return-history-table');
    const returnHistoryBody = document.getElementById('return-history-body');
    const closeReturnRegisterModalBtn = document.getElementById('close-return-register-modal');
    const closeReturnRegisterModalBtn2 = document.getElementById('close-return-register-modal-btn2');

    const returnItemsSection = document.getElementById('return_items_section');
    const returnItemsBody = document.getElementById('return_items_body');
    const returnItemsTotalEl = document.getElementById('return_items_total');
    const returnTotalRow = document.getElementById('return_total_row');
    const grandTotalRow = document.getElementById('grand_total_row');
    const grandTotalAmountEl = document.getElementById('grand_total_amount');
    const returnItemsInput = document.getElementById('return_items_input');

    let returnCart = []; // { sale_item_id, sale_id, sale_date, product_name, unit_price, remaining, quantity }

    function fmtReturnRegNum(v) {
        const n = Number(v) || 0;
        if (Number.isInteger(n)) return n.toLocaleString();
        return (Math.round(n * 100) / 100).toLocaleString(undefined, { maximumFractionDigits: 2 });
    }

    function escapeReturnRegHtml(str) {
        const div = document.createElement('div');
        div.textContent = str == null ? '' : str;
        return div.innerHTML;
    }

    function openReturnRegisterModal() {
        if (!returnRegisterModal || !customerId.value) return;
        const nameEl = document.getElementById('selected_customer_name');
        returnRegisterCustomerName.textContent = nameEl ? ('- ' + nameEl.textContent) : '';
        returnRegisterModal.classList.remove('hidden');
        loadReturnHistory();
    }

    function closeReturnRegisterModalFn() {
        if (returnRegisterModal) returnRegisterModal.classList.add('hidden');
    }

    function loadReturnHistory() {
        returnHistoryLoading.classList.remove('hidden');
        returnHistoryEmpty.classList.add('hidden');
        returnHistoryTable.classList.add('hidden');
        returnHistoryBody.innerHTML = '';

        fetch('ajax_search_wholesale_customer_returns.php?customer_id=' + encodeURIComponent(customerId.value))
            .then(function(res) { return res.json(); })
            .then(function(data) {
                returnHistoryLoading.classList.add('hidden');
                if (!data.success || !data.items || data.items.length === 0) {
                    returnHistoryEmpty.classList.remove('hidden');
                    return;
                }
                data.items.forEach(function(item) {
                    const remaining = Math.round((parseFloat(item.quantity) - parseFloat(item.returned_quantity)) * 100) / 100;
                    if (remaining <= 0) return;
                    const saleItemId = parseInt(item.sale_item_id, 10);
                    const alreadyAdded = returnCart.some(function(c) { return c.sale_item_id === saleItemId; });

                    const tr = document.createElement('tr');
                    tr.className = 'border-b border-gray-100';
                    tr.innerHTML = `
                        <td class="px-2 py-2 text-gray-700">${escapeReturnRegHtml(item.sale_date)}</td>
                        <td class="px-2 py-2 text-gray-700">${escapeReturnRegHtml(item.sku)}</td>
                        <td class="px-2 py-2 text-gray-900">${escapeReturnRegHtml(item.product_name || '-')}</td>
                        <td class="px-2 py-2 text-center text-gray-700">${fmtReturnRegNum(remaining)}</td>
                        <td class="px-2 py-2 text-center text-gray-700">${fmtReturnRegNum(item.unit_price)}</td>
                        <td class="px-2 py-2 text-center">
                            <button type="button" class="return-add-btn px-2 py-1 text-xs font-medium rounded-md border ${alreadyAdded ? 'text-gray-400 bg-gray-100 border-gray-200 cursor-not-allowed' : 'text-orange-700 bg-orange-50 border-orange-200 hover:bg-orange-100'}" ${alreadyAdded ? 'disabled' : ''}>${alreadyAdded ? translations.return_added_badge : translations.return_add_btn}</button>
                        </td>
                    `;
                    if (!alreadyAdded) {
                        tr.querySelector('.return-add-btn').addEventListener('click', function() {
                            addToReturnCart({
                                sale_item_id: saleItemId,
                                sale_id: parseInt(item.sale_id, 10),
                                sale_date: item.sale_date,
                                product_name: item.product_name || '-',
                                unit_price: parseFloat(item.unit_price),
                                remaining: remaining
                            });
                            this.outerHTML = '<button type="button" class="px-2 py-1 text-xs font-medium rounded-md border text-gray-400 bg-gray-100 border-gray-200 cursor-not-allowed" disabled>' + translations.return_added_badge + '</button>';
                        });
                    }
                    returnHistoryBody.appendChild(tr);
                });

                if (returnHistoryBody.children.length === 0) {
                    returnHistoryEmpty.classList.remove('hidden');
                } else {
                    returnHistoryTable.classList.remove('hidden');
                }
            })
            .catch(function() {
                returnHistoryLoading.classList.add('hidden');
                returnHistoryEmpty.classList.remove('hidden');
            });
    }

    function addToReturnCart(entry) {
        entry.quantity = entry.remaining;
        returnCart.push(entry);
        renderReturnItemsSection();
        showNotification(translations.return_added_notice, 'success');
    }

    function removeFromReturnCart(saleItemId) {
        returnCart = returnCart.filter(function(c) { return c.sale_item_id !== saleItemId; });
        renderReturnItemsSection();
    }

    function renderReturnItemsSection() {
        returnItemsBody.innerHTML = '';

        if (returnCart.length === 0) {
            returnItemsSection.classList.add('hidden');
            returnItemsInput.value = '';
            updateGrandTotal();
            updateSaleButton();
            return;
        }

        returnItemsSection.classList.remove('hidden');

        returnCart.forEach(function(entry) {
            const tr = document.createElement('tr');
            tr.className = 'border-b border-orange-100';
            tr.innerHTML = `
                <td class="px-2 py-1 text-gray-700">${escapeReturnRegHtml(entry.sale_date)}</td>
                <td class="px-2 py-1 text-gray-900">${escapeReturnRegHtml(entry.product_name)}</td>
                <td class="px-2 py-1 text-center">
                    <input type="number" class="return-cart-qty-input w-20 px-2 py-1 text-sm border border-gray-300 rounded-md text-right"
                           min="0.01" max="${entry.remaining}" step="0.01" value="${entry.quantity}">
                </td>
                <td class="px-2 py-1 text-right return-cart-amount text-orange-700">-${fmtReturnRegNum(entry.quantity * entry.unit_price)}</td>
                <td class="px-2 py-1 text-center">
                    <button type="button" class="return-cart-remove-btn text-red-400 hover:text-red-600"><i class="fas fa-trash"></i></button>
                </td>
            `;
            const qtyInput = tr.querySelector('.return-cart-qty-input');
            qtyInput.addEventListener('input', function() {
                let qty = parseFloat(qtyInput.value) || 0;
                if (qty > entry.remaining) qty = entry.remaining;
                if (qty < 0) qty = 0;
                entry.quantity = qty;
                tr.querySelector('.return-cart-amount').textContent = '-' + fmtReturnRegNum(entry.quantity * entry.unit_price);
                syncReturnItemsInput();
                updateGrandTotal();
            });
            tr.querySelector('.return-cart-remove-btn').addEventListener('click', function() {
                removeFromReturnCart(entry.sale_item_id);
            });
            returnItemsBody.appendChild(tr);
        });

        syncReturnItemsInput();
        updateGrandTotal();
        updateSaleButton();
    }

    function syncReturnItemsInput() {
        returnItemsInput.value = JSON.stringify(returnCart.map(function(c) {
            return { sale_item_id: c.sale_item_id, quantity: c.quantity };
        }));
    }

    function returnItemsTotal() {
        return returnCart.reduce(function(sum, c) { return sum + (c.quantity * c.unit_price); }, 0);
    }

    // 상품 합계(cart) - 반품 차감액을 반영한 최종 합계 표시. updateCart()에서도 호출됨.
    window.updateGrandTotal = function updateGrandTotal() {
        const cartSum = cart.reduce((sum, item) => sum + item.total_price, 0);
        const returnSum = Math.round(returnItemsTotal() * 100) / 100;

        if (returnSum > 0) {
            returnTotalRow.classList.remove('hidden');
            grandTotalRow.classList.remove('hidden');
            returnItemsTotalEl.textContent = fmtReturnRegNum(returnSum);
            grandTotalAmountEl.textContent = fmtReturnRegNum(Math.round((cartSum - returnSum) * 100) / 100);
        } else {
            returnTotalRow.classList.add('hidden');
            grandTotalRow.classList.add('hidden');
        }
    };

    if (returnRegisterBtn) returnRegisterBtn.addEventListener('click', openReturnRegisterModal);
    if (closeReturnRegisterModalBtn) closeReturnRegisterModalBtn.addEventListener('click', closeReturnRegisterModalFn);
    if (closeReturnRegisterModalBtn2) closeReturnRegisterModalBtn2.addEventListener('click', closeReturnRegisterModalFn);
    if (returnRegisterModal) {
        returnRegisterModal.addEventListener('click', function(e) {
            if (e.target === returnRegisterModal) closeReturnRegisterModalFn();
        });
    }
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && returnRegisterModal && !returnRegisterModal.classList.contains('hidden')) {
            closeReturnRegisterModalFn();
        }
    });

    // ── 기존 납품상품 리스트: 거래처에 이전에 납품했던 상품을 조회해 현재 작성 중인 장바구니에 바로 담음 ──
    const existingProductsBtn = document.getElementById('existing_products_btn');
    const existingProductsModal = document.getElementById('existing-products-modal');
    const existingProductsCustomerName = document.getElementById('existing-products-customer-name');
    const existingProductsSearch = document.getElementById('existing-products-search');
    const existingProductsLoading = document.getElementById('existing-products-loading');
    const existingProductsEmpty = document.getElementById('existing-products-empty');
    const existingProductsNoMatch = document.getElementById('existing-products-no-match');
    const existingProductsTable = document.getElementById('existing-products-table');
    const existingProductsBody = document.getElementById('existing-products-body');
    const existingProductsPagination = document.getElementById('existing-products-pagination');
    const existingProductsPageInfo = document.getElementById('existing-products-page-info');
    const existingProductsPrevBtn = document.getElementById('existing-products-prev-btn');
    const existingProductsNextBtn = document.getElementById('existing-products-next-btn');
    const closeExistingProductsModalBtn = document.getElementById('close-existing-products-modal');
    const closeExistingProductsModalBtn2 = document.getElementById('close-existing-products-modal-btn2');

    const EXISTING_PRODUCTS_PAGE_SIZE = 15;
    let existingProductsAll = [];       // 이 거래처의 전체 납품 이력 (검색은 이 배열을 클라이언트에서 필터링)
    let existingProductsFiltered = [];  // 검색 필터가 적용된 결과 (페이지네이션은 이 배열 기준)
    let existingProductsPage = 1;
    let existingProductsSearchTimeout;

    function openExistingProductsModal() {
        if (!existingProductsModal || !customerId.value) return;
        const nameEl = document.getElementById('selected_customer_name');
        existingProductsCustomerName.textContent = nameEl ? ('- ' + nameEl.textContent) : '';
        if (existingProductsSearch) existingProductsSearch.value = '';
        existingProductsModal.classList.remove('hidden');
        loadExistingProducts();
    }

    function closeExistingProductsModalFn() {
        if (existingProductsModal) existingProductsModal.classList.add('hidden');
    }

    function loadExistingProducts() {
        existingProductsLoading.classList.remove('hidden');
        existingProductsEmpty.classList.add('hidden');
        existingProductsNoMatch.classList.add('hidden');
        existingProductsTable.classList.add('hidden');
        existingProductsPagination.classList.add('hidden');
        existingProductsBody.innerHTML = '';
        existingProductsAll = [];

        fetch('ajax_search_wholesale_customer_products.php?customer_id=' + encodeURIComponent(customerId.value))
            .then(function(res) { return res.json(); })
            .then(function(data) {
                existingProductsLoading.classList.add('hidden');
                if (!data.success || !data.products || data.products.length === 0) {
                    existingProductsEmpty.classList.remove('hidden');
                    return;
                }
                existingProductsAll = data.products;
                existingProductsPage = 1;
                renderExistingProductsList(existingProductsAll);
            })
            .catch(function() {
                existingProductsLoading.classList.add('hidden');
                existingProductsEmpty.classList.remove('hidden');
            });
    }

    function renderExistingProductsList(list) {
        existingProductsFiltered = list;
        existingProductsBody.innerHTML = '';

        if (list.length === 0) {
            existingProductsTable.classList.add('hidden');
            existingProductsPagination.classList.add('hidden');
            existingProductsNoMatch.classList.remove('hidden');
            return;
        }
        existingProductsNoMatch.classList.add('hidden');

        const totalPages = Math.max(1, Math.ceil(list.length / EXISTING_PRODUCTS_PAGE_SIZE));
        if (existingProductsPage > totalPages) existingProductsPage = totalPages;
        const startIdx = (existingProductsPage - 1) * EXISTING_PRODUCTS_PAGE_SIZE;
        const pageItems = list.slice(startIdx, startIdx + EXISTING_PRODUCTS_PAGE_SIZE);

        pageItems.forEach(function(product) {
            const isManual = !product.id;
            const boxPrice = parseFloat(product.wholesale_price) > 0 ? parseFloat(product.wholesale_price) : parseFloat(product.last_unit_price) || 0;
            const skuLabel = isManual ? translations.manual_sku_label : (product.sku || '-');
            const nameKo = product.display_name_ko || '';
            const nameEn = product.display_name_en || '';
            const nameCell = nameKo
                ? `<div class="text-gray-900">${escapeHtml(nameKo)}</div>` + (nameEn && nameEn !== nameKo ? `<div class="text-xs text-gray-500">${escapeHtml(nameEn)}</div>` : '')
                : `<div class="text-gray-900">${escapeHtml(nameEn || 'N/A')}</div>`;
            const tr = document.createElement('tr');
            tr.className = 'border-b border-gray-100';
            tr.innerHTML = `
                <td class="px-2 py-2 text-gray-700">${escapeHtml(product.last_sale_date || '-')}</td>
                <td class="px-2 py-2 text-gray-700">${escapeHtml(skuLabel)}</td>
                <td class="px-2 py-2">${nameCell}</td>
                <td class="px-2 py-2 text-center text-blue-700 font-medium">${fmtNum(boxPrice)}</td>
                <td class="px-2 py-2 text-center text-blue-700 font-medium">${parseFloat(product.wholesale_price_piece) > 0 ? fmtNum(product.wholesale_price_piece) : '-'}</td>
                <td class="px-2 py-2 text-center">
                    <button type="button" class="existing-product-add-btn px-2 py-1 text-xs font-medium rounded-md border text-green-700 bg-green-50 border-green-200 hover:bg-green-100">
                        <i class="fas fa-plus mr-1"></i><?php echo t('wholesale.add_to_cart'); ?>
                    </button>
                </td>
            `;
            tr.querySelector('.existing-product-add-btn').addEventListener('click', function() {
                addExistingProductToCart(product);
            });
            existingProductsBody.appendChild(tr);
        });

        existingProductsTable.classList.remove('hidden');

        if (list.length > EXISTING_PRODUCTS_PAGE_SIZE) {
            existingProductsPagination.classList.remove('hidden');
            existingProductsPageInfo.textContent = existingProductsPage + ' / ' + totalPages + ' (' + list.length + <?php echo json_encode(t('common.items')); ?> + ')';
            existingProductsPrevBtn.disabled = (existingProductsPage <= 1);
            existingProductsNextBtn.disabled = (existingProductsPage >= totalPages);
        } else {
            existingProductsPagination.classList.add('hidden');
        }
    }

    if (existingProductsPrevBtn) {
        existingProductsPrevBtn.addEventListener('click', function() {
            if (existingProductsPage > 1) {
                existingProductsPage--;
                renderExistingProductsList(existingProductsFiltered);
            }
        });
    }
    if (existingProductsNextBtn) {
        existingProductsNextBtn.addEventListener('click', function() {
            const totalPages = Math.max(1, Math.ceil(existingProductsFiltered.length / EXISTING_PRODUCTS_PAGE_SIZE));
            if (existingProductsPage < totalPages) {
                existingProductsPage++;
                renderExistingProductsList(existingProductsFiltered);
            }
        });
    }

    function filterExistingProducts(query) {
        const q = query.trim().toLowerCase();
        if (q === '') return existingProductsAll;
        return existingProductsAll.filter(function(product) {
            const haystack = [
                product.display_name_ko, product.display_name_en, product.sku
            ].filter(Boolean).join(' ').toLowerCase();
            return haystack.indexOf(q) !== -1;
        });
    }

    if (existingProductsSearch) {
        existingProductsSearch.addEventListener('input', function() {
            const query = this.value;
            clearTimeout(existingProductsSearchTimeout);
            existingProductsSearchTimeout = setTimeout(function() {
                if (existingProductsTable.classList.contains('hidden') && existingProductsNoMatch.classList.contains('hidden')) return; // 아직 로딩 전
                existingProductsPage = 1;
                renderExistingProductsList(filterExistingProducts(query));
            }, 150);
        });
    }

    function notifyAdded() {
        if (typeof showNotification === 'function') {
            showNotification(translations.product_added, 'success');
        }
    }

    function addExistingProductToCart(product) {
        const isManual = !product.id;

        if (isManual) {
            const name = product.display_name_ko || product.display_name_en || '';
            const existingIndex = cart.findIndex(function(ci) {
                return ci.is_manual && ci.name_ko === name;
            });

            if (existingIndex >= 0) {
                cart[existingIndex].quantity += 1;
                cart[existingIndex].total_price = cart[existingIndex].quantity * cart[existingIndex].unit_price;
            } else {
                const unitPrice = parseFloat(product.last_unit_price) || parseFloat(product.wholesale_price) || 0;
                cart.push({
                    product_id: null,
                    is_manual: true,
                    sku: translations.manual_sku_label,
                    name_ko: name,
                    name_en: product.display_name_en || name,
                    unit_price: unitPrice,
                    quantity: 1,
                    total_price: unitPrice,
                    min_quantity: 1,
                    wholesale_price: unitPrice,
                    wholesale_price_piece: 0,
                    cost_price: parseFloat(product.cost_price) || 0,
                    selling_price: 0,
                    sale_unit: 'piece'
                });
            }

            updateCart();
            notifyAdded();
            return;
        }

        const productId = product.id;
        const existingIndex = cart.findIndex(function(ci) { return ci.product_id == productId; });

        if (existingIndex >= 0) {
            cart[existingIndex].quantity += 1;
            cart[existingIndex].total_price = cart[existingIndex].quantity * cart[existingIndex].unit_price;
        } else {
            const wholesalePrice = parseFloat(product.wholesale_price) || parseFloat(product.last_unit_price) || 0;
            const wholesalePricePiece = parseFloat(product.wholesale_price_piece) || 0;
            const minQuantity = parseInt(product.min_quantity, 10) || 1;
            const costBox = parseFloat(product.wp_cost_box) || 0;
            const costPiece = parseFloat(product.wp_cost_piece) || 0;
            const costPrice = parseFloat(product.cost_price) || 0;
            const sellingPrice = parseFloat(product.selling_price) || 0;

            cart.push({
                product_id: productId,
                sku: product.sku,
                name_ko: product.display_name_ko,
                name_en: product.display_name_en,
                unit_price: wholesalePrice,
                quantity: 1,
                total_price: wholesalePrice * 1,
                min_quantity: minQuantity,
                wholesale_price: wholesalePrice,
                wholesale_price_piece: wholesalePricePiece,
                cost_price: costBox || costPrice,
                cost_price_piece: costPiece,
                selling_price: sellingPrice,
                sale_unit: 'piece'
            });
        }

        updateCart();
        notifyAdded();
    }

    if (existingProductsBtn) existingProductsBtn.addEventListener('click', openExistingProductsModal);
    if (closeExistingProductsModalBtn) closeExistingProductsModalBtn.addEventListener('click', closeExistingProductsModalFn);
    if (closeExistingProductsModalBtn2) closeExistingProductsModalBtn2.addEventListener('click', closeExistingProductsModalFn);
    if (existingProductsModal) {
        existingProductsModal.addEventListener('click', function(e) {
            if (e.target === existingProductsModal) closeExistingProductsModalFn();
        });
    }
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && existingProductsModal && !existingProductsModal.classList.contains('hidden')) {
            closeExistingProductsModalFn();
        }
    });
});

</script>

<style>
/* 모달 아이템 호버 */
.modal-customer-item, .modal-product-item {
    transition: all 0.15s ease-in-out;
}
.modal-customer-item:hover, .modal-product-item:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}

/* 모달 진입 애니메이션 */
@keyframes fadeIn  { from { opacity:0 } to { opacity:1 } }
@keyframes slideIn { from { opacity:0; transform:translateY(-16px) } to { opacity:1; transform:translateY(0) } }

#customer-modal, #product-modal, #new-customer-modal { animation: fadeIn 0.15s ease-in-out; }
#customer-modal > div, #product-modal > div, #new-customer-modal > div { animation: slideIn 0.15s ease-in-out; }

/* 모달 스크롤바 */
#customer-list::-webkit-scrollbar, #product-list::-webkit-scrollbar { width:5px; }
#customer-list::-webkit-scrollbar-track, #product-list::-webkit-scrollbar-track { background:#f3f4f6; border-radius:3px; }
#customer-list::-webkit-scrollbar-thumb, #product-list::-webkit-scrollbar-thumb { background:#d1d5db; border-radius:3px; }

/* 장바구니 테이블 가로 스크롤 */
.cart-table-wrapper { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.cart-table { min-width: 940px; }

/* 모바일 */
@media (max-width: 640px) {
    .cart-table { font-size: 0.7rem; }
    .cart-table th, .cart-table td { padding: 0.25rem; }
    .cart-table .product-name { min-width: 110px; }
    .cart-table .sku-column  { min-width: 55px; font-size: 0.625rem; }
    .cart-table .quantity-controls button { width:1.25rem; height:1.25rem; font-size:0.625rem; }
    #customer-modal > div, #product-modal > div { margin:0.75rem; max-height:calc(100vh - 1.5rem); }
}
</style>

<?php require_once __DIR__ . '/partials/footer.php'; ?>