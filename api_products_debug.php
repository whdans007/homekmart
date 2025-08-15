<?php
/**
 * 상품 API 디버그 버전
 */

// 에러 표시 활성화
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    $debug_steps = [];
    
    $debug_steps[] = [
        'debug' => 'Starting API debug',
        'step' => 1,
        'message' => 'Headers set successfully'
    ];
    
    // 설정 파일 include 테스트
    if (!file_exists(__DIR__ . '/config/db_config.php')) {
        throw new Exception('Config file not found: ' . __DIR__ . '/config/db_config.php');
    }
    
    require_once __DIR__ . '/config/db_config.php';
    
    // 상수 확인
    if (!defined('DB_HOST')) {
        throw new Exception('DB_HOST not defined');
    }
    
    $debug_steps[] = [
        'debug' => 'Config loaded',
        'step' => 2,
        'db_constants' => [
            'DB_HOST' => defined('DB_HOST') ? DB_HOST : 'undefined',
            'DB_NAME' => defined('DB_NAME') ? DB_NAME : 'undefined',
            'DB_USER' => defined('DB_USER') ? DB_USER : 'undefined',
            'DB_CHARSET' => defined('DB_CHARSET') ? DB_CHARSET : 'undefined'
        ]
    ];
    
    // 데이터베이스 연결 테스트
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $debug_steps[] = [
        'debug' => 'Database connected',
        'step' => 3,
        'message' => 'PDO connection successful'
    ];
    
    // 간단한 쿼리 테스트
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM products");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    $debug_steps[] = [
        'debug' => 'Query executed',
        'step' => 4,
        'product_count' => $count,
        'success' => true
    ];
    
    // 모든 디버그 단계를 하나의 JSON으로 출력
    echo json_encode([
        'success' => true,
        'debug_steps' => $debug_steps,
        'final_result' => 'All tests passed',
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
        'current_directory' => __DIR__,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>