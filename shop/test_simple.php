<?php
// 간단한 테스트 페이지
require_once __DIR__ . '/../config/db_config.php';

try {
    $conn = get_db_connection();
    echo "<h1>데이터베이스 연결 성공!</h1>";
    
    // 기본 정보 조회
    $stores_query = "SELECT COUNT(*) as count FROM stores";
    $result = $conn->query($stores_query);
    if ($result) {
        $row = $result->fetch_assoc();
        echo "<p>점포 수: " . $row['count'] . "</p>";
    }
    
    // 섹션 정보 조회  
    $sections_query = "SELECT COUNT(*) as count FROM display_sections";
    $result = $conn->query($sections_query);
    if ($result) {
        $row = $result->fetch_assoc();
        echo "<p>진열 섹션 수: " . $row['count'] . "</p>";
    }
    
    // 레이아웃 테이블 확인
    $table_check = $conn->query("SHOW TABLES LIKE 'layout_rows'");
    if ($table_check && $table_check->num_rows > 0) {
        echo "<p>레이아웃 시스템: 설치됨</p>";
    } else {
        echo "<p>레이아웃 시스템: 미설치 (기본 레이아웃 사용)</p>";
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<h1>오류 발생</h1>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
?>