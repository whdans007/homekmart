<?php
/**
 * 경로 수정된 간단한 API 테스트
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    // 절대 경로로 시도
    $config_paths = [
        __DIR__ . '/../config/db_config.php',
        dirname(__DIR__) . '/config/db_config.php',
        '/volume1/web/min/config/db_config.php',
        __DIR__ . '/../../config/db_config.php'
    ];
    
    $config_loaded = false;
    $config_path_used = '';
    
    foreach ($config_paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $config_loaded = true;
            $config_path_used = $path;
            break;
        }
    }
    
    if (!$config_loaded) {
        throw new Exception('Config file not found. Tried: ' . implode(', ', $config_paths));
    }
    
    // 데이터베이스 연결 테스트
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 간단한 쿼리
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM products");
    $product_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // 배달 테이블 확인
    $tables = $pdo->query("SHOW TABLES LIKE 'delivery_%'")->fetchAll(PDO::FETCH_COLUMN);
    
    $response = [
        'success' => true,
        'message' => 'API test successful (fixed version)',
        'data' => [
            'config_path_used' => $config_path_used,
            'product_count' => $product_count,
            'delivery_tables' => $tables,
            'db_constants' => [
                'DB_HOST' => defined('DB_HOST') ? DB_HOST : 'undefined',
                'DB_NAME' => defined('DB_NAME') ? DB_NAME : 'undefined',
                'DB_USER' => defined('DB_USER') ? DB_USER : 'undefined'
            ]
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    $error_response = [
        'success' => false,
        'message' => 'API test failed',
        'error' => $e->getMessage(),
        'config_loaded' => $config_loaded ?? false,
        'config_path_used' => $config_path_used ?? '',
        'available_paths' => array_map(function($path) {
            return [
                'path' => $path,
                'exists' => file_exists($path),
                'readable' => file_exists($path) && is_readable($path)
            ];
        }, $config_paths ?? []),
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    http_response_code(500);
    echo json_encode($error_response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>