<?php
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// JSON 응답 헤더 설정
header('Content-Type: application/json');

// 로그인 및 권한 확인
if (!is_logged_in() || !has_permission('store_transfer_management')) {
    http_response_code(403);
    echo json_encode([
        'success' => false, 
        'message' => '접근 권한이 없습니다.',
        'error_type' => 'permission_denied',
        'debug_info' => [
            'logged_in' => is_logged_in(),
            'has_permission' => function_exists('has_permission') ? has_permission('store_transfer_management') : false
        ]
    ]);
    exit;
}

// POST 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '허용되지 않은 요청 방식입니다.']);
    exit;
}

$query = trim($_POST['q'] ?? '');
$limit = min((int)($_POST['limit'] ?? 20), 100);
$show_all = isset($_POST['show_all']) && $_POST['show_all'] == '1';
$from_store_id = (int)($_POST['from_store_id'] ?? 0);

if (!$from_store_id) {
    echo json_encode(['success' => false, 'message' => '출발 점포를 선택해주세요.']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 테이블 존재 여부 확인
    $brands_exists = $pdo->query("SHOW TABLES LIKE 'brands'")->fetch();
    $barcode_exists = $pdo->query("SHOW COLUMNS FROM products LIKE 'barcode'")->fetch();
    $is_active_exists = $pdo->query("SHOW COLUMNS FROM products LIKE 'is_active'")->fetch();
    $pieces_per_box_exists = $pdo->query("SHOW COLUMNS FROM products LIKE 'pieces_per_box'")->fetch();
    
    // 기본 WHERE 조건들을 배열로 구성
    $conditions = [];
    $params = [];
    
    // 출발 점포에 재고가 있는 상품만
    $conditions[] = "i.store_id = ?";
    $params[] = $from_store_id;
    
    $conditions[] = "i.quantity > 0";
    $conditions[] = "i.cost_price > 0";
    
    // 활성 상품만 조회 (컬럼이 존재하는 경우)
    if ($is_active_exists) {
        $conditions[] = "p.is_active = 1";
    }
    
    // 검색어가 있는 경우
    if (!$show_all && !empty($query)) {
        $search_conditions = [];
        $search_term = "%{$query}%";
        
        $search_conditions[] = "p.sku LIKE ?";
        $params[] = $search_term;
        
        $search_conditions[] = "p.name_ko LIKE ?";
        $params[] = $search_term;
        
        $search_conditions[] = "p.name_en LIKE ?";
        $params[] = $search_term;
        
        // barcode 컬럼이 있는 경우만 추가
        if ($barcode_exists) {
            $search_conditions[] = "p.barcode LIKE ?";
            $params[] = $search_term;
        }
        
        $conditions[] = "(" . implode(" OR ", $search_conditions) . ")";
    }
    
    // WHERE 절 구성
    $where_clause = "WHERE " . implode(" AND ", $conditions);
    
    // brands 테이블이 있으면 LEFT JOIN, 없으면 제외
    $brand_join = "";
    $brand_select = "'' as brand_name";
    
    if ($brands_exists) {
        $brand_join = "LEFT JOIN brands b ON p.brand_id = b.id";
        $brand_select = "COALESCE(b.name_ko, '') as brand_name";
    }
    
    // pieces_per_box 컬럼 처리
    $pieces_per_box_select = $pieces_per_box_exists ? "COALESCE(p.pieces_per_box, 1)" : "1";
    
    // barcode 컬럼 처리
    $barcode_select = $barcode_exists ? "COALESCE(p.barcode, '')" : "''";
    
    // SQL 쿼리 구성
    $sql = "
        SELECT 
            p.id,
            p.sku,
            COALESCE(p.name_ko, '') as name_ko,
            COALESCE(p.name_en, '') as name_en,
            {$barcode_select} as barcode,
            {$pieces_per_box_select} as pieces_per_box,
            {$brand_select},
            i.cost_price,
            i.quantity as available_quantity,
            1 as min_quantity
        FROM products p
        {$brand_join}
        INNER JOIN inventory i ON p.id = i.product_id
        {$where_clause}
        ORDER BY p.id
    ";
    
    // LIMIT을 별도로 처리하여 SQL 구문 오류 방지
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $all_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // PHP에서 LIMIT 처리
    $products = array_slice($all_products, 0, $limit);
    
    // 결과 가공
    $result_products = [];
    foreach ($products as $product) {
        $cost_price = (float)$product['cost_price'];
        $available_quantity = (int)$product['available_quantity'];
        
        // 원가가 0이거나 재고가 없는 상품은 제외
        if ($cost_price <= 0 || $available_quantity <= 0) {
            continue;
        }
        
        $result_products[] = [
            'id' => $product['id'],
            'sku' => $product['sku'],
            'name_ko' => $product['name_ko'],
            'name_en' => $product['name_en'],
            'barcode' => $product['barcode'],
            'brand_name' => $product['brand_name'],
            'cost_price' => number_format($cost_price, 2, '.', ''),
            'available_quantity' => $available_quantity,
            'min_quantity' => max(1, (int)$product['min_quantity']),
            'pieces_per_box' => (int)$product['pieces_per_box']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'products' => $result_products,
        'total_found' => count($result_products),
        'from_store_id' => $from_store_id,
        'debug_info' => [
            'brands_exists' => (bool)$brands_exists,
            'barcode_exists' => (bool)$barcode_exists,
            'is_active_exists' => (bool)$is_active_exists,
            'pieces_per_box_exists' => (bool)$pieces_per_box_exists,
            'total_before_limit' => count($all_products),
            'applied_limit' => $limit,
            'search_query' => $query,
            'show_all' => $show_all
        ]
    ]);
    
} catch (PDOException $e) {
    error_log("Transfer product search error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => '상품 검색 중 오류가 발생했습니다.',
        'error_detail' => $e->getMessage(),
        'error_code' => $e->getCode(),
        'error_type' => 'database_error'
    ]);
} catch (Exception $e) {
    error_log("Transfer product search general error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => '일반 오류가 발생했습니다.',
        'error_detail' => $e->getMessage(),
        'error_type' => 'general_error'
    ]);
}
?>