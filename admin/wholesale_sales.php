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
    
    if ($_SESSION['role'] === 'super_admin') {
        $store_stmt = $pdo->prepare("SELECT id, name FROM stores ORDER BY name");
        $store_stmt->execute();
        $stores = $store_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
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
            // Get sale items (도매상품 전용 상품명 우선 사용)
            $items_sql = "
                SELECT 
                    wsi.product_id,
                    wsi.quantity,
                    wsi.unit_price,
                    wsi.total_price,
                    wsi.sale_unit,
                    wsi.remarks,
                    p.sku,
                    COALESCE(wp.wholesale_name_ko, p.name_ko) as name_ko,
                    COALESCE(wp.wholesale_name_en, p.name_en) as name_en,
                    wp.wholesale_price,
                    COALESCE(wp.wholesale_price_piece, 0) as wholesale_price_piece,
                    COALESCE(p.pieces_per_box, wp.min_quantity, 1) as min_quantity
                FROM wholesale_sale_items wsi
                LEFT JOIN products p ON wsi.product_id = p.id
                LEFT JOIN wholesale_products wp ON wp.product_id = p.id AND wp.is_active = 1
                WHERE wsi.sale_id = ?
                ORDER BY COALESCE(wp.wholesale_name_en, p.name_en), COALESCE(wp.wholesale_name_ko, p.name_ko)
            ";
            
            $items_stmt = $pdo->prepare($items_sql);
            $items_stmt->execute([$edit_sale_id]);
            $edit_data['items'] = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
} catch (PDOException $e) {
    $errors[] = '데이터베이스 연결 오류: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $store_id = $_SESSION['role'] === 'super_admin' ? (int)($_POST['store_id'] ?? 0) : $current_store_id;
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
                foreach ($cart_items as $item) {
                    $item_stmt = $pdo->prepare("
                        INSERT INTO wholesale_sale_items (sale_id, product_id, quantity, unit_price, total_price, sale_unit, remarks, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $item_stmt->execute([
                        $edit_sale_id_post, 
                        $item['product_id'], 
                        $item['quantity'], 
                        $item['unit_price'], 
                        $item['total_price'], 
                        $item['sale_unit'] ?? 'box',
                        $item['remarks'] ?? ''
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
                foreach ($cart_items as $item) {
                    $item_stmt = $pdo->prepare("
                        INSERT INTO wholesale_sale_items (sale_id, product_id, quantity, unit_price, total_price, sale_unit, remarks, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $item_stmt->execute([
                        $sale_id, 
                        $item['product_id'], 
                        $item['quantity'], 
                        $item['unit_price'], 
                        $item['total_price'], 
                        $item['sale_unit'] ?? 'box',
                        $item['remarks'] ?? ''
                    ]);
                }
                
                $success_msg = t('wholesale.sale_registered_successfully');
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

<div class="container mx-auto px-2 sm:px-3 md:px-4 py-8">
    <div class="w-full mx-auto">
        <div class="mb-6">
            <nav class="flex" aria-label="Breadcrumb">
                <ol class="flex items-center space-x-2">
                    <li>
                        <a href="wholesale_customer_management.php" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-handshake mr-1"></i>
                            <?php echo t('navigation.wholesale_sales'); ?>
                        </a>
                    </li>
                    <li>
                        <div class="flex items-center">
                            <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                            <span class="text-gray-600"><?php echo t('navigation.wholesale_sales'); ?></span>
                        </div>
                    </li>
                </ol>
            </nav>
        </div>

        <div class="bg-white shadow-sm rounded-lg border">
            <div class="px-6 py-4 border-b border-gray-200">
                <h1 class="text-xl font-semibold text-gray-900">
                    <i class="fas fa-handshake mr-2 text-primary-500"></i>
                    <?php echo $edit_mode ? t('wholesale.sales_edit') : t('navigation.wholesale_sales'); ?>
                </h1>
                <p class="mt-1 text-sm text-gray-600">
                    <?php echo $edit_mode ? t('wholesale.sales_edit_description') : t('wholesale.sales_register_description'); ?>
                </p>
            </div>

            <div class="px-6 py-4">
                <?php if (isset($flash)): ?>
                    <div class="mb-6 p-4 rounded-md <?php echo $flash['type'] === 'error' ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200'; ?>">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas <?php echo $flash['type'] === 'error' ? 'fa-exclamation-triangle text-red-400' : 'fa-check-circle text-green-400'; ?>"></i>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm <?php echo $flash['type'] === 'error' ? 'text-red-700' : 'text-green-700'; ?>">
                                    <?php echo htmlspecialchars($flash['message']); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-md">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-triangle text-red-400"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-red-800">다음 오류를 해결해주세요:</h3>
                                <ul class="mt-2 text-sm text-red-700 list-disc list-inside">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" id="wholesale-sales-form">
                    <!-- Customer and basic information -->
                    <div class="space-y-6">
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h3 class="text-lg font-medium text-gray-900 mb-4"><?php echo t('wholesale.sales_info'); ?></h3>
                            
                            <!-- Customer, store, sale date in one line -->
                            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
                                <!-- Customer selection -->
                                <div>
                                    <label for="customer_search" class="block text-sm font-medium text-gray-700 mb-2">
                                        <?php echo t('wholesale.customer_required'); ?>
                                    </label>
                                    <div class="flex gap-2">
                                        <div class="relative flex-1">
                                            <input type="text" id="customer_search" 
                                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                                   placeholder="<?php echo t('wholesale.customer_search_placeholder'); ?>"
                                                   autocomplete="off">
                                            <div id="customer_search_results" class="absolute z-10 w-full bg-white border border-gray-300 rounded-md shadow-lg mt-1 max-h-60 overflow-y-auto hidden">
                                                <!-- 검색 결과가 여기에 표시됩니다 -->
                                            </div>
                                        </div>
                                        <button type="button" id="customer_search_btn" 
                                                class="px-3 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 whitespace-nowrap">
                                            <i class="fas fa-search"></i>
                                        </button>
                                    </div>
                                    <input type="hidden" name="customer_id" id="customer_id" value="">
                                </div>

                                <?php if ($_SESSION['role'] === 'super_admin' && !empty($stores)): ?>
                                <!-- 점포 선택 -->
                                <div>
                                    <label for="store_id" class="block text-sm font-medium text-gray-700 mb-2">
                                        점포 선택 <span class="text-red-500">*</span>
                                    </label>
                                    <select name="store_id" id="store_id" required
                                            class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                                        <option value="">점포를 선택하세요</option>
                                        <?php foreach ($stores as $store): ?>
                                            <option value="<?php echo $store['id']; ?>" 
                                                    <?php echo ($store['id'] == $current_store_id) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($store['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php else: ?>
                                <!-- 현재 점포 표시 (수정 불가) -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        점포
                                    </label>
                                    <div class="p-3 bg-gray-50 border border-gray-300 rounded-md">
                                        <span class="text-gray-900 font-medium"><?php echo htmlspecialchars($current_store_name); ?></span>
                                    </div>
                                    <input type="hidden" name="store_id" value="<?php echo $current_store_id; ?>">
                                </div>
                                <?php endif; ?>

                                <!-- Sale date -->
                                <div>
                                    <label for="sale_date" class="block text-sm font-medium text-gray-700 mb-2">
                                        <?php echo t('wholesale.sale_date'); ?>
                                    </label>
                                    <input type="date" name="sale_date" id="sale_date" value="<?php echo date('Y-m-d'); ?>"
                                           class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                                </div>
                            </div>
                            
                            <!-- Display selected customer information -->
                            <div id="selected_customer" class="mb-4 p-3 bg-white border rounded-md hidden">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="font-medium text-gray-900" id="selected_customer_name"></div>
                                        <div class="text-sm text-gray-600" id="selected_customer_info"></div>
                                    </div>
                                    <button type="button" id="clear_customer_selection" class="text-red-500 hover:text-red-700">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- 상품 검색 및 추가 -->
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h3 class="text-lg font-medium text-gray-900 mb-4"><?php echo t('wholesale.add_product'); ?></h3>

                            <!-- 도매 마진율 설정 -->
                            <div class="mb-4">
                                <label for="margin_rate" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-percent mr-1"></i>
                                    도매 마진율 (미등록 상품용)
                                </label>
                                <div class="flex gap-2 items-center">
                                    <input type="number" id="margin_rate"
                                           class="w-32 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                           value="15"
                                           min="0"
                                           max="100"
                                           step="0.1">
                                    <span class="text-sm text-gray-600">% (기본: 15%)</span>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    도매상품으로 등록되지 않은 상품의 판매가 계산에 사용됩니다. (원가 × (1 + 마진율/100))
                                </p>
                            </div>

                            <!-- 통합 상품 검색 (바코드/상품명) -->
                            <div>
                                <label for="product_search" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-search mr-1"></i>
                                    상품 검색 (바코드 / 상품명 / SKU)
                                </label>
                                <div class="flex gap-2">
                                    <div class="relative flex-1">
                                        <input type="text" id="product_search"
                                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                               placeholder="바코드 스캔 또는 상품명/SKU 검색..."
                                               autocomplete="off">
                                        <div id="product_search_results" class="absolute z-10 w-full bg-white border border-gray-300 rounded-md shadow-lg mt-1 max-h-96 overflow-y-auto hidden">
                                            <!-- 검색 결과가 여기에 표시됩니다 -->
                                        </div>
                                    </div>
                                    <button type="button" id="product_search_btn"
                                            class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 whitespace-nowrap">
                                        <i class="fas fa-search mr-1"></i>
                                        검색
                                    </button>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    엔터키로 바로 검색 가능 / 도매상품과 일반상품이 모두 표시됩니다
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- 장바구니 섹션 -->
                    <div class="mt-8">
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">
                                <i class="fas fa-shopping-cart mr-2 text-primary-500"></i>
                                <?php echo t('wholesale.cart'); ?>
                            </h3>
                            
                            <div id="cart_empty" class="text-center text-gray-500 py-8">
                                <i class="fas fa-shopping-cart text-4xl mb-4"></i>
                                <p><?php echo t('wholesale.cart_empty'); ?></p>
                                <p class="text-sm"><?php echo t('wholesale.search_add_products'); ?></p>
                            </div>
                            
                            <div id="cart_items" class="hidden">
                                <!-- 테이블 형태 장바구니 -->
                                <div class="border rounded-md overflow-hidden cart-table-wrapper">
                                    <table class="w-full cart-table">
                                        <thead class="bg-gray-100">
                                            <tr>
                                                <th class="px-2 py-3 text-left text-xs font-semibold text-gray-700"><?php echo t('product.sku'); ?></th>
                                                <th class="px-2 py-3 text-left text-xs font-semibold text-gray-700"><?php echo t('product.name'); ?></th>
                                                <th class="px-2 py-3 text-center text-xs font-semibold text-gray-700"><?php echo t('wholesale.box_packaging'); ?></th>
                                                <th class="px-2 py-3 text-center text-xs font-semibold text-gray-700">원가</th>
                                                <th class="px-2 py-3 text-center text-xs font-semibold text-gray-700">판매가</th>
                                                <th class="px-2 py-3 text-center text-xs font-semibold text-gray-700">도매판매가</th>
                                                <th class="px-2 py-3 text-center text-xs font-semibold text-gray-700"><?php echo t('wholesale.quantity'); ?></th>
                                                <th class="px-2 py-3 text-right text-xs font-semibold text-gray-700"><?php echo t('common.total'); ?></th>
                                                <th class="px-2 py-3 text-center text-xs font-semibold text-gray-700"><?php echo t('common.delete'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody id="cart_list">
                                            <!-- Cart items will be added here -->
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- 총 금액 -->
                                <div class="mt-4 pt-3 border-t border-gray-300">
                                    <div class="flex justify-between text-lg font-medium">
                                        <span>총 금액:</span>
                                        <span id="cart_total">0</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 저장 버튼 -->
                        <div class="mt-8 flex justify-center space-x-3">
                            <button type="submit" id="complete_sale_btn" 
                                    class="px-4 py-2 bg-green-500 text-white rounded-md hover:bg-green-600 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 disabled:bg-gray-400 disabled:hover:bg-gray-400 whitespace-nowrap"
                                    disabled>
                                <i class="fas fa-save mr-1"></i>
                                <?php echo $edit_mode ? '수정 완료' : '저장'; ?>
                            </button>
                            
                            <a href="<?php echo $edit_mode ? 'wholesale_sales_list.php' : 'wholesale_sales_list.php'; ?>" 
                               class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                                <i class="fas fa-times mr-1"></i>
                                취소
                            </a>
                        </div>
                    </div>
                    
                    <input type="hidden" name="cart_items" id="cart_items_input" value="">
                    <?php if ($edit_mode): ?>
                        <input type="hidden" name="edit_sale_id" value="<?php echo $edit_sale_id; ?>">
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
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

<!-- 상품 목록 모달 -->
<div id="product-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-h-[70vh] flex flex-col">
            <div class="flex items-center justify-between p-4 border-b border-gray-200">
                <h3 class="text-base font-medium text-gray-900">
                    <i class="fas fa-box mr-2 text-green-500"></i>
                    상품 목록
                </h3>
                <button type="button" id="close-product-modal" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>
            <div class="flex-1 overflow-hidden">
                <div class="p-3 border-b border-gray-200">
                    <input type="text" id="modal-product-search" 
                           class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                           placeholder="상품명이나 SKU로 필터링...">
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
                    상품 선택
                </h3>
                <button type="button" id="close-type-selection-modal" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div class="p-6">
                <p class="text-sm text-gray-600 mb-4">
                    이 상품은 도매상품으로 등록되어 있습니다. 어떤 가격으로 판매하시겠습니까?
                </p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- 도매상품 옵션 -->
                    <div id="wholesale-option" class="border-2 border-blue-500 rounded-lg p-4 cursor-pointer hover:bg-blue-50 transition">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="font-medium text-gray-900">도매 등록상품</h4>
                            <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded">추천</span>
                        </div>
                        <div class="text-sm text-gray-600 space-y-1">
                            <div>상품명: <span id="modal-wholesale-name" class="font-medium"></span></div>
                            <div>도매가: <span id="modal-wholesale-price" class="font-medium text-blue-600"></span>원</div>
                            <div class="text-xs text-gray-500 mt-2">등록된 도매가격으로 판매합니다</div>
                        </div>
                    </div>

                    <!-- 인벤토리 상품 옵션 -->
                    <div id="inventory-option" class="border-2 border-gray-300 rounded-lg p-4 cursor-pointer hover:bg-gray-50 transition">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="font-medium text-gray-900">인벤토리 상품</h4>
                            <span class="px-2 py-1 bg-gray-100 text-gray-600 text-xs rounded">일반</span>
                        </div>
                        <div class="text-sm text-gray-600 space-y-1">
                            <div>상품명: <span id="modal-inventory-name" class="font-medium"></span></div>
                            <div>원가: <span id="modal-inventory-cost" class="font-medium"></span>원</div>
                            <div>판매가: <span id="modal-inventory-price" class="font-medium text-green-600"></span>원</div>
                            <div class="text-xs text-gray-500 mt-2">원가에 마진율을 적용하여 판매합니다</div>
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
    no_image: '<?php echo addslashes(t("wholesale.js_no_image")); ?>'
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
            const query = this.value.trim();

            if (query.length === 0) {
                showProductModal();
                return;
            }

            // 바코드 스캔으로 추정되는 경우 (검색 결과가 없거나 정확히 일치하는 경우)
            // 먼저 바코드로 검색 시도
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
    
    // ESC 키로 모달 닫기
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            customerModal.classList.add('hidden');
            productModal.classList.add('hidden');
        }
    });
    
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
            body: 'q=' + encodeURIComponent(query) + '&limit=10'
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
                
                if (data.success && data.customers) {
                    console.log('고객 수:', data.customers.length);
                    displayCustomerResults(data.customers);
                } else {
                    console.log('검색 실패 또는 결과 없음:', data.message);
                    customerSearchResults.innerHTML = '<div class="p-3 text-sm text-gray-500">' + 
                        (data.message || '검색 결과가 없습니다.') + '</div>';
                    customerSearchResults.classList.remove('hidden');
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
            body: 'q=' + encodeURIComponent(query) + '&limit=10'
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
    
    function displayCustomerResults(customers) {
        let html = '';
        customers.forEach(function(customer) {
            html += `
                <div class="p-3 hover:bg-gray-100 cursor-pointer border-b border-gray-100 last:border-b-0 customer-item" 
                     data-id="${customer.id}" 
                     data-name="${customer.name}"
                     data-phone="${customer.phone || ''}"
                     data-address="${customer.address || ''}">
                    <div class="font-medium text-gray-900">${customer.name}</div>
                    <div class="text-sm text-gray-600">${customer.phone || ''} ${customer.address || ''}</div>
                </div>
            `;
        });
        
        customerSearchResults.innerHTML = html;
        customerSearchResults.classList.remove('hidden');
        
        // 거래처 선택 이벤트
        document.querySelectorAll('.customer-item').forEach(function(item) {
            item.addEventListener('click', function() {
                selectCustomer(this);
            });
        });
    }
    
    function displayProductResults(products) {
        let html = '';
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
                         data-selling-price="${product.selling_price || 0}"
                         data-registered="true">
                        <div class="font-medium text-gray-900">
                            ${product.display_name_en || product.display_name_ko || 'N/A'}
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 ml-2">도매상품</span>
                        </div>
                        <div class="text-sm text-gray-600">${product.display_name_ko && product.display_name_en && product.display_name_ko !== product.display_name_en ? product.display_name_ko : ''}</div>
                        <div class="text-xs text-gray-500 mt-1">
                            SKU: ${displaySkus} | 도매가: ${Number(product.wholesale_price).toLocaleString()}원 | 박스: ${product.min_quantity || 1}개
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
            productData.margin_rate = 0; // 등록 상품은 마진율 표시 안함
        } else {
            // 미등록 상품 - 제안가 사용
            const marginRateInput = document.getElementById('margin_rate');
            const marginRate = marginRateInput ? parseFloat(marginRateInput.value) : 15.0;

            productData.wholesale_price = parseFloat(item.dataset.suggestedPrice);
            productData.wholesale_price_piece = 0;
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
            showNotification('바코드를 입력해주세요.', 'error');
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
            showNotification('점포 정보가 필요합니다.', 'error');
            return;
        }

        // 마진율 가져오기
        const marginRateInput = document.getElementById('margin_rate');
        const marginRate = marginRateInput ? parseFloat(marginRateInput.value) : 15.0;

        // 마진율 유효성 검사
        if (marginRate < 0 || marginRate > 100) {
            showNotification('마진율은 0-100% 사이여야 합니다.', 'error');
            return;
        }

        // AJAX로 바코드 검색
        fetch(`ajax_get_wholesale_product_by_barcode.php?barcode=${encodeURIComponent(barcode)}&store_id=${storeId}&margin_rate=${marginRate}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const product = data.data;

                    // 장바구니에 추가
                    const existingIndex = cart.findIndex(item => item.product_id == product.product_id);

                    if (existingIndex >= 0) {
                        cart[existingIndex].quantity += 1;
                        cart[existingIndex].total_price = cart[existingIndex].quantity * cart[existingIndex].unit_price;
                        showNotification('수량이 증가되었습니다.', 'success');
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

                        // 미등록 상품인 경우 알림 메시지
                        if (!product.is_registered) {
                            showNotification(`미등록 상품입니다. 원가에 ${product.margin_rate}% 마진이 적용되었습니다.`, 'info');
                        } else {
                            showNotification('상품이 장바구니에 추가되었습니다.', 'success');
                        }
                    }

                    updateCart();
                    productSearch.value = ''; // 검색창 초기화
                    productSearch.focus(); // 포커스 다시 이동
                } else {
                    showNotification(data.message || '상품을 찾을 수 없습니다.', 'error');
                    productSearch.value = '';
                    productSearch.focus();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('바코드 검색 중 오류가 발생했습니다.', 'error');
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
            showNotification('점포 정보가 필요합니다.', 'error');
            return;
        }

        // 마진율 가져오기
        const marginRateInput = document.getElementById('margin_rate');
        const marginRate = marginRateInput ? parseFloat(marginRateInput.value) : 15.0;

        // 먼저 바코드로 검색 시도
        fetch(`ajax_get_wholesale_product_by_barcode.php?barcode=${encodeURIComponent(query)}&store_id=${storeId}&margin_rate=${marginRate}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 바코드로 상품을 찾았으면 처리
                    const product = data.data;

                    if (product.is_registered) {
                        // 도매상품으로 등록되어 있으면 모달 표시
                        addProductToCartDirect(product);
                    } else {
                        // 미등록 상품이면 바로 장바구니에 추가
                        addToCart(product, parseFloat(product.wholesale_price));
                    }

                    productSearch.value = '';
                    productSearch.focus();
                } else {
                    // 바코드로 찾지 못했으면 일반 검색 실행
                    searchProducts(query);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                // 에러 발생 시에도 일반 검색으로 fallback
                searchProducts(query);
            });
    }

    // 상품을 바로 장바구니에 추가하는 함수
    function addProductToCartDirect(product) {
        // 도매상품으로 등록되어 있고 (원가 또는 판매가) 정보가 있는 경우 모달 표시
        const costPrice = parseFloat(product.cost_price) || 0;
        const sellingPrice = parseFloat(product.selling_price) || 0;

        if (product.is_registered && (costPrice > 0 || sellingPrice > 0)) {
            showProductTypeSelectionModal(product);
            return;
        }

        // 그 외의 경우 바로 장바구니에 추가
        addToCart(product, product.wholesale_price);
    }

    // 실제 장바구니 추가 함수
    function addToCart(product, price) {
        const existingIndex = cart.findIndex(item => item.product_id == product.product_id);

        if (existingIndex >= 0) {
            cart[existingIndex].quantity += 1;
            cart[existingIndex].total_price = cart[existingIndex].quantity * cart[existingIndex].unit_price;
            showNotification('수량이 증가되었습니다.', 'success');
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
                cost_price: product.cost_price || 0,
                selling_price: product.selling_price || 0
            });

            // 미등록 상품인 경우 알림 메시지
            if (!product.is_registered) {
                showNotification(`미등록 상품입니다. 원가에 ${product.margin_rate}% 마진이 적용되었습니다.`, 'info');
            } else {
                showNotification('상품이 장바구니에 추가되었습니다.', 'success');
            }
        }

        updateCart();
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

                        <!-- 원가 -->
                        <td class="px-2 py-3 text-center">
                            <div class="text-sm text-gray-700">
                                ${item.cost_price ? Number(item.cost_price).toLocaleString() : '-'}
                            </div>
                        </td>

                        <!-- 판매가 -->
                        <td class="px-2 py-3 text-center">
                            <div class="text-sm text-gray-700">
                                ${item.selling_price ? Number(item.selling_price).toLocaleString() : '-'}
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
                                <span class="text-sm font-medium px-2 min-w-[24px] text-center">${item.quantity}</span>
                                <button type="button" onclick="updateQuantity(${index}, 1)" 
                                        class="w-6 h-6 text-xs bg-gray-200 hover:bg-gray-300 rounded flex items-center justify-center">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </td>
                        
                        <!-- 합계 -->
                        <td class="px-2 py-3 text-right">
                            <div class="text-sm font-semibold text-primary-600">
                                ${Number(item.total_price).toLocaleString()}
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
            cartTotal.textContent = Number(total).toLocaleString();
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

    window.updateUnitPrice = function(index, newPrice) {
        const price = parseFloat(newPrice) || 0;
        cart[index].unit_price = price;
        cart[index].total_price = cart[index].quantity * price;
        updateCart();
    };
    
    function updateSaleButton() {
        const hasCustomer = customerId.value !== '';
        const hasItems = cart.length > 0;
        completeSaleBtn.disabled = !hasCustomer || !hasItems;
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
            body: 'q=&limit=100&show_all=1' // 전체 목록 요청
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
        let html = '';
        customers.forEach(function(customer) {
            html += `
                <div class="modal-customer-item p-3 border border-gray-200 rounded-md hover:bg-blue-50 cursor-pointer transition-colors duration-200" 
                     data-id="${customer.id}" 
                     data-name="${customer.name}"
                     data-phone="${customer.phone || ''}"
                     data-address="${customer.address || ''}">
                    <div class="flex items-center justify-between">
                        <div class="flex-1 min-w-0">
                            <div class="font-medium text-gray-900 text-sm truncate">${customer.name}</div>
                            <div class="text-xs text-gray-600 mt-1 truncate">
                                <i class="fas fa-phone mr-1"></i>${customer.phone || translations.no_phone}
                            </div>
                            ${customer.address ? `<div class="text-xs text-gray-500 truncate mt-1">
                                <i class="fas fa-map-marker-alt mr-1"></i>${customer.address}
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
        
        // 클릭 이벤트 추가
        customerList.querySelectorAll('.modal-customer-item').forEach(function(item) {
            item.addEventListener('click', function() {
                selectCustomerFromModal(this);
            });
        });
    }
    
    // 모달 상품 목록 표시
    function displayModalProductList(products) {
        let html = '';
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
                                <span class="text-green-600 font-medium"><i class="fas fa-won-sign mr-1"></i>${Number(product.wholesale_price).toLocaleString()}원</span>
                                <span><i class="fas fa-box mr-1"></i>${translations.minimum_prefix}${product.min_quantity || 1}</span>
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
            const costPrice = parseFloat(item.dataset.costPrice) || 0;
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
                cost_price: costPrice,
                selling_price: sellingPrice
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
            
            // 선택된 거래처 표시 (있는 경우)
            if (selectedCustomer && editData.customer_name) {
                selectedCustomer.innerHTML = `
                    <div class="flex items-center justify-between p-3 bg-blue-50 border border-blue-200 rounded-md">
                        <div class="flex-1">
                            <div class="font-medium text-blue-900">${editData.customer_name}</div>
                            <div class="text-sm text-blue-700">
                                ${editData.customer_phone || ''} ${editData.customer_address || ''}
                            </div>
                        </div>
                        <button type="button" id="clear_customer_selection" class="text-blue-500 hover:text-blue-700">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                `;
                selectedCustomer.classList.remove('hidden');
                
                // 거래처 삭제 버튼 이벤트 재등록
                const newClearBtn = document.getElementById('clear_customer_selection');
                if (newClearBtn) {
                    newClearBtn.addEventListener('click', function() {
                        customerId.value = '';
                        customerSearch.value = '';
                        selectedCustomer.classList.add('hidden');
                        updateCompleteSaleBtn();
                    });
                }
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
                cart.push({
                    product_id: item.product_id,
                    sku: item.sku,
                    name_ko: item.name_ko,
                    name_en: item.name_en,
                    unit_price: parseFloat(item.unit_price),
                    quantity: parseInt(item.quantity),
                    total_price: parseFloat(item.total_price),
                    min_quantity: parseInt(item.min_quantity) || 1, // 기존 데이터에서 박스포장수량 로드
                    wholesale_price: parseFloat(item.wholesale_price) || 0, // 박스 판매가
                    wholesale_price_piece: parseFloat(item.wholesale_price_piece) || 0, // 낱개 판매가
                    cost_price: parseFloat(item.cost_price) || 0,
                    selling_price: parseFloat(item.selling_price) || 0
                });
            });
            
            updateCart();
        }
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
                showNotification('도매상품으로 등록되었습니다!', 'success');
                
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
            showNotification('등록 중 오류가 발생했습니다.', 'error');
            
            // 버튼 복원
            button.disabled = false;
            button.innerHTML = originalText;
        });
    };

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
/* 모달 스타일링 */
.modal-customer-item, .modal-product-item {
    transition: all 0.2s ease-in-out;
}

.modal-customer-item:hover, .modal-product-item:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

/* 모달 애니메이션 */
#customer-modal, #product-modal {
    animation: fadeIn 0.2s ease-in-out;
}

#customer-modal > div, #product-modal > div {
    animation: slideIn 0.2s ease-in-out;
}

@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* 검색 버튼 호버 효과 */
#customer_search_btn, #product_search_btn {
    transition: all 0.2s ease-in-out;
}

#customer_search_btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(59, 130, 246, 0.3);
}

#product_search_btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(34, 197, 94, 0.3);
}

/* 모달 스크롤바 커스터마이징 */
#customer-list::-webkit-scrollbar,
#product-list::-webkit-scrollbar {
    width: 6px;
}

#customer-list::-webkit-scrollbar-track,
#product-list::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

#customer-list::-webkit-scrollbar-thumb,
#product-list::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}

#customer-list::-webkit-scrollbar-thumb:hover,
#product-list::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

/* 모바일 반응형 */
@media (max-width: 640px) {
    #customer-modal > div, #product-modal > div {
        margin: 1rem;
        max-height: calc(100vh - 2rem);
        max-width: calc(100vw - 2rem);
    }
    
    #customer-modal .max-w-2xl,
    #product-modal .max-w-3xl {
        max-width: calc(100vw - 2rem);
    }
    
    .modal-customer-item,
    .modal-product-item {
        padding: 0.75rem;
    }
    
    .modal-customer-item .text-lg, .modal-product-item .text-lg {
        font-size: 1rem;
    }
    
    .modal-product-item .flex.items-center.space-x-4 {
        flex-direction: column;
        align-items: flex-start;
        space-x: 0;
        gap: 0.5rem;
    }
}

/* 점포/날짜 그리드 반응형 개선 */
@media (max-width: 1024px) {
    .grid.grid-cols-1.lg\\:grid-cols-3 {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
}

/* 태블릿에서 2열 배치 */
@media (min-width: 768px) and (max-width: 1023px) {
    .grid.grid-cols-1.lg\\:grid-cols-3 {
        grid-template-columns: 1fr 1fr;
    }
    
    .grid.grid-cols-1.lg\\:grid-cols-3 > div:nth-child(3) {
        grid-column: 1 / -1;
    }
}


/* 모바일 최적화 */
@media (max-width: 640px) {
    #customer_search_btn {
        padding: 0.5rem 0.75rem;
        min-width: 44px;
    }
    
    /* 장바구니 테이블 모바일 최적화 */
    .cart-table {
        font-size: 0.75rem; /* 12px */
    }
    
    .cart-table th,
    .cart-table td {
        padding: 0.375rem 0.25rem; /* 6px 4px */
    }
    
    /* 비고 입력란 모바일 최적화 */
    .cart-table td input[type="text"] {
        min-width: 80px;
        font-size: 0.75rem;
        padding: 0.25rem;
    }
    
    /* 단가 입력란 모바일 최적화 */
    .cart-table td input[type="number"] {
        min-width: 80px;
        font-size: 0.75rem;
        padding: 0.25rem;
    }
    
    /* 상품명 컬럼 최적화 */
    .cart-table .product-name {
        min-width: 120px;
    }
    
    .cart-table .product-name div {
        font-size: 0.6875rem; /* 11px */
        line-height: 1.2;
    }
    
    /* SKU 컬럼 최적화 */
    .cart-table .sku-column {
        font-size: 0.625rem; /* 10px */
        min-width: 60px;
    }
    
    /* 수량 버튼 최적화 */
    .cart-table .quantity-controls button {
        width: 1.25rem; /* 20px */
        height: 1.25rem; /* 20px */
        font-size: 0.625rem; /* 10px */
    }
    
    .cart-table .quantity-controls span {
        font-size: 0.6875rem; /* 11px */
        min-width: 20px;
        padding: 0 0.25rem;
    }
}

/* 태블릿 최적화 */
@media (min-width: 641px) and (max-width: 768px) {
    .cart-table th,
    .cart-table td {
        padding: 0.5rem 0.375rem; /* 8px 6px */
    }
    
    .cart-table td input[type="text"] {
        min-width: 100px;
    }
    
    .cart-table td input[type="number"] {
        min-width: 100px;
    }
}

/* 장바구니 테이블 가로 스크롤 */
@media (max-width: 768px) {
    .cart-table-wrapper {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    .cart-table {
        min-width: 900px; /* 박스포장수량, 판매단위 컬럼 추가로 인한 너비 증가 */
    }
}

/* 라디오 버튼 스타일링 */
.sale-unit-btn {
    @apply flex items-center space-x-1 px-2 py-1 rounded-lg border-2 cursor-pointer transition-all duration-200 text-xs;
    min-width: 50px;
    justify-content: center;
}

.sale-unit-btn-active {
    @apply bg-primary-100 border-primary-500 text-primary-700;
    box-shadow: 0 2px 4px rgba(59, 130, 246, 0.2);
    transform: translateY(-1px);
}

.sale-unit-btn-inactive {
    @apply bg-gray-50 border-gray-300 text-gray-600;
}

.sale-unit-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
}

.sale-unit-btn-inactive:hover {
    @apply bg-gray-100 border-gray-400 text-gray-700;
}

/* 모바일에서 라디오 버튼 최적화 */
@media (max-width: 640px) {
    .sale-unit-btn {
        min-width: 45px;
        padding: 0.25rem 0.5rem;
    }
    
    .sale-unit-btn span {
        font-size: 0.7rem;
    }
    
    .sale-unit-btn i {
        font-size: 0.6rem;
    }
}
</style>

<?php require_once __DIR__ . '/partials/footer.php'; ?>