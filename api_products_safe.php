<?php
/**
 * 안전한 상품 API (에러 처리 강화)
 */

// 에러 표시 활성화 (디버깅용)
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// 출력 버퍼링 시작
ob_start();

try {
    // 1. 설정 파일 확인
    $config_path = __DIR__ . '/config/db_config.php';
    if (!file_exists($config_path)) {
        throw new Exception("Config file not found: $config_path");
    }
    
    require_once $config_path;
    
    // 2. 필수 상수 확인
    $required_constants = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_CHARSET'];
    foreach ($required_constants as $const) {
        if (!defined($const)) {
            throw new Exception("Required constant '$const' not defined");
        }
    }
    
    // 3. 데이터베이스 연결
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    
    // 4. 파라미터 처리
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    
    // 5. WHERE 조건 구성
    $where_conditions = ["(p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')"];
    $params = [];
    
    if ($search) {
        $where_conditions[] = "p.name LIKE ?";
        $params[] = "%{$search}%";
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // 6. 상품 목록 쿼리
    $sql = "
        SELECT 
            p.id, 
            p.name, 
            p.description, 
            p.barcode,
            COALESCE(c.name, 'Unknown') as category_name,
            COALESCE(b.name_ko, 'Unknown') as brand_name,
            COALESCE(MIN(CASE WHEN i.selling_price > 0 THEN i.selling_price END), 0) as min_price,
            COALESCE(MAX(CASE WHEN i.selling_price > 0 THEN i.selling_price END), 0) as max_price,
            COALESCE(SUM(i.quantity), 0) as total_stock
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        LEFT JOIN inventory i ON p.id = i.product_id
        WHERE $where_clause
        GROUP BY p.id, p.name, p.description, p.barcode, c.name, b.name_ko
        ORDER BY p.name ASC
        LIMIT ? OFFSET ?
    ";
    
    // 파라미터에 LIMIT과 OFFSET 추가
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();
    
    // 7. 데이터 가공
    $processed_products = [];
    foreach ($products as $product) {
        $min_price = (float)$product['min_price'];
        $max_price = (float)$product['max_price'];
        
        $processed_product = [
            'id' => (int)$product['id'],
            'name' => $product['name'],
            'description' => $product['description'],
            'barcode' => $product['barcode'],
            'category_name' => $product['category_name'],
            'brand_name' => $product['brand_name'],
            'total_stock' => (int)$product['total_stock'],
            'available_for_delivery' => $product['total_stock'] > 0
        ];
        
        // 가격 정보 추가
        if ($min_price > 0) {
            $processed_product['price'] = [
                'min' => $min_price,
                'max' => $max_price,
                'currency' => 'PHP',
                'formatted_min' => '₱' . number_format($min_price, 2),
                'formatted_max' => '₱' . number_format($max_price, 2)
            ];
        } else {
            $processed_product['price'] = null;
        }
        
        $processed_products[] = $processed_product;
    }
    
    // 8. 총 개수 조회
    $count_sql = "
        SELECT COUNT(DISTINCT p.id) as total_count
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        WHERE $where_clause
    ";
    
    $count_params = array_slice($params, 0, -2); // LIMIT과 OFFSET 제거
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($count_params);
    $total_count = (int)$count_stmt->fetch()['total_count'];
    
    // 9. 응답 구성
    $response = [
        'success' => true,
        'data' => [
            'products' => $processed_products,
            'pagination' => [
                'total_count' => $total_count,
                'current_page' => floor($offset / $limit) + 1,
                'per_page' => $limit,
                'total_pages' => ceil($total_count / $limit),
                'has_next' => ($offset + $limit) < $total_count,
                'has_prev' => $offset > 0
            ],
            'filters' => [
                'search' => $search,
                'limit' => $limit,
                'offset' => $offset
            ],
            'currency' => 'PHP'
        ],
        'message' => 'Products retrieved successfully',
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    // 10. 출력 버퍼 정리 및 응답
    ob_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage(),
        'code' => $e->getCode(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} finally {
    ob_end_flush();
}
?>