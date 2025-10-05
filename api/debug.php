<?php
/**
 * API 디버그 테스트
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');

$debug = [];

// 1. PHP 버전
$debug['php_version'] = phpversion();

// 2. 파일 경로 확인
$debug['current_dir'] = __DIR__;
$debug['config_exists'] = file_exists(__DIR__ . '/../config/db_config.php');

// 3. config 파일 로드 시도
try {
    if (file_exists(__DIR__ . '/../config/db_config.php')) {
        require_once __DIR__ . '/../config/db_config.php';
        $debug['config_loaded'] = true;
        $debug['db_host'] = defined('DB_HOST') ? DB_HOST : 'NOT DEFINED';
        $debug['db_name'] = defined('DB_NAME') ? DB_NAME : 'NOT DEFINED';
    } else {
        $debug['config_loaded'] = false;
        $debug['error'] = 'Config file not found';
    }
} catch (Exception $e) {
    $debug['config_error'] = $e->getMessage();
}

// 4. 데이터베이스 연결 시도
try {
    if (function_exists('get_db_connection')) {
        $conn = get_db_connection();
        if ($conn) {
            $debug['db_connected'] = true;

            // 간단한 쿼리
            $result = $conn->query("SELECT COUNT(*) as count FROM products");
            if ($result) {
                $row = $result->fetch_assoc();
                $debug['product_count'] = $row['count'];
            }
        } else {
            $debug['db_connected'] = false;
        }
    } else {
        $debug['db_function_exists'] = false;
    }
} catch (Exception $e) {
    $debug['db_error'] = $e->getMessage();
}

echo json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
