<?php
/**
 * 에러 확인
 */

echo "<!DOCTYPE html>";
echo "<html><head><title>API 에러 확인</title></head><body>";
echo "<h1>API 에러 확인</h1>";

echo "<h2>PHP 설정</h2>";
echo "<ul>";
echo "<li>PHP Version: " . phpversion() . "</li>";
echo "<li>Display Errors: " . (ini_get('display_errors') ? 'On' : 'Off') . "</li>";
echo "<li>Log Errors: " . (ini_get('log_errors') ? 'On' : 'Off') . "</li>";
echo "<li>Error Log: " . (ini_get('error_log') ?: '설정되지 않음') . "</li>";
echo "</ul>";

echo "<h2>파일 경로 확인</h2>";
echo "<ul>";
echo "<li>현재 디렉토리: " . __DIR__ . "</li>";
echo "<li>상위 디렉토리: " . dirname(__DIR__) . "</li>";
echo "<li>config 파일: " . __DIR__ . '/../config/db_config.php</li>';
echo "<li>config 파일 존재: " . (file_exists(__DIR__ . '/../config/db_config.php') ? '✓' : '✗') . "</li>";
echo "</ul>";

echo "<h2>디렉토리 내용</h2>";
echo "<pre>";
print_r(scandir(__DIR__));
echo "</pre>";

echo "<h2>상위 디렉토리 내용</h2>";
echo "<pre>";
print_r(scandir(dirname(__DIR__)));
echo "</pre>";

// 간단한 에러 테스트
echo "<h2>에러 테스트</h2>";
try {
    // 의도적으로 에러 발생
    $test = 1 / 0; // Warning
} catch (Exception $e) {
    echo "<p>Exception: " . $e->getMessage() . "</p>";
}

// 수동으로 에러 로그 작성
error_log("[API Test] " . date('Y-m-d H:i:s') . " - API 에러 확인 실행됨");

echo "</body></html>";
?>