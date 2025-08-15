<?php
// PDO 없이 기본 기능만 테스트
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$response = [
    'success' => true,
    'message' => 'Basic PHP test without PDO',
    'php_info' => [
        'version' => phpversion(),
        'extensions' => [
            'pdo' => extension_loaded('pdo'),
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'mysqli' => extension_loaded('mysqli'),
            'json' => extension_loaded('json'),
            'curl' => extension_loaded('curl')
        ],
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time')
    ],
    'server_info' => [
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown'
    ],
    'timestamp' => date('Y-m-d H:i:s')
];

echo json_encode($response, JSON_PRETTY_PRINT);
?>