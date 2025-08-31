<?php
// 최소한의 테스트 페이지 - 오류 확인용
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><title>기본 테스트</title></head><body>";
echo "<h1>HOME K MART 기본 테스트</h1>";

// 1. PHP 기본 동작 확인
echo "<h2>1. PHP 기본 정보</h2>";
echo "PHP 버전: " . phpversion() . "<br>";
echo "현재 시간: " . date('Y-m-d H:i:s') . "<br>";

// 2. 파일 경로 확인
echo "<h2>2. 파일 경로</h2>";
echo "현재 파일: " . __FILE__ . "<br>";
echo "현재 디렉토리: " . __DIR__ . "<br>";

// 3. config 파일 확인
echo "<h2>3. Config 파일 테스트</h2>";
$config_path = __DIR__ . '/../config/db_config.php';
echo "Config 경로: " . $config_path . "<br>";

if (file_exists($config_path)) {
    echo "✅ Config 파일 존재<br>";
    try {
        require_once $config_path;
        echo "✅ Config 파일 로드 성공<br>";
        
        // 4. 데이터베이스 연결 테스트
        echo "<h2>4. 데이터베이스 연결</h2>";
        try {
            $conn = get_db_connection();
            echo "✅ 데이터베이스 연결 성공<br>";
            
            // 간단한 쿼리 테스트
            $result = $conn->query("SELECT COUNT(*) as count FROM users");
            $row = $result->fetch_assoc();
            echo "사용자 수: " . $row['count'] . "<br>";
            
            $conn->close();
        } catch (Exception $e) {
            echo "❌ 데이터베이스 오류: " . $e->getMessage() . "<br>";
        }
        
    } catch (Exception $e) {
        echo "❌ Config 로드 오류: " . $e->getMessage() . "<br>";
    }
} else {
    echo "❌ Config 파일 없음<br>";
}

// 5. 세션 테스트
echo "<h2>5. 세션 테스트</h2>";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
echo "세션 상태: " . (session_status() === PHP_SESSION_ACTIVE ? "활성" : "비활성") . "<br>";
echo "세션 ID: " . session_id() . "<br>";

// 6. 헬퍼 파일 테스트
echo "<h2>6. 헬퍼 파일 테스트</h2>";
$session_helper = __DIR__ . '/../lib/session_helper.php';
$permission_helper = __DIR__ . '/../lib/permission_helper.php';

if (file_exists($session_helper)) {
    echo "✅ session_helper.php 존재<br>";
    try {
        require_once $session_helper;
        echo "✅ session_helper.php 로드 성공<br>";
    } catch (Exception $e) {
        echo "❌ session_helper.php 오류: " . $e->getMessage() . "<br>";
    }
} else {
    echo "❌ session_helper.php 없음<br>";
}

if (file_exists($permission_helper)) {
    echo "✅ permission_helper.php 존재<br>";
    try {
        require_once $permission_helper;
        echo "✅ permission_helper.php 로드 성공<br>";
    } catch (Exception $e) {
        echo "❌ permission_helper.php 오류: " . $e->getMessage() . "<br>";
    }
} else {
    echo "❌ permission_helper.php 없음<br>";
}

echo "</body></html>";
?>