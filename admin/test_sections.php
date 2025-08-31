<?php
// 최소한의 display_sections 테스트 페이지 - 오류 확인용
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><title>섹션 테스트</title></head><body>";
echo "<h1>Display Sections 테스트</h1>";

// 1. PHP 기본 확인
echo "<h2>1. PHP 기본 상태</h2>";
echo "PHP 버전: " . phpversion() . "<br>";
echo "현재 시간: " . date('Y-m-d H:i:s') . "<br>";

// 2. Config 파일 로드
echo "<h2>2. Config 파일 로드</h2>";
try {
    require_once __DIR__ . '/../config/db_config.php';
    echo "✅ Config 파일 로드 성공<br>";
    
    $conn = get_db_connection();
    echo "✅ 데이터베이스 연결 성공<br>";
    
} catch (Exception $e) {
    echo "❌ Config 오류: " . $e->getMessage() . "<br>";
    exit;
}

// 3. display_sections 테이블 확인
echo "<h2>3. display_sections 테이블 확인</h2>";
try {
    $check_table = $conn->query("SHOW TABLES LIKE 'display_sections'");
    if ($check_table->num_rows > 0) {
        echo "✅ display_sections 테이블 존재<br>";
        
        // 테이블 구조 확인
        $desc_result = $conn->query("DESCRIBE display_sections");
        echo "테이블 컬럼: ";
        while ($row = $desc_result->fetch_assoc()) {
            echo $row['Field'] . " ";
        }
        echo "<br>";
        
        // 데이터 개수 확인
        $count_result = $conn->query("SELECT COUNT(*) as count FROM display_sections");
        $count_row = $count_result->fetch_assoc();
        echo "섹션 수: " . $count_row['count'] . "<br>";
        
    } else {
        echo "❌ display_sections 테이블 없음<br>";
        echo "테이블을 생성해야 합니다.<br>";
    }
} catch (Exception $e) {
    echo "❌ 테이블 확인 오류: " . $e->getMessage() . "<br>";
}

// 4. 세션 테스트
echo "<h2>4. 세션 테스트</h2>";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
echo "세션 상태: " . (session_status() === PHP_SESSION_ACTIVE ? "활성" : "비활성") . "<br>";

// 5. 헬퍼 파일 테스트
echo "<h2>5. 헬퍼 파일 테스트</h2>";
try {
    require_once __DIR__ . '/../lib/session_helper.php';
    echo "✅ session_helper.php 로드 성공<br>";
    
    require_once __DIR__ . '/../lib/permission_helper.php';
    echo "✅ permission_helper.php 로드 성공<br>";
    
} catch (Exception $e) {
    echo "❌ 헬퍼 파일 오류: " . $e->getMessage() . "<br>";
}

$conn->close();
echo "</body></html>";
?>