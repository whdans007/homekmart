<?php
/**
 * 상품 목록 API (직접 접근)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    require_once __DIR__ . '/../config/db_config.php';
    
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 페이지네이션
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = min(20, max(1, (int)($_GET['per_page'] ?? 10)));
    $offset = ($page - 1) * $per_page;
    
    // 상품 총 개수
    $total_stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM products p 
        WHERE p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00'
    ");
    $total_count = $total_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 상품 목록 조회
    $sql = "
        SELECT 
            p.id, p.name, p.description, p.image_url, p.barcode,
            c.name as category_name,
            b.name_ko as brand_name,
            MIN(i.selling_price) as min_price,
            MAX(i.selling_price) as max_price,
            SUM(i.quantity) as total_stock
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        LEFT JOIN inventory i ON p.id = i.product_id
        WHERE p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00'
        GROUP BY p.id, p.name, p.description, p.image_url, p.barcode, c.name, b.name_ko
        ORDER BY p.name ASC
        LIMIT {$per_page} OFFSET {$offset}
    ";
    
    $stmt = $pdo->query($sql);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 가격 포맷팅
    foreach ($products as &$product) {
        $product['id'] = (int)$product['id'];
        $product['total_stock'] = (int)($product['total_stock'] ?? 0);
        
        if ($product['min_price']) {
            $product['price'] = [
                'min' => (float)$product['min_price'],
                'max' => (float)$product['max_price'],
                'formatted_min' => '₱' . number_format($product['min_price'], 2),
                'formatted_max' => '₱' . number_format($product['max_price'], 2),
                'currency' => 'PHP'
            ];
        } else {
            $product['price'] = null;
        }
        
        // 임시 필드 제거
        unset($product['min_price'], $product['max_price']);
        
        $product['available_for_delivery'] = $product['total_stock'] > 0;
    }
    
    $response = [
        'success' => true,
        'message' => 'Products retrieved successfully',
        'data' => [
            'products' => $products,
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total_count' => (int)$total_count,
                'total_pages' => ceil($total_count / $per_page),
                'has_next' => ($page * $per_page) < $total_count,
                'has_prev' => $page > 1
            ],
            'currency' => [
                'code' => 'PHP',
                'symbol' => '₱'
            ]
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    $error_response = [
        'success' => false,
        'message' => 'Failed to retrieve products',
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    http_response_code(500);
    echo json_encode($error_response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>