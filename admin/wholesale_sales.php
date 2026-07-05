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
    
    // Load existing data in edit mode
    if ($edit_mode && $edit_sale_id > 0) {
        // Join sales info with customer info
        $edit_sql = "
            SELECT 
                ws.*,
                wc.name as customer_name,
                wc.phone as customer_phone,
                wc.address as customer_address
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
                    COALESCE(p.sku, '수기') as sku,
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
        }
    }
    
} catch (PDOException $e) {
    $errors[] = '데이터베이스 연결 오류: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $store_id = $current_store_id; // 점포는 접속자 점포로 고정
    $sale_date = $_POST['sale_date'] ?? date('Y-m-d');
    $cart_items = json_decode($_POST['cart_items'] ?? '[]', true);
    $is_edit = isset($_POST['edit_sale_id']) && is_numeric($_POST['edit_sale_id']);
    $edit_sale_id_post = $is_edit ? (int)$_POST['edit_sale_id'] : 0;
    
    if (empty($customer_id)) {
        $errors[] = t('wholesale.customer_required_error');
    }
    
    if (empty($cart_items)) {
        $errors[] = t('wholesale.products_required_error');
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            $total_amount = array_sum(array_column($cart_items, 'total_price'));
            
            if ($is_edit && $edit_sale_id_post > 0) {
                // 수정 모드 - 기존 데이터 업데이트
                
                // 권한 확인
                $check_sql = "SELECT id FROM wholesale_sales WHERE id = ?";
                if ($_SESSION['role'] !== 'super_admin') {
                    $check_sql .= " AND store_id = " . (int)$current_store_id;
                }
                $check_stmt = $pdo->prepare($check_sql);
                $check_stmt->execute([$edit_sale_id_post]);
                
                if (!$check_stmt->fetch()) {
                    throw new Exception('수정 권한이 없습니다.');
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
                // Register new sale
                $sale_stmt = $pdo->prepare("
                    INSERT INTO wholesale_sales (customer_id, store_id, user_id, sale_date, total_amount, final_amount, status, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, 'confirmed', NOW())
                ");
                $sale_stmt->execute([$customer_id, $store_id, $_SESSION['user_id'], $sale_date, $total_amount, $total_amount]);
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
            $errors[] = '처리 중 오류가 발생했습니다: ' . $e->getMessage();
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
            <button type="button" id="clear_customer_selection" class="text-blue-400 hover:text-blue-600 ml-2 flex-shrink-0">
                <i class="fas fa-times text-xs"></i>
            </button>
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
                    <span><i class="fas fa-tags text-primary-500 mr-1"></i>변경된 단가를 이 거래처 도매가로 저장 <span class="text-gray-400">(기본가와 다른 항목만)</span></span>
                </label>
                <div class="text-base font-semibold text-gray-900">
                    <?php echo t('wholesale.cart_total_label'); ?>: <span id="cart_total" class="text-primary-600">0</span>
                </div>
            </div>
        </div>

        <input type="hidden" name="cart_items" id="cart_items_input" value="">
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
    server_error: '<?php echo addslashes(t("wholesale.js_server_error")); ?>'
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
            sku: '수기',
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
                        <div class="p-3 text-sm text-gray-500">검색 결과가 없습니다.</div>
                        <div class="p-2 border-t border-gray-100">
                            <button type="button" class="open-new-customer-modal w-full flex items-center justify-center gap-2 px-3 py-2 text-sm text-green-700 bg-green-50 border border-green-200 rounded-md hover:bg-green-100"
                                    data-name="${escapeAttr(query)}">
                                <i class="fas fa-plus-circle"></i>
                                "<span class="font-medium">${escapeHtml(query)}</span>" 신규 거래처로 등록
                            </button>
                        </div>`;
                    customerSearchResults.classList.remove('hidden');
                    bindNewCustomerBtns();
                }
            } catch (parseError) {
                console.error('JSON 파싱 오류:', parseError);
                console.log('원본 응답:', text);
                customerSearchResults.innerHTML = '<div class="p-3 text-sm text-red-500">응답 파싱 오류: ' + parseError.message + '</div>';
                customerSearchResults.classList.remove('hidden');
            }
        })
        .catch(error => {
            console.error('네트워크 오류:', error);
            customerSearchResults.innerHTML = '<div class="p-3 text-sm text-red-500">검색 중 오류 발생: ' + error.message + '</div>';
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
                productSearchResults.innerHTML = '<div class="p-3 text-sm text-gray-500">검색 결과가 없습니다.</div>';
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
                     data-address="${escapeAttr(customer.address || '')}">
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
                    신규 거래처 등록
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
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 ml-2">도매상품</span>
                        </div>
                        <div class="text-sm text-gray-600">${product.display_name_ko && product.display_name_en && product.display_name_ko !== product.display_name_en ? product.display_name_ko : ''}</div>
                        <div class="text-xs text-gray-500 mt-1">SKU: ${displaySkus} | 박스: ${product.min_quantity || 1}개</div>
                        <div class="text-xs text-gray-600 mt-1 flex flex-wrap gap-x-3 gap-y-0.5">
                            <span>원가(낱개): ${fmtPrice(product.wp_cost_piece)}</span>
                            <span>원가(박스): ${fmtPrice(product.wp_cost_box)}</span>
                            <span>마진: ${Number(product.wp_margin_rate || 0).toFixed(1)}%</span>
                            <span class="text-blue-700 font-medium">도매가(낱개): ${fmtPrice(product.wholesale_price_piece)}</span>
                            <span class="text-blue-700 font-medium">도매가(박스): ${fmtPrice(product.wholesale_price)}</span>
                        </div>
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
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800 ml-2">미등록</span>
                        </div>
                        <div class="text-sm text-gray-600">${product.display_name_ko && product.display_name_en && product.display_name_ko !== product.display_name_en ? product.display_name_ko : ''}</div>
                        <div class="text-xs text-gray-500 mt-1">
                            SKU: ${product.sku} | 원가: ${costPrice.toLocaleString()}원 | 제안 도매가(${currentMarginRate}% 마진): ${suggestedPrice.toLocaleString()}원 | 박스: ${product.product_pieces_per_box || 1}개
                        </div>
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
                    showNotification(data.message || '상품을 찾을 수 없습니다.', 'error');
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
                sale_unit: 'piece'  // 기본 판매단위 PCS (단가는 유지, 토글 기본만 PCS)
            });

            if (!product.is_registered) {
                showNotification(translations.unregistered_margin.replace('{rate}', product.margin_rate), 'info');
            } else {
                showNotification(translations.product_added, 'success');
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
        document.getElementById('modal-inventory-cost').textContent = costPrice > 0 ? costPrice.toLocaleString() : '판매가 기준';
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
                showNotification('낱개 판매가가 없습니다. 도매판매가를 직접 입력하세요.', 'info');
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
        const hasItems = cart.length > 0;
        completeSaleBtn.disabled = !hasCustomer || !hasItems;

        // 거래처를 먼저 선택(등록)해야 상품 검색/추가가 가능하도록 제어
        productSearch.disabled = !hasCustomer;
        productSearchBtn.disabled = !hasCustomer;
        const manualToggleBtn = document.getElementById('toggle_manual_entry');
        if (manualToggleBtn) manualToggleBtn.disabled = !hasCustomer;
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
                    신규 거래처 등록
                </button>
            </div>`;

        customers.forEach(function(customer) {
            html += `
                <div class="modal-customer-item p-3 border border-gray-200 rounded-md hover:bg-blue-50 cursor-pointer transition-colors duration-200"
                     data-id="${customer.id}"
                     data-name="${escapeAttr(customer.name)}"
                     data-phone="${escapeAttr(customer.phone || '')}"
                     data-address="${escapeAttr(customer.address || '')}">
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
                                <span>원가(낱개): ${fmtPrice(product.wp_cost_piece)}</span>
                                <span>원가(박스): ${fmtPrice(product.wp_cost_box)}</span>
                                <span>마진: ${Number(product.wp_margin_rate || 0).toFixed(1)}%</span>
                                <span class="text-blue-700 font-medium">도매가(낱개): ${fmtPrice(product.wholesale_price_piece)}</span>
                                <span class="text-blue-700 font-medium">도매가(박스): ${fmtPrice(product.wholesale_price)}</span>
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
                sale_unit: 'piece'  // 기본 판매단위 PCS (단가는 유지, 토글 기본만 PCS)
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
                    sku: item.sku || '수기',
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
        button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>등록중...';
        
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
                showNotification(data.message || '등록 중 오류가 발생했습니다.', 'error');
                
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
            errText.textContent = '업체명을 입력해주세요.';
            errBox.classList.remove('hidden');
            return;
        }
        errBox.classList.add('hidden');

        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>저장 중...';

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
                errText.textContent = data.message || '저장 중 오류가 발생했습니다.';
                errBox.classList.remove('hidden');
            }
        })
        .catch(() => {
            errText.textContent = translations.server_error;
            errBox.classList.remove('hidden');
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save mr-1"></i>저장';
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