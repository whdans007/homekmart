<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('purchase_product.management') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 일괄 업데이트 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['bulk_action']) && isset($_POST['selected_products'])) {
        $bulk_action = $_POST['bulk_action'];
        $selected_products = $_POST['selected_products'];
        
        if (!is_array($selected_products) || empty($selected_products)) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => '선택된 상품이 없습니다.'];
        } else {
            $conn = get_db_connection();
            
            try {
                $conn->autocommit(false);
                $updated_count = 0;
                
                if ($bulk_action === 'update_category' && isset($_POST['category_id'])) {
                    $category_id = intval($_POST['category_id']);
                    
                    // 카테고리 존재 여부 확인
                    $category_check = $conn->prepare("SELECT name FROM categories WHERE id = ?");
                    $category_check->bind_param("i", $category_id);
                    $category_check->execute();
                    $category_result = $category_check->get_result();
                    
                    if ($category_result->num_rows > 0) {
                        $category_data = $category_result->fetch_assoc();
                        
                        // 선택된 상품들의 카테고리 업데이트
                        $placeholders = str_repeat('?,', count($selected_products) - 1) . '?';
                        $update_sql = "UPDATE products SET category_id = ? WHERE id IN ($placeholders)";
                        $update_stmt = $conn->prepare($update_sql);
                        
                        $types = 'i' . str_repeat('i', count($selected_products));
                        $params = array_merge([$category_id], array_map('intval', $selected_products));
                        $update_stmt->bind_param($types, ...$params);
                        $update_stmt->execute();
                        
                        $updated_count = $update_stmt->affected_rows;
                        $update_stmt->close();
                        
                        $_SESSION['flash'] = [
                            'type' => 'success', 
                            'message' => "{$updated_count}개 상품의 카테고리가 '{$category_data['name']}'로 변경되었습니다."
                        ];
                    } else {
                        $_SESSION['flash'] = ['type' => 'error', 'message' => '존재하지 않는 카테고리입니다.'];
                    }
                    $category_check->close();
                    
                } elseif ($bulk_action === 'update_brand' && isset($_POST['brand_id'])) {
                    $brand_id = intval($_POST['brand_id']);
                    
                    // 브랜드 존재 여부 확인
                    $brand_check = $conn->prepare("SELECT name_en, name_ko FROM brands WHERE id = ?");
                    $brand_check->bind_param("i", $brand_id);
                    $brand_check->execute();
                    $brand_result = $brand_check->get_result();
                    
                    if ($brand_result->num_rows > 0) {
                        $brand_data = $brand_result->fetch_assoc();
                        $brand_name = $brand_data['name_ko'] ?: $brand_data['name_en'];
                        
                        // 선택된 상품들의 브랜드 업데이트
                        $placeholders = str_repeat('?,', count($selected_products) - 1) . '?';
                        $update_sql = "UPDATE products SET brand_id = ? WHERE id IN ($placeholders)";
                        $update_stmt = $conn->prepare($update_sql);
                        
                        $types = 'i' . str_repeat('i', count($selected_products));
                        $params = array_merge([$brand_id], array_map('intval', $selected_products));
                        $update_stmt->bind_param($types, ...$params);
                        $update_stmt->execute();
                        
                        $updated_count = $update_stmt->affected_rows;
                        $update_stmt->close();
                        
                        $_SESSION['flash'] = [
                            'type' => 'success', 
                            'message' => "{$updated_count}개 상품의 브랜드가 '{$brand_name}'로 변경되었습니다."
                        ];
                    } else {
                        $_SESSION['flash'] = ['type' => 'error', 'message' => '존재하지 않는 브랜드입니다.'];
                    }
                    $brand_check->close();
                }
                
                $conn->commit();
                
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['flash'] = ['type' => 'error', 'message' => '일괄 업데이트 중 오류가 발생했습니다: ' . $e->getMessage()];
            }
            
            $conn->autocommit(true);
            $conn->close();
            
            // 페이지 새로고침으로 결과 표시
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
    }
}

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

// 브랜드 목록 미리 로드
$brands_list = [];
try {
    $brands_sql = "SELECT id, name_en, name_ko FROM brands ORDER BY name_ko, name_en LIMIT 100";
    $brands_result = $conn->query($brands_sql);
    if ($brands_result) {
        while ($brand_row = $brands_result->fetch_assoc()) {
            $brands_list[] = $brand_row;
        }
    }
} catch (Exception $e) {
}

// 날짜 변수 - 기본적으로 최근 7일간의 데이터 표시
$display_mode = $_GET['mode'] ?? 'recent'; // 'recent' 또는 'date'
$selected_date = $_GET['date'] ?? date('Y-m-d');
$prev_date = date('Y-m-d', strtotime($selected_date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($selected_date . ' +1 day'));

// 검색 변수
$search_term = $_GET['search'] ?? '';
$search_term = trim($search_term);

// URL 파라미터를 생성하는 함수
function build_url_params($mode, $date = null, $search = null) {
    $params = ['mode' => $mode];
    if ($mode === 'date' && $date) {
        $params['date'] = $date;
    }
    if (!empty($search)) {
        $params['search'] = $search;
    }
    return http_build_query($params);
}

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
    }
}

// 선택된 날짜의 매입 상품 조회
$purchase_products = [];

try {
    // deleted_at 컬럼 존재 여부 확인
    $check_deleted_at = $conn->query("SHOW COLUMNS FROM purchases LIKE 'deleted_at'");
    $has_deleted_at = $check_deleted_at->num_rows > 0;
    
    $deleted_condition = $has_deleted_at ? "AND p.deleted_at IS NULL" : "";
    
    // SQL 쿼리 구성 - 검색어가 있으면 날짜 조건 무시, 없으면 표시 모드에 따라 조건 변경
    if (!empty($search_term)) {
        // 검색 시에는 날짜 제한 없이 전체 기간 검색
        $where_condition = "1 = 1";
        $order_clause = "ORDER BY pi.item_id DESC";
    } elseif ($display_mode === 'recent') {
        $where_condition = "DATE(p.purchase_date) BETWEEN ? AND ?";
        $order_clause = "ORDER BY pi.item_id DESC";
    } else {
        $where_condition = "DATE(p.purchase_date) = ?";
        $order_clause = "ORDER BY pi.item_id DESC";
    }

    // 검색 조건 추가
    $search_condition = '';
    if (!empty($search_term)) {
        $search_condition = " AND (pr.sku LIKE ? OR pr.name_ko LIKE ? OR pr.name_en LIKE ?)";
    }

    // 점포 필터링 조건 추가
    $store_condition = '';
    if ($_SESSION['role'] !== 'super_admin') {
        if (!empty($current_store_id)) {
            $store_condition = " AND p.store_id = ?";
        } else {
            // 점포가 지정되지 않은 경우 데이터 조회 불가
            $store_condition = " AND 1 = 0";
        }
    }

    $sql = "
        SELECT
            pr.id as product_id,
            pr.sku,
            pr.name_en,
            pr.name_ko,
            pr.pieces_per_box,
            b.name_en as brand_name_en,
            b.name_ko as brand_name_ko,
            c.name as category_name,
            pi.quantity,
            pi.unit_price,
            pi.purchase_type,
            pi.discount_rate,
            pi.discounted_total,
            COALESCE(pi.discounted_total, pi.quantity * pi.unit_price) as total_amount,
            s.name as supplier_name,
            p.purchase_date,
            pi.item_id as purchase_item_id,
            p.purchase_id,
            p.store_id,
            st.name as store_name
        FROM purchase_items pi
        JOIN purchases p ON pi.purchase_id = p.purchase_id
        JOIN products pr ON pi.product_id = pr.id
        LEFT JOIN brands b ON pr.brand_id = b.id
        LEFT JOIN categories c ON pr.category_id = c.id
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        LEFT JOIN stores st ON p.store_id = st.id
        WHERE $where_condition
        $deleted_condition
        $search_condition
        $store_condition
        $order_clause
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    // 바인딩 파라미터 설정
    $bind_params = [];
    $bind_types = '';

    // 검색어가 없을 때만 날짜 파라미터 추가
    if (empty($search_term)) {
        if ($display_mode === 'recent') {
            $bind_params[] = $start_date;
            $bind_params[] = $end_date;
            $bind_types .= 'ss';
        } else {
            $bind_params[] = $selected_date;
            $bind_types .= 's';
        }
    }

    // 검색어가 있으면 검색 파라미터 추가
    if (!empty($search_term)) {
        $search_like = "%$search_term%";
        $bind_params[] = $search_like;
        $bind_params[] = $search_like;
        $bind_params[] = $search_like;
        $bind_types .= 'sss';
    }

    // 점포 필터링 파라미터 추가
    if ($_SESSION['role'] !== 'super_admin' && !empty($current_store_id)) {
        $bind_params[] = $current_store_id;
        $bind_types .= 'i';
    }

    if (!empty($bind_params)) {
        $stmt->bind_param($bind_types, ...$bind_params);
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
}

$conn->close();
?>

<div class="w-full px-2 sm:px-3 md:px-4 py-8">

    <!-- 표시 모드 및 날짜 네비게이션 -->
    <div class="bg-white rounded shadow mb-3">
        <div class="px-3 py-2">
            <!-- 표시 모드 및 매입이력 정보 한 줄 표시 -->
            <div class="flex items-center justify-between">
                <!-- 표시 모드 선택 -->
                <div class="flex items-center space-x-2">
                    <span class="text-sm text-gray-600"><?php echo t('purchase_product.display_mode'); ?>:</span>
                    <a href="?<?php echo build_url_params('recent', null, $search_term); ?>" class="px-3 py-1 text-xs rounded-full <?php echo $display_mode === 'recent' ? 'bg-blue-100 text-blue-800 font-medium' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                        <i class="fas fa-calendar-week mr-1"></i>
                        <?php echo t('purchase_product.recent_7_days'); ?>
                    </a>
                    <a href="?<?php echo build_url_params('date', $selected_date, $search_term); ?>" class="px-3 py-1 text-xs rounded-full <?php echo $display_mode === 'date' ? 'bg-blue-100 text-blue-800 font-medium' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                        <i class="fas fa-calendar-day mr-1"></i>
                        <?php echo t('common.date'); ?>
                    </a>
                </div>
                
                <!-- 선택된 모드에 따른 정보 표시 -->
                <?php if ($display_mode === 'recent'): ?>
                <div class="text-sm text-gray-700">
                    <i class="fas fa-calendar-week text-blue-600 mr-1"></i>
                    <?php echo t('purchase_product.recent_purchase_history'); ?> 
                    <span class="text-gray-500">(<?php echo date('Y.m.d', strtotime($start_date)); ?> ~ <?php echo date('Y.m.d', strtotime($end_date)); ?>)</span>
                </div>
                <?php else: ?>
                <div class="flex items-center space-x-2">
                    <a href="?<?php echo build_url_params('date', $prev_date, $search_term); ?>" class="inline-flex items-center px-2 py-1 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                        <i class="fas fa-chevron-left mr-1"></i>
                        <?php echo t('common.previous'); ?>
                    </a>
                    <input type="date" id="date-picker" value="<?php echo $selected_date; ?>" 
                           class="border border-gray-300 rounded px-2 py-1 text-xs"
                           onchange="window.location.href='?<?php echo build_url_params('date', '', $search_term); ?>'.replace('date=', 'date=' + this.value);">
                    <span class="text-sm font-medium text-gray-900">
                        <?php echo date('Y년 m월 d일 (l)', strtotime($selected_date)); ?>
                    </span>
                    <a href="?<?php echo build_url_params('date', $next_date, $search_term); ?>" class="inline-flex items-center px-2 py-1 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                        <?php echo t('common.next'); ?>
                        <i class="fas fa-chevron-right ml-1"></i>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 검색 기능 -->
    <div class="bg-white rounded shadow mb-3">
        <div class="px-3 py-2">
            <form action="purchase_product_management.php" method="get" class="flex items-center space-x-3">
                <!-- 현재 모드와 날짜 유지를 위한 hidden 필드 -->
                <input type="hidden" name="mode" value="<?php echo htmlspecialchars($display_mode); ?>">
                <?php if ($display_mode === 'date'): ?>
                <input type="hidden" name="date" value="<?php echo htmlspecialchars($selected_date); ?>">
                <?php endif; ?>
                
                <!-- 검색 입력 필드 -->
                <div class="flex-1 relative">
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                        <i class="fas fa-search text-gray-400 text-sm"></i>
                    </div>
                    <input type="search" 
                           name="search" 
                           placeholder="SKU, 상품명(한글/영문) 검색..."
                           class="block w-full rounded-md border-gray-300 pl-10 pr-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500" 
                           value="<?php echo htmlspecialchars($search_term); ?>">
                </div>
                
                <!-- 검색 버튼 -->
                <button type="submit" class="inline-flex items-center px-3 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <i class="fas fa-search mr-1"></i>
                    검색
                </button>
                
                <!-- 초기화 버튼 -->
                <?php if (!empty($search_term)): ?>
                <a href="?<?php echo build_url_params($display_mode, $display_mode === 'date' ? $selected_date : null, ''); ?>" 
                   class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <i class="fas fa-times mr-1"></i>
                    초기화
                </a>
                <?php endif; ?>
            </form>
            
            <!-- 검색 결과 정보 -->
            <?php if (!empty($search_term)): ?>
            <div class="mt-2 text-sm text-gray-600">
                <i class="fas fa-info-circle mr-1"></i>
                '<strong><?php echo htmlspecialchars($search_term); ?></strong>' 검색 결과: 
                <span class="font-medium"><?php echo count($purchase_products); ?>개</span> 상품
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 상품 목록 테이블 -->
    <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400">
        <div class="px-6 py-4 border-b border-gray-200 bg-white">
            <div class="flex justify-between items-center mb-3">
                <h3 class="text-lg leading-6 font-semibold text-gray-900">
                    <?php echo t('purchase_product.product_list'); ?>
                    <span class="text-sm font-normal text-gray-500 ml-2">(<?php echo count($purchase_products); ?><?php echo t('common.items'); ?>)</span>
                </h3>
            </div>
            
            <!-- 일괄 작업 영역 -->
            <div id="bulk-actions" class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 mb-3">
                <form id="bulk-update-form" method="POST" action="">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <span class="text-sm font-medium text-blue-900">
                                <span id="selected-count">0</span>개 상품 선택됨
                            </span>
                            <button type="button" id="clear-selection" class="text-xs text-blue-600 hover:text-blue-800 underline">
                                선택 해제
                            </button>
                        </div>
                        <div class="flex space-x-2">
                            <button type="button" id="bulk-category-btn" class="inline-flex items-center px-3 py-2 border border-transparent text-xs font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:bg-gray-300 disabled:cursor-not-allowed" disabled>
                                <i class="fas fa-sitemap mr-1"></i>
                                카테고리 일괄등록
                            </button>
                            <button type="button" id="bulk-brand-btn" class="inline-flex items-center px-3 py-2 border border-transparent text-xs font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 disabled:bg-gray-300 disabled:cursor-not-allowed" disabled>
                                <i class="fas fa-tags mr-1"></i>
                                브랜드 일괄등록
                            </button>
                        </div>
                    </div>
                    
                    <!-- 히든 필드들 -->
                    <input type="hidden" name="bulk_action" id="bulk_action" value="">
                    <input type="hidden" name="category_id" id="hidden_category_id" value="">
                    <input type="hidden" name="brand_id" id="hidden_brand_id" value="">
                    <div id="selected-products-container"></div>
                </form>
            </div>
        </div>
        
        <?php if (empty($purchase_products)): ?>
        <div class="px-6 py-12 text-center">
            <i class="fas fa-inbox text-gray-400 text-4xl mb-4"></i>
            <p class="text-gray-500"><?php echo t('purchase_product.no_data'); ?></p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full text-xs">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th scope="col" class="px-2 py-2 text-center w-12">
                            <input type="checkbox" id="select-all" class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                        </th>
                        <?php if ($display_mode === 'recent'): ?>
                        <th scope="col" class="px-2 py-2 text-left text-xs font-semibold text-gray-700 uppercase w-12">
                            <?php echo t('purchase.purchase_date'); ?>
                        </th>
                        <th scope="col" class="px-2 py-2 text-left text-xs font-semibold text-gray-700 uppercase w-20">
                            거래처
                        </th>
                        <?php endif; ?>
                        <th scope="col" class="px-2 py-2 text-left text-xs font-semibold text-gray-700 uppercase w-16">
                            <?php echo t('product.category'); ?>
                        </th>
                        <th scope="col" class="px-2 py-2 text-left text-xs font-semibold text-gray-700 uppercase w-16">
                            <?php echo t('product.sku'); ?>
                        </th>
                        <th scope="col" class="px-2 py-2 text-left text-xs font-semibold text-gray-700 uppercase w-16">
                            <?php echo t('product.brand'); ?>
                        </th>
                        <th scope="col" class="px-2 py-2 text-left text-xs font-semibold text-gray-700 uppercase" style="min-width: 150px; max-width: 250px;">
                            <?php echo t('product.name'); ?>
                        </th>
                        <th scope="col" class="px-2 py-2 text-right text-xs font-semibold text-gray-700 uppercase w-12">
                            <?php echo t('product.box_packaging'); ?>
                        </th>
                        <th scope="col" class="px-2 py-2 text-right text-xs font-semibold text-gray-700 uppercase w-12">
                            <?php echo t('purchase.quantity'); ?>
                        </th>
                        <th scope="col" class="px-2 py-2 text-right text-xs font-semibold text-gray-700 uppercase w-16">
                            <?php echo t('purchase.unit_price'); ?>
                        </th>
                        <th scope="col" class="px-2 py-2 text-right text-xs font-semibold text-gray-700 uppercase w-16">
                            <?php echo t('purchase_product.piece_price'); ?>
                        </th>
                        <th scope="col" class="px-2 py-2 text-right text-xs font-semibold text-gray-700 uppercase w-20">
                            <?php echo t('purchase.total_amount'); ?>
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white">
                    <?php foreach ($purchase_products as $product): ?>
                    <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150" data-product-id="<?php echo $product['product_id']; ?>">
                        <td class="px-2 py-2 text-center">
                            <input type="checkbox" class="product-checkbox rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50" value="<?php echo $product['product_id']; ?>">
                        </td>
                        <?php if ($display_mode === 'recent'): ?>
                        <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900">
                            <div class="text-gray-800"><?php echo date('m.d', strtotime($product['purchase_date'])); ?></div>
                            <div class="text-gray-500 text-xs"><?php echo date('D', strtotime($product['purchase_date'])); ?></div>
                        </td>
                        <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900">
                            <?php echo htmlspecialchars($product['supplier_name'] ?? '-'); ?>
                        </td>
                        <?php endif; ?>
                        <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900">
                            <?php echo htmlspecialchars($product['category_name'] ?? '-'); ?>
                        </td>
                        <td class="px-2 py-2 whitespace-nowrap text-xs font-medium text-gray-900">
                            <?php echo htmlspecialchars($product['sku']); ?>
                        </td>
                        <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900">
                            <?php if (!empty($product['brand_name_en']) || !empty($product['brand_name_ko'])): ?>
                                <span class="font-medium">
                                    <?php echo htmlspecialchars($product['brand_name_en'] ?? ''); ?><?php if (!empty($product['brand_name_en']) && !empty($product['brand_name_ko'])): ?>/<?php endif; ?><?php echo htmlspecialchars($product['brand_name_ko'] ?? ''); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-gray-400">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-2 py-2 text-xs text-gray-900 cursor-pointer" onclick="showProductDetails(<?php echo $product['product_id']; ?>)">
                            <div class="text-gray-800 truncate" style="max-width: 250px;" title="<?php echo htmlspecialchars($product['name_en'] . ' / ' . $product['name_ko']); ?>">
                                <?php echo htmlspecialchars($product['name_en']); ?>
                            </div>
                            <div class="text-gray-600 truncate" style="max-width: 250px;">
                                <?php echo htmlspecialchars($product['name_ko']); ?>
                            </div>
                        </td>
                        <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 text-right">
                            <?php echo number_format($product['pieces_per_box']); ?>
                        </td>
                        <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 text-right">
                            <?php echo number_format($product['quantity']); ?>
                        </td>
                        <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 text-right">
                            <?php echo number_format($product['unit_price'], 2); ?>
                        </td>
                        <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 text-right">
                            <?php echo number_format($product['piece_price'], 2); ?>
                        </td>
                        <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-900 text-right font-medium">
                            <?php echo number_format($product['total_amount'], 2); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-gray-50">
                    <tr>
                        <td colspan="<?php echo $display_mode === 'recent' ? '9' : '8'; ?>" class="px-2 py-1 text-right text-xs font-medium text-gray-900">
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
                            <td class="px-4 py-2">
                                <input type="text" id="modal-name-en" class="w-full px-2 py-1 text-sm border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500" maxlength="255">
                            </td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50 w-1/3"><?php echo t('product.name_ko'); ?></td>
                            <td class="px-4 py-2">
                                <input type="text" id="modal-name-ko" class="w-full px-2 py-1 text-sm border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500" maxlength="255">
                            </td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50">SKU</td>
                            <td class="px-4 py-2">
                                <input type="text" id="modal-sku" class="w-full px-2 py-1 text-sm font-mono border border-gray-300 rounded bg-gray-100" readonly>
                            </td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50"><?php echo t('product.brand'); ?></td>
                            <td class="px-4 py-2">
                                <!-- 선택된 브랜드 표시 -->
                                <div class="mb-2 p-2 bg-gray-50 border border-gray-200 rounded">
                                    <span id="modal-brand-display" class="text-sm text-gray-700">선택된 브랜드 없음</span>
                                </div>
                                <!-- 검색 및 신규등록 -->
                                <div class="flex space-x-2">
                                    <input type="text" id="modal-brand-search" 
                                           class="flex-1 px-2 py-1 text-sm border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="브랜드 검색...">
                                    <button type="button" id="modal-brand-new-btn" class="px-3 py-1 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded">
                                        신규등록
                                    </button>
                                </div>
                                <input type="hidden" id="modal-brand-id" value="">
                                <input type="hidden" id="modal-brand-en" value="">
                                <input type="hidden" id="modal-brand-ko" value="">
                                <div id="brand-search-results" class="absolute z-10 mt-1 bg-white border border-gray-300 rounded-md shadow-lg hidden max-h-48 overflow-y-auto" style="width: calc(100% - 1rem); left: 1rem;">
                                    <!-- 검색 결과가 여기 표시됩니다 -->
                                </div>
                            </td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50"><?php echo t('product.category'); ?></td>
                            <td class="px-4 py-2">
                                <!-- 선택된 카테고리 표시 -->
                                <div class="mb-2 p-2 bg-gray-50 border border-gray-200 rounded">
                                    <span id="modal-category-display" class="text-sm text-gray-700">선택된 카테고리 없음</span>
                                </div>
                                <!-- 계층형 카테고리 선택 -->
                                <div class="flex space-x-2">
                                    <button type="button" id="modal-category-popup-btn" 
                                            class="flex-1 px-3 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded transition duration-200">
                                        <i class="fas fa-sitemap mr-2"></i>카테고리 선택 (팝업)
                                    </button>
                                    <button type="button" id="modal-category-new-btn" class="px-3 py-2 text-sm bg-green-600 hover:bg-green-700 text-white rounded transition duration-200">
                                        <i class="fas fa-plus mr-2"></i>신규등록
                                    </button>
                                </div>
                                <input type="hidden" id="modal-category-id" value="">
                                <input type="hidden" id="modal-category-en" value="">
                                <input type="hidden" id="modal-category-ko" value="">
                            </td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50"><?php echo t('product.box_packaging'); ?></td>
                            <td class="px-4 py-2">
                                <input type="number" id="modal-pieces-per-box" class="w-full px-2 py-1 text-sm border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500" min="1">
                            </td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50 align-top"><?php echo t('product.description'); ?></td>
                            <td class="px-4 py-2">
                                <textarea id="modal-description" class="w-full px-2 py-1 text-sm border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500" rows="3"></textarea>
                            </td>
                        </tr>
                        <tr class="border-b">
                            <td class="px-4 py-2 font-semibold bg-gray-50"><?php echo t('product.status'); ?></td>
                            <td class="px-4 py-2">
                                <select id="modal-status" class="w-full px-2 py-1 text-sm border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500">
                                    <option value="1">활성</option>
                                    <option value="0">비활성</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <td class="px-4 py-2 font-semibold bg-gray-50"><?php echo t('product.last_modified'); ?></td>
                            <td class="px-4 py-2" id="modal-last-modified"></td>
                        </tr>
                    </tbody>
                </table>
                
                <!-- 저장 버튼 추가 -->
                <div class="flex justify-end mt-4">
                    <button id="save-all-product-details-btn" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 disabled:bg-gray-400 disabled:cursor-not-allowed">
                        <i class="fas fa-save mr-2"></i><?php echo t('common.save'); ?> 변경사항
                    </button>
                </div>

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
                            <label class="block text-xs font-medium text-blue-700 mb-1"><?php echo t('purchase_product.selected_cost_price'); ?></label>
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
                                       placeholder="<?php echo t('purchase_product.direct_input'); ?>" min="0" max="100" step="0.1">
                                <span class="text-xs text-gray-600">%</span>
                                <button id="apply-custom-margin-btn" class="px-2 py-1 text-xs bg-gray-100 text-gray-700 rounded hover:bg-gray-200"><?php echo t('purchase_product.apply'); ?></button>
                            </div>
                        </div>
                        
                        <!-- 계산된 판매가 -->
                        <div class="flex-shrink-0">
                            <label class="block text-xs font-medium text-blue-700 mb-1"><?php echo t('purchase_product.calculated_selling_price'); ?></label>
                            <div class="flex items-center space-x-2">
                                <input type="number" id="new-selling-price" 
                                       class="w-24 px-2 py-1 text-sm border border-blue-300 rounded-md focus:ring-blue-500 focus:border-blue-500" 
                                       placeholder="<?php echo t('product.selling_price'); ?>">
                                <button id="apply-selling-price-btn" 
                                        class="px-3 py-1 text-sm bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:ring-2 focus:ring-blue-500">
                                    <?php echo t('purchase_product.apply'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 마진 정보 표시 -->
                    <div class="flex justify-between items-center text-xs">
                        <div class="flex space-x-4">
                            <span class="text-blue-600">
                                <i class="fas fa-calculator mr-1"></i>
                                <?php echo t('purchase_product.actual_margin_rate'); ?>: <span id="margin-rate">0%</span>
                            </span>
                            <span class="text-gray-500">
                                <i class="fas fa-info-circle mr-1"></i>
                                <?php echo t('product.recommended_margin'); ?>: <span id="recommended-margin-rate">30%</span>
                            </span>
                        </div>
                        <button id="cancel-pricing-btn" class="text-blue-600 hover:text-blue-800">
                            <?php echo t('common.cancel'); ?>
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
        applyMargin: <?php echo json_encode(t('purchase_product.js_apply_margin')); ?>,
        productNameRequired: <?php echo json_encode(t('product.enter_korean_name')); ?>,
        productNameTooLong: '상품명은 255자 이내로 입력해주세요.'
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
        description: document.getElementById('modal-description'),
        brandEn: document.getElementById('modal-brand-en'),
        brandKo: document.getElementById('modal-brand-ko'),
        brandId: document.getElementById('modal-brand-id'),
        categoryEn: document.getElementById('modal-category-en'),
        categoryKo: document.getElementById('modal-category-ko'),
        categoryId: document.getElementById('modal-category-id'),
        piecesPerBox: document.getElementById('modal-pieces-per-box'),
        inventoryWrapper: document.getElementById('modal-inventory-wrapper'),
        totalStock: document.getElementById('modal-total-stock'),
        image: document.getElementById('modal-image'),
        statusSelect: document.getElementById('modal-status'),
        lastModified: document.getElementById('modal-last-modified'),
        loading: document.getElementById('modal-loading'),
        bodyWrapper: document.getElementById('modal-body-wrapper'),
        imageContent: document.getElementById('modal-image-content')
    };
    
    // 브랜드와 카테고리 데이터 저장용
    let brandsData = [];
    let categoriesData = [];
    let originalProductData = {};

    function showModal() {
        modal.classList.remove('hidden');
    }

    function hideModal() {
        modal.classList.add('hidden');
        // Reset content
        modalContent.loading.style.display = 'block';
        modalContent.bodyWrapper.classList.add('hidden');
        
        // 변경사항 확인
        if (currentProductId && hasChanges()) {
            if (confirm('저장하지 않은 변경사항이 있습니다. 그래도 닫으시겠습니까?')) {
                // 원본 데이터 초기화
                originalProductData = {};
                currentProductId = null;
            } else {
                modal.classList.remove('hidden');
                return;
            }
        }
        
        // 원본 데이터 초기화
        originalProductData = {};
        currentProductId = null;
    }

    closeModalBtn.addEventListener('click', hideModal);
    modal.addEventListener('click', function(e) {
        // Close if clicking on the background overlay
        if (e.target === modal) {
            hideModal();
        }
    });

    // 브랜드 검색 기능
    let brandSearchTimeout = null;
    const brandSearchResults = document.getElementById('brand-search-results');
    const brandSearchInput = document.getElementById('modal-brand-search');
    const brandDisplay = document.getElementById('modal-brand-display');
    const brandNewBtn = document.getElementById('modal-brand-new-btn');
    
    // 브랜드 검색 입력 이벤트
    brandSearchInput.addEventListener('input', function() {
        clearTimeout(brandSearchTimeout);
        const searchTerm = this.value.trim();
        
        brandSearchTimeout = setTimeout(() => {
            if (searchTerm.length > 0) {
                searchBrands(searchTerm);
            } else {
                brandSearchResults.classList.add('hidden');
            }
        }, 300);
    });
    
    brandSearchInput.addEventListener('focus', function() {
        const searchTerm = this.value.trim();
        if (searchTerm.length > 0) {
            searchBrands(searchTerm);
        } else {
            // 포커스 시 빈 검색어면 전체 목록 표시
            searchBrands('');
        }
    });
    
    // 브랜드 신규등록 버튼 클릭 이벤트
    brandNewBtn.addEventListener('click', function() {
        showBrandRegistrationModal();
    });
    
    function searchBrands(query) {
        fetch(`ajax_search_brands.php?q=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    displayBrandSearchResults(result.brands, query);
                }
            })
            .catch(error => console.error('Brand search error:', error));
    }
    
    function displayBrandSearchResults(brands, query) {
        brandSearchResults.innerHTML = '';
        
        if (brands.length > 0) {
            brands.forEach(brand => {
                const div = document.createElement('div');
                div.className = 'px-3 py-2 hover:bg-gray-100 cursor-pointer text-sm';
                // 영문명과 한글명 모두 표시
                let displayText = brand.name_en || '';
                if (brand.name_ko || brand.name) {
                    if (displayText) {
                        displayText += ` / ${brand.name_ko || brand.name}`;
                    } else {
                        displayText = brand.name_ko || brand.name || '';
                    }
                }
                div.textContent = displayText;
                div.onclick = function() {
                    // 선택된 브랜드 정보 저장
                    modalContent.brandEn.value = brand.name_en || '';
                    modalContent.brandKo.value = brand.name_ko || brand.name || '';
                    modalContent.brandId.value = brand.id;
                    
                    // 선택된 브랜드 표시
                    let displayText = '';
                    if (brand.name_en) {
                        displayText = brand.name_en;
                    }
                    if (brand.name_ko || brand.name) {
                        if (displayText) {
                            displayText += ' / ' + (brand.name_ko || brand.name);
                        } else {
                            displayText = brand.name_ko || brand.name;
                        }
                    }
                    brandDisplay.textContent = displayText || '선택된 브랜드 없음';
                    
                    // 검색 입력 초기화 및 결과 숨기기
                    brandSearchInput.value = '';
                    brandSearchResults.classList.add('hidden');
                };
                brandSearchResults.appendChild(div);
            });
        }
        
        // 브랜드 선택 취소 옵션 추가
        if (modalContent.brandId.value) {
            const clearDiv = document.createElement('div');
            clearDiv.className = 'px-3 py-2 bg-red-50 hover:bg-red-100 cursor-pointer text-sm font-medium text-red-700 border-t';
            clearDiv.innerHTML = `<i class="fas fa-times mr-2"></i>브랜드 선택 취소`;
            clearDiv.onclick = function() {
                // 브랜드 선택 취소
                modalContent.brandEn.value = '';
                modalContent.brandKo.value = '';
                modalContent.brandId.value = '';
                brandDisplay.textContent = '선택된 브랜드 없음';
                brandSearchInput.value = '';
                brandSearchResults.classList.add('hidden');
            };
            brandSearchResults.appendChild(clearDiv);
        }
        
        brandSearchResults.classList.remove('hidden');
    }
    
    function addNewBrand(name) {
        const formData = new FormData();
        formData.append('name', name);
        
        fetch('ajax_add_brand_quick.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(result => {
            if (result.success) {
                modalContent.brandEn.value = result.data.name_en || '';
                modalContent.brandKo.value = result.data.name_ko || result.data.name || '';
                modalContent.brandId.value = result.data.id;
                
                // 선택된 브랜드 표시 업데이트
                let displayText = '';
                if (result.data.name_en) {
                    displayText = result.data.name_en;
                }
                if (result.data.name_ko || result.data.name) {
                    if (displayText) {
                        displayText += ' / ' + (result.data.name_ko || result.data.name);
                    } else {
                        displayText = result.data.name_ko || result.data.name;
                    }
                }
                brandDisplay.textContent = displayText || '선택된 브랜드 없음';
                brandSearchInput.value = '';
                brandSearchResults.classList.add('hidden');
                showToast(result.message, result.exists ? 'info' : 'success');
            } else {
                console.error('Brand add failed:', result.message);
                showToast(result.message, 'error');
            }
        })
        .catch(error => {
            console.error('Brand add error:', error);
            showToast('브랜드 추가 중 오류가 발생했습니다: ' + error.message, 'error');
        });
    }
    
    // 카테고리 검색 기능 (팝업으로 교체됨 - 주석처리)
    // let categorySearchTimeout = null;
    // const categorySearchResults = document.getElementById('category-search-results');
    // const categorySearchInput = document.getElementById('modal-category-search');
    const categoryDisplay = document.getElementById('modal-category-display');
    const categoryNewBtn = document.getElementById('modal-category-new-btn');
    
    // 카테고리 검색 입력 이벤트 (팝업으로 교체됨 - 주석처리)
    /*
    categorySearchInput.addEventListener('input', function() {
        clearTimeout(categorySearchTimeout);
        const searchTerm = this.value.trim();
        
        categorySearchTimeout = setTimeout(() => {
            if (searchTerm.length > 0) {
                searchCategories(searchTerm);
            } else {
                categorySearchResults.classList.add('hidden');
            }
        }, 300);
    });
    
    categorySearchInput.addEventListener('focus', function() {
        const searchTerm = this.value.trim();
        if (searchTerm.length > 0) {
            searchCategories(searchTerm);
        } else {
            // 포커스 시 빈 검색어면 전체 목록 표시
            searchCategories('');
        }
    });
    */
    
    // 카테고리 신규등록 버튼 클릭 이벤트
    categoryNewBtn.addEventListener('click', function() {
        showCategoryRegistrationModal();
    });
    
    // 기존 검색 함수들 (팝업으로 교체됨 - 주석처리)
    /*
    function searchCategories(query) {
        fetch(`ajax_search_categories.php?q=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    displayCategorySearchResults(result.categories, query);
                }
            })
            .catch(error => console.error('Category search error:', error));
    }
    
    function displayCategorySearchResults(categories, query) {
        categorySearchResults.innerHTML = '';
        
        if (categories.length > 0) {
            categories.forEach(category => {
                const div = document.createElement('div');
                div.className = 'px-3 py-2 hover:bg-gray-100 cursor-pointer text-sm';
                // 영문명과 한글명 모두 표시
                let displayText = category.name_en || '';
                if (category.name_ko || category.name) {
                    if (displayText) {
                        displayText += ` / ${category.name_ko || category.name}`;
                    } else {
                        displayText = category.name_ko || category.name || '';
                    }
                }
                div.textContent = displayText;
                div.onclick = function() {
                    // 선택된 카테고리 정보 저장
                    modalContent.categoryEn.value = category.name_en || '';
                    modalContent.categoryKo.value = category.name_ko || category.name || '';
                    modalContent.categoryId.value = category.id;
                    
                    // 선택된 카테고리 표시
                    let displayText = '';
                    if (category.name_en) {
                        displayText = category.name_en;
                    }
                    if (category.name_ko || category.name) {
                        if (displayText) {
                            displayText += ' / ' + (category.name_ko || category.name);
                        } else {
                            displayText = category.name_ko || category.name;
                        }
                    }
                    categoryDisplay.textContent = displayText || '선택된 카테고리 없음';
                    
                    // 검색 입력 초기화 및 결과 숨기기
                    categorySearchInput.value = '';
                    categorySearchResults.classList.add('hidden');
                };
                categorySearchResults.appendChild(div);
            });
        }
        
        // 카테고리 선택 취소 옵션 추가
        if (modalContent.categoryId.value) {
            const clearDiv = document.createElement('div');
            clearDiv.className = 'px-3 py-2 bg-red-50 hover:bg-red-100 cursor-pointer text-sm font-medium text-red-700 border-t';
            clearDiv.innerHTML = `<i class="fas fa-times mr-2"></i>카테고리 선택 취소`;
            clearDiv.onclick = function() {
                // 카테고리 선택 취소
                modalContent.categoryEn.value = '';
                modalContent.categoryKo.value = '';
                modalContent.categoryId.value = '';
                categoryDisplay.textContent = '선택된 카테고리 없음';
                categorySearchInput.value = '';
                categorySearchResults.classList.add('hidden');
            };
            categorySearchResults.appendChild(clearDiv);
        }
        
        categorySearchResults.classList.remove('hidden');
    }
    */
    
    function addNewCategory(name) {
        const formData = new FormData();
        formData.append('name', name);
        
        fetch('ajax_add_category_quick.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(result => {
            if (result.success) {
                modalContent.categoryEn.value = result.data.name_en || '';
                modalContent.categoryKo.value = result.data.name_ko || result.data.name || '';
                modalContent.categoryId.value = result.data.id;
                
                // 선택된 카테고리 표시 업데이트
                let displayText = '';
                if (result.data.name_en) {
                    displayText = result.data.name_en;
                }
                if (result.data.name_ko || result.data.name) {
                    if (displayText) {
                        displayText += ' / ' + (result.data.name_ko || result.data.name);
                    } else {
                        displayText = result.data.name_ko || result.data.name;
                    }
                }
                categoryDisplay.textContent = displayText || '선택된 카테고리 없음';
                // categorySearchInput.value = '';
                // categorySearchResults.classList.add('hidden');
                showToast(result.message, result.exists ? 'info' : 'success');
            } else {
                console.error('Category add failed:', result.message);
                showToast(result.message, 'error');
            }
        })
        .catch(error => {
            console.error('Category add error:', error);
            showToast('카테고리 추가 중 오류가 발생했습니다: ' + error.message, 'error');
        });
    }
    
    // 모달 외부 클릭 시 검색 결과 숨기기
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#modal-brand-search') && !e.target.closest('#brand-search-results')) {
            brandSearchResults.classList.add('hidden');
        }
        // 카테고리 검색 관련 코드 (팝업으로 교체됨 - 주석처리)
        // if (!e.target.closest('#modal-category-search') && !e.target.closest('#category-search-results')) {
        //     categorySearchResults.classList.add('hidden');
        // }
    });
    
    // 전역 함수로 showProductDetails 정의
    window.showProductDetails = function(productId) {
        showModal();
        currentProductId = productId;
        
        const url = `ajax_get_product_details.php?id=${productId}${currentStoreId ? `&store_id=${currentStoreId}` : ''}`;
        fetch(url)
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    const product = result.data;
                    
                    // 원본 데이터 저장
                    originalProductData = {
                        name_en: product.name_en || '',
                        name_ko: product.name_ko || '',
                        sku: product.sku || '',
                        brand_id: product.brand_id || '',
                        category_id: product.category_id || '',
                        pieces_per_box: product.pieces_per_box || 1,
                        description: product.description || '',
                        is_active: product.is_active || 1
                    };
                    
                    // 폼 필드에 데이터 채우기
                    modalContent.name.textContent = translations.productDetails;
                    modalContent.nameEn.value = product.name_en || '';
                    modalContent.nameKo.value = product.name_ko || '';
                    modalContent.sku.value = product.sku || '';
                    
                    // 브랜드 설정
                    modalContent.brandEn.value = product.brand_name_en || '';
                    modalContent.brandKo.value = product.brand_name_ko || '';
                    modalContent.brandId.value = product.brand_id || '';
                    
                    // 브랜드 표시 업데이트
                    let brandDisplayText = '';
                    if (product.brand_name_en) {
                        brandDisplayText = product.brand_name_en;
                    }
                    if (product.brand_name_ko) {
                        if (brandDisplayText) {
                            brandDisplayText += ' / ' + product.brand_name_ko;
                        } else {
                            brandDisplayText = product.brand_name_ko;
                        }
                    }
                    brandDisplay.textContent = brandDisplayText || '선택된 브랜드 없음';
                    
                    // 카테고리 설정
                    modalContent.categoryEn.value = product.category_name_en || '';
                    modalContent.categoryKo.value = product.category_name_ko || product.category_name || '';
                    modalContent.categoryId.value = product.category_id || '';
                    
                    // 카테고리 표시 업데이트
                    let categoryDisplayText = '';
                    if (product.category_name_en) {
                        categoryDisplayText = product.category_name_en;
                    }
                    if (product.category_name_ko || product.category_name) {
                        if (categoryDisplayText) {
                            categoryDisplayText += ' / ' + (product.category_name_ko || product.category_name);
                        } else {
                            categoryDisplayText = product.category_name_ko || product.category_name;
                        }
                    }
                    categoryDisplay.textContent = categoryDisplayText || '선택된 카테고리 없음';
                    
                    modalContent.piecesPerBox.value = product.pieces_per_box || 1;
                    modalContent.description.value = product.description || '';
                    modalContent.statusSelect.value = product.is_active !== undefined ? product.is_active : 1;
                    
                    // Inventory and Pricing by Store
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
                            const storeCostPrice = inv.cost_price ? `${parseFloat(inv.cost_price).toLocaleString()}` : '-';
                            const storeSellingPrice = inv.selling_price ? `${parseFloat(inv.selling_price).toLocaleString()}` : '-';
                            
                            // 마진율 계산
                            let marginRate = '-';
                            if (inv.cost_price && inv.selling_price && parseFloat(inv.cost_price) > 0) {
                                const margin = ((parseFloat(inv.selling_price) - parseFloat(inv.cost_price)) / parseFloat(inv.cost_price)) * 100;
                                marginRate = `${margin.toFixed(1)}%`;
                            }
                            
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

                    // Meta (status는 이제 select로 변경됨)
                    
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
                                    const storeCostPrice = inv.cost_price ? `${parseFloat(inv.cost_price).toLocaleString()}` : '-';
                                    const storeSellingPrice = inv.selling_price ? `${parseFloat(inv.selling_price).toLocaleString()}` : '-';
                                    
                                    // 마진율 계산
                                    let marginRate = '-';
                                    if (inv.cost_price && inv.selling_price && parseFloat(inv.cost_price) > 0) {
                                        const margin = ((parseFloat(inv.selling_price) - parseFloat(inv.cost_price)) / parseFloat(inv.cost_price)) * 100;
                                        marginRate = `${margin.toFixed(1)}%`;
                                    }
                                    
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
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(result => {
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
    
    // 변경사항 감지
    function hasChanges() {
        return (
            modalContent.nameEn.value !== originalProductData.name_en ||
            modalContent.nameKo.value !== originalProductData.name_ko ||
            modalContent.brandId.value !== String(originalProductData.brand_id || '') ||
            modalContent.categoryId.value !== String(originalProductData.category_id || '') ||
            modalContent.piecesPerBox.value !== String(originalProductData.pieces_per_box) ||
            modalContent.description.value !== originalProductData.description ||
            modalContent.statusSelect.value !== String(originalProductData.is_active)
        );
    }
    
    // 입력 필드 변경 감지
    const watchFields = [
        modalContent.nameEn, modalContent.nameKo,
        modalContent.brandKo, modalContent.brandEn, modalContent.categoryKo, modalContent.categoryEn,
        modalContent.piecesPerBox, modalContent.description, modalContent.statusSelect
    ];
    
    watchFields.forEach(field => {
        if (field) {
            field.addEventListener('input', function() {
                const saveBtn = document.getElementById('save-all-product-details-btn');
                if (hasChanges()) {
                    saveBtn.classList.remove('disabled:bg-gray-400');
                    saveBtn.classList.add('bg-blue-600', 'hover:bg-blue-700');
                } else {
                    saveBtn.classList.add('disabled:bg-gray-400');
                }
            });
            
            field.addEventListener('change', function() {
                const saveBtn = document.getElementById('save-all-product-details-btn');
                if (hasChanges()) {
                    saveBtn.classList.remove('disabled:bg-gray-400');
                    saveBtn.classList.add('bg-blue-600', 'hover:bg-blue-700');
                } else {
                    saveBtn.classList.add('disabled:bg-gray-400');
                }
            });
        }
    });
    
    // 모든 상품 정보 저장
    document.getElementById('save-all-product-details-btn').addEventListener('click', function() {
        if (!currentProductId || !hasChanges()) {
            showToast('변경된 내용이 없습니다.', 'info');
            return;
        }
        
        // 유효성 검사
        if (!modalContent.nameKo.value.trim()) {
            showToast('한글 상품명은 필수입니다.', 'error');
            modalContent.nameKo.focus();
            return;
        }
        
        const saveBtn = this;
        const originalText = saveBtn.innerHTML;
        
        // 저장 중 상태
        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>' + translations.savingText;
        saveBtn.disabled = true;
        
        const formData = new FormData();
        formData.append('product_id', currentProductId);
        formData.append('name_en', modalContent.nameEn.value.trim());
        formData.append('name_ko', modalContent.nameKo.value.trim());
        formData.append('brand_id', modalContent.brandId.value);
        formData.append('category_id', modalContent.categoryId.value);
        formData.append('pieces_per_box', modalContent.piecesPerBox.value);
        formData.append('description', modalContent.description.value.trim());
        formData.append('is_active', modalContent.statusSelect.value);
        
        fetch('ajax_update_product_details.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                showToast(result.message, 'success');
                
                // 원본 데이터 업데이트
                originalProductData = {
                    name_en: modalContent.nameEn.value,
                    name_ko: modalContent.nameKo.value,
                    sku: modalContent.sku.value,
                    brand_id: modalContent.brandId.value,
                    category_id: modalContent.categoryId.value,
                    pieces_per_box: modalContent.piecesPerBox.value,
                    description: modalContent.description.value,
                    is_active: modalContent.statusSelect.value
                };
                
                // 테이블 목록 업데이트
                updateProductInTable(currentProductId);
                
                // 저장 버튼 비활성화
                saveBtn.classList.add('disabled:bg-gray-400');
                
                // 모달 닫기
                hideModal();
                
            } else {
                showToast(`오류: ${result.message}`, 'error');
            }
        })
        .catch(error => {
            console.error('상품 정보 저장 오류:', error);
            showToast('상품 정보 저장 중 오류가 발생했습니다.', 'error');
        })
        .finally(() => {
            saveBtn.innerHTML = originalText;
            saveBtn.disabled = false;
        });
    });
    
    // 테이블에서 상품 정보 업데이트 함수
    function updateProductInTable(productId) {
        // 테이블의 모든 행을 확인하여 해당 상품의 정보를 업데이트
        const tableRows = document.querySelectorAll('tbody tr[onclick*="showProductDetails"]');
        
        tableRows.forEach(row => {
            const onclickAttr = row.getAttribute('onclick');
            if (onclickAttr && onclickAttr.includes(`showProductDetails(${productId})`)) {
                // 해당 상품 행을 찾았으면 상품명 업데이트
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
                    
                    if (nameEn) {
                        nameEn.textContent = modalContent.nameEn.value;
                    }
                    if (nameKo) {
                        nameKo.textContent = modalContent.nameKo.value;
                    }
                }
                
                // 박스당 수량으로 재고 수량 재계산 (필요한 경우)
                // 이 부분은 purchase_products 데이터 구조에 따라 조정 필요
            }
        });
    }


    // ESC 키로 모달 닫기
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            hideModal();
        }
    });

    // 브랜드 신규등록 모달 함수
    function showBrandRegistrationModal() {
        const modalHtml = `
            <div id="brand-registration-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
                <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
                    <div class="mt-3">
                        <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">브랜드 신규등록</h3>
                        <div class="mt-2">
                            <div class="mb-3">
                                <label class="block text-sm font-medium text-gray-700 mb-1">영문명</label>
                                <input type="text" id="new-brand-en" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div class="mb-3">
                                <label class="block text-sm font-medium text-gray-700 mb-1">한글명</label>
                                <input type="text" id="new-brand-ko" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>
                        <div class="mt-4 flex justify-end space-x-2">
                            <button id="brand-reg-cancel" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400">취소</button>
                            <button id="brand-reg-save" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">저장</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        const modal = document.getElementById('brand-registration-modal');
        const cancelBtn = document.getElementById('brand-reg-cancel');
        const saveBtn = document.getElementById('brand-reg-save');
        const brandEnInput = document.getElementById('new-brand-en');
        const brandKoInput = document.getElementById('new-brand-ko');
        
        cancelBtn.onclick = function() {
            modal.remove();
        };
        
        saveBtn.onclick = function() {
            const brandNameKo = brandKoInput.value.trim();
            const brandNameEn = brandEnInput.value.trim();
            
            if (brandNameKo || brandNameEn) {
                // 새로운 브랜드 추가 함수 호출
                const formData = new FormData();
                formData.append('name_ko', brandNameKo);
                formData.append('name_en', brandNameEn);
                
                fetch('ajax_add_brand_full.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        // 브랜드 정보 업데이트
                        modalContent.brandEn.value = result.data.name_en || '';
                        modalContent.brandKo.value = result.data.name_ko || '';
                        modalContent.brandId.value = result.data.id;
                        
                        // 브랜드 표시 업데이트
                        let displayText = '';
                        if (result.data.name_en) {
                            displayText = result.data.name_en;
                        }
                        if (result.data.name_ko) {
                            if (displayText) {
                                displayText += ' / ' + result.data.name_ko;
                            } else {
                                displayText = result.data.name_ko;
                            }
                        }
                        brandDisplay.textContent = displayText || '선택된 브랜드 없음';
                        brandSearchInput.value = '';
                        
                        showToast(result.message, result.exists ? 'info' : 'success');
                    } else {
                        showToast(result.message, 'error');
                    }
                    modal.remove();
                })
                .catch(error => {
                    console.error('Brand add error:', error);
                    showToast('브랜드 추가 중 오류가 발생했습니다.', 'error');
                    modal.remove();
                });
            } else {
                showToast('브랜드명을 입력해주세요.', 'error');
            }
        };
        
        // ESC 키로 닫기
        modal.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                modal.remove();
            }
        });
    }
    
    // 카테고리 신규등록 모달 함수
    function showCategoryRegistrationModal() {
        const modalHtml = `
            <div id="category-registration-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
                <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
                    <div class="mt-3">
                        <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">카테고리 신규등록</h3>
                        <div class="mt-2">
                            <div class="mb-3">
                                <label class="block text-sm font-medium text-gray-700 mb-1">영문명</label>
                                <input type="text" id="new-category-en" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div class="mb-3">
                                <label class="block text-sm font-medium text-gray-700 mb-1">한글명</label>
                                <input type="text" id="new-category-ko" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>
                        <div class="mt-4 flex justify-end space-x-2">
                            <button id="category-reg-cancel" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400">취소</button>
                            <button id="category-reg-save" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">저장</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        const modal = document.getElementById('category-registration-modal');
        const cancelBtn = document.getElementById('category-reg-cancel');
        const saveBtn = document.getElementById('category-reg-save');
        const categoryEnInput = document.getElementById('new-category-en');
        const categoryKoInput = document.getElementById('new-category-ko');
        
        cancelBtn.onclick = function() {
            modal.remove();
        };
        
        saveBtn.onclick = function() {
            const categoryNameKo = categoryKoInput.value.trim();
            const categoryNameEn = categoryEnInput.value.trim();
            
            if (categoryNameKo || categoryNameEn) {
                // 새로운 카테고리 추가 함수 호출
                const formData = new FormData();
                formData.append('name_ko', categoryNameKo);
                formData.append('name_en', categoryNameEn);
                
                fetch('ajax_add_category_full.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        // 카테고리 정보 업데이트
                        modalContent.categoryEn.value = result.data.name_en || '';
                        modalContent.categoryKo.value = result.data.name_ko || '';
                        modalContent.categoryId.value = result.data.id;
                        
                        // 카테고리 표시 업데이트
                        let displayText = '';
                        if (result.data.name_en) {
                            displayText = result.data.name_en;
                        }
                        if (result.data.name_ko) {
                            if (displayText) {
                                displayText += ' / ' + result.data.name_ko;
                            } else {
                                displayText = result.data.name_ko;
                            }
                        }
                        categoryDisplay.textContent = displayText || '선택된 카테고리 없음';
                        // categorySearchInput.value = '';
                        
                        showToast(result.message, result.exists ? 'info' : 'success');
                    } else {
                        showToast(result.message, 'error');
                    }
                    modal.remove();
                })
                .catch(error => {
                    console.error('Category add error:', error);
                    showToast('카테고리 추가 중 오류가 발생했습니다.', 'error');
                    modal.remove();
                });
            } else {
                showToast('카테고리명을 입력해주세요.', 'error');
            }
        };
        
        // ESC 키로 닫기
        modal.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                modal.remove();
            }
        });
    }
});
</script>

<script>
// 카테고리 팝업 HTML 생성 함수
function createCategoryPopup() {
    return `
        <div id="category-popup-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-50" style="display: none;">
            <div class="fixed inset-0 flex items-center justify-center p-4">
                <div class="bg-white rounded-lg shadow-xl overflow-hidden" style="width: 720px; max-height: 90vh;">
                    <div class="flex justify-between items-center p-2 border-b border-gray-200 bg-blue-50">
                        <h2 class="text-sm font-semibold text-blue-800">
                            <i class="fas fa-sitemap mr-1"></i>카테고리 선택
                        </h2>
                        <button id="category-popup-close" class="text-gray-400 hover:text-gray-600 text-lg">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <div class="p-1 border-b border-gray-200 bg-blue-50">
                        <div class="text-xs text-blue-700 text-center">
                            <span>대분류: 6개 | 소분류: 36개 | 총 42개</span>
                        </div>
                    </div>
                    
                    <div class="p-2 max-h-[450px] overflow-y-auto">
                        <div id="category-popup-grid" style="display: flex; gap: 10px;">
                            ${generateCategoryHTML()}
                        </div>
                    </div>
                    
                </div>
            </div>
        </div>
    `;
}

// 카테고리 HTML 생성 (데이터베이스에서 가져온 실제 카테고리 사용)
function generateCategoryHTML() {
    // 로딩 중 표시
    return `<div style="text-align: center; padding: 20px;">
        <i class="fas fa-spinner fa-spin"></i> 카테고리 로딩 중...
    </div>`;
}

// 실제 카테고리 데이터를 가져와서 HTML 생성
function loadAndGenerateCategoryHTML(callback) {
    fetch('ajax_get_all_categories.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const categories = data.grouped;
                let html = "<div style=\"flex: 1; padding-right: 5px;\">";
                
                // 왼쪽 컬럼: 신선식품, 가공식품, 간식/음료
                ['신선식품', '가공식품', '간식/음료'].forEach(groupName => {
                    if (categories[groupName]) {
                        html += generateCategoryGroup(groupName, categories[groupName]);
                    }
                });
                
                html += "</div><div style=\"flex: 1; padding-left: 5px;\">";
                
                // 오른쪽 컬럼: 생활용품, 주방/가정용품, 기타
                ['생활용품', '주방/가정용품', '기타'].forEach(groupName => {
                    if (categories[groupName]) {
                        html += generateCategoryGroup(groupName, categories[groupName]);
                    }
                });
                
                html += "</div>";
                
                if (callback) callback(html);
            } else {
                console.error('카테고리 로드 실패:', data.message);
                if (callback) callback(generateFallbackCategories());
            }
        })
        .catch(error => {
            console.error('카테고리 로드 오류:', error);
            if (callback) callback(generateFallbackCategories());
        });
}

// 폴백 카테고리 (API 실패 시 사용)
function generateFallbackCategories() {
    return `<div style="text-align: center; padding: 20px; color: red;">
        카테고리를 불러올 수 없습니다. 페이지를 새로고침해주세요.
    </div>`;
}

function generateCategoryGroup(title, categoryData) {
    // categoryData가 새로운 구조인지 확인
    const nameEn = categoryData.name_en || '';
    const items = categoryData.items || categoryData;
    
    let html = `
        <div style="border: 1px solid #d1d5db; border-radius: 4px; padding: 6px; background-color: #f9fafb; margin-bottom: 6px;">
            <h3 style="font-weight: 600; margin-bottom: 4px; color: #1e40af; font-size: 12px; padding: 2px 4px; background-color: #dbeafe; border-radius: 2px;">
                ${title} ${nameEn ? `<span style="color: #000000; font-weight: normal;">(${nameEn})</span>` : ''}
            </h3>
            <div style="display: flex; flex-direction: column; gap: 1px;">
    `;
    
    items.forEach(item => {
        // 영문명 전체 표시
        const fullEn = item.name_en || '';
        html += `
            <div class="category-item" style="display: flex; align-items: center; padding: 4px 6px; border-radius: 2px; cursor: pointer; border: 1px solid transparent; transition: all 0.1s; font-size: 13px;" 
                 data-category-id="${item.id}" data-category-name="${item.name}" data-category-name-en="${item.name_en}">
                <span style="color: #6b7280; margin-right: 3px; font-size: 12px;">▸</span>
                <span style="color: #111827; font-weight: 500;">${item.name}</span>
                <span style="color: #000000; margin-left: 4px; font-size: 12px;">(${fullEn})</span>
            </div>
        `;
    });
    
    html += "</div></div>";
    return html;
}

// 카테고리 팝업 관리 클래스
class CategoryPopup {
    constructor() {
        this.selectedCategory = null;
        this.onSelectCallback = null;
        this.isInitialized = false;
    }
    
    init() {
        if (this.isInitialized) return;
        
        document.body.insertAdjacentHTML("beforeend", createCategoryPopup());
        this.setupEventListeners();
        this.isInitialized = true;
        
        // 실제 카테고리 데이터 로드
        this.loadCategories();
    }
    
    loadCategories() {
        const popupGrid = document.getElementById("category-popup-grid");
        if (!popupGrid) return;
        
        loadAndGenerateCategoryHTML(function(html) {
            popupGrid.innerHTML = html;
        });
    }
    
    setupEventListeners() {
        const overlay = document.getElementById("category-popup-overlay");
        const closeBtn = document.getElementById("category-popup-close");
        
        closeBtn.addEventListener("click", () => this.close());
        
        overlay.addEventListener("click", (e) => {
            if (e.target === overlay) this.close();
        });
        
        document.addEventListener("keydown", (e) => {
            if (e.key === "Escape" && overlay.style.display !== "none") {
                this.close();
            }
        });
        
        // 카테고리 아이템 클릭 이벤트 - 클릭 시 바로 선택 및 닫기
        document.addEventListener("click", (e) => {
            if (e.target.closest(".category-item")) {
                const item = e.target.closest(".category-item");
                const category = {
                    id: item.dataset.categoryId,
                    name: item.dataset.categoryName,
                    name_en: item.dataset.categoryNameEn
                };
                
                // 바로 선택 처리 및 팝업 닫기
                if (this.onSelectCallback) {
                    this.onSelectCallback(category);
                }
                this.close();
            }
        });
    }
    
    open(onSelectCallback) {
        this.init();
        this.onSelectCallback = onSelectCallback;
        this.selectedCategory = null;
        
        document.getElementById("category-popup-overlay").style.display = "block";
        document.body.style.overflow = "hidden";
    }
    
    close() {
        document.getElementById("category-popup-overlay").style.display = "none";
        document.body.style.overflow = "auto";
    }
}

// 전역 인스턴스
window.categoryPopup = new CategoryPopup();

// 팝업 버튼 이벤트 등록
document.addEventListener("DOMContentLoaded", function() {
    const popupBtn = document.getElementById("modal-category-popup-btn");
    if (popupBtn) {
        popupBtn.addEventListener("click", function() {
            categoryPopup.open(function(selectedCategory) {
                // DOM 요소 직접 찾아서 업데이트
                const categoryIdInput = document.getElementById("modal-category-id");
                const categoryKoInput = document.getElementById("modal-category-ko");
                const categoryEnInput = document.getElementById("modal-category-en");
                
                if (categoryIdInput) categoryIdInput.value = selectedCategory.id;
                if (categoryKoInput) categoryKoInput.value = selectedCategory.name;
                if (categoryEnInput) categoryEnInput.value = selectedCategory.name_en;
                
                // 화면 표시 업데이트
                const categoryDisplay = document.getElementById("modal-category-display");
                if (categoryDisplay) {
                    categoryDisplay.textContent = `${selectedCategory.name} (${selectedCategory.name_en})`;
                }
                
                // 성공 메시지
                if (typeof showToast === "function") {
                    showToast(`카테고리 "${selectedCategory.name}"가 선택되었습니다.`, "success");
                }
            });
        });
    }
});

// =============================================================================
// 체크박스 및 일괄 작업 기능
// =============================================================================

let selectedProductIds = [];
let bulkCategoryPopup;
let bulkBrandSearchModal;

// 체크박스 관리
function initBulkActionCheckboxes() {
    const selectAllCheckbox = document.getElementById('select-all');
    const productCheckboxes = document.querySelectorAll('.product-checkbox');
    const bulkActionsDiv = document.getElementById('bulk-actions');
    const selectedCountSpan = document.getElementById('selected-count');
    const clearSelectionBtn = document.getElementById('clear-selection');

    // 전체 선택 체크박스 이벤트
    selectAllCheckbox.addEventListener('change', function() {
        const isChecked = this.checked;
        productCheckboxes.forEach(checkbox => {
            checkbox.checked = isChecked;
            
            // 각 행에 시각적 피드백 적용
            const row = checkbox.closest('tr');
            if (row) {
                if (isChecked) {
                    row.classList.add('bg-blue-50', 'border-blue-200');
                } else {
                    row.classList.remove('bg-blue-50', 'border-blue-200');
                }
            }
        });
        updateSelectedProducts();
    });

    // 개별 체크박스 이벤트
    productCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updateSelectedProducts();
            
            // 전체 선택 체크박스 상태 업데이트
            const checkedCount = document.querySelectorAll('.product-checkbox:checked').length;
            const totalCount = productCheckboxes.length;
            
            selectAllCheckbox.checked = checkedCount === totalCount;
            selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < totalCount;
            
            // 해당 행에 시각적 피드백 추가
            const row = this.closest('tr');
            if (row) {
                if (this.checked) {
                    row.classList.add('bg-blue-50', 'border-blue-200');
                } else {
                    row.classList.remove('bg-blue-50', 'border-blue-200');
                }
            }
        });
    });

    // 선택 해제 버튼 이벤트
    clearSelectionBtn.addEventListener('click', function() {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = false;
        productCheckboxes.forEach(checkbox => {
            checkbox.checked = false;
            
            // 각 행에서 시각적 피드백 제거
            const row = checkbox.closest('tr');
            if (row) {
                row.classList.remove('bg-blue-50', 'border-blue-200');
            }
        });
        updateSelectedProducts();
    });

    // 선택된 상품 업데이트 함수
    function updateSelectedProducts() {
        const checkedCheckboxes = document.querySelectorAll('.product-checkbox:checked');
        selectedProductIds = Array.from(checkedCheckboxes).map(cb => parseInt(cb.value));
        
        selectedCountSpan.textContent = selectedProductIds.length;
        
        // 버튼 활성화/비활성화
        const bulkCategoryBtn = document.getElementById('bulk-category-btn');
        const bulkBrandBtn = document.getElementById('bulk-brand-btn');
        
        if (selectedProductIds.length > 0) {
            bulkCategoryBtn.disabled = false;
            bulkBrandBtn.disabled = false;
        } else {
            bulkCategoryBtn.disabled = true;
            bulkBrandBtn.disabled = true;
        }
        
        // 선택된 상품 ID를 히든 필드로 업데이트
        updateSelectedProductsForm();
    }
    
    // 선택된 상품들을 폼의 히든 필드로 업데이트
    function updateSelectedProductsForm() {
        const container = document.getElementById('selected-products-container');
        container.innerHTML = '';
        
        selectedProductIds.forEach(productId => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_products[]';
            input.value = productId;
            container.appendChild(input);
        });
    }
}

// 일괄 카테고리 변경
function initBulkCategoryUpdate() {
    const bulkCategoryBtn = document.getElementById('bulk-category-btn');
    
    bulkCategoryBtn.addEventListener('click', function() {
        if (selectedProductIds.length === 0) {
            alert('선택된 상품이 없습니다.');
            return;
        }
        
        // 기존 카테고리 팝업 활용
        if (typeof categoryPopup !== 'undefined' && categoryPopup.open) {
            categoryPopup.open(function(selectedCategory) {
                // 폼에 카테고리 정보 설정하고 제출
                document.getElementById('bulk_action').value = 'update_category';
                document.getElementById('hidden_category_id').value = selectedCategory.id;
                
                // 확인 메시지
                const categoryName = selectedCategory.name || selectedCategory.name_en;
                if (confirm(`선택된 ${selectedProductIds.length}개 상품의 카테고리를 '${categoryName}'로 변경하시겠습니까?`)) {
                    document.getElementById('bulk-update-form').submit();
                }
            });
            
            // 백업 클릭 핸들러
            setTimeout(() => {
                const categoryItems = document.querySelectorAll('.category-item');
                categoryItems.forEach(item => {
                    item.addEventListener('click', function(e) {
                        e.stopPropagation();
                        const category = {
                            id: this.dataset.categoryId,
                            name: this.dataset.categoryName,
                            name_en: this.dataset.categoryNameEn
                        };
                        
                        categoryPopup.close();
                        
                        // 폼 제출
                        document.getElementById('bulk_action').value = 'update_category';
                        document.getElementById('hidden_category_id').value = category.id;
                        
                        const categoryName = category.name || category.name_en;
                        if (confirm(`선택된 ${selectedProductIds.length}개 상품의 카테고리를 '${categoryName}'로 변경하시겠습니까?`)) {
                            document.getElementById('bulk-update-form').submit();
                        }
                    }, { once: true });
                });
            }, 500);
        } else {
            alert('카테고리 선택 기능을 사용할 수 없습니다.');
        }
    });
}

// 일괄 브랜드 변경
function initBulkBrandUpdate() {
    const bulkBrandBtn = document.getElementById('bulk-brand-btn');
    
    bulkBrandBtn.addEventListener('click', function() {
        if (selectedProductIds.length === 0) {
            alert('선택된 상품이 없습니다.');
            return;
        }
        
        // 브랜드 선택 모달 표시
        showSimpleBrandSelectionModal();
    });
}


// PHP에서 로드된 브랜드 목록
const brandsList = <?php echo json_encode($brands_list); ?>;

// 간단한 브랜드 선택 모달 표시
function showSimpleBrandSelectionModal() {
    const modalHtml = `
        <div id="bulk-brand-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
            <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
                <div class="mt-3">
                    <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">
                        <i class="fas fa-tags mr-2"></i>브랜드 일괄 선택
                        <span class="text-sm font-normal text-gray-500 ml-2">(${selectedProductIds.length}개 상품)</span>
                    </h3>
                    
                    <!-- 브랜드 검색 -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">브랜드 검색</label>
                        <input type="text" id="bulk-brand-search" placeholder="브랜드명을 입력하세요..." 
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <div id="bulk-brand-search-results" class="hidden mt-2 max-h-48 overflow-y-auto border border-gray-200 rounded-md bg-white shadow-sm">
                        </div>
                        <div class="text-xs text-gray-500 mt-1">최소 1글자 이상 입력하면 검색 결과가 나타납니다.</div>
                    </div>
                    
                    <!-- 선택된 브랜드 표시 -->
                    <div class="mb-4 p-3 bg-gray-50 border border-gray-200 rounded-md">
                        <label class="block text-sm font-medium text-gray-700 mb-1">선택된 브랜드</label>
                        <div id="bulk-selected-brand" class="text-sm text-gray-600">선택된 브랜드 없음</div>
                    </div>
                    
                    <div class="flex justify-end space-x-2">
                        <button id="bulk-brand-cancel" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400">취소</button>
                        <button id="bulk-brand-apply" class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 disabled:bg-gray-300" disabled>적용</button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    
    const modal = document.getElementById('bulk-brand-modal');
    const searchInput = document.getElementById('bulk-brand-search');
    const searchResults = document.getElementById('bulk-brand-search-results');
    const selectedBrandDiv = document.getElementById('bulk-selected-brand');
    const cancelBtn = document.getElementById('bulk-brand-cancel');
    const applyBtn = document.getElementById('bulk-brand-apply');
    
    let selectedBrand = null;
    let searchTimeout = null;
    
    // 검색 기능
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const searchTerm = this.value.trim();
        
        searchTimeout = setTimeout(() => {
            if (searchTerm.length >= 1) {  // 최소 1글자 이상
                searchBrands(searchTerm);
            } else {
                searchResults.classList.add('hidden');
            }
        }, 300);
    });
    
    function searchBrands(searchTerm) {
        // PHP에서 로드된 브랜드 목록에서 검색
        const filteredBrands = brandsList.filter(brand => {
            const searchLower = searchTerm.toLowerCase();
            const nameKo = (brand.name_ko || '').toLowerCase();
            const nameEn = (brand.name_en || '').toLowerCase();
            return nameKo.includes(searchLower) || nameEn.includes(searchLower);
        });
        
        displayBrandSearchResults(filteredBrands);
    }
    
    function displayBrandSearchResults(brands) {
        searchResults.innerHTML = '';
        
        if (brands.length > 0) {
            brands.forEach(brand => {
                const div = document.createElement('div');
                div.className = 'px-3 py-2 hover:bg-gray-100 cursor-pointer text-sm border-b border-gray-100 last:border-b-0';
                
                let displayText = '';
                if (brand.name_en) displayText = brand.name_en;
                if (brand.name_ko) {
                    displayText += displayText ? ` / ${brand.name_ko}` : brand.name_ko;
                }
                
                div.textContent = displayText;
                div.onclick = function() {
                    selectedBrand = brand;
                    selectedBrandDiv.innerHTML = `<i class="fas fa-tags mr-2 text-purple-600"></i>${displayText}`;
                    searchInput.value = '';
                    searchResults.classList.add('hidden');
                    applyBtn.disabled = false;
                };
                searchResults.appendChild(div);
            });
        } else {
            const noResultDiv = document.createElement('div');
            noResultDiv.className = 'px-3 py-2 text-sm text-gray-500 text-center';
            noResultDiv.textContent = '검색 결과가 없습니다.';
            searchResults.appendChild(noResultDiv);
        }
        
        searchResults.classList.remove('hidden');
    }
    
    // 취소 버튼
    cancelBtn.onclick = function() {
        modal.remove();
    };
    
    // 적용 버튼
    applyBtn.onclick = function() {
        if (selectedBrand) {
            // 확인 메시지
            const brandName = selectedBrand.name_ko || selectedBrand.name_en;
            if (confirm(`선택된 ${selectedProductIds.length}개 상품의 브랜드를 '${brandName}'로 변경하시겠습니까?`)) {
                // 폼에 브랜드 정보 설정하고 제출
                document.getElementById('bulk_action').value = 'update_brand';
                document.getElementById('hidden_brand_id').value = selectedBrand.id;
                
                // 선택된 상품 ID들을 폼에 추가
                selectedProductIds.forEach(id => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'selected_products[]';
                    input.value = id;
                    document.getElementById('bulk-update-form').appendChild(input);
                });
                
                document.getElementById('bulk-update-form').submit();
            }
        }
    };
    
    // ESC 키로 닫기
    document.addEventListener('keydown', function escHandler(e) {
        if (e.key === 'Escape') {
            modal.remove();
            document.removeEventListener('keydown', escHandler);
        }
    });
    
    // 배경 클릭으로 닫기
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.remove();
        }
    });
    
    // 검색 입력란에 포커스
    searchInput.focus();
}

// 테이블의 카테고리 표시 업데이트
function updateTableCategories(productIds, category) {
    productIds.forEach(productId => {
        const row = document.querySelector(`tr[data-product-id="${productId}"]`);
        if (row) {
            <?php if ($display_mode === 'recent'): ?>
            const categoryCell = row.children[3]; // 체크박스, 날짜, 거래처 다음
            <?php else: ?>
            const categoryCell = row.children[2]; // 체크박스, 카테고리
            <?php endif; ?>
            if (categoryCell) {
                categoryCell.textContent = category.name || '-';
            }
        }
    });
}

// 테이블의 브랜드 표시 업데이트
function updateTableBrands(productIds, brand) {
    productIds.forEach(productId => {
        const row = document.querySelector(`tr[data-product-id="${productId}"]`);
        if (row) {
            <?php if ($display_mode === 'recent'): ?>
            const brandCell = row.children[5]; // 체크박스, 날짜, 거래처, 카테고리, SKU 다음
            <?php else: ?>
            const brandCell = row.children[4]; // 체크박스, 카테고리, SKU, 브랜드
            <?php endif; ?>
            if (brandCell) {
                let displayText = '';
                if (brand.name_en || brand.name_ko) {
                    if (brand.name_en) displayText = brand.name_en;
                    if (brand.name_ko) {
                        displayText += displayText ? ` / ${brand.name_ko}` : brand.name_ko;
                    }
                    brandCell.innerHTML = `<span class="font-medium">${displayText}</span>`;
                } else {
                    brandCell.innerHTML = '<span class="text-gray-400">-</span>';
                }
            }
        }
    });
}

// 테이블 행 클릭으로 체크박스 선택
function initRowClickSelection() {
    const tableRows = document.querySelectorAll('tbody tr[data-product-id]');
    
    tableRows.forEach(row => {
        row.addEventListener('click', function(e) {
            // 이미 체크박스나 버튼, 링크를 클릭한 경우는 무시
            if (e.target.type === 'checkbox' || 
                e.target.tagName === 'BUTTON' || 
                e.target.tagName === 'A' || 
                e.target.closest('button') || 
                e.target.closest('a')) {
                return;
            }
            
            // 해당 행의 체크박스 찾기
            const checkbox = this.querySelector('.product-checkbox');
            if (checkbox) {
                checkbox.checked = !checkbox.checked;
                
                // 체크박스 변경 이벤트 수동 트리거
                const changeEvent = new Event('change', { bubbles: true });
                checkbox.dispatchEvent(changeEvent);
                
                // 시각적 피드백
                if (checkbox.checked) {
                    this.classList.add('bg-blue-50', 'border-blue-200');
                } else {
                    this.classList.remove('bg-blue-50', 'border-blue-200');
                }
            }
        });
        
        // 마우스 오버 시 커서 변경
        row.style.cursor = 'pointer';
    });
}

// 초기화
document.addEventListener('DOMContentLoaded', function() {
    initBulkActionCheckboxes();
    initBulkCategoryUpdate();
    initBulkBrandUpdate();
    initRowClickSelection();
});
</script>

<style>
.category-item.selected {
    border: 1px solid #2563eb !important;
    background-color: #dbeafe !important;
}
.category-item:hover {
    background-color: #bfdbfe !important;
    border: 1px solid #93c5fd !important;
}
.category-item.selected span {
    color: #1e40af !important;
    font-weight: 600 !important;
}
</style>

<?php require_once __DIR__ . '/partials/footer.php'; ?>