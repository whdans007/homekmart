<?php
// 진열 섹션 테이블 상태 확인 테스트
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/db_config.php';

echo "<!DOCTYPE html>";
echo "<html><head><title>Display Sections 테스트</title></head><body>";
echo "<h1>Display Sections 테이블 테스트</h1>";

try {
    $conn = get_db_connection();
    echo "<p>✅ 데이터베이스 연결 성공</p>";
    
    // 테이블 존재 확인
    echo "<h2>1. 테이블 존재 여부 확인</h2>";
    $tables_result = $conn->query("SHOW TABLES LIKE 'display_sections'");
    if ($tables_result->num_rows > 0) {
        echo "<p>✅ display_sections 테이블 존재</p>";
        
        // 테이블 구조 확인
        echo "<h2>2. 테이블 구조</h2>";
        $desc_result = $conn->query("DESCRIBE display_sections");
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
        while ($row = $desc_result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['Field'] . "</td>";
            echo "<td>" . $row['Type'] . "</td>";
            echo "<td>" . $row['Null'] . "</td>";
            echo "<td>" . $row['Key'] . "</td>";
            echo "<td>" . $row['Default'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // 데이터 확인
        echo "<h2>3. 테이블 데이터</h2>";
        $data_result = $conn->query("SELECT * FROM display_sections ORDER BY display_order ASC");
        echo "<p>총 " . $data_result->num_rows . "개 섹션</p>";
        
        if ($data_result->num_rows > 0) {
            echo "<table border='1' style='border-collapse: collapse;'>";
            echo "<tr><th>ID</th><th>Name</th><th>Type</th><th>Order</th><th>Active</th><th>Show Main</th></tr>";
            while ($row = $data_result->fetch_assoc()) {
                echo "<tr>";
                echo "<td>" . $row['id'] . "</td>";
                echo "<td>" . htmlspecialchars($row['name']) . "</td>";
                echo "<td>" . $row['section_type'] . "</td>";
                echo "<td>" . $row['display_order'] . "</td>";
                echo "<td>" . $row['is_active'] . "</td>";
                echo "<td>" . $row['show_on_main'] . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p>❌ 데이터가 없습니다. 테이블이 비어있습니다.</p>";
        }
        
        // 테스트 데이터 삽입
        echo "<h2>4. 테스트 섹션 생성</h2>";
        $test_insert = "INSERT INTO display_sections (name, section_type, display_order, layout_type, show_on_main, is_active) 
                       VALUES ('테스트 배너', 'banner', 10, 'banner', 1, 1)";
        
        if ($conn->query($test_insert)) {
            echo "<p>✅ 테스트 섹션 '테스트 배너' 생성 성공</p>";
        } else {
            echo "<p>❌ 테스트 섹션 생성 실패: " . $conn->error . "</p>";
        }
        
    } else {
        echo "<p>❌ display_sections 테이블이 존재하지 않습니다.</p>";
        echo "<p><strong>해결책:</strong> shopping_mall_schema.sql을 실행하여 테이블을 생성하세요.</p>";
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<p>❌ 오류 발생: " . $e->getMessage() . "</p>";
}

echo "<p><a href='display_sections.php'>진열 섹션 관리로 돌아가기</a></p>";
echo "</body></html>";
?>