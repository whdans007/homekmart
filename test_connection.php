<?php
/**
 * 연결 테스트 파일
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    echo json_encode([
        'success' => true,
        'message' => 'Connection test successful',
        'server_info' => [
            'php_version' => phpversion(),
            'server_name' => $_SERVER['SERVER_NAME'] ?? 'unknown',
            'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'script_name' => $_SERVER['SCRIPT_NAME'] ?? 'unknown',
            'current_directory' => __DIR__,
            'file_path' => __FILE__
        ],
        'timestamp' => date('Y-m-d H:i:s'),
        'timezone' => date_default_timezone_get()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>