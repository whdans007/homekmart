<?php
// 에러 표시 강제 활성화
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 출력 버퍼 시작
ob_start();

try {
    // JSON 헤더 설정
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    
    // 현재 에러 상태 체크
    $last_error = error_get_last();
    if ($last_error && $last_error['message']) {
        throw new Exception("PHP Error detected: " . $last_error['message']);
    }
    
    // 성공 응답
    $response = [
        'success' => true,
        'message' => 'Ultra simple API test passed',
        'timestamp' => date('Y-m-d H:i:s'),
        'php_version' => phpversion()
    ];
    
    // 출력 버퍼 정리
    ob_clean();
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Throwable $e) {
    // 모든 에러/예외 캐치
    ob_clean();
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error_type' => get_class($e),
        'error_message' => $e->getMessage(),
        'error_file' => $e->getFile(),
        'error_line' => $e->getLine(),
        'php_version' => phpversion(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
}

// 출력 버퍼 종료
ob_end_flush();
?>