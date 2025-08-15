<?php
/**
 * 카테고리 목록 API
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config/db_config.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 카테고리별 상품 개수와 함께 조회
    $sql = "
        SELECT 
            c.id, c.name, c.description,
            COUNT(DISTINCT p.id) as product_count,
            COALESCE(SUM(i.quantity), 0) as total_stock
        FROM categories c
        LEFT JOIN products p ON c.id = p.category_id 
            AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
        LEFT JOIN inventory i ON p.id = i.product_id
        GROUP BY c.id, c.name, c.description
        ORDER BY c.name ASC
    ";
    
    $stmt = $pdo->query($sql);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($categories as &$category) {
        $category['id'] = (int)$category['id'];
        $category['product_count'] = (int)$category['product_count'];
        $category['total_stock'] = (int)$category['total_stock'];
        $category['has_products'] = $category['product_count'] > 0;
        $category['available_for_delivery'] = $category['total_stock'] > 0;
    }
    
    $response = [
        'success' => true,
        'data' => [
            'categories' => $categories,
            'count' => count($categories)
        ],
        'message' => 'Categories retrieved successfully',
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