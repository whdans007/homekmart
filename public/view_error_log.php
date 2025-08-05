<?php
// 관리자만 접근 가능하도록 체크
session_start();
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    die('관리자만 접근 가능합니다.');
}

// 디버그 로그 지우기
if (isset($_GET['clear_debug'])) {
    $debug_file = __DIR__ . '/debug_log.txt';
    if (file_exists($debug_file)) {
        file_put_contents($debug_file, '');
        echo "<p style='color: green;'>디버그 로그를 지웠습니다.</p>";
    }
}

echo "<h2>PHP 오류 로그 확인</h2>";
echo "<style>body { font-family: monospace; } pre { background: #f5f5f5; padding: 10px; border: 1px solid #ddd; max-height: 500px; overflow-y: scroll; }</style>";

// 가능한 로그 파일 위치들
$log_locations = [
    '/var/log/apache2/error.log',
    '/var/log/php_errors.log',
    '/var/log/nginx/error.log',
    '/tmp/php_errors.log',
    ini_get('error_log'),
    '/usr/local/apache2/logs/error_log',
    '/volume1/@appstore/WebStation/var/log/php_error.log'
];

$found_logs = false;

foreach ($log_locations as $log_file) {
    if (!empty($log_file) && file_exists($log_file) && is_readable($log_file)) {
        echo "<h3>로그 파일: $log_file</h3>";
        
        // 최근 100줄만 읽기
        $lines = file($log_file);
        if ($lines !== false) {
            $recent_lines = array_slice($lines, -100);
            
            // 우리가 추가한 디버깅 로그만 필터링
            $filtered_lines = array_filter($recent_lines, function($line) {
                return strpos($line, '개별 가격적용') !== false || 
                       strpos($line, '점포별 가격') !== false ||
                       strpos($line, 'inventory 테이블') !== false ||
                       strpos($line, '가격변경 이력') !== false;
            });
            
            if (!empty($filtered_lines)) {
                echo "<pre>" . htmlspecialchars(implode('', $filtered_lines)) . "</pre>";
                $found_logs = true;
            } else {
                echo "<p>관련 디버깅 로그가 없습니다.</p>";
            }
        }
    }
}

if (!$found_logs) {
    echo "<h3>수동으로 오류 로그 확인하기</h3>";
    echo "<p>PHP 설정 정보:</p>";
    echo "<ul>";
    echo "<li>log_errors: " . (ini_get('log_errors') ? 'On' : 'Off') . "</li>";
    echo "<li>error_log: " . (ini_get('error_log') ?: '설정되지 않음') . "</li>";
    echo "<li>error_reporting: " . error_reporting() . "</li>";
    echo "</ul>";
    
    echo "<p>가격적용 버튼을 클릭한 후 이 페이지를 새로고침해보세요.</p>";
    
    // 디버그 파일 확인
    $debug_file = __DIR__ . '/debug_log.txt';
    if (file_exists($debug_file)) {
        echo "<h3>디버그 로그 파일: debug_log.txt</h3>";
        $debug_content = file_get_contents($debug_file);
        echo "<pre>" . htmlspecialchars($debug_content) . "</pre>";
        
        echo "<br><a href='?clear_debug=1'>디버그 로그 지우기</a>";
    } else {
        echo "<p>디버그 로그 파일이 없습니다.</p>";
    }
    
    // 현재 시간을 로그에 기록해서 테스트
    error_log("로그 테스트 - 현재 시간: " . date('Y-m-d H:i:s'));
    echo "<p>테스트 로그를 기록했습니다: " . date('Y-m-d H:i:s') . "</p>";
}

echo "<br><a href='purchase_price_change.php?purchase_id=" . ($_GET['purchase_id'] ?? '20') . "'>가격변동 페이지로 돌아가기</a>";
?>