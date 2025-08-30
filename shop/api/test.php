<?php
/**
 * API 테스트 파일 - 직접 접근용
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

echo json_encode([
    'status' => 'success',
    'message' => 'API 파일이 정상적으로 작동합니다!',
    'timestamp' => date('Y-m-d H:i:s'),
    'test_urls' => [
        'products' => '/homekmart/shop/api/products.php',
        'categories' => '/homekmart/shop/api/categories.php',
        'main_api' => '/homekmart/shop/api/index.php'
    ]
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>