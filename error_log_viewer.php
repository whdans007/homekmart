<?php
/**
 * 에러 로그 뷰어
 */

header('Content-Type: text/plain; charset=utf-8');

echo "=== Error Log Viewer ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// 일반적인 에러 로그 위치들
$possible_log_paths = [
    '/var/log/apache2/error.log',
    '/var/log/httpd/error_log',
    '/var/log/nginx/error.log',
    '/xampp/apache/logs/error.log',
    '/Applications/XAMPP/logs/php_error_log',
    ini_get('error_log'),
    __DIR__ . '/error.log',
    $_SERVER['DOCUMENT_ROOT'] . '/error.log'
];

echo "Checking possible error log locations:\n";
foreach ($possible_log_paths as $path) {
    if ($path && file_exists($path) && is_readable($path)) {
        echo "✓ Found: $path\n";
        
        $lines = file($path);
        $recent_lines = array_slice($lines, -20); // 마지막 20줄
        
        echo "\n--- Last 20 lines ---\n";
        foreach ($recent_lines as $line) {
            echo $line;
        }
        echo "\n";
        break;
    } else {
        echo "✗ Not found: $path\n";
    }
}

echo "\n=== PHP Configuration ===\n";
echo "Error Reporting: " . error_reporting() . "\n";
echo "Display Errors: " . (ini_get('display_errors') ? 'On' : 'Off') . "\n";
echo "Log Errors: " . (ini_get('log_errors') ? 'On' : 'Off') . "\n";
echo "Error Log: " . (ini_get('error_log') ?: 'Not set') . "\n";

echo "\n=== Server Info ===\n";
echo "PHP Version: " . phpversion() . "\n";
echo "Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "\n";
echo "Document Root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'Unknown') . "\n";

// 마지막 PHP 에러 확인
$last_error = error_get_last();
if ($last_error) {
    echo "\n=== Last PHP Error ===\n";
    echo "Type: " . $last_error['type'] . "\n";
    echo "Message: " . $last_error['message'] . "\n";
    echo "File: " . $last_error['file'] . "\n";
    echo "Line: " . $last_error['line'] . "\n";
}

echo "\n=== File Permissions ===\n";
$files_to_check = [
    __DIR__ . '/config/db_config.php',
    __DIR__ . '/api_products.php',
    __DIR__ . '/api_products_safe.php'
];

foreach ($files_to_check as $file) {
    if (file_exists($file)) {
        $perms = fileperms($file);
        $perms_str = substr(sprintf('%o', $perms), -4);
        echo "✓ $file - Permissions: $perms_str\n";
    } else {
        echo "✗ $file - Not found\n";
    }
}
?>