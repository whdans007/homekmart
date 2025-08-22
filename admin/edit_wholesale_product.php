<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('wholesale.edit_product') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 도매판매 권한 확인
if (!has_permission('wholesale_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

$errors = [];
$wholesale_product = null;
$product = null;
$stores = [];

// ID 확인
$wholesale_product_id = (int)($_GET['id'] ?? 0);
if ($wholesale_product_id <= 0) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => t('messages.invalid_request')
    ];
    header('Location: wholesale_product_management.php');
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 도매상품 정보 가져오기 (스키마 호환성 처리)
    try {
        // 먼저 새로운 컬럼들이 존재하는지 확인
        $check_columns = $pdo->query("SHOW COLUMNS FROM wholesale_products LIKE 'wholesale_name_ko'");
        $has_new_columns = $check_columns->rowCount() > 0;
        
        if ($has_new_columns) {
            $stmt = $pdo->prepare("
                SELECT wp.*, 
                       wp.wholesale_name_ko, wp.wholesale_name_en, wp.wholesale_skus, wp.wholesale_description,
                       COALESCE(wp.sale_unit, 'box') as sale_unit,
                       COALESCE(wp.cost_price, p.cost_price, 0) as cost_price,
                       COALESCE(wp.margin_rate, 15.00) as margin_rate,
                       p.sku, p.name_ko, p.name_en, p.selling_price, s.name as store_name
                FROM wholesale_products wp
                LEFT JOIN products p ON wp.product_id = p.id
                LEFT JOIN stores s ON wp.store_id = s.id
                WHERE wp.id = ? AND wp.is_active = 1
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT wp.*, 
                       NULL as wholesale_name_ko, NULL as wholesale_name_en, NULL as wholesale_skus, NULL as wholesale_description,
                       'box' as sale_unit,
                       COALESCE(wp.cost_price, p.cost_price, 0) as cost_price,
                       COALESCE(wp.margin_rate, 15.00) as margin_rate,
                       p.sku, p.name_ko, p.name_en, p.selling_price, s.name as store_name
                FROM wholesale_products wp
                LEFT JOIN products p ON wp.product_id = p.id
                LEFT JOIN stores s ON wp.store_id = s.id
                WHERE wp.id = ? AND wp.is_active = 1
            ");
        }
    } catch (PDOException $e) {
        // 컬럼이 없는 경우 기본 쿼리 사용
        $stmt = $pdo->prepare("
            SELECT wp.*, 
                   NULL as wholesale_name_ko, NULL as wholesale_name_en, NULL as wholesale_skus, NULL as wholesale_description,
                   'box' as sale_unit,
                   COALESCE(wp.cost_price, p.cost_price, 0) as cost_price,
                   COALESCE(wp.margin_rate, 15.00) as margin_rate,
                   p.sku, p.name_ko, p.name_en, p.selling_price, s.name as store_name
            FROM wholesale_products wp
            LEFT JOIN products p ON wp.product_id = p.id
            LEFT JOIN stores s ON wp.store_id = s.id
            WHERE wp.id = ? AND wp.is_active = 1
        ");
    }
    $stmt->execute([$wholesale_product_id]);
    $wholesale_product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$wholesale_product) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => t('wholesale.product_not_found')
        ];
        header('Location: wholesale_product_management.php');
        exit;
    }
    
    // 권한 확인 (super_admin이 아닌 경우 자신의 점포만 수정 가능)
    if ($_SESSION['role'] !== 'super_admin' && $wholesale_product['store_id'] != $current_store_id) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => t('wholesale.cannot_edit_other_store')
        ];
        header('Location: wholesale_product_management.php');
        exit;
    }
    
    // 점포 목록 가져오기 (super_admin인 경우)
    if ($_SESSION['role'] === 'super_admin') {
        $store_stmt = $pdo->prepare("SELECT id, name FROM stores ORDER BY name");
        $store_stmt->execute();
        $stores = $store_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 최신 입고 이력 조회 (상위 3개) - 통합된 단일 조회
    $receiving_history = [];
    if ($wholesale_product) {
        try {
            $history_stmt = $pdo->prepare("
                SELECT 
                    pi.item_id,
                    pi.unit_price,
                    pi.quantity,
                    pi.purchase_type,
                    p.purchase_date as received_date,
                    s.name as supplier_name,
                    pr.sku,
                    pr.name_ko,
                    pr.name_en,
                    pr.barcode,
                    COALESCE(pr.pieces_per_box, 1) as pieces_per_box,
                    CASE 
                        WHEN pi.purchase_type = 'box' THEN pi.unit_price
                        WHEN pi.purchase_type = 'piece' THEN ROUND(pi.unit_price * COALESCE(pr.pieces_per_box, 1))
                        ELSE pi.unit_price
                    END as box_receiving_price,
                    CASE 
                        WHEN pi.purchase_type = 'piece' THEN pi.unit_price
                        WHEN pi.purchase_type = 'box' THEN ROUND(pi.unit_price / COALESCE(pr.pieces_per_box, 1), 2)
                        ELSE pi.unit_price
                    END as piece_receiving_price
                FROM purchase_items pi
                INNER JOIN purchases p ON pi.purchase_id = p.purchase_id
                LEFT JOIN suppliers s ON p.supplier_id = s.id
                INNER JOIN products pr ON pi.product_id = pr.id
                WHERE pi.product_id = ?
                ORDER BY p.purchase_date DESC, p.purchase_id DESC
                LIMIT 3
            ");
            
            $history_stmt->execute([$wholesale_product['product_id']]);
            $receiving_history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 날짜 포맷팅
            foreach ($receiving_history as &$item) {
                $item['received_date_formatted'] = date('Y-m-d', strtotime($item['received_date']));
            }
        } catch (PDOException $e) {
            error_log("Purchase history query error: " . $e->getMessage());
            $receiving_history = [];
        }
    }
    
} catch (PDOException $e) {
    $errors[] = t('messages.database_error') . ': ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 입력값 검증 (새로운 필드들 추가)
    $wholesale_name_ko = trim($_POST['wholesale_name_ko'] ?? '');
    $wholesale_name_en = trim($_POST['wholesale_name_en'] ?? '');
    $wholesale_skus_input = trim($_POST['wholesale_skus'] ?? '');
    $cost_price = trim($_POST['cost_price'] ?? '0');
    $cost_price_piece = trim($_POST['cost_price_piece'] ?? '0');
    $margin_rate = trim($_POST['margin_rate'] ?? '15');
    $wholesale_price = trim($_POST['wholesale_price'] ?? '');
    $wholesale_price_piece = trim($_POST['wholesale_price_piece'] ?? '0');
    $sale_unit = trim($_POST['sale_unit'] ?? 'box');
    $store_id = $_SESSION['role'] === 'super_admin' ? (int)($_POST['store_id'] ?? 0) : $wholesale_product['store_id'];
    
    // 도매 상품명 검증 (한국어 또는 영어 중 최소 하나는 필수)
    if (empty($wholesale_name_ko) && empty($wholesale_name_en)) {
        $errors[] = t('wholesale.name_required');
    }
    
    // SKU 검증 및 JSON 변환
    $wholesale_skus_json = null;
    if (!empty($wholesale_skus_input)) {
        $skus_array = array_map('trim', explode(',', $wholesale_skus_input));
        $skus_array = array_filter($skus_array); // 빈 값 제거
        if (!empty($skus_array)) {
            $wholesale_skus_json = json_encode($skus_array);
        }
    }
    
    if (empty($wholesale_price) || !is_numeric($wholesale_price) || $wholesale_price <= 0) {
        $errors[] = t('wholesale.valid_price_required');
    }
    
    if (!is_numeric($cost_price) || $cost_price < 0) {
        $errors[] = '올바른 원가를 입력해주세요.';
    }
    
    if (!is_numeric($margin_rate) || $margin_rate < 0 || $margin_rate > 1000) {
        $errors[] = '마진율은 0%에서 1000% 사이로 입력해주세요.';
    }
    
    
    if ($_SESSION['role'] === 'super_admin' && empty($store_id)) {
        $errors[] = t('messages.select_store');
    }
    
    if (empty($errors)) {
        try {
            // 같은 점포에 같은 상품이 이미 등록되어 있는지 확인 (자신 제외)
            $check_stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM wholesale_products 
                WHERE product_id = ? AND store_id = ? AND is_active = 1 AND id != ?
            ");
            $check_stmt->execute([$wholesale_product['product_id'], $store_id, $wholesale_product_id]);
            
            if ($check_stmt->fetchColumn() > 0) {
                $errors[] = t('wholesale.product_already_exists');
            } else {
                // 스키마 호환성에 따른 UPDATE 쿼리 선택
                if ($has_new_columns) {
                    // cost_price, margin_rate, sale_unit 컬럼 존재 확인
                    try {
                        $check_cost_column = $pdo->query("SHOW COLUMNS FROM wholesale_products LIKE 'cost_price'");
                        $has_cost_price_column = $check_cost_column->rowCount() > 0;
                        
                        $check_margin_column = $pdo->query("SHOW COLUMNS FROM wholesale_products LIKE 'margin_rate'");
                        $has_margin_rate_column = $check_margin_column->rowCount() > 0;
                        
                        $check_sale_unit_column = $pdo->query("SHOW COLUMNS FROM wholesale_products LIKE 'sale_unit'");
                        $has_sale_unit_column = $check_sale_unit_column->rowCount() > 0;
                        
                        $check_cost_piece_column = $pdo->query("SHOW COLUMNS FROM wholesale_products LIKE 'cost_price_piece'");
                        $has_cost_piece_column = $check_cost_piece_column->rowCount() > 0;
                    } catch (PDOException $e) {
                        $has_cost_price_column = false;
                        $has_margin_rate_column = false;
                        $has_sale_unit_column = false;
                        $has_cost_piece_column = false;
                    }
                    
                    if ($has_cost_price_column && $has_margin_rate_column && $has_sale_unit_column && $has_cost_piece_column) {
                        // 모든 새 컬럼이 있는 경우 (cost_price_piece 포함)
                        $stmt = $pdo->prepare("
                            UPDATE wholesale_products 
                            SET store_id = ?, 
                                wholesale_name_ko = ?, 
                                wholesale_name_en = ?, 
                                wholesale_skus = ?, 
                                wholesale_description = null,
                                cost_price = ?,
                                cost_price_piece = ?,
                                margin_rate = ?,
                                wholesale_price = ?,
                                wholesale_price_piece = ?,
                                sale_unit = ?,
                                updated_at = NOW() 
                            WHERE id = ?
                        ");
                        
                        $update_success = $stmt->execute([
                            $store_id, 
                            $wholesale_name_ko ?: null, 
                            $wholesale_name_en ?: null, 
                            $wholesale_skus_json, 
                            $cost_price,
                            $cost_price_piece,
                            $margin_rate,
                            $wholesale_price,
                            $wholesale_price_piece,
                            $sale_unit,
                            $wholesale_product_id
                        ]);
                    } elseif ($has_cost_price_column && $has_margin_rate_column && $has_cost_piece_column) {
                        // cost_price, margin_rate, cost_price_piece 컬럼이 있는 경우
                        $stmt = $pdo->prepare("
                            UPDATE wholesale_products 
                            SET store_id = ?, 
                                wholesale_name_ko = ?, 
                                wholesale_name_en = ?, 
                                wholesale_skus = ?, 
                                wholesale_description = null,
                                cost_price = ?,
                                cost_price_piece = ?,
                                margin_rate = ?,
                                wholesale_price = ?,
                                wholesale_price_piece = ?,
                                updated_at = NOW() 
                            WHERE id = ?
                        ");
                        
                        $update_success = $stmt->execute([
                            $store_id, 
                            $wholesale_name_ko ?: null, 
                            $wholesale_name_en ?: null, 
                            $wholesale_skus_json, 
                            $cost_price,
                            $cost_price_piece,
                            $margin_rate,
                            $wholesale_price,
                            $wholesale_price_piece,
                            $wholesale_product_id
                        ]);
                    } elseif ($has_cost_price_column) {
                        // cost_price 컬럼만 있는 경우
                        $stmt = $pdo->prepare("
                            UPDATE wholesale_products 
                            SET store_id = ?, 
                                wholesale_name_ko = ?, 
                                wholesale_name_en = ?, 
                                wholesale_skus = ?, 
                                wholesale_description = null,
                                wholesale_price = ?,
                                cost_price = ?, 
                                updated_at = NOW() 
                            WHERE id = ?
                        ");
                        
                        $update_success = $stmt->execute([
                            $store_id, 
                            $wholesale_name_ko ?: null, 
                            $wholesale_name_en ?: null, 
                            $wholesale_skus_json, 
                            $wholesale_price,
                            $cost_price, 
                            $wholesale_product_id
                        ]);
                    } else {
                        // cost_price 컬럼이 없는 경우 (기존 방식)
                        $stmt = $pdo->prepare("
                            UPDATE wholesale_products 
                            SET store_id = ?, 
                                wholesale_name_ko = ?, 
                                wholesale_name_en = ?, 
                                wholesale_skus = ?, 
                                wholesale_description = null,
                                wholesale_price = ?, 
                                updated_at = NOW() 
                            WHERE id = ?
                        ");
                        
                        $update_success = $stmt->execute([
                            $store_id, 
                            $wholesale_name_ko ?: null, 
                            $wholesale_name_en ?: null, 
                            $wholesale_skus_json, 
                            $wholesale_price, 
                            $wholesale_product_id
                        ]);
                    }
                } else {
                    // 기존 스키마 사용 (새 필드들은 업데이트하지 않음)
                    $stmt = $pdo->prepare("
                        UPDATE wholesale_products 
                        SET store_id = ?, 
                            wholesale_price = ?
                        WHERE id = ?
                    ");
                    
                    $update_success = $stmt->execute([
                        $store_id, 
                        $wholesale_price, 
                        $wholesale_product_id
                    ]);
                }
                
                if ($update_success) {
                    $_SESSION['flash'] = [
                        'type' => 'success',
                        'message' => t('wholesale.product_updated_success')
                    ];
                    header('Location: wholesale_product_management.php');
                    exit;
                } else {
                    $errors[] = t('wholesale.update_error');
                }
            }
        } catch (PDOException $e) {
            $errors[] = t('messages.database_error') . ': ' . $e->getMessage();
        }
    }
}

// 플래시 메시지 표시
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

?>

<div class="container-fluid px-4 py-6">
    <div class="max-w-7xl">
        <div class="mb-6">
            <nav class="flex" aria-label="Breadcrumb">
                <ol class="flex items-center space-x-2">
                    <li>
                        <a href="wholesale_product_management.php" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-box mr-1"></i>
                            <?php echo t('navigation.wholesale_product_management'); ?>
                        </a>
                    </li>
                    <li>
                        <div class="flex items-center">
                            <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                            <span class="text-gray-600"><?php echo t('common.edit_product'); ?></span>
                        </div>
                    </li>
                </ol>
            </nav>
        </div>

        <div class="bg-white shadow-sm rounded-lg border">
            <div class="px-6 py-4 border-b border-gray-200">
                <h1 class="text-xl font-semibold text-gray-900">
                    <i class="fas fa-edit mr-2 text-primary-500"></i>
                    <?php echo t('wholesale.edit_product'); ?>
                </h1>
                <p class="mt-1 text-sm text-gray-600"><?php echo t('wholesale.edit_product_description'); ?></p>
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
                                <h3 class="text-sm font-medium text-red-800"><?php echo t('messages.fix_errors'); ?>:</h3>
                                <ul class="mt-2 text-sm text-red-700 list-disc list-inside">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($wholesale_product): ?>
                <form method="POST" class="space-y-6">
                    <!-- 기준 상품 정보 표시 (수정 불가) -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <?php echo t('wholesale.base_product_info'); ?>
                        </label>
                        <div class="p-3 bg-gray-50 rounded-md border">
                            <div class="font-medium text-gray-900">
                                <?php echo htmlspecialchars($wholesale_product['name_en'] ?: $wholesale_product['name_ko']); ?>
                            </div>
                            <div class="text-sm text-gray-600 mt-1">
                                SKU: <?php echo htmlspecialchars($wholesale_product['sku']); ?>
                                <?php if ($wholesale_product['name_en'] && $wholesale_product['name_ko']): ?>
                                    | <?php echo htmlspecialchars($wholesale_product['name_ko']); ?>
                                <?php endif; ?>
                            </div>
                            <div class="text-xs text-gray-500 mt-1">
                                원가: <?php echo number_format($wholesale_product['cost_price']); ?> | 
                                <?php echo t('product.selling_price'); ?>: <?php echo number_format($wholesale_product['selling_price']); ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 도매 전용 상품 정보 -->
                    <?php if ($has_new_columns): ?>
                    <div class="bg-blue-50 p-3 rounded-lg border border-blue-200">
                        <h3 class="text-lg font-medium text-blue-900 mb-3">
                            <i class="fas fa-warehouse mr-2"></i>
                            <?php echo t('wholesale.wholesale_product_info'); ?>
                        </h3>
                        
                        <!-- 한줄 테이블 형태로 표시 -->
                        <div class="overflow-x-auto">
                            <table class="w-full border border-gray-300 rounded-md">
                                <thead>
                                    <tr class="bg-gray-100">
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-700 border-r border-gray-300">
                                            <?php echo t('wholesale.sku'); ?> (복수 가능)
                                        </th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-700 border-r border-gray-300">
                                            <?php echo t('wholesale.product_name_ko'); ?>
                                        </th>
                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-700">
                                            <?php echo t('wholesale.product_name_en'); ?>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="px-2 py-1 border-r border-gray-300" style="width: 30%;">
                                            <?php
                                            $current_skus = '';
                                            if (!empty($_POST['wholesale_skus'])) {
                                                $current_skus = $_POST['wholesale_skus'];
                                            } elseif (!empty($wholesale_product['wholesale_skus'])) {
                                                $skus_array = json_decode($wholesale_product['wholesale_skus'], true);
                                                if (is_array($skus_array)) {
                                                    $current_skus = implode(', ', $skus_array);
                                                }
                                            } else {
                                                $current_skus = $wholesale_product['sku'];
                                            }
                                            ?>
                                            <input type="text" name="wholesale_skus" id="wholesale_skus" 
                                                   value="<?php echo htmlspecialchars($current_skus); ?>"
                                                   class="w-full px-2 py-1 text-xs border border-gray-200 rounded focus:outline-none focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
                                                   placeholder="<?php echo t('wholesale.sku_placeholder'); ?>">
                                        </td>
                                        <td class="px-2 py-1 border-r border-gray-300" style="width: 35%;">
                                            <input type="text" name="wholesale_name_ko" id="wholesale_name_ko" 
                                                   value="<?php echo htmlspecialchars($_POST['wholesale_name_ko'] ?? $wholesale_product['wholesale_name_ko'] ?? $wholesale_product['name_ko']); ?>"
                                                   class="w-full px-2 py-1 text-xs border border-gray-200 rounded focus:outline-none focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
                                                   placeholder="<?php echo t('wholesale.product_name_ko_placeholder'); ?>">
                                        </td>
                                        <td class="px-2 py-1" style="width: 35%;">
                                            <input type="text" name="wholesale_name_en" id="wholesale_name_en" 
                                                   value="<?php echo htmlspecialchars($_POST['wholesale_name_en'] ?? $wholesale_product['wholesale_name_en'] ?? $wholesale_product['name_en']); ?>"
                                                   class="w-full px-2 py-1 text-xs border border-gray-200 rounded focus:outline-none focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
                                                   placeholder="<?php echo t('wholesale.product_name_en_placeholder'); ?>">
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                    </div>
                    <?php endif; ?>

                    <!-- hidden input for store_id -->
                    <input type="hidden" name="store_id" value="<?php echo $wholesale_product['store_id']; ?>">

                    <!-- 최신 매입 이력 -->
                    <?php if (!empty($purchase_history)): ?>
                    <div class="bg-yellow-50 p-4 rounded-lg border border-yellow-200">
                        <h3 class="text-lg font-medium text-yellow-900 mb-4">
                            <i class="fas fa-history mr-2"></i>
                            최신 매입 이력 (최근 3건)
                        </h3>
                        
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-yellow-200 text-sm">
                                <thead class="bg-yellow-100">
                                    <tr>
                                        <th class="px-2 py-1.5 text-left text-xs font-medium text-yellow-700 uppercase tracking-wider">매입일자</th>
                                        <th class="px-2 py-1.5 text-left text-xs font-medium text-yellow-700 uppercase tracking-wider">공급업체</th>
                                        <th class="px-2 py-1.5 text-right text-xs font-medium text-yellow-700 uppercase tracking-wider">박스단가</th>
                                        <th class="px-2 py-1.5 text-right text-xs font-medium text-yellow-700 uppercase tracking-wider">개당단가</th>
                                        <th class="px-2 py-1.5 text-center text-xs font-medium text-yellow-700 uppercase tracking-wider">수량</th>
                                        <th class="px-2 py-1.5 text-center text-xs font-medium text-yellow-700 uppercase tracking-wider">타입</th>
                                        <th class="px-2 py-1.5 text-center text-xs font-medium text-yellow-700 uppercase tracking-wider">선택</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-yellow-200">
                                    <?php foreach ($purchase_history as $index => $item): ?>
                                    <tr class="hover:bg-yellow-50">
                                        <td class="px-2 py-1.5 whitespace-nowrap text-gray-900 text-xs"><?php echo htmlspecialchars($item['purchase_date_formatted']); ?></td>
                                        <td class="px-2 py-1.5 whitespace-nowrap text-gray-900 text-xs"><?php echo htmlspecialchars($item['supplier_name'] ?: '미지정'); ?></td>
                                        <td class="px-2 py-1.5 whitespace-nowrap text-right text-gray-900 font-medium text-xs"><?php echo number_format($item['box_unit_price']); ?></td>
                                        <td class="px-2 py-1.5 whitespace-nowrap text-right text-gray-500 text-xs"><?php echo number_format($item['piece_unit_price'], 2); ?></td>
                                        <td class="px-2 py-1.5 whitespace-nowrap text-center text-gray-900 text-xs"><?php echo $item['quantity']; ?></td>
                                        <td class="px-2 py-1.5 whitespace-nowrap text-center">
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium <?php echo $item['purchase_type'] === 'box' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800'; ?>">
                                                <?php echo $item['purchase_type'] === 'box' ? '박스' : '낱개'; ?>
                                            </span>
                                        </td>
                                        <td class="px-2 py-1.5 whitespace-nowrap text-center">
                                            <button type="button" 
                                                    class="inline-flex items-center px-2 py-1 border border-transparent text-xs font-medium rounded text-white bg-yellow-600 hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500 use-purchase-price-btn"
                                                    data-cost-price="<?php echo $item['box_unit_price']; ?>"
                                                    data-supplier="<?php echo htmlspecialchars($item['supplier_name'] ?: '미지정'); ?>"
                                                    data-date="<?php echo htmlspecialchars($item['purchase_date_formatted']); ?>"
                                                    data-index="<?php echo $index; ?>">
                                                선택
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="mt-2 text-xs text-green-700">
                            <i class="fas fa-info-circle mr-1"></i>
                            박스 또는 낱개 단가를 선택하면 원가 필드에 자동으로 입력됩니다.
                        </p>
                    </div>
                    <?php endif; ?>

                    <!-- 최근 입고 내역 -->
                    <div class="bg-blue-50 p-4 rounded-lg border border-blue-200">
                        <h3 class="text-lg font-medium text-blue-900 mb-4">
                            <i class="fas fa-truck mr-2"></i>
                            최근 입고 내역 (최근 3건)
                        </h3>
                        
                        <?php if (!empty($receiving_history)): ?>
                        <div class="bg-blue-50 border border-blue-200 rounded-md p-3 mb-3">
                            <p class="text-sm text-blue-800">
                                <i class="fas fa-info-circle mr-1"></i>
                                입고 이력을 선택하면 해당 원가를 기준으로 도매가격을 설정할 수 있습니다
                            </p>
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table id="receiving-history-table" class="min-w-full text-sm border border-blue-200 rounded-md">
                                <thead class="bg-blue-100">
                                    <tr>
                                        <th class="px-3 py-2 text-left font-semibold text-blue-700">바코드</th>
                                        <th class="px-3 py-2 text-left font-semibold text-blue-700">상품명</th>
                                        <th class="px-3 py-2 text-left font-semibold text-blue-700">공급처</th>
                                        <th class="px-3 py-2 text-left font-semibold text-blue-700">입고일</th>
                                        <th class="px-3 py-2 text-right font-semibold text-blue-700">박스원가</th>
                                        <th class="px-3 py-2 text-right font-semibold text-blue-700">낱개원가</th>
                                        <th class="px-3 py-2 text-center font-semibold text-blue-700">선택</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-blue-200">
                                    <?php foreach ($receiving_history as $index => $item): ?>
                                    <tr class="border-b hover:bg-blue-50 cursor-pointer receiving-row" data-index="<?php echo $index; ?>">
                                        <td class="px-3 py-2 font-mono text-xs"><?php echo htmlspecialchars($item['barcode'] ?: $item['sku']); ?></td>
                                        <td class="px-3 py-2">
                                            <div class="font-medium text-gray-900"><?php echo htmlspecialchars($item['name_en'] ?: $item['name_ko']); ?></div>
                                            <?php if ($item['name_en'] && $item['name_ko'] && $item['name_en'] !== $item['name_ko']): ?>
                                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($item['name_ko']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-3 py-2"><?php echo htmlspecialchars($item['supplier_name'] ?: '미지정'); ?></td>
                                        <td class="px-3 py-2"><?php echo htmlspecialchars($item['received_date_formatted']); ?></td>
                                        <td class="px-3 py-2 text-right font-mono font-medium"><?php echo number_format($item['box_receiving_price'], 2); ?></td>
                                        <td class="px-3 py-2 text-right font-mono"><?php echo number_format($item['piece_receiving_price'], 2); ?></td>
                                        <td class="px-3 py-2 text-center">
                                            <button type="button" 
                                                    class="select-receiving-btn px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs hover:bg-blue-200 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                                    data-box-price="<?php echo $item['box_receiving_price']; ?>"
                                                    data-piece-price="<?php echo $item['piece_receiving_price']; ?>"
                                                    data-product-name="<?php echo htmlspecialchars($item['name_en'] ?: $item['name_ko']); ?>"
                                                    data-supplier="<?php echo htmlspecialchars($item['supplier_name'] ?: '미지정'); ?>"
                                                    data-date="<?php echo htmlspecialchars($item['received_date_formatted']); ?>"
                                                    data-index="<?php echo $index; ?>">
                                                선택
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-6">
                            <div class="text-blue-600 mb-2">
                                <i class="fas fa-box-open text-3xl"></i>
                            </div>
                            <p class="text-blue-700 font-medium">입고 이력이 없습니다</p>
                            <p class="text-xs text-blue-600 mt-1">
                                이 상품의 입고 이력이 현재 점포에서 발견되지 않았습니다.<br>
                                매입 관리에서 먼저 상품을 입고해주세요.
                            </p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- 한줄 테이블 형태로 가격 정보 표시 -->
                    <div class="overflow-x-auto">
                        <table class="w-full border border-gray-300 rounded-md">
                            <thead>
                                <tr class="bg-gray-100">
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-700 border-r border-gray-300">
                                        박스원가 <span class="text-red-500">*</span>
                                    </th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-700 border-r border-gray-300">
                                        낱개원가
                                    </th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-700 border-r border-gray-300">
                                        마진율(%) <span class="text-red-500">*</span>
                                    </th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-700 border-r border-gray-300">
                                        박스판매가 <span class="text-red-500">*</span>
                                    </th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-700">
                                        낱개판매가
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="px-2 py-1 border-r border-gray-300" style="width: 20%;">
                                        <input type="number" name="cost_price" id="cost_price" step="0.01" min="0" required
                                               value="<?php echo htmlspecialchars($_POST['cost_price'] ?? $wholesale_product['cost_price']); ?>"
                                               class="w-full px-2 py-1 text-xs border border-gray-200 rounded focus:outline-none focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
                                               placeholder="박스당 원가">
                                    </td>
                                    <td class="px-2 py-1 border-r border-gray-300" style="width: 20%;">
                                        <input type="number" name="cost_price_piece" id="cost_price_piece" step="0.01" min="0"
                                               class="w-full px-2 py-1 text-xs border border-gray-200 rounded focus:outline-none focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
                                               placeholder="낱개 원가"
                                               value="<?php echo htmlspecialchars($_POST['cost_price_piece'] ?? $wholesale_product['cost_price_piece'] ?? ''); ?>">
                                    </td>
                                    <td class="px-2 py-1 border-r border-gray-300" style="width: 20%;">
                                        <input type="number" name="margin_rate" id="margin_rate" step="0.1" min="0" max="1000" required
                                               value="<?php echo htmlspecialchars($_POST['margin_rate'] ?? $wholesale_product['margin_rate'] ?? '15'); ?>"
                                               class="w-full px-2 py-1 text-xs border border-gray-200 rounded focus:outline-none focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
                                               placeholder="15">
                                    </td>
                                    <td class="px-2 py-1 border-r border-gray-300" style="width: 20%;">
                                        <input type="number" name="wholesale_price" id="wholesale_price" step="0.01" min="0" required
                                               value="<?php echo htmlspecialchars($_POST['wholesale_price'] ?? $wholesale_product['wholesale_price']); ?>"
                                               class="w-full px-2 py-1 text-xs border border-gray-200 rounded focus:outline-none focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
                                               placeholder="박스 판매가">
                                    </td>
                                    <td class="px-2 py-1" style="width: 20%;">
                                        <input type="number" name="wholesale_price_piece" id="wholesale_price_piece" step="0.01" min="0"
                                               class="w-full px-2 py-1 text-xs border border-gray-200 rounded focus:outline-none focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
                                               placeholder="낱개 판매가"
                                               value="<?php echo htmlspecialchars($_POST['wholesale_price_piece'] ?? $wholesale_product['wholesale_price_piece'] ?? ''); ?>">
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    

                    <!-- hidden input for sale_unit (default to box) -->
                    <input type="hidden" name="sale_unit" value="box">


                    <div class="flex justify-end space-x-4 pt-4">
                        <a href="wholesale_product_management.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            <i class="fas fa-arrow-left mr-2"></i>
                            <?php echo t('common.cancel'); ?>
                        </a>
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500" onclick="return validateAndSubmit(event)">
                            <i class="fas fa-save mr-2"></i>
                            <?php echo t('common.save_changes'); ?>
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const costPriceInput = document.getElementById('cost_price');
    const costPricePieceInput = document.getElementById('cost_price_piece');
    const marginRateInput = document.getElementById('margin_rate');
    const wholesalePriceInput = document.getElementById('wholesale_price');
    const wholesalePricePieceInput = document.getElementById('wholesale_price_piece');
    
    // 상품 정보에서 pieces_per_box 가져오기
    const piecesPerBox = <?php echo $wholesale_product['pieces_per_box'] ?? 1; ?>;
    
    // 수동으로 설정된 낱개원가 보호를 위한 플래그
    let isManualPiecePrice = false;
    
    // 박스 도매가 자동 계산 (원가와 마진율 기반)
    function calculateWholesalePrice() {
        const costPrice = parseFloat(costPriceInput.value) || 0;
        const marginRate = parseFloat(marginRateInput.value) || 0;
        
        if (costPrice > 0 && marginRate >= 0) {
            const calculatedPrice = Math.ceil(costPrice * (1 + marginRate / 100));
            wholesalePriceInput.value = calculatedPrice;
            
            // 낱개판매가는 자동계산하지 않음 - 박스판매가만 계산
            
            // 계산 완료 알림
            if (marginRate > 0) {
                showNotification(`마진율 ${marginRate}% 적용 → 도매가: ${calculatedPrice.toLocaleString()}`, 'info');
            }
        }
    }
    
    // 마진율 자동 계산 (원가와 도매가 기반)
    function calculateMarginRate() {
        const costPrice = parseFloat(costPriceInput.value) || 0;
        const wholesalePrice = parseFloat(wholesalePriceInput.value) || 0;
        
        if (costPrice > 0 && wholesalePrice > 0) {
            const calculatedMarginRate = ((wholesalePrice / costPrice - 1) * 100).toFixed(1);
            marginRateInput.value = calculatedMarginRate;
            
            // 낱개판매가는 자동계산하지 않음 - 마진율만 계산
            
            // 마진율 계산 완료 알림
            showNotification(`마진율이 ${calculatedMarginRate}%로 계산되었습니다`, 'info');
        }
    }
    
    // 모든 계산 업데이트 (낱개 가격 포함)
    function updateAllCalculations() {
        const costPrice = parseFloat(costPriceInput.value) || 0;
        const wholesalePrice = parseFloat(wholesalePriceInput.value) || 0;
        
        // 낱개 원가 계산 (수동 설정된 경우 제외)
        if (!isManualPiecePrice) {
            if (costPrice > 0 && piecesPerBox > 0) {
                const costPricePiece = (costPrice / piecesPerBox).toFixed(2);
                costPricePieceInput.value = costPricePiece;
            } else {
                costPricePieceInput.value = '';
            }
        }
        
        // 낱개 도매가 계산  
        if (wholesalePrice > 0 && piecesPerBox > 0) {
            const wholesalePricePiece = (wholesalePrice / piecesPerBox).toFixed(2);
            wholesalePricePieceInput.value = wholesalePricePiece;
        } else {
            wholesalePricePieceInput.value = '';
        }
        
        updateMarginDisplay();
        updateCalculationStatus();
    }
    
    // 마진 정보 표시 업데이트 (표시 기능 제거됨)
    function updateMarginDisplay() {
        // 마진 표시는 제거되었지만 함수는 호환성을 위해 유지
        return;
    }
    
    // 박스원가 포커스 이동 시에도 자동 계산
    costPriceInput.addEventListener('blur', function() {
        if (marginRateInput.value && parseFloat(marginRateInput.value) > 0) {
            calculateWholesalePrice();
        }
    });
    
    // 낱개원가 포커스 이동 시 낱개판매가 계산
    costPricePieceInput.addEventListener('blur', function() {
        if (marginRateInput.value && parseFloat(marginRateInput.value) > 0) {
            const pieceCostPrice = parseFloat(costPricePieceInput.value) || 0;
            const marginRate = parseFloat(marginRateInput.value) || 0;
            
            if (pieceCostPrice > 0 && marginRate >= 0) {
                const pieceSalePrice = Math.ceil(pieceCostPrice * (1 + marginRate / 100));
                wholesalePricePieceInput.value = pieceSalePrice;
                
                // 박스판매가는 자동계산하지 않음 - 낱개판매가만 계산
            }
        }
    });
    
    // 마진율 변경 시 박스판매가와 낱개판매가 자동 계산
    marginRateInput.addEventListener('input', function() {
        const costPrice = parseFloat(costPriceInput.value) || 0;
        const pieceCostPrice = parseFloat(costPricePieceInput.value) || 0;
        const marginRate = parseFloat(this.value) || 0;
        
        // 박스원가가 있으면 박스판매가 계산
        if (costPrice > 0 && marginRate >= 0) {
            const boxSalePrice = Math.ceil(costPrice * (1 + marginRate / 100));
            wholesalePriceInput.value = boxSalePrice;
        }
        
        // 낱개원가가 있으면 낱개판매가 계산
        if (pieceCostPrice > 0 && marginRate >= 0) {
            const pieceSalePrice = Math.ceil(pieceCostPrice * (1 + marginRate / 100));
            wholesalePricePieceInput.value = pieceSalePrice;
        }
    });
    
    // 박스판매가 입력 시 자동 계산 제거
    
    // 엔터 키로 폼 제출 방지
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && e.target.type !== 'submit') {
                e.preventDefault(); // 폼 제출 방지
                
                // 박스원가 필드에서 엔터 시 계산
                if (e.target === costPriceInput) {
                    if (marginRateInput.value && parseFloat(marginRateInput.value) > 0) {
                        calculateWholesalePrice();
                    }
                }
                
                // 낱개원가 필드에서 엔터 시 계산
                if (e.target === costPricePieceInput) {
                    if (marginRateInput.value && parseFloat(marginRateInput.value) > 0) {
                        const pieceCostPrice = parseFloat(costPricePieceInput.value) || 0;
                        const marginRate = parseFloat(marginRateInput.value) || 0;
                        
                        if (pieceCostPrice > 0 && marginRate >= 0) {
                            const pieceSalePrice = Math.ceil(pieceCostPrice * (1 + marginRate / 100));
                            wholesalePricePieceInput.value = pieceSalePrice;
                            
                            // 박스판매가는 자동계산하지 않음 - 낱개판매가만 계산
                        }
                    }
                }
            }
        });
    }
    
    // 매입 이력에서 가격 선택 기능
    const usePurchasePriceButtons = document.querySelectorAll('.use-purchase-price-btn');
    
    usePurchasePriceButtons.forEach(button => {
        button.addEventListener('click', function() {
            const costPrice = parseFloat(this.dataset.costPrice);
            const supplier = this.dataset.supplier;
            const date = this.dataset.date;
            const index = this.dataset.index;
            
            // 원가 필드에 값 설정
            costPriceInput.value = costPrice;
            
            // 마진율이 설정되어 있으면 도매가 자동 계산
            if (marginRateInput.value) {
                calculateWholesalePrice();
            } else {
                updateAllCalculations();
            }
            
            // 선택된 행 하이라이트
            usePurchasePriceButtons.forEach((btn, btnIndex) => {
                const row = btn.closest('tr');
                if (btnIndex == index) {
                    row.classList.add('bg-yellow-100', 'border-yellow-300');
                    btn.textContent = '적용됨';
                    btn.classList.remove('bg-yellow-600', 'hover:bg-yellow-700');
                    btn.classList.add('bg-green-600', 'hover:bg-green-700');
                } else {
                    row.classList.remove('bg-yellow-100', 'border-yellow-300');
                    btn.textContent = '선택';
                    btn.classList.remove('bg-green-600', 'hover:bg-green-700');
                    btn.classList.add('bg-yellow-600', 'hover:bg-yellow-700');
                }
            });
            
            // 사용자에게 피드백 제공
            showNotification(`${supplier} (${date})의 매입가 ${costPrice.toLocaleString()}이 적용되었습니다.`);
        });
    });
    
    // 입고 내역에서 가격 선택 기능 - 개선된 버전
    const selectReceivingButtons = document.querySelectorAll('.select-receiving-btn');
    let selectedReceivingData = null;
    
    selectReceivingButtons.forEach(button => {
        button.addEventListener('click', function() {
            const boxPrice = parseFloat(this.dataset.boxPrice);
            const piecePrice = parseFloat(this.dataset.piecePrice);
            const productName = this.dataset.productName;
            const supplier = this.dataset.supplier;
            const date = this.dataset.date;
            const index = this.dataset.index;
            
            // 선택된 데이터 저장
            selectedReceivingData = {
                boxPrice: boxPrice,
                piecePrice: piecePrice,
                productName: productName,
                supplier: supplier,
                date: date,
                index: index
            };
            
            // 박스 원가를 기본으로 설정 (도매는 주로 박스 단위)
            costPriceInput.value = boxPrice.toFixed(2);
            
            // 낱개 원가도 함께 설정
            costPricePieceInput.value = piecePrice.toFixed(2);
            
            // 수동 설정 플래그 활성화
            isManualPiecePrice = true;
            
            // 마진율이 설정되어 있으면 도매가 자동 계산
            if (marginRateInput.value && parseFloat(marginRateInput.value) > 0) {
                calculateWholesalePrice();
                
                // 입고내역 선택 시에만 낱개판매가도 계산
                const marginRate = parseFloat(marginRateInput.value);
                const pieceSalePrice = Math.ceil(piecePrice * (1 + marginRate / 100));
                wholesalePricePieceInput.value = pieceSalePrice;
            } else {
                // 기본 마진율 15% 적용
                marginRateInput.value = 15;
                calculateWholesalePrice();
                
                // 입고내역 선택 시에만 낱개판매가도 계산
                const pieceSalePrice = Math.ceil(piecePrice * 1.15);
                wholesalePricePieceInput.value = pieceSalePrice;
            }
            
            // 선택된 버튼 상태 업데이트
            selectReceivingButtons.forEach((btn, btnIndex) => {
                if (btnIndex == index) {
                    btn.textContent = '선택됨';
                    btn.classList.remove('bg-blue-100', 'text-blue-700', 'hover:bg-blue-200');
                    btn.classList.add('bg-green-100', 'text-green-700', 'border-green-300');
                } else {
                    btn.textContent = '선택';
                    btn.classList.remove('bg-green-100', 'text-green-700', 'border-green-300');
                    btn.classList.add('bg-blue-100', 'text-blue-700', 'hover:bg-blue-200');
                }
            });
            
            // 선택된 행 하이라이트
            document.querySelectorAll('.receiving-row').forEach((row, rowIndex) => {
                if (rowIndex == index) {
                    row.classList.add('bg-blue-100', 'border-blue-300');
                } else {
                    row.classList.remove('bg-blue-100', 'border-blue-300');
                }
            });
            
            // 가격 설정 정보 표시
            showPricingInfo(selectedReceivingData);
            
            // 사용자에게 피드백 제공
            showNotification(`${supplier} (${date})의 입고가격이 적용되었습니다. 박스원가: ${boxPrice.toLocaleString()}`);
        });
    });
    
    // 가격 설정 정보 표시 함수
    function showPricingInfo(data) {
        // 기존 정보 패널이 있으면 제거
        const existingPanel = document.querySelector('.pricing-info-panel');
        if (existingPanel) {
            existingPanel.remove();
        }
        
        // 새로운 정보 패널 생성
        const infoPanel = document.createElement('div');
        infoPanel.className = 'pricing-info-panel mt-3 p-3 bg-green-50 border border-green-200 rounded-md';
        infoPanel.innerHTML = `
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle text-green-600 mr-2"></i>
                        <span class="text-sm font-medium text-green-800">선택된 입고내역</span>
                    </div>
                    <div class="text-xs text-green-700">
                        ${data.supplier} | ${data.date}
                    </div>
                </div>
                <button type="button" class="text-green-600 hover:text-green-800 clear-selection-btn">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="mt-2 grid grid-cols-2 gap-4 text-sm">
                <div class="flex justify-between">
                    <span class="text-green-700">박스원가:</span>
                    <span class="font-mono font-medium">${data.boxPrice.toLocaleString()}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-green-700">낱개원가:</span>
                    <span class="font-mono">${data.piecePrice.toLocaleString()}</span>
                </div>
            </div>
        `;
        
        // 입고내역 테이블 다음에 추가
        const receivingTable = document.querySelector('#receiving-history-table');
        if (receivingTable) {
            receivingTable.parentNode.insertBefore(infoPanel, receivingTable.nextSibling);
        }
        
        // 선택 해제 버튼 이벤트
        infoPanel.querySelector('.clear-selection-btn').addEventListener('click', function() {
            clearReceivingSelection();
        });
    }
    
    // 선택 해제 함수
    function clearReceivingSelection() {
        selectedReceivingData = null;
        
        // 버튼 상태 초기화
        selectReceivingButtons.forEach(btn => {
            btn.textContent = '선택';
            btn.classList.remove('bg-green-100', 'text-green-700', 'border-green-300');
            btn.classList.add('bg-blue-100', 'text-blue-700', 'hover:bg-blue-200');
        });
        
        // 행 하이라이트 제거
        document.querySelectorAll('.receiving-row').forEach(row => {
            row.classList.remove('bg-blue-100', 'border-blue-300');
        });
        
        // 정보 패널 제거
        const infoPanel = document.querySelector('.pricing-info-panel');
        if (infoPanel) {
            infoPanel.remove();
        }
        
        showNotification('입고내역 선택이 해제되었습니다.');
    }
    
    // 개선된 알림 표시 함수
    function showNotification(message, type = 'success') {
        // 기존 알림이 있으면 제거
        const existingNotification = document.querySelector('.notification-toast');
        if (existingNotification) {
            existingNotification.remove();
        }
        
        // 알림 스타일 설정
        const styles = {
            success: {
                bg: 'bg-green-500',
                icon: 'fa-check-circle',
                border: 'border-green-400'
            },
            info: {
                bg: 'bg-blue-500',
                icon: 'fa-info-circle',
                border: 'border-blue-400'
            },
            warning: {
                bg: 'bg-yellow-500',
                icon: 'fa-exclamation-triangle',
                border: 'border-yellow-400'
            },
            error: {
                bg: 'bg-red-500',
                icon: 'fa-times-circle',
                border: 'border-red-400'
            }
        };
        
        const style = styles[type] || styles.success;
        
        // 새 알림 생성
        const notification = document.createElement('div');
        notification.className = `notification-toast fixed top-4 right-4 ${style.bg} text-white px-4 py-3 rounded-lg shadow-lg z-50 border-2 ${style.border} transform transition-all duration-300 ease-in-out`;
        notification.innerHTML = `
            <div class="flex items-center">
                <i class="fas ${style.icon} mr-2"></i>
                <span class="text-sm font-medium">${message}</span>
                <button class="ml-3 text-white hover:text-gray-200" onclick="this.parentElement.parentElement.remove()">
                    <i class="fas fa-times text-xs"></i>
                </button>
            </div>
        `;
        
        // 애니메이션 시작
        notification.style.transform = 'translateX(100%)';
        document.body.appendChild(notification);
        
        // 애니메이션 실행
        setTimeout(() => {
            notification.style.transform = 'translateX(0)';
        }, 10);
        
        // 5초 후 자동 제거
        setTimeout(() => {
            if (notification.parentNode) {
                notification.style.transform = 'translateX(100%)';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.remove();
                    }
                }, 300);
            }
        }, 5000);
    }
    
    // 가격 입력 검증 함수
    function validatePriceInputs() {
        const costPrice = parseFloat(costPriceInput.value) || 0;
        const marginRate = parseFloat(marginRateInput.value) || 0;
        const wholesalePrice = parseFloat(wholesalePriceInput.value) || 0;
        
        let isValid = true;
        let messages = [];
        
        if (costPrice <= 0) {
            messages.push('원가를 입력해주세요');
            isValid = false;
        }
        
        if (marginRate < 0 || marginRate > 1000) {
            messages.push('마진율은 0%에서 1000% 사이로 입력해주세요');
            isValid = false;
        }
        
        if (wholesalePrice <= 0) {
            messages.push('도매가격을 입력해주세요');
            isValid = false;
        }
        
        // 매우 낮은 마진율 경고
        if (isValid && costPrice > 0 && wholesalePrice > 0) {
            const actualMargin = ((wholesalePrice - costPrice) / costPrice) * 100;
            if (actualMargin < 5) {
                showNotification(`마진율이 ${actualMargin.toFixed(1)}%로 매우 낮습니다. 확인해 주세요.`, 'warning');
            }
        }
        
        if (!isValid) {
            showNotification(messages.join(' | '), 'error');
        }
        
        return isValid;
    }
    
    // 실시간 가격 계산 상태 표시
    function updateCalculationStatus() {
        // 기존 상태 표시 제거
        const existingStatus = document.querySelector('.calculation-status');
        if (existingStatus) {
            existingStatus.remove();
        }
        
        const costPrice = parseFloat(costPriceInput.value) || 0;
        const marginRate = parseFloat(marginRateInput.value) || 0;
        const wholesalePrice = parseFloat(wholesalePriceInput.value) || 0;
        
        if (costPrice > 0 && marginRate > 0 && wholesalePrice > 0) {
            const actualMargin = ((wholesalePrice - costPrice) / costPrice) * 100;
            const suggestedPrice = costPrice * (1 + marginRate / 100);
            const priceDiff = wholesalePrice - suggestedPrice;
            
            // 상태 패널 생성
            const statusPanel = document.createElement('div');
            statusPanel.className = 'calculation-status mt-3 p-3 bg-gray-50 border border-gray-200 rounded-md';
            statusPanel.innerHTML = `
                <div class="text-sm text-gray-700">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <span class="font-medium">실제 마진율:</span>
                            <span class="font-mono ${actualMargin < 10 ? 'text-red-600' : actualMargin < 20 ? 'text-yellow-600' : 'text-green-600'}">${actualMargin.toFixed(1)}%</span>
                        </div>
                        <div>
                            <span class="font-medium">권장 가격:</span>
                            <span class="font-mono">${suggestedPrice.toLocaleString()}</span>
                        </div>
                    </div>
                    ${Math.abs(priceDiff) > 1 ? `
                        <div class="mt-2 text-xs ${priceDiff > 0 ? 'text-blue-600' : 'text-orange-600'}">
                            ${priceDiff > 0 ? '권장가격보다 ' + priceDiff.toLocaleString() + ' 높음' : '권장가격보다 ' + Math.abs(priceDiff).toLocaleString() + ' 낮음'}
                        </div>
                    ` : ''}
                </div>
            `;
            
            // 가격 테이블 다음에 추가
            const priceTable = document.querySelector('.overflow-x-auto');
            if (priceTable) {
                priceTable.parentNode.insertBefore(statusPanel, priceTable.nextSibling);
            }
        }
    }
    
    // 폼 제출 전 검증
    function validateAndSubmit(event) {
        // 검증 실행
        if (!validatePriceInputs()) {
            event.preventDefault();
            return false;
        }
        
        // 선택된 입고내역이 있는 경우 확인 메시지
        if (selectedReceivingData) {
            const confirmMessage = `선택된 입고내역 (${selectedReceivingData.supplier}, ${selectedReceivingData.date})의 정보로 저장하시겠습니까?`;
            if (!confirm(confirmMessage)) {
                event.preventDefault();
                return false;
            }
            
            showNotification('도매상품 정보를 저장하는 중...', 'info');
        }
        
        return true;
    }
    
    // 페이지 로드 시 초기화 함수 개선
    function initializePage() {
        // 자동 계산 제거 - 입고내역 선택시에만 계산
        
        // 낱개원가 필드 수동 수정 감지
        costPricePieceInput.addEventListener('input', function() {
            isManualPiecePrice = true;
        });
        
        
        // 입력 필드 이벤트 리스너 추가
        [costPriceInput, marginRateInput, wholesalePriceInput].forEach(input => {
            input.addEventListener('focus', function() {
                this.style.borderColor = '#3B82F6';
                this.style.boxShadow = '0 0 0 2px rgba(59, 130, 246, 0.2)';
            });
            
            input.addEventListener('blur', function() {
                this.style.borderColor = '';
                this.style.boxShadow = '';
                
                // 값이 변경된 경우 계산 상태 업데이트
                setTimeout(() => {
                    updateCalculationStatus();
                }, 100);
            });
        });
        
        // 입고내역이 있는 경우 안내 메시지 표시
        <?php if (!empty($receiving_history)): ?>
        setTimeout(() => {
            showNotification('입고내역에서 원가를 선택하여 빠르게 도매가격을 설정할 수 있습니다', 'info');
        }, 1000);
        <?php endif; ?>
    }
    
    // 페이지 로드 시 초기화 실행
    initializePage();
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>