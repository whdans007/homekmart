<?php
/**
 * 종합 진단 도구
 */

// 에러 설정
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

echo "=== COMPREHENSIVE DIAGNOSTIC CHECK ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// 1. PHP 기본 정보
echo "1. PHP BASIC INFO\n";
echo "   PHP Version: " . phpversion() . "\n";
echo "   Memory Limit: " . ini_get('memory_limit') . "\n";
echo "   Max Execution Time: " . ini_get('max_execution_time') . "\n";
echo "   Error Reporting: " . error_reporting() . "\n";
echo "   Display Errors: " . (ini_get('display_errors') ? 'On' : 'Off') . "\n\n";

// 2. 파일 존재 여부
echo "2. FILE EXISTENCE CHECK\n";
$files_to_check = [
    __DIR__ . '/config/db_config.php',
    __DIR__ . '/api_products.php',
    __DIR__ . '/simple_products_api.php'
];

foreach ($files_to_check as $file) {
    $exists = file_exists($file);
    $readable = $exists && is_readable($file);
    echo "   " . basename($file) . ": " . ($exists ? "EXISTS" : "MISSING") . 
         ($readable ? " & READABLE" : ($exists ? " & NOT_READABLE" : "")) . "\n";
}
echo "\n";

// 3. 설정 파일 테스트
echo "3. CONFIG FILE TEST\n";
try {
    require_once __DIR__ . '/config/db_config.php';
    echo "   Config loaded: OK\n";
    echo "   DB_HOST: " . (defined('DB_HOST') ? DB_HOST : 'UNDEFINED') . "\n";
    echo "   DB_NAME: " . (defined('DB_NAME') ? DB_NAME : 'UNDEFINED') . "\n";
    echo "   DB_USER: " . (defined('DB_USER') ? DB_USER : 'UNDEFINED') . "\n";
    echo "   DB_PASS: " . (defined('DB_PASS') ? '[SET]' : 'UNDEFINED') . "\n";
} catch (Exception $e) {
    echo "   Config error: " . $e->getMessage() . "\n";
}
echo "\n";

// 4. 데이터베이스 연결 테스트
echo "4. DATABASE CONNECTION TEST\n";
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    echo "   Database connection: OK\n";
    
    $stmt = $pdo->query("SELECT VERSION() as version");
    $version = $stmt->fetch(PDO::FETCH_ASSOC)['version'];
    echo "   MySQL version: " . $version . "\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM products");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "   Products count: " . $count . "\n";
    
} catch (Exception $e) {
    echo "   Database error: " . $e->getMessage() . "\n";
}
echo "\n";

// 5. JSON 인코딩 테스트
echo "5. JSON ENCODING TEST\n";
$test_data = [
    'test' => 'value',
    'korean' => '한글테스트',
    'number' => 12345,
    'array' => [1, 2, 3]
];

$json = json_encode($test_data, JSON_UNESCAPED_UNICODE);
if ($json) {
    echo "   JSON encoding: OK\n";
    echo "   Sample JSON: " . $json . "\n";
} else {
    echo "   JSON encoding: FAILED\n";
    echo "   JSON error: " . json_last_error_msg() . "\n";
}
echo "\n";

// 6. 메모리 사용량
echo "6. MEMORY USAGE\n";
echo "   Current usage: " . memory_get_usage(true) . " bytes\n";
echo "   Peak usage: " . memory_get_peak_usage(true) . " bytes\n";
echo "   Memory limit: " . ini_get('memory_limit') . "\n\n";

// 7. 에러 로그 확인
echo "7. ERROR LOG CHECK\n";
$last_error = error_get_last();
if ($last_error) {
    echo "   Last error: " . $last_error['message'] . "\n";
    echo "   File: " . $last_error['file'] . "\n";
    echo "   Line: " . $last_error['line'] . "\n";
} else {
    echo "   No recent errors\n";
}

echo "\n=== DIAGNOSTIC COMPLETE ===\n";
?>