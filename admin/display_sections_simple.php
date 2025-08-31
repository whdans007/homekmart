<?php
// 단순화된 display_sections 페이지 - 500 오류 진단용
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>";
echo "<html><head><title>Display Sections 단순 테스트</title></head><body>";
echo "<h1>Display Sections 단순 테스트</h1>";

try {
    // 1. 기본 설정
    require_once __DIR__ . '/../config/db_config.php';
    require_once __DIR__ . '/../lib/session_helper.php';
    require_once __DIR__ . '/../lib/permission_helper.php';
    
    echo "<p>✅ 필수 파일 로드 성공</p>";
    
    // 2. 로그인 체크 (단순화)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    echo "<p>✅ 세션 시작 성공</p>";
    
    // 로그인 확인 (단순 체크)
    if (!isset($_SESSION['user_id'])) {
        echo "<p>❌ 로그인 필요 - user_id 없음</p>";
        echo "<p>현재 세션: " . print_r($_SESSION, true) . "</p>";
    } else {
        echo "<p>✅ 로그인 상태 확인 - user_id: " . $_SESSION['user_id'] . "</p>";
    }
    
    // 3. 데이터베이스 연결
    $conn = get_db_connection();
    echo "<p>✅ 데이터베이스 연결 성공</p>";
    
    // 4. 기본 쿼리 테스트
    $result = $conn->query("SELECT COUNT(*) as count FROM display_sections");
    $row = $result->fetch_assoc();
    echo "<p>✅ 쿼리 실행 성공 - 섹션 수: " . $row['count'] . "</p>";
    
    // 5. 액션 처리 (GET 파라미터 확인)
    $action = $_GET['action'] ?? 'list';
    echo "<p>현재 액션: " . htmlspecialchars($action) . "</p>";
    
    // 6. POST 데이터 확인
    if ($_POST) {
        echo "<p>POST 데이터 존재:</p>";
        echo "<pre>" . print_r($_POST, true) . "</pre>";
    }
    
    echo "<p>✅ 모든 기본 체크 완료</p>";
    echo "<p><a href='display_sections.php'>원본 display_sections.php로 이동</a></p>";
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<p>❌ 오류 발생: " . $e->getMessage() . "</p>";
    echo "<p>오류 위치: " . $e->getFile() . " 라인 " . $e->getLine() . "</p>";
} catch (Error $e) {
    echo "<p>❌ PHP 오류 발생: " . $e->getMessage() . "</p>";
    echo "<p>오류 위치: " . $e->getFile() . " 라인 " . $e->getLine() . "</p>";
}

echo "</body></html>";
?>