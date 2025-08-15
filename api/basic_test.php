<?php
/**
 * 가장 기본적인 테스트
 */

// 에러 표시 활성화
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

echo json_encode([
    'success' => true,
    'message' => 'Basic PHP test',
    'php_version' => phpversion(),
    'timestamp' => date('Y-m-d H:i:s'),
    'current_directory' => __DIR__,
    'file_exists_check' => [
        'config_exists' => file_exists(__DIR__ . '/../config/db_config.php'),
        'config_path' => __DIR__ . '/../config/db_config.php'
    ]
], JSON_PRETTY_PRINT);
?>