<?php
// 레이아웃 데이터 상세 확인
require_once __DIR__ . '/../config/db_config.php';

try {
    $conn = get_db_connection();
    
    echo "<h1>레이아웃 데이터 상세 확인</h1>";
    
    // 1. layout_rows 확인
    echo "<h2>1. layout_rows 테이블</h2>";
    $rows_query = "SELECT * FROM layout_rows ORDER BY row_order ASC";
    $rows_result = $conn->query($rows_query);
    
    if ($rows_result) {
        echo "<table border='1'>";
        echo "<tr><th>ID</th><th>Name</th><th>Order</th><th>Description</th><th>Active</th></tr>";
        while ($row = $rows_result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['id']}</td>";
            echo "<td>{$row['row_name']}</td>";
            echo "<td>{$row['row_order']}</td>";
            echo "<td>{$row['row_description']}</td>";
            echo "<td>" . ($row['is_active'] ? 'Y' : 'N') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "<p>총 " . $rows_result->num_rows . "개 행</p>";
    }
    
    // 2. layout_columns 확인
    echo "<h2>2. layout_columns 테이블</h2>";
    $columns_query = "SELECT * FROM layout_columns ORDER BY row_id, column_order ASC";
    $columns_result = $conn->query($columns_query);
    
    if ($columns_result) {
        echo "<table border='1'>";
        echo "<tr><th>ID</th><th>Row ID</th><th>Order</th><th>Width</th><th>Section ID</th><th>Active</th></tr>";
        while ($col = $columns_result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$col['id']}</td>";
            echo "<td>{$col['row_id']}</td>";
            echo "<td>{$col['column_order']}</td>";
            echo "<td>{$col['column_width']}</td>";
            echo "<td>{$col['section_id']}</td>";
            echo "<td>" . ($col['is_active'] ? 'Y' : 'N') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "<p>총 " . $columns_result->num_rows . "개 컬럼</p>";
    }
    
    // 3. 데이터 정합성 체크
    echo "<h2>3. 데이터 정합성 체크</h2>";
    
    // 존재하지 않는 row_id 참조하는 컬럼 찾기
    $orphan_query = "
        SELECT lc.* 
        FROM layout_columns lc 
        LEFT JOIN layout_rows lr ON lc.row_id = lr.id 
        WHERE lr.id IS NULL
    ";
    $orphan_result = $conn->query($orphan_query);
    
    if ($orphan_result && $orphan_result->num_rows > 0) {
        echo "<p style='color: red;'>⚠️ 존재하지 않는 row_id를 참조하는 컬럼들:</p>";
        echo "<table border='1'>";
        echo "<tr><th>Column ID</th><th>Row ID (존재하지 않음)</th><th>Order</th><th>Section ID</th></tr>";
        while ($orphan = $orphan_result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$orphan['id']}</td>";
            echo "<td style='color: red;'>{$orphan['row_id']}</td>";
            echo "<td>{$orphan['column_order']}</td>";
            echo "<td>{$orphan['section_id']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: green;'>✅ 모든 컬럼이 올바른 row_id를 참조하고 있습니다.</p>";
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<p style='color: red;'>오류: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>