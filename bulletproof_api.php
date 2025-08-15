<?php
/**
 * 완전 방탄 API (모든 가능한 에러 핸들링)
 */

// 모든 에러를 캐치하기 위한 설정
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING])) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(500);
        }
        echo json_encode([
            'success' => false,
            'error' => 'Fatal error occurred',
            'details' => $error['message'],
            'file' => basename($error['file']),
            'line' => $error['line']
        ]);
    }
});

// 출력 버퍼링
ob_start();

try {
    // 헤더 설정
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
    }
    
    // 설정 파일 로드
    $config_path = __DIR__ . '/config/db_config.php';
    if (!file_exists($config_path)) {
        throw new Exception("Config file not found at: $config_path");
    }
    
    if (!is_readable($config_path)) {
        throw new Exception("Config file is not readable: $config_path");
    }
    
    require_once $config_path;
    
    // 상수 확인
    $required_constants = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_CHARSET'];
    foreach ($required_constants as $const) {
        if (!defined($const) || empty(constant($const))) {
            throw new Exception("Required constant '$const' is not defined or empty");
        }
    }
    
    // PDO 연결 (더 안전한 옵션들과 함께)
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 30, // 30초 타임아웃
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
    ];
    
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    
    // 연결 테스트
    $pdo->query("SELECT 1");
    
    // 매우 간단한 쿼리 (에러 가능성 최소화)
    $stmt = $pdo->prepare("SELECT id, name FROM products WHERE id <= ? LIMIT 3");
    $stmt->execute([100]);
    $products = $stmt->fetchAll();
    
    // 응답 데이터 구성
    $response = [
        'success' => true,
        'message' => 'Bulletproof API test successful',
        'data' => [
            'products' => $products,
            'count' => count($products),
            'server_info' => [
                'php_version' => phpversion(),
                'memory_usage' => memory_get_usage(true),
                'time' => date('Y-m-d H:i:s')
            ]
        ]
    ];
    
    // JSON 인코딩 전 검증
    if (!is_array($response) || !isset($response['success'])) {
        throw new Exception("Invalid response structure");
    }
    
    // JSON 인코딩
    $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
    if ($json === false) {
        throw new Exception("JSON encoding failed: " . json_last_error_msg());
    }
    
    // 출력 버퍼 정리 및 응답
    ob_clean();
    echo $json;
    
} catch (PDOException $e) {
    ob_clean();
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode([
        'success' => false,
        'error_type' => 'Database Error',
        'error_message' => $e->getMessage(),
        'error_code' => $e->getCode(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    
} catch (ErrorException $e) {
    ob_clean();
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode([
        'success' => false,
        'error_type' => 'PHP Error',
        'error_message' => $e->getMessage(),
        'error_severity' => $e->getSeverity(),
        'error_file' => basename($e->getFile()),
        'error_line' => $e->getLine(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    ob_clean();
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode([
        'success' => false,
        'error_type' => 'General Exception',
        'error_message' => $e->getMessage(),
        'error_file' => basename($e->getFile()),
        'error_line' => $e->getLine(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    ob_clean();
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode([
        'success' => false,
        'error_type' => 'Fatal Error',
        'error_message' => $e->getMessage(),
        'error_file' => basename($e->getFile()),
        'error_line' => $e->getLine(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
}

// 출력 버퍼 종료
ob_end_flush();
?>