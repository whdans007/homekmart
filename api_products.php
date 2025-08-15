<?php
/**
 * 간단한 상품 API (테스트용)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config/db_config.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 간단한 상품 목록 조회
    $limit = min(20, max(1, (int)($_GET['limit'] ?? 5)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    
    $sql = "
        SELECT 
            p.id, p.name, p.description, p.barcode,
            c.name as category_name,
            b.name_ko as brand_name,
            COALESCE(MIN(i.selling_price), 0) as min_price,
            COALESCE(MAX(i.selling_price), 0) as max_price,
            COALESCE(SUM(i.quantity), 0) as total_stock
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        LEFT JOIN inventory i ON p.id = i.product_id
        WHERE (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
        GROUP BY p.id, p.name, p.description, p.barcode, c.name, b.name_ko
        HAVING total_stock > 0
        ORDER BY p.name ASC
        LIMIT $limit OFFSET $offset
    ";
    
    $stmt = $pdo->query($sql);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 가격 형식화
    foreach ($products as &$product) {
        $product['id'] = (int)$product['id'];
        $product['total_stock'] = (int)$product['total_stock'];
        
        if ($product['min_price'] > 0) {
            $product['price'] = [
                'min' => (float)$product['min_price'],
                'max' => (float)$product['max_price'], 
                'currency' => 'PHP',
                'formatted_min' => '₱' . number_format($product['min_price'], 2),
                'formatted_max' => '₱' . number_format($product['max_price'], 2)
            ];
        } else {
            $product['price'] = null;
        }
        
        unset($product['min_price'], $product['max_price']);
        $product['available_for_delivery'] = $product['total_stock'] > 0;
    }
    
    $response = [
        'success' => true,
        'data' => [
            'products' => $products,
            'count' => count($products),
            'limit' => $limit,
            'offset' => $offset,
            'currency' => 'PHP'
        ],
        'message' => 'Products retrieved successfully',
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>