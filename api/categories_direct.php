<?php
/**
 * 카테고리 목록 API (직접 접근)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    require_once __DIR__ . '/../config/db_config.php';
    
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 카테고리와 상품 수 조회
    $sql = "
        SELECT 
            c.id, c.name,
            COUNT(DISTINCT p.id) as product_count,
            COUNT(DISTINCT CASE WHEN i.quantity > 0 THEN p.id END) as available_count
        FROM categories c
        LEFT JOIN products p ON c.id = p.category_id 
            AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
        LEFT JOIN inventory i ON p.id = i.product_id
        GROUP BY c.id, c.name
        ORDER BY c.name ASC
    ";
    
    $stmt = $pdo->query($sql);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 타입 변환
    foreach ($categories as &$category) {
        $category['id'] = (int)$category['id'];
        $category['product_count'] = (int)$category['product_count'];
        $category['available_count'] = (int)$category['available_count'];
    }
    
    $response = [
        'success' => true,
        'message' => 'Categories retrieved successfully',
        'data' => [
            'categories' => $categories,
            'total_count' => count($categories)
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    $error_response = [
        'success' => false,
        'message' => 'Failed to retrieve categories',
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    http_response_code(500);
    echo json_encode($error_response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>