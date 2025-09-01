<?php
// 쿼리 테스트
require_once __DIR__ . '/../config/db_config.php';

try {
    $conn = get_db_connection();
    
    echo "<h1>쿼리 테스트</h1>";
    
    // 1. display_sections 테이블 확인
    echo "<h2>1. display_sections 테이블 데이터</h2>";
    $sections_query = "SELECT id, name, description, section_type, display_order FROM display_sections ORDER BY display_order ASC";
    $sections_result = $conn->query($sections_query);
    
    if ($sections_result) {
        echo "<table border='1'>";
        echo "<tr><th>ID</th><th>Name</th><th>Description</th><th>Type</th><th>Order</th></tr>";
        while ($row = $sections_result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['id']}</td>";
            echo "<td>{$row['name']}</td>";
            echo "<td>{$row['description']}</td>";
            echo "<td>{$row['section_type']}</td>";
            echo "<td>{$row['display_order']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "<p>총 " . $sections_result->num_rows . "개 섹션</p>";
    } else {
        echo "<p style='color: red;'>섹션 조회 실패: " . $conn->error . "</p>";
    }
    
    // 2. JOIN 쿼리 테스트
    echo "<h2>2. JOIN 쿼리 테스트</h2>";
    $join_query = "
        SELECT lc.*, ds.id as section_id, ds.name as section_name, ds.section_type, 
               ds.layout_type, ds.is_active as section_active
        FROM layout_columns lc 
        LEFT JOIN display_sections ds ON lc.section_id = ds.id 
        WHERE lc.is_active = 1 
        ORDER BY lc.row_id, lc.column_order ASC
    ";
    
    echo "<p>실행할 쿼리:</p>";
    echo "<pre>" . htmlspecialchars($join_query) . "</pre>";
    
    $join_result = $conn->query($join_query);
    
    if ($join_result) {
        echo "<table border='1'>";
        echo "<tr><th>Column ID</th><th>Row ID</th><th>Order</th><th>Width</th><th>Section ID</th><th>Section Name</th><th>Type</th></tr>";
        while ($row = $join_result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['id']}</td>";
            echo "<td>{$row['row_id']}</td>";
            echo "<td>{$row['column_order']}</td>";
            echo "<td>{$row['column_width']}</td>";
            echo "<td>{$row['section_id']}</td>";
            echo "<td>{$row['section_name']}</td>";
            echo "<td>{$row['section_type']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "<p>총 " . $join_result->num_rows . "개 결과</p>";
    } else {
        echo "<p style='color: red;'>JOIN 쿼리 실패: " . $conn->error . "</p>";
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<p style='color: red;'>오류: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>