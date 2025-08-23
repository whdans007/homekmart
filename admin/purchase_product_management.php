<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('purchase_product.management') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 매입관리 권한 확인
if (!has_permission('purchase_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

$conn = get_db_connection();

// 날짜 변수 - 기본적으로 최근 7일간의 데이터 표시
$display_mode = $_GET['mode'] ?? 'recent'; // 'recent' 또는 'date'
$selected_date = $_GET['date'] ?? date('Y-m-d');
$prev_date = date('Y-m-d', strtotime($selected_date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($selected_date . ' +1 day'));

// 최근 데이터 조회를 위한 날짜 범위
$recent_days = 7; // 최근 7일
$start_date = date('Y-m-d', strtotime("-$recent_days days"));
$end_date = date('Y-m-d');

// 현재 사용자의 점포 정보 가져오기
$current_store_name = t('store.main_store');
$current_store_id = null;
if (!empty($_SESSION['user_id'])) {
    try {
        $user_stmt = $conn->prepare("SELECT s.name as store_name, s.id as store_id FROM users u LEFT JOIN stores s ON u.store_id = s.id WHERE u.id = ?");
        $user_stmt->bind_param("i", $_SESSION['user_id']);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        if ($user_row = $user_result->fetch_assoc()) {
            $current_store_name = $user_row['store_name'] ?? t('store.main_store');
            $current_store_id = $user_row['store_id'];
        }
        $user_stmt->close();
    } catch (Exception $e) {
        error_log("Store info error: " . $e->getMessage());
    }
}

// 선택된 날짜의 매입 상품 조회
$purchase_products = [];

try {
    // deleted_at 컬럼 존재 여부 확인
    $check_deleted_at = $conn->query("SHOW COLUMNS FROM purchases LIKE 'deleted_at'");
    $has_deleted_at = $check_deleted_at->num_rows > 0;
    
    $deleted_condition = $has_deleted_at ? "AND p.deleted_at IS NULL" : "";
    
    // SQL 쿼리 구성 - 표시 모드에 따라 조건 변경
    if ($display_mode === 'recent') {
        $where_condition = "DATE(p.purchase_date) BETWEEN ? AND ?";
        $order_clause = "ORDER BY pr.id DESC, p.purchase_date DESC";
    } else {
        $where_condition = "DATE(p.purchase_date) = ?";
        $order_clause = "ORDER BY pr.id DESC, p.purchase_date DESC";
    }
    
    $sql = "
        SELECT 
            pr.id as product_id,
            pr.sku,
            pr.name_en,
            pr.name_ko,
            pr.pieces_per_box,
            pi.quantity,
            pi.unit_price,
            pi.purchase_type,
            pi.discount_rate,
            pi.discounted_total,
            COALESCE(pi.discounted_total, pi.quantity * pi.unit_price) as total_amount,
            s.name as supplier_name,
            p.purchase_date,
            pi.item_id as purchase_item_id,
            p.purchase_id
        FROM purchase_items pi
        JOIN purchases p ON pi.purchase_id = p.purchase_id
        JOIN products pr ON pi.product_id = pr.id
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        WHERE $where_condition 
        $deleted_condition
        $order_clause
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    // 바인딩 파라미터 설정
    if ($display_mode === 'recent') {
        $stmt->bind_param("ss", $start_date, $end_date);
    } else {
        $stmt->bind_param("s", $selected_date);
    }
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        // 낱개단가 계산
        $piece_price = 0;
        if ($row['purchase_type'] === 'box' && $row['pieces_per_box'] > 0) {
            $piece_price = $row['unit_price'] / $row['pieces_per_box'];
        } elseif ($row['purchase_type'] === 'piece') {
            $piece_price = $row['unit_price'];
        }
        
        $row['piece_price'] = $piece_price;
        $purchase_products[] = $row;
    }
    
    $stmt->close();
} catch (Exception $e) {
    error_log("Purchase products query error: " . $e->getMessage());
}

$conn->close();
?>

<div class="container mx-auto px-2 py-4">
    <div class="flex justify-between items-center mb-3">
        <div>
            <h1 class="text-lg font-bold text-gray-900"><?php echo t('purchase_product.management'); ?></h1>
            <p class="text-xs text-gray-600"><?php echo t('purchase_product.description'); ?></p>
        </div>
    </div>

    <!-- 표시 모드 및 날짜 네비게이션 -->
    <div class="bg-white rounded shadow mb-3">
        <div class="px-3 py-2">
            <!-- 표시 모드 선택 -->
            <div class="flex items-center justify-center space-x-4 mb-3">
                <div class="flex items-center space-x-2">
                    <span class="text-sm text-gray-600"><?php echo t('purchase_product.display_mode'); ?>:</span>
                    <a href="?mode=recent" class="px-3 py-1 text-xs rounded-full <?php echo $display_mode === 'recent' ? 'bg-blue-100 text-blue-800 font-medium' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                        <i class="fas fa-calendar-week mr-1"></i>
                        <?php echo t('purchase_product.recent_7_days'); ?>
                    </a>
                    <a href="?mode=date&date=<?php echo $selected_date; ?>" class="px-3 py-1 text-xs rounded-full <?php echo $display_mode === 'date' ? 'bg-blue-100 text-blue-800 font-medium' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                        <i class="fas fa-calendar-day mr-1"></i>
                        <?php echo t('common.date'); ?>
                    </a>
                </div>
            </div>
            
            <!-- 날짜 정보 표시 -->
            <?php if ($display_mode === 'recent'): ?>
            <div class="text-center">
                <div class="text-sm font-semibold text-gray-900">
                    <i class="fas fa-calendar-week text-blue-600 mr-2"></i>
                    <?php echo t('purchase_product.recent_purchase_history'); ?> (<?php echo date('Y.m.d', strtotime($start_date)); ?> ~ <?php echo date('Y.m.d', strtotime($end_date)); ?>)
                </div>
                <div class="text-xs text-gray-600 mt-1">
                    <?php echo t('purchase_product.recent_7_days'); ?>
                </div>
            </div>
            <?php else: ?>
            <div class="flex items-center justify-center space-x-2">
                <a href="?mode=date&date=<?php echo $prev_date; ?>" class="inline-flex items-center px-2 py-1 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                    <i class="fas fa-chevron-left mr-1"></i>
                    <?php echo t('common.previous'); ?>
                </a>
                
                <div class="flex items-center space-x-2">
                    <input type="date" id="date-picker" value="<?php echo $selected_date; ?>" 
                           class="border border-gray-300 rounded px-2 py-1 text-xs"
                           onchange="window.location.href='?mode=date&date=' + this.value;">
                    <span class="text-sm font-semibold text-gray-900">
                        <?php echo date('Y년 m월 d일 (l)', strtotime($selected_date)); ?>
                    </span>
                </div>
                
                <a href="?mode=date&date=<?php echo $next_date; ?>" class="inline-flex items-center px-2 py-1 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                    <?php echo t('common.next'); ?>
                    <i class="fas fa-chevron-right ml-1"></i>
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>


    <!-- 상품 목록 테이블 -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg leading-6 font-medium text-gray-900">
                <?php echo t('purchase_product.product_list'); ?>
                <span class="text-sm text-gray-500 ml-2">(<?php echo count($purchase_products); ?><?php echo t('common.items'); ?>)</span>
            </h3>
        </div>
        
        <?php if (empty($purchase_products)): ?>
        <div class="px-6 py-12 text-center">
            <i class="fas fa-inbox text-gray-400 text-4xl mb-4"></i>
            <p class="text-gray-500"><?php echo t('purchase_product.no_data'); ?></p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-xs">
                <thead class="bg-gray-50">
                    <tr>
                        <?php if ($display_mode === 'recent'): ?>
                        <th scope="col" class="px-2 py-1 text-left text-xs font-medium text-gray-500 uppercase">
                            매입일
                        </th>
                        <?php endif; ?>
                        <th scope="col" class="px-2 py-1 text-left text-xs font-medium text-gray-500 uppercase">
                            <?php echo t('product.sku'); ?>
                        </th>
                        <th scope="col" class="px-2 py-1 text-left text-xs font-medium text-gray-500 uppercase">
                            상품명
                        </th>
                        <th scope="col" class="px-2 py-1 text-right text-xs font-medium text-gray-500 uppercase">
                            <?php echo t('purchase.quantity'); ?>
                        </th>
                        <th scope="col" class="px-2 py-1 text-right text-xs font-medium text-gray-500 uppercase">
                            <?php echo t('purchase.unit_price'); ?>
                        </th>
                        <th scope="col" class="px-2 py-1 text-right text-xs font-medium text-gray-500 uppercase">
                            <?php echo t('purchase_product.piece_price'); ?>
                        </th>
                        <th scope="col" class="px-2 py-1 text-right text-xs font-medium text-gray-500 uppercase">
                            <?php echo t('purchase_product.stock_quantity'); ?>
                        </th>
                        <th scope="col" class="px-2 py-1 text-right text-xs font-medium text-gray-500 uppercase">
                            <?php echo t('purchase.total_amount'); ?>
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($purchase_products as $product): ?>
                    <tr class="hover:bg-gray-50 cursor-pointer" onclick="showProductDetails(<?php echo $product['product_id']; ?>)">
                        <?php if ($display_mode === 'recent'): ?>
                        <td class="px-2 py-1 whitespace-nowrap text-xs text-gray-900">
                            <div class="text-gray-800"><?php echo date('m.d', strtotime($product['purchase_date'])); ?></div>
                            <div class="text-gray-500 text-xs"><?php echo date('D', strtotime($product['purchase_date'])); ?></div>
                        </td>
                        <?php endif; ?>
                        <td class="px-2 py-1 whitespace-nowrap text-xs font-medium text-gray-900">
                            <?php echo htmlspecialchars($product['sku']); ?>
                        </td>
                        <td class="px-2 py-1 text-xs text-gray-900">
                            <div class="text-gray-800"><?php echo htmlspecialchars($product['name_en']); ?></div>
                            <div class="text-gray-600"><?php echo htmlspecialchars($product['name_ko']); ?></div>
                        </td>
                        <td class="px-2 py-1 whitespace-nowrap text-xs text-gray-900 text-right">
                            <?php echo number_format($product['quantity']); ?>
                        </td>
                        <td class="px-2 py-1 whitespace-nowrap text-xs text-gray-900 text-right">
                            <?php echo number_format($product['unit_price'], 2); ?>
                        </td>
                        <td class="px-2 py-1 whitespace-nowrap text-xs text-gray-900 text-right">
                            <?php echo number_format($product['piece_price'], 2); ?>
                        </td>
                        <td class="px-2 py-1 whitespace-nowrap text-xs text-gray-900 text-right">
                            <?php
                            $total_pieces = $product['purchase_type'] === 'box' ? 
                                $product['quantity'] * $product['pieces_per_box'] : 
                                $product['quantity'];
                            echo number_format($total_pieces);
                            ?>
                        </td>
                        <td class="px-2 py-1 whitespace-nowrap text-xs text-gray-900 text-right font-medium">
                            <?php echo number_format($product['total_amount'], 2); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-gray-50">
                    <tr>
                        <td colspan="<?php echo $display_mode === 'recent' ? '7' : '6'; ?>" class="px-2 py-1 text-right text-xs font-medium text-gray-900">
                            <?php echo t('common.total'); ?>:
                        </td>
                        <td class="px-2 py-1 text-right text-xs font-bold text-gray-900">
                            <?php echo number_format(array_sum(array_column($purchase_products, 'total_amount')), 2); ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 상품 상세 정보 Modal -->
<div id="product-details-modal" class="fixed inset-0 bg-gray-900 bg-opacity-75 overflow-y-auto h-full w-full hidden z-50 flex items-center justify-center p-4">
    <div class="relative w-full max-w-3xl max-h-[90vh] bg-white rounded-lg shadow-xl flex flex-col">
        <!-- Modal Header -->
        <div class="flex justify-between items-center p-4 border-b rounded-t-lg">
            <div>
                <h3 class="text-xl font-semibold text-gray-800" id="modal-product-name"><?php echo t('product.details'); ?></h3>
                <div class="flex items-center space-x-1 text-sm text-blue-600 mt-1">
                    <i class="fas fa-store"></i>
                    <span><?php echo str_replace('{store}', htmlspecialchars($current_store_name), t('product.store_based')); ?></span>
                </div>
            </div>
            <button id="close-modal-btn" class="text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm p-1.5 ml-auto inline-flex items-center">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <!-- Modal Body -->
        <div class="p-6 flex-grow overflow-y-auto">
            <!-- 로딩 스피너 -->
            <div id="modal-loading" class="text-center py-20">
                <i class="fas fa-spinner fa-spin text-4xl text-primary-600"></i>
                <p class="mt-3 text-gray-500"><?php echo t('product.loading_info'); ?></p>
            </div>
            
            <!-- 상세 정보 전체 래퍼 -->
            <div id="modal-body-wrapper" class="hidden">
                <!-- Basic Details Table -->
                <h4 class="text-lg font-semibold text-gray-800 mb-2"><?php echo t('product.basic_info'); ?></h4>
                <table class="w-full text-sm text-left text-gray-600 mb-6">
                    <tbody>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50 w-1/3"><?php echo t('product.name_en'); ?></td>
                            <td class="px-4 py-2 relative" id="modal-name-en-container">
                                <div id="modal-name-en-display" class="flex items-center justify-between">
                                    <span id="modal-name-en"></span>
                                    <button id="edit-name-en-btn" class="ml-2 px-2 py-1 text-xs bg-blue-100 text-blue-700 rounded hover:bg-blue-200">
                                        <i class="fas fa-edit mr-1"></i>수정
                                    </button>
                                </div>
                                <div id="modal-name-en-edit" class="hidden flex items-center space-x-2">
                                    <input type="text" id="modal-name-en-input" class="flex-1 px-2 py-1 text-sm border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500" maxlength="255">
                                    <button id="save-name-en-btn" class="px-2 py-1 text-xs bg-green-600 text-white rounded hover:bg-green-700">
                                        <i class="fas fa-save mr-1"></i>저장
                                    </button>
                                    <button id="cancel-name-en-btn" class="px-2 py-1 text-xs bg-gray-500 text-white rounded hover:bg-gray-600">
                                        <i class="fas fa-times mr-1"></i>취소
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50 w-1/3"><?php echo t('product.name_ko'); ?></td>
                            <td class="px-4 py-2 relative" id="modal-name-ko-container">
                                <div id="modal-name-ko-display" class="flex items-center justify-between">
                                    <span id="modal-name-ko"></span>
                                    <button id="edit-name-ko-btn" class="ml-2 px-2 py-1 text-xs bg-blue-100 text-blue-700 rounded hover:bg-blue-200">
                                        <i class="fas fa-edit mr-1"></i>수정
                                    </button>
                                </div>
                                <div id="modal-name-ko-edit" class="hidden flex items-center space-x-2">
                                    <input type="text" id="modal-name-ko-input" class="flex-1 px-2 py-1 text-sm border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500" maxlength="255">
                                    <button id="save-name-ko-btn" class="px-2 py-1 text-xs bg-green-600 text-white rounded hover:bg-green-700">
                                        <i class="fas fa-save mr-1"></i>저장
                                    </button>
                                    <button id="cancel-name-ko-btn" class="px-2 py-1 text-xs bg-gray-500 text-white rounded hover:bg-gray-600">
                                        <i class="fas fa-times mr-1"></i>취소
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50">SKU</td>
                            <td class="px-4 py-2 font-mono" id="modal-sku"></td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50"><?php echo t('product.barcode'); ?></td>
                            <td class="px-4 py-2 font-mono" id="modal-barcode"></td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50"><?php echo t('product.brand'); ?></td>
                            <td class="px-4 py-2" id="modal-brand"></td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50"><?php echo t('product.category'); ?></td>
                            <td class="px-4 py-2" id="modal-category"></td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50"><?php echo t('product.box_packaging'); ?></td>
                            <td class="px-4 py-2" id="modal-box-info"></td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50 align-top"><?php echo t('product.description'); ?></td>
                            <td class="px-4 py-2 whitespace-pre-wrap" id="modal-description"></td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50"><?php echo t('product.status'); ?></td>
                            <td class="px-4 py-2" id="modal-status"></td>
                        </tr>
                        <tr>
                            <td class="px-4 py-2 font-semibold bg-gray-50"><?php echo t('product.last_modified'); ?></td>
                            <td class="px-4 py-2" id="modal-last-modified"></td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pricing by Store -->
                <h4 class="text-lg font-semibold text-gray-800 mb-2"><?php echo t('product.inventory_pricing'); ?></h4>
                <div id="modal-inventory-wrapper">
                    <!-- JS will populate this -->
                </div>

                <!-- Purchase History Section -->
                <h4 class="text-lg font-semibold text-gray-800 mb-2 mt-6"><?php echo t('product.recent_purchase_history'); ?></h4>
                <div id="modal-purchase-history-wrapper" class="mb-4">
                    <div id="purchase-history-loading" class="text-center py-4">
                        <i class="fas fa-spinner fa-spin text-primary-600"></i>
                        <span class="ml-2 text-gray-500"><?php echo t('product.loading_info'); ?></span>
                    </div>
                    <div id="purchase-history-content" class="hidden">
                        <div class="bg-yellow-50 border border-yellow-200 rounded-md p-3 mb-3">
                            <p class="text-sm text-yellow-800">
                                <i class="fas fa-info-circle mr-1"></i>
                                <?php echo t('product.purchase_history_info'); ?>
                            </p>
                        </div>
                        <table id="purchase-history-table" class="w-full text-sm border border-gray-200 rounded-md">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2 text-left font-semibold text-gray-700"><?php echo t('product.purchase_date'); ?></th>
                                    <th class="px-3 py-2 text-left font-semibold text-gray-700"><?php echo t('product.supplier'); ?></th>
                                    <th class="px-3 py-2 text-left font-semibold text-gray-700"><?php echo t('price_change.store'); ?></th>
                                    <th class="px-3 py-2 text-right font-semibold text-gray-700"><?php echo t('product.box_cost'); ?></th>
                                    <th class="px-3 py-2 text-right font-semibold text-gray-700"><?php echo t('product.unit_cost'); ?></th>
                                    <th class="px-3 py-2 text-center font-semibold text-gray-700"><?php echo t('product.select'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="purchase-history-tbody">
                                <!-- Purchase history will be dynamically added here -->
                            </tbody>
                        </table>
                        <div id="no-purchase-history" class="text-center py-4 text-gray-500 hidden">
                            <i class="fas fa-exclamation-circle text-2xl"></i>
                            <p class="mt-2"><?php echo t('product.no_purchase_history'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Pricing Update Section -->
                <div id="modal-pricing-section" class="bg-blue-50 border border-blue-200 rounded-md p-4 mb-4 hidden">
                    <h5 class="font-semibold text-blue-800 mb-3">
                        <i class="fas fa-tag mr-1"></i>
                        <?php echo t('purchase_product.selling_price_setup'); ?>
                    </h5>
                    
                    <!-- 가로 한줄 레이아웃 -->
                    <div class="flex items-end space-x-4 mb-3">
                        <!-- 원가 정보 -->
                        <div class="flex-shrink-0">
                            <label class="block text-xs font-medium text-blue-700 mb-1">선택된 원가</label>
                            <div id="selected-cost-display" class="text-sm font-bold text-blue-900 bg-white px-2 py-1 rounded border">0</div>
                        </div>
                        
                        <!-- 마진율 선택 -->
                        <div class="flex-grow">
                            <div class="flex justify-between items-center mb-1">
                                <label class="block text-xs font-medium text-blue-700"><?php echo t('purchase_product.margin_rate_selection'); ?></label>
                                <button id="configure-presets-btn" class="text-xs text-blue-600 hover:text-blue-800">
                                    <i class="fas fa-cog mr-1"></i><?php echo t('purchase_product.setting'); ?>
                                </button>
                            </div>
                            <div class="flex items-center space-x-1">
                                <div id="margin-presets-container" class="flex space-x-1">
                                    <!-- 동적으로 생성될 마진율 버튼들 -->
                                </div>
                                <input type="number" id="custom-margin-input" 
                                       class="w-16 px-1 py-1 text-xs border border-blue-300 rounded-md focus:ring-blue-500 focus:border-blue-500" 
                                       placeholder="직접입력" min="0" max="100" step="0.1">
                                <span class="text-xs text-gray-600">%</span>
                                <button id="apply-custom-margin-btn" class="px-2 py-1 text-xs bg-gray-100 text-gray-700 rounded hover:bg-gray-200">적용</button>
                            </div>
                        </div>
                        
                        <!-- 계산된 판매가 -->
                        <div class="flex-shrink-0">
                            <label class="block text-xs font-medium text-blue-700 mb-1">계산된 판매가</label>
                            <div class="flex items-center space-x-2">
                                <input type="number" id="new-selling-price" 
                                       class="w-24 px-2 py-1 text-sm border border-blue-300 rounded-md focus:ring-blue-500 focus:border-blue-500" 
                                       placeholder="판매가">
                                <button id="apply-selling-price-btn" 
                                        class="px-3 py-1 text-sm bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:ring-2 focus:ring-blue-500">
                                    적용
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 마진 정보 표시 -->
                    <div class="flex justify-between items-center text-xs">
                        <div class="flex space-x-4">
                            <span class="text-blue-600">
                                <i class="fas fa-calculator mr-1"></i>
                                실제 마진율: <span id="margin-rate">0%</span>
                            </span>
                            <span class="text-gray-500">
                                <i class="fas fa-info-circle mr-1"></i>
                                권장 마진: <span id="recommended-margin-rate">30%</span>
                            </span>
                        </div>
                        <button id="cancel-pricing-btn" class="text-blue-600 hover:text-blue-800">
                            취소
                        </button>
                    </div>
                </div>

                <!-- Image at the bottom -->
                <div id="modal-image-content" class="mt-6 flex justify-start items-center">
                    <img id="modal-image" src="" alt="상품 이미지" class="w-[30px] h-[30px] rounded-md border bg-gray-100 object-contain">
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Margin Rate Preset Setting Modal -->
<div id="preset-config-modal" class="fixed inset-0 bg-gray-900 bg-opacity-75 overflow-y-auto h-full w-full hidden z-50 flex items-center justify-center p-4">
    <div class="relative w-full max-w-md bg-white rounded-lg shadow-xl">
        <!-- Modal Header -->
        <div class="flex justify-between items-center p-4 border-b rounded-t-lg">
            <h3 class="text-lg font-semibold text-gray-800"><?php echo t('product.margin_preset_setup'); ?></h3>
            <button id="close-preset-modal-btn" class="text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm p-1.5">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Modal Body -->
        <div class="p-4">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <?php echo t('product.margin_presets_desc'); ?>
                </label>
                <input type="text" id="presets-input" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500" 
                       placeholder="<?php echo t('product.margin_presets_placeholder'); ?>">
                <p class="mt-1 text-xs text-gray-500">
                    <?php echo t('product.margin_presets_hint'); ?>
                </p>
            </div>
            
            <div class="flex justify-end space-x-3">
                <button id="cancel-preset-btn" class="px-4 py-2 text-sm text-gray-600 border border-gray-300 rounded-md hover:bg-gray-50">
                    <?php echo t('product.cancel'); ?>
                </button>
                <button id="save-preset-btn" class="px-4 py-2 text-sm text-white bg-blue-600 rounded-md hover:bg-blue-700">
                    <?php echo t('product.save'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Translation strings for JavaScript
    const translations = {
        productDetails: <?php echo json_encode(t('product.js_product_details_title')); ?>,
        noDescription: <?php echo json_encode(t('product.js_no_description')); ?>,
        noBarcode: <?php echo json_encode(t('product.js_no_barcode')); ?>,
        piecesPerBox: <?php echo json_encode(t('product.js_pieces_per_box')); ?>,
        activeStatus: <?php echo json_encode(t('product.js_active_status')); ?>,
        inactiveStatus: <?php echo json_encode(t('product.js_inactive_status')); ?>,
        lastModified: <?php echo json_encode(t('product.js_last_modified')); ?>,
        errorLoadProduct: <?php echo json_encode(t('product.js_error_load_product')); ?>,
        errorLoadPurchase: <?php echo json_encode(t('product.js_error_load_purchase')); ?>,
        noPurchaseHistory: <?php echo json_encode(t('product.js_no_purchase_history_msg')); ?>,
        errorApi: <?php echo json_encode(t('product.js_error_api')); ?>,
        selectPurchaseFirst: <?php echo json_encode(t('product.js_select_purchase_first')); ?>,
        enterValidPrice: <?php echo json_encode(t('product.js_enter_valid_price')); ?>,
        lowMarginWarning: <?php echo json_encode(t('product.js_low_margin_warning')); ?>,
        enterMarginRate: <?php echo json_encode(t('product.js_enter_margin_rate')); ?>,
        selectedText: <?php echo json_encode(t('product.js_selected_text')); ?>,
        selectText: <?php echo json_encode(t('product.js_select_text')); ?>,
        savingText: <?php echo json_encode(t('product.js_saving_text')); ?>,
        noPricingInfo: <?php echo json_encode(t('product.js_no_pricing_info')); ?>,
        noInventoryInfo: <?php echo json_encode(t('product.js_no_inventory_info')); ?>,
        deleteConfirm: <?php echo json_encode(t('product.js_delete_confirm')); ?>,
        deleteConfirmInventory: <?php echo json_encode(t('product.js_delete_confirm_inventory')); ?>,
        errorMarginRange: <?php echo json_encode(t('product.error_margin_range')); ?>,
        errorSavePresets: <?php echo json_encode(t('product.js_error_margin_presets')); ?>,
        store: <?php echo json_encode(t('price_change.store')); ?>,
        inventory: <?php echo json_encode(t('product.inventory_info')); ?>,
        invalidMargin: <?php echo json_encode(t('purchase_product.js_invalid_margin')); ?>,
        invalidPrice: <?php echo json_encode(t('purchase_product.js_invalid_price')); ?>,
        selectCost: <?php echo json_encode(t('purchase_product.js_select_cost')); ?>,
        priceUpdated: <?php echo json_encode(t('purchase_product.js_price_updated')); ?>,
        noImage: <?php echo json_encode(t('purchase_product.js_no_image')); ?>,
        directInput: <?php echo json_encode(t('purchase_product.js_direct_input')); ?>,
        applyMargin: <?php echo json_encode(t('purchase_product.js_apply_margin')); ?>
    };
    
    // 현재 점포 정보
    const currentStoreId = <?php echo json_encode($current_store_id); ?>;
    const currentStoreName = <?php echo json_encode($current_store_name); ?>;
    
    const modal = document.getElementById('product-details-modal');
    const closeModalBtn = document.getElementById('close-modal-btn');
    
    const modalContent = {
        name: document.getElementById('modal-product-name'),
        nameEn: document.getElementById('modal-name-en'),
        nameKo: document.getElementById('modal-name-ko'),
        sku: document.getElementById('modal-sku'),
        barcode: document.getElementById('modal-barcode'),
        description: document.getElementById('modal-description'),
        brand: document.getElementById('modal-brand'),
        category: document.getElementById('modal-category'),
        boxInfo: document.getElementById('modal-box-info'),
        inventoryWrapper: document.getElementById('modal-inventory-wrapper'),
        totalStock: document.getElementById('modal-total-stock'),
        image: document.getElementById('modal-image'),
        status: document.getElementById('modal-status'),
        lastModified: document.getElementById('modal-last-modified'),
        loading: document.getElementById('modal-loading'),
        bodyWrapper: document.getElementById('modal-body-wrapper'),
        imageContent: document.getElementById('modal-image-content')
    };

    function showModal() {
        modal.classList.remove('hidden');
    }

    function hideModal() {
        modal.classList.add('hidden');
        // Reset content
        modalContent.loading.style.display = 'block';
        modalContent.bodyWrapper.classList.add('hidden');
    }

    closeModalBtn.addEventListener('click', hideModal);
    modal.addEventListener('click', function(e) {
        // Close if clicking on the background overlay
        if (e.target === modal) {
            hideModal();
        }
    });

    // 전역 함수로 showProductDetails 정의
    window.showProductDetails = function(productId) {
        showModal();
        
        const url = `ajax_get_product_details.php?id=${productId}${currentStoreId ? `&store_id=${currentStoreId}` : ''}`;
        fetch(url)
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    const product = result.data;
                    
                    // Populate modal with new resume style
                    modalContent.name.textContent = translations.productDetails; // 제목 고정
                    modalContent.nameEn.textContent = product.name_en || ' ';
                    modalContent.nameKo.textContent = product.name_ko || ' ';
                    modalContent.sku.textContent = product.sku || 'N/A';
                    modalContent.description.textContent = product.description || translations.noDescription;
                    modalContent.brand.textContent = product.brand_name_ko || 'N/A';
                    modalContent.category.textContent = product.category_name || 'N/A';
                    
                    // Barcode and Box packaging information
                    modalContent.barcode.textContent = product.barcode || translations.noBarcode;
                    const piecesPerBox = parseInt(product.pieces_per_box) || 1;
                    modalContent.boxInfo.innerHTML = `<span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-800">${piecesPerBox}${translations.piecesPerBox}</span>`;
                    
                    // Inventory and Pricing by Store
                    console.log('Product inventory data:', product.inventory);
                    modalContent.inventoryWrapper.innerHTML = '';
                    if (product.inventory && product.inventory.length > 0) {
                        const inventoryTable = document.createElement('table');
                        inventoryTable.className = 'w-full text-sm text-left text-gray-600 border';
                        inventoryTable.innerHTML = `
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 font-semibold">${translations.store}</th>
                                    <th class="px-4 py-2 font-semibold text-right"><?php echo t('product.cost_price'); ?></th>
                                    <th class="px-4 py-2 font-semibold text-right"><?php echo t('product.margin_rate'); ?>(%)</th>
                                    <th class="px-4 py-2 font-semibold text-right"><?php echo t('product.selling_price'); ?></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        `;
                        const tbody = inventoryTable.querySelector('tbody');
                        product.inventory.forEach(inv => {
                            console.log('Processing inventory item:', inv);
                            const storeCostPrice = inv.cost_price ? `${parseFloat(inv.cost_price).toLocaleString()}` : '-';
                            const storeSellingPrice = inv.selling_price ? `${parseFloat(inv.selling_price).toLocaleString()}` : '-';
                            
                            // 마진율 계산
                            let marginRate = '-';
                            if (inv.cost_price && inv.selling_price && parseFloat(inv.cost_price) > 0) {
                                const margin = ((parseFloat(inv.selling_price) - parseFloat(inv.cost_price)) / parseFloat(inv.cost_price)) * 100;
                                marginRate = `${margin.toFixed(1)}%`;
                            }
                            
                            console.log('Formatted prices - Cost:', storeCostPrice, 'Margin:', marginRate, 'Selling:', storeSellingPrice);
                            const row = document.createElement('tr');
                            row.className = 'border-b';
                            row.innerHTML = `
                                <td class="px-4 py-2">${inv.store_name}</td>
                                <td class="px-4 py-2 text-right font-semibold text-green-700">${storeCostPrice}</td>
                                <td class="px-4 py-2 text-right font-semibold text-orange-600">${marginRate}</td>
                                <td class="px-4 py-2 text-right font-semibold text-blue-700">${storeSellingPrice}</td>
                            `;
                            tbody.appendChild(row);
                        });
                        modalContent.inventoryWrapper.appendChild(inventoryTable);
                    } else {
                        modalContent.inventoryWrapper.innerHTML = `<p class="text-slate-500">${translations.noPricingInfo}</p>`;
                    }

                    // Image
                    if (product.image_url) {
                        modalContent.image.src = product.image_url;
                        modalContent.image.alt = product.name_ko;
                        modalContent.imageContent.classList.remove('hidden');
                    } else {
                        // Hide image container if no image
                        modalContent.imageContent.classList.add('hidden');
                    }

                    // Meta
                    modalContent.status.innerHTML = product.is_active 
                        ? `<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">${translations.activeStatus}</span>`
                        : `<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">${translations.inactiveStatus}</span>`;
                    
                    const lastModifiedDate = new Date(product.updated_at).toLocaleString('ko-KR', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' });
                    modalContent.lastModified.innerHTML = `${translations.lastModified}: ${lastModifiedDate} <br> by ${product.last_modified_by || 'N/A'}`;

                    // Load purchase history
                    loadPurchaseHistory(productId);

                    // Show content
                    modalContent.loading.style.display = 'none';
                    modalContent.bodyWrapper.classList.remove('hidden');

                } else {
                    alert(`${translations.errorApi}: ${result.message}`);
                    hideModal();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert(translations.errorLoadProduct);
                hideModal();
            });
    };

    // Purchase History 관련 함수들
    let currentProductId = null;
    let selectedPurchaseData = null;
    let marginPresets = [20, 25, 30, 35]; // 기본값

    // 마진율 프리셋 로드
    function loadMarginPresets() {
        fetch('ajax_get_margin_presets.php')
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    marginPresets = result.data;
                    updateMarginPresetButtons();
                } else {
                    console.error(translations.errorMarginRange, result.message);
                    updateMarginPresetButtons(); // 기본값 사용
                }
            })
            .catch(error => {
                console.error(translations.errorMarginRange, error);
                updateMarginPresetButtons(); // 기본값 사용
            });
    }

    // 마진율 프리셋 버튼 업데이트
    function updateMarginPresetButtons() {
        const container = document.getElementById('margin-presets-container');
        container.innerHTML = '';
        
        marginPresets.forEach(preset => {
            const button = document.createElement('button');
            button.className = 'margin-preset-btn px-3 py-2 text-sm border border-blue-300 rounded-md hover:bg-blue-100 focus:bg-blue-200';
            button.dataset.margin = preset;
            button.textContent = `${preset}%`;
            container.appendChild(button);
        });
    }

    // 토스트 알림 함수
    function showToast(message, type = 'info') {
        // 기존 토스트 제거
        const existingToast = document.querySelector('.toast-notification');
        if (existingToast) {
            existingToast.remove();
        }

        const toast = document.createElement('div');
        toast.className = `toast-notification fixed top-4 right-4 z-50 px-4 py-3 rounded-md shadow-lg max-w-sm transition-all duration-300 ${
            type === 'success' ? 'bg-green-500 text-white' : 
            type === 'error' ? 'bg-red-500 text-white' : 
            'bg-blue-500 text-white'
        }`;
        
        toast.innerHTML = `
            <div class="flex items-center">
                <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'} mr-2"></i>
                <span>${message}</span>
                <button class="ml-3 text-white hover:text-gray-200" onclick="this.parentElement.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;

        document.body.appendChild(toast);

        // 3초 후 자동 제거
        setTimeout(() => {
            if (toast.parentNode) {
                toast.remove();
            }
        }, 3000);
    }

    function loadPurchaseHistory(productId) {
        currentProductId = productId;
        const loadingDiv = document.getElementById('purchase-history-loading');
        const contentDiv = document.getElementById('purchase-history-content');
        const noHistoryDiv = document.getElementById('no-purchase-history');
        
        // 로딩 상태 표시
        loadingDiv.style.display = 'block';
        contentDiv.classList.add('hidden');
        
        const purchaseUrl = `ajax_get_purchase_history.php?product_id=${productId}${currentStoreId ? `&store_id=${currentStoreId}` : ''}`;
        fetch(purchaseUrl)
            .then(response => response.json())
            .then(result => {
                loadingDiv.style.display = 'none';
                
                if (result.success) {
                    if (result.data && result.data.length > 0) {
                        populatePurchaseHistory(result.data);
                        contentDiv.classList.remove('hidden');
                        noHistoryDiv.classList.add('hidden');
                    } else {
                        // 매입 이력이 없는 경우
                        contentDiv.classList.add('hidden');
                        noHistoryDiv.classList.remove('hidden');
                        if (result.message) {
                            noHistoryDiv.innerHTML = `
                                <div class="text-center py-4 text-gray-500">
                                    <i class="fas fa-info-circle text-2xl"></i>
                                    <p class="mt-2">${result.message}</p>
                                </div>
                            `;
                        }
                    }
                } else {
                    // API 오류인 경우
                    contentDiv.classList.add('hidden');
                    noHistoryDiv.innerHTML = `
                        <div class="text-center py-4 text-red-500">
                            <i class="fas fa-exclamation-triangle text-2xl"></i>
                            <p class="mt-2">오류: ${result.message}</p>
                        </div>
                    `;
                    noHistoryDiv.classList.remove('hidden');
                }
            })
            .catch(error => {
                console.error(translations.errorLoadPurchase, error);
                loadingDiv.innerHTML = `<p class="text-red-500"><i class="fas fa-exclamation-triangle mr-2"></i>${translations.errorLoadProduct}</p>`;
                showToast(translations.errorLoadPurchase, 'error');
            });
    }

    function populatePurchaseHistory(historyData) {
        const tbody = document.getElementById('purchase-history-tbody');
        tbody.innerHTML = '';
        
        historyData.forEach((item, index) => {
            const row = document.createElement('tr');
            row.className = 'border-b hover:bg-gray-50 cursor-pointer';
            row.dataset.purchaseIndex = index;
            
            row.innerHTML = `
                <td class="px-3 py-2">${item.purchase_date_formatted}</td>
                <td class="px-3 py-2">${item.supplier_name}</td>
                <td class="px-3 py-2">${item.store_name || currentStoreName}</td>
                <td class="px-3 py-2 text-right font-mono">${item.box_cost_formatted || '-'}</td>
                <td class="px-3 py-2 text-right font-mono">${item.unit_cost_per_piece_formatted}</td>
                <td class="px-3 py-2 text-center">
                    <button class="select-purchase-btn px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs hover:bg-blue-200">
                        ${translations.selectText}
                    </button>
                </td>
            `;
            
            // 선택 버튼 이벤트
            const selectBtn = row.querySelector('.select-purchase-btn');
            selectBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                selectPurchaseForPricing(item, selectBtn);
            });
            
            tbody.appendChild(row);
        });
    }

    function selectPurchaseForPricing(purchaseData, buttonElement) {
        selectedPurchaseData = purchaseData;
        
        // 이전 선택 해제
        document.querySelectorAll('.select-purchase-btn').forEach(btn => {
            btn.textContent = translations.selectText;
            btn.className = 'select-purchase-btn px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs hover:bg-blue-200';
        });
        
        // 현재 버튼 선택 상태로 변경
        buttonElement.textContent = translations.selectedText;
        buttonElement.className = 'select-purchase-btn px-2 py-1 bg-green-100 text-green-700 rounded text-xs';
        
        // 가격 설정 섹션 표시
        const pricingSection = document.getElementById('modal-pricing-section');
        const costDisplay = document.getElementById('selected-cost-display');
        const sellingPriceInput = document.getElementById('new-selling-price');
        const recommendedMarginRate = document.getElementById('recommended-margin-rate');
        
        costDisplay.textContent = purchaseData.unit_cost_per_piece_formatted;
        
        // 권장 마진율 표시 (마진 관리 시스템에서 받은 데이터 사용)
        if (purchaseData.margin_rate) {
            recommendedMarginRate.textContent = `${purchaseData.margin_rate}%`;
            // 권장 마진율로 기본 판매가 계산
            applyMarginRate(purchaseData.margin_rate);
        } else {
            // 기본값 사용
            sellingPriceInput.value = purchaseData.suggested_selling_price;
            updateMarginRate();
        }
        
        // 마진 버튼 이벤트 리스너 추가
        setupMarginButtons();
        
        pricingSection.classList.remove('hidden');
    }

    function applyMarginRate(marginRate) {
        if (!selectedPurchaseData) return;
        
        const costPrice = parseFloat(selectedPurchaseData.unit_cost_per_piece);
        const sellingPrice = Math.round(costPrice * (1 + marginRate / 100));
        
        document.getElementById('new-selling-price').value = sellingPrice;
        updateMarginRate();
        
        // 선택된 마진 버튼 강조
        document.querySelectorAll('.margin-preset-btn').forEach(btn => {
            btn.classList.remove('bg-blue-200', 'border-blue-500');
            btn.classList.add('border-blue-300');
            if (parseFloat(btn.dataset.margin) === marginRate) {
                btn.classList.add('bg-blue-200', 'border-blue-500');
                btn.classList.remove('border-blue-300');
            }
        });
    }

    function setupMarginButtons() {
        // 기존 이벤트 리스너 제거 (중복 방지)
        document.querySelectorAll('.margin-preset-btn').forEach(btn => {
            btn.replaceWith(btn.cloneNode(true));
        });
        
        const customMarginBtn = document.getElementById('apply-custom-margin-btn');
        const customMarginInput = document.getElementById('custom-margin-input');
        
        customMarginBtn.replaceWith(customMarginBtn.cloneNode(true));
        customMarginInput.replaceWith(customMarginInput.cloneNode(true));
        
        // 새로운 이벤트 리스너 추가
        document.querySelectorAll('.margin-preset-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const marginRate = parseFloat(this.dataset.margin);
                applyMarginRate(marginRate);
                
                // 커스텀 입력 필드 초기화
                document.getElementById('custom-margin-input').value = '';
            });
        });
        
        // 커스텀 마진 적용 버튼
        document.getElementById('apply-custom-margin-btn').addEventListener('click', function() {
            const customMargin = parseFloat(document.getElementById('custom-margin-input').value);
            if (isNaN(customMargin) || customMargin < 0 || customMargin > 100) {
                showToast(translations.errorMarginRange, 'error');
                return;
            }
            
            applyMarginRate(customMargin);
            
            // 프리셋 버튼 선택 해제
            document.querySelectorAll('.margin-preset-btn').forEach(btn => {
                btn.classList.remove('bg-blue-200', 'border-blue-500');
                btn.classList.add('border-blue-300');
            });
        });
        
        // 커스텀 마진 입력 시 엔터키 처리
        document.getElementById('custom-margin-input').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.getElementById('apply-custom-margin-btn').click();
            }
        });
    }

    function updateMarginRate() {
        if (!selectedPurchaseData) return;
        
        const costPrice = parseFloat(selectedPurchaseData.unit_cost_per_piece);
        const sellingPrice = parseFloat(document.getElementById('new-selling-price').value) || 0;
        const marginRate = costPrice > 0 ? ((sellingPrice - costPrice) / costPrice * 100).toFixed(1) : 0;
        
        document.getElementById('margin-rate').textContent = `${marginRate}%`;
    }

    // 이벤트 리스너
    document.getElementById('new-selling-price').addEventListener('input', updateMarginRate);
    
    document.getElementById('apply-selling-price-btn').addEventListener('click', function() {
        if (!selectedPurchaseData || !currentProductId) {
            showToast(translations.selectPurchaseFirst, 'error');
            return;
        }
        
        const sellingPrice = parseFloat(document.getElementById('new-selling-price').value);
        if (!sellingPrice || sellingPrice <= 0) {
            showToast(translations.enterValidPrice, 'error');
            return;
        }

        // 매우 낮은 마진율 경고
        const costPrice = parseFloat(selectedPurchaseData.unit_cost_per_piece);
        const marginRate = ((sellingPrice - costPrice) / costPrice * 100);
        if (marginRate < 5) {
            if (!confirm(translations.lowMarginWarning.replace('{rate}', marginRate.toFixed(1)))) {
                return;
            }
        }
        
        // 판매가 업데이트 API 호출
        const formData = new FormData();
        formData.append('product_id', currentProductId);
        formData.append('selling_price', sellingPrice);
        formData.append('cost_price', costPrice); // 선택된 원가 추가
        if (currentStoreId) {
            formData.append('store_id', currentStoreId);
        }
        if (selectedPurchaseData && selectedPurchaseData.purchase_id) {
            formData.append('purchase_id', selectedPurchaseData.purchase_id);
        }
        
        fetch('ajax_update_selling_price.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                showToast(result.message, 'success');
                
                // 상품 정보 새로고침 (지점별 재고 테이블 업데이트)
                const productId = currentProductId;
                const url = `ajax_get_product_details.php?id=${productId}${currentStoreId ? `&store_id=${currentStoreId}` : ''}`;
                fetch(url)
                    .then(response => response.json())
                    .then(refreshResult => {
                        if (refreshResult.success) {
                            const product = refreshResult.data;
                            
                            // 지점별 재고 및 가격 테이블 업데이트
                            modalContent.inventoryWrapper.innerHTML = '';
                            if (product.inventory && product.inventory.length > 0) {
                                const inventoryTable = document.createElement('table');
                                inventoryTable.className = 'w-full text-sm text-left text-gray-600 border';
                                inventoryTable.innerHTML = `
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-3 py-2 font-semibold">${translations.store}</th>
                                            <th class="px-3 py-2 font-semibold text-right">${translations.inventory}</th>
                                            <th class="px-3 py-2 font-semibold text-right"><?php echo t('product.cost_price'); ?></th>
                                            <th class="px-3 py-2 font-semibold text-right"><?php echo t('product.margin_rate'); ?>(%)</th>
                                            <th class="px-3 py-2 font-semibold text-right"><?php echo t('product.selling_price'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                `;
                                const tbody = inventoryTable.querySelector('tbody');
                                product.inventory.forEach(inv => {
                                    console.log('Refresh - Processing inventory item:', inv);
                                    const storeCostPrice = inv.cost_price ? `${parseFloat(inv.cost_price).toLocaleString()}` : '-';
                                    const storeSellingPrice = inv.selling_price ? `${parseFloat(inv.selling_price).toLocaleString()}` : '-';
                                    
                                    // 마진율 계산
                                    let marginRate = '-';
                                    if (inv.cost_price && inv.selling_price && parseFloat(inv.cost_price) > 0) {
                                        const margin = ((parseFloat(inv.selling_price) - parseFloat(inv.cost_price)) / parseFloat(inv.cost_price)) * 100;
                                        marginRate = `${margin.toFixed(1)}%`;
                                    }
                                    
                                    console.log('Refresh - Formatted prices - Cost:', storeCostPrice, 'Margin:', marginRate, 'Selling:', storeSellingPrice);
                                    const row = document.createElement('tr');
                                    row.className = 'border-b';
                                    row.innerHTML = `
                                        <td class="px-3 py-2">${inv.store_name}</td>
                                        <td class="px-3 py-2 text-right">${parseInt(inv.quantity)}개</td>
                                        <td class="px-3 py-2 text-right font-semibold text-green-700">${storeCostPrice}</td>
                                        <td class="px-3 py-2 text-right font-semibold text-orange-600">${marginRate}</td>
                                        <td class="px-3 py-2 text-right font-semibold text-blue-700">${storeSellingPrice}</td>
                                    `;
                                    tbody.appendChild(row);
                                });
                                modalContent.inventoryWrapper.appendChild(inventoryTable);
                            } else {
                                modalContent.inventoryWrapper.innerHTML = `<p class="text-slate-500">${translations.noInventoryInfo}</p>`;
                            }
                        }
                    })
                    .catch(error => {
                        console.error(translations.errorLoadProduct, error);
                    });
                
                // 판매가 설정 섹션 숨기기
                document.getElementById('modal-pricing-section').classList.add('hidden');
                
                // 선택 초기화
                selectedPurchaseData = null;
                document.querySelectorAll('.select-purchase-btn').forEach(btn => {
                    btn.textContent = translations.selectText;
                    btn.className = 'select-purchase-btn px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs hover:bg-blue-200';
                });
                
            } else {
                showToast(`${translations.errorApi}: ${result.message}`, 'error');
            }
        })
        .catch(error => {
            console.error(translations.errorLoadProduct, error);
            showToast(translations.errorLoadProduct, 'error');
        });
    });
    
    document.getElementById('cancel-pricing-btn').addEventListener('click', function() {
        // 판매가 설정 섹션 숨기기
        document.getElementById('modal-pricing-section').classList.add('hidden');
        
        // 선택 초기화
        selectedPurchaseData = null;
        document.querySelectorAll('.select-purchase-btn').forEach(btn => {
            btn.textContent = translations.selectText;
            btn.className = 'select-purchase-btn px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs hover:bg-blue-200';
        });
        
        // 마진 선택 상태 초기화
        document.querySelectorAll('.margin-preset-btn').forEach(btn => {
            btn.classList.remove('bg-blue-200', 'border-blue-500');
            btn.classList.add('border-blue-300');
        });
        document.getElementById('custom-margin-input').value = '';
        document.getElementById('new-selling-price').value = '';
    });
    
    // 페이지 로드 시 마진율 프리셋 로드
    loadMarginPresets();
    
    // 마진율 프리셋 설정 모달 관련
    const presetConfigModal = document.getElementById('preset-config-modal');
    const configurePresetsBtn = document.getElementById('configure-presets-btn');
    const closePresetModalBtn = document.getElementById('close-preset-modal-btn');
    const cancelPresetBtn = document.getElementById('cancel-preset-btn');
    const savePresetBtn = document.getElementById('save-preset-btn');
    const presetsInput = document.getElementById('presets-input');
    
    configurePresetsBtn.addEventListener('click', function() {
        // 현재 프리셋을 입력 필드에 표시
        presetsInput.value = marginPresets.join(', ');
        presetConfigModal.classList.remove('hidden');
    });
    
    closePresetModalBtn.addEventListener('click', function() {
        presetConfigModal.classList.add('hidden');
    });
    
    cancelPresetBtn.addEventListener('click', function() {
        presetConfigModal.classList.add('hidden');
    });
    
    presetConfigModal.addEventListener('click', function(e) {
        if (e.target === presetConfigModal) {
            presetConfigModal.classList.add('hidden');
        }
    });
    
    savePresetBtn.addEventListener('click', function() {
        const presetsValue = presetsInput.value.trim();
        if (!presetsValue) {
            showToast(translations.enterMarginRate, 'error');
            return;
        }
        
        // 저장 중 표시
        savePresetBtn.textContent = translations.savingText;
        savePresetBtn.disabled = true;
        
        const formData = new FormData();
        formData.append('presets', presetsValue);
        
        fetch('ajax_save_margin_presets.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('Response status:', response.status);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(result => {
            console.log('Save result:', result);
            if (result.success) {
                showToast(result.message, 'success');
                marginPresets = result.data;
                updateMarginPresetButtons();
                presetConfigModal.classList.add('hidden');
            } else {
                showToast(`${translations.errorApi}: ${result.message}`, 'error');
                console.error('Save failed:', result.message);
            }
        })
        .catch(error => {
            console.error(translations.errorSavePresets, error);
            showToast(`${translations.errorSavePresets}: ${error.message}`, 'error');
        })
        .finally(() => {
            savePresetBtn.textContent = <?php echo json_encode(t('product.save')); ?>;
            savePresetBtn.disabled = false;
        });
    });
    
    // 상품명 편집 기능
    let currentEditingProductId = null;
    
    // 상품명 편집 모드 전환 (영어)
    document.getElementById('edit-name-en-btn').addEventListener('click', function() {
        if (!currentProductId) return;
        
        const displayDiv = document.getElementById('modal-name-en-display');
        const editDiv = document.getElementById('modal-name-en-edit');
        const input = document.getElementById('modal-name-en-input');
        const currentName = document.getElementById('modal-name-en').textContent;
        
        input.value = currentName;
        displayDiv.classList.add('hidden');
        editDiv.classList.remove('hidden');
        input.focus();
        input.select();
        
        currentEditingProductId = currentProductId;
    });
    
    // 상품명 편집 모드 전환 (한글)
    document.getElementById('edit-name-ko-btn').addEventListener('click', function() {
        if (!currentProductId) return;
        
        const displayDiv = document.getElementById('modal-name-ko-display');
        const editDiv = document.getElementById('modal-name-ko-edit');
        const input = document.getElementById('modal-name-ko-input');
        const currentName = document.getElementById('modal-name-ko').textContent;
        
        input.value = currentName;
        displayDiv.classList.add('hidden');
        editDiv.classList.remove('hidden');
        input.focus();
        input.select();
        
        currentEditingProductId = currentProductId;
    });
    
    // 상품명 저장 함수
    function saveProductName(language, newName) {
        if (!currentEditingProductId) return;
        
        const formData = new FormData();
        formData.append('product_id', currentEditingProductId);
        formData.append('language', language);
        formData.append('product_name', newName.trim());
        
        const saveBtn = document.getElementById(`save-name-${language}-btn`);
        const originalText = saveBtn.innerHTML;
        
        // 저장 중 상태
        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>저장중...';
        saveBtn.disabled = true;
        
        fetch('ajax_update_product_name.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('Response status:', response.status);
            console.log('Response headers:', response.headers);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            return response.text(); // 먼저 텍스트로 받아서 확인
        })
        .then(text => {
            console.log('Response text:', text);
            
            try {
                const result = JSON.parse(text);
                console.log('Parsed result:', result);
                
                if (result.success) {
                    showToast(result.message, 'success');
                    
                    // UI 업데이트
                    document.getElementById(`modal-name-${language}`).textContent = newName;
                    
                    // 테이블 목록에서도 해당 상품의 상품명 업데이트 (페이지 새로고침 없이 즉시 반영)
                    updateProductNameInTable(currentEditingProductId, language, newName);
                    
                    cancelProductNameEdit(language);
                    
                } else {
                    showToast(`오류: ${result.message}`, 'error');
                }
            } catch (parseError) {
                console.error('JSON 파싱 오류:', parseError);
                console.error('원본 응답:', text);
                showToast('서버 응답을 처리할 수 없습니다: ' + text.substring(0, 100), 'error');
            }
        })
        .catch(error => {
            console.error('상품명 저장 오류:', error);
            showToast('상품명 저장 중 오류가 발생했습니다: ' + error.message, 'error');
        })
        .finally(() => {
            saveBtn.innerHTML = originalText;
            saveBtn.disabled = false;
        });
    }
    
    // 테이블에서 상품명 업데이트 함수
    function updateProductNameInTable(productId, language, newName) {
        // 테이블의 모든 행을 확인하여 해당 상품의 상품명을 업데이트
        const tableRows = document.querySelectorAll('tbody tr[onclick*="showProductDetails"]');
        
        tableRows.forEach(row => {
            const onclickAttr = row.getAttribute('onclick');
            if (onclickAttr && onclickAttr.includes(`showProductDetails(${productId})`)) {
                // 해당 상품 행을 찾았으면 상품명 업데이트
                // 상품명이 있는 셀을 찾기 (text-gray-800과 text-gray-600 클래스가 있는 div가 포함된 셀)
                const nameCells = row.querySelectorAll('td');
                let nameCell = null;
                
                for (let cell of nameCells) {
                    const nameEn = cell.querySelector('.text-gray-800');
                    const nameKo = cell.querySelector('.text-gray-600');
                    if (nameEn && nameKo) {
                        nameCell = cell;
                        break;
                    }
                }
                
                if (nameCell) {
                    const nameEn = nameCell.querySelector('.text-gray-800');
                    const nameKo = nameCell.querySelector('.text-gray-600');
                    
                    if (language === 'en' && nameEn) {
                        nameEn.textContent = newName;
                    } else if (language === 'ko' && nameKo) {
                        nameKo.textContent = newName;
                    }
                }
            }
        });
    }

    // 상품명 편집 취소 함수
    function cancelProductNameEdit(language) {
        const displayDiv = document.getElementById(`modal-name-${language}-display`);
        const editDiv = document.getElementById(`modal-name-${language}-edit`);
        
        displayDiv.classList.remove('hidden');
        editDiv.classList.add('hidden');
        
        // 입력값 초기화
        document.getElementById(`modal-name-${language}-input`).value = '';
        currentEditingProductId = null;
    }
    
    // 영어 상품명 저장 버튼
    document.getElementById('save-name-en-btn').addEventListener('click', function() {
        const newName = document.getElementById('modal-name-en-input').value.trim();
        if (!newName) {
            showToast('상품명을 입력해주세요.', 'error');
            return;
        }
        if (newName.length > 255) {
            showToast('상품명은 255자 이내로 입력해주세요.', 'error');
            return;
        }
        saveProductName('en', newName);
    });
    
    // 한글 상품명 저장 버튼
    document.getElementById('save-name-ko-btn').addEventListener('click', function() {
        const newName = document.getElementById('modal-name-ko-input').value.trim();
        if (!newName) {
            showToast('상품명을 입력해주세요.', 'error');
            return;
        }
        if (newName.length > 255) {
            showToast('상품명은 255자 이내로 입력해주세요.', 'error');
            return;
        }
        saveProductName('ko', newName);
    });
    
    // 영어 상품명 취소 버튼
    document.getElementById('cancel-name-en-btn').addEventListener('click', function() {
        cancelProductNameEdit('en');
    });
    
    // 한글 상품명 취소 버튼
    document.getElementById('cancel-name-ko-btn').addEventListener('click', function() {
        cancelProductNameEdit('ko');
    });
    
    // 엔터키로 저장, ESC키로 취소
    document.getElementById('modal-name-en-input').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('save-name-en-btn').click();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            cancelProductNameEdit('en');
        }
    });
    
    document.getElementById('modal-name-ko-input').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('save-name-ko-btn').click();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            cancelProductNameEdit('ko');
        }
    });

    // ESC 키로 모달 닫기
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            hideModal();
        }
    });
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>