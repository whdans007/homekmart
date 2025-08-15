<?php
/**
 * 작동하는 카테고리 API
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    require_once __DIR__ . '/config/db_config.php';
    
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 간단한 카테고리 조회
    $sql = "SELECT id, name, description FROM categories ORDER BY name ASC LIMIT 20";
    
    $stmt = $pdo->query($sql);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 데이터 가공
    $processed_categories = [];
    foreach ($categories as $category) {
        $processed_categories[] = [
            'id' => (int)$category['id'],
            'name' => $category['name'],
            'description' => $category['description'],
            'available_for_delivery' => true
        ];
    }
    
    $response = [
        'success' => true,
        'message' => 'Categories retrieved successfully',
        'data' => [
            'categories' => $processed_categories,
            'count' => count($processed_categories)
        ],
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