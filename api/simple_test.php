<?php
/**
 * 간단한 API 테스트
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    require_once __DIR__ . '/../config/db_config.php';
    
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 간단한 상품 조회
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM products");
    $product_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // 테이블 확인
    $tables = $pdo->query("SHOW TABLES LIKE 'delivery_%'")->fetchAll(PDO::FETCH_COLUMN);
    
    $response = [
        'success' => true,
        'message' => 'API test successful',
        'data' => [
            'product_count' => $product_count,
            'delivery_tables' => $tables,
            'timestamp' => date('Y-m-d H:i:s'),
            'server_info' => [
                'php_version' => phpversion(),
                'request_uri' => $_SERVER['REQUEST_URI'],
                'method' => $_SERVER['REQUEST_METHOD']
            ]
        ]
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    $error_response = [
        'success' => false,
        'message' => 'API test failed',
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    http_response_code(500);
    echo json_encode($error_response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>