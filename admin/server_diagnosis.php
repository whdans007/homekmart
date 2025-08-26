<?php
// 본 서버 환경 진단
header('Content-Type: application/json; charset=utf-8');

try {
    $diagnosis = [];
    
    // PHP 기본 정보
    $diagnosis['php_version'] = PHP_VERSION;
    $diagnosis['server_software'] = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
    
    // 백업 디렉토리 진단
    $backup_dir = __DIR__ . '/../backups/';
    $diagnosis['backup_dir_path'] = realpath($backup_dir) ?: $backup_dir;
    $diagnosis['backup_dir_exists'] = is_dir($backup_dir);
    $diagnosis['backup_dir_writable'] = is_writable($backup_dir);
    $diagnosis['backup_dir_readable'] = is_readable($backup_dir);
    
    // 디렉토리 생성 테스트
    if (!is_dir($backup_dir)) {
        $diagnosis['mkdir_result'] = mkdir($backup_dir, 0755, true);
        $diagnosis['mkdir_error'] = error_get_last();
    }
    
    // 파일 쓰기 테스트
    $test_file = $backup_dir . 'test_write.txt';
    $diagnosis['file_write_test'] = file_put_contents($test_file, 'test') !== false;
    if (file_exists($test_file)) {
        unlink($test_file);
    }
    
    // mysqldump 사용 가능 여부
    $diagnosis['exec_function'] = function_exists('exec');
    $diagnosis['shell_exec_function'] = function_exists('shell_exec');
    
    // PHP 설정 확인
    $diagnosis['memory_limit'] = ini_get('memory_limit');
    $diagnosis['max_execution_time'] = ini_get('max_execution_time');
    $diagnosis['error_reporting'] = error_reporting();
    $diagnosis['display_errors'] = ini_get('display_errors');
    
    // include 파일 경로 확인
    $config_path = '../config/db_config.php';
    $session_helper_path = '../lib/session_helper.php';
    $permission_helper_path = '../lib/permission_helper.php';
    
    $diagnosis['config_file_exists'] = file_exists($config_path);
    $diagnosis['session_helper_exists'] = file_exists($session_helper_path);
    $diagnosis['permission_helper_exists'] = file_exists($permission_helper_path);
    
    // 데이터베이스 연결 테스트
    if (file_exists($config_path)) {
        require_once $config_path;
        try {
            $conn = get_db_connection();
            $diagnosis['db_connection'] = ($conn && !$conn->connect_error);
            if ($conn) $conn->close();
        } catch (Exception $e) {
            $diagnosis['db_connection'] = false;
            $diagnosis['db_error'] = $e->getMessage();
        }
    }
    
    // 현재 작업 디렉토리
    $diagnosis['current_working_dir'] = getcwd();
    $diagnosis['script_filename'] = $_SERVER['SCRIPT_FILENAME'] ?? '';
    
    echo json_encode([
        'success' => true,
        'message' => '서버 환경 진단 완료',
        'diagnosis' => $diagnosis
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
} catch (Error $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Fatal Error: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
?>