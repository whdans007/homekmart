<?php
// 테스트용 상품 검색 API - 세션 체크 없이 작동
require_once __DIR__ . '/../config/db_config.php';

// 테스트 모드임을 알려주는 헤더
header('X-Test-Mode: true');
header('Content-Type: application/json');

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
    
    // 기본 WHERE 조건
    $where_conditions = [];
    $params = [];
    
    // 검색어 조건 (전체 목록이 아닌 경우)
    if (!$show_all && !empty($query)) {
        $search_conditions = [
            "p.sku LIKE ?",
            "p.name_ko LIKE ?", 
            "p.name_en LIKE ?",
            "p.barcode LIKE ?"
        ];
        $where_conditions[] = "(" . implode(" OR ", $search_conditions) . ")";
        
        $search_term = "%{$query}%";
        for ($i = 0; $i < 4; $i++) {
            $params[] = $search_term;
        }
    }
    
    // 활성 상품만 조회 (컬럼이 존재할 경우)
    $check_column = $pdo->query("SHOW COLUMNS FROM products LIKE 'is_active'")->fetch();
    if ($check_column) {
        $where_conditions[] = "p.is_active = 1";
    }
    
    // 출발 점포에 재고가 있는 상품만 조회 (수량 > 0)
    $where_conditions[] = "i.quantity > 0";
    $where_conditions[] = "i.store_id = ?";
    $params[] = $from_store_id;
    
    // 최종 WHERE 절 구성
    $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
    
    // SQL 쿼리 구성 - 출발 점포의 재고와 원가 정보 포함
    $sql = "
        SELECT DISTINCT
            p.id,
            p.sku,
            p.name_ko,
            p.name_en,
            COALESCE(p.barcode, '') as barcode,
            COALESCE(p.pieces_per_box, 1) as pieces_per_box,
            COALESCE(b.name_ko, '') as brand_name,
            COALESCE(i.cost_price, 0) as cost_price,
            COALESCE(i.quantity, 0) as available_quantity,
            1 as min_quantity
        FROM products p
        LEFT JOIN brands b ON p.brand_id = b.id
        INNER JOIN inventory i ON p.id = i.product_id
        {$where_clause}
        ORDER BY p.name_en, p.name_ko
        LIMIT " . (int)$limit;
    
    // LIMIT을 직접 삽입하므로 params에서 제거
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
        'test_mode' => true,
        'debug_info' => [
            'sql' => $sql,
            'params' => $params,
            'raw_count' => count($products)
        ]
    ]);
    
} catch (PDOException $e) {
    error_log("Transfer product search test error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => '상품 검색 중 오류가 발생했습니다.',
        'error_detail' => $e->getMessage(),
        'test_mode' => true
    ]);
} catch (Exception $e) {
    error_log("Transfer product search general error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => '일반 오류가 발생했습니다.',
        'error_detail' => $e->getMessage(),
        'test_mode' => true
    ]);
}
?>