<?php
/**
 * 작동하는 상품 API
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    // 설정 파일 로드
    require_once __DIR__ . '/config/db_config.php';
    
    // PDO 연결
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 파라미터 처리
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    
    // 상품 목록 조회
    $sql = "
        SELECT 
            p.id, 
            p.name, 
            p.description, 
            p.barcode,
            c.name as category_name,
            b.name_ko as brand_name,
            MIN(CASE WHEN i.selling_price > 0 THEN i.selling_price END) as min_price,
            MAX(CASE WHEN i.selling_price > 0 THEN i.selling_price END) as max_price,
            SUM(i.quantity) as total_stock
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        LEFT JOIN inventory i ON p.id = i.product_id
        WHERE (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
        GROUP BY p.id, p.name, p.description, p.barcode, c.name, b.name_ko
        HAVING total_stock > 0
        ORDER BY p.name ASC
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$limit, $offset]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 데이터 가공
    $processed_products = [];
    foreach ($products as $product) {
        $min_price = (float)($product['min_price'] ?? 0);
        $max_price = (float)($product['max_price'] ?? 0);
        
        $processed_product = [
            'id' => (int)$product['id'],
            'name' => $product['name'],
            'description' => $product['description'],
            'barcode' => $product['barcode'],
            'category_name' => $product['category_name'] ?: 'Unknown',
            'brand_name' => $product['brand_name'] ?: 'Unknown',
            'total_stock' => (int)($product['total_stock'] ?? 0),
            'available_for_delivery' => ($product['total_stock'] ?? 0) > 0
        ];
        
        // 가격 정보
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
    
    // 총 개수 조회
    $count_sql = "
        SELECT COUNT(DISTINCT p.id) as total_count
        FROM products p
        LEFT JOIN inventory i ON p.id = i.product_id
        WHERE (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
        AND i.quantity > 0
    ";
    
    $count_stmt = $pdo->query($count_sql);
    $total_count = (int)$count_stmt->fetch(PDO::FETCH_ASSOC)['total_count'];
    
    // 응답 구성
    $response = [
        'success' => true,
        'data' => [
            'products' => $processed_products,
            'pagination' => [
                'total_count' => $total_count,
                'count' => count($processed_products),
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $total_count
            ],
            'currency' => [
                'code' => 'PHP',
                'symbol' => '₱',
                'name' => 'Philippine Peso'
            ]
        ],
        'message' => 'Products retrieved successfully for delivery',
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>