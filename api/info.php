<?php
/**
 * API 정보 (라우터 없이 직접 접근)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$response = [
    'success' => true,
    'name' => 'HOME K MART Delivery API',
    'version' => '1.0.0',
    'description' => 'Philippines delivery app API with COD payment support',
    'endpoints' => [
        'test' => 'GET /min/api/simple_test.php',
        'info' => 'GET /min/api/info.php',
        'products_direct' => 'GET /min/api/products_direct.php',
        'categories_direct' => 'GET /min/api/categories_direct.php'
    ],
    'server_info' => [
        'php_version' => phpversion(),
        'current_time' => date('Y-m-d H:i:s'),
        'request_uri' => $_SERVER['REQUEST_URI']
    ],
    'currency' => [
        'code' => 'PHP',
        'symbol' => '₱'
    ],
    'supported_languages' => ['en', 'ko'],
    'payment_methods' => ['cod', 'gcash', 'paymaya']
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>