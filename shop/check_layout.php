<?php
// 간단한 레이아웃 데이터 확인
require_once __DIR__ . '/../config/db_config.php';

try {
    $conn = get_db_connection();
    
    echo "<h1>레이아웃 데이터 확인</h1>";
    
    // 레이아웃 행 수 확인
    $rows_result = $conn->query("SELECT COUNT(*) as count FROM layout_rows");
    $rows_count = $rows_result->fetch_assoc()['count'];
    echo "<p>레이아웃 행 수: <strong>$rows_count</strong></p>";
    
    // 레이아웃 컬럼 수 확인
    $columns_result = $conn->query("SELECT COUNT(*) as count FROM layout_columns");
    $columns_count = $columns_result->fetch_assoc()['count'];
    echo "<p>레이아웃 컬럼 수: <strong>$columns_count</strong></p>";
    
    // 진열 섹션 수 확인
    $sections_result = $conn->query("SELECT COUNT(*) as count FROM display_sections WHERE is_active = 1");
    $sections_count = $sections_result->fetch_assoc()['count'];
    echo "<p>활성 진열 섹션 수: <strong>$sections_count</strong></p>";
    
    if ($rows_count > 0 && $columns_count > 0) {
        echo "<p style='color: green;'>✅ 레이아웃 데이터가 존재합니다.</p>";
        
        // 첫 번째 행의 데이터 확인
        $first_row = $conn->query("SELECT * FROM layout_rows ORDER BY row_order ASC LIMIT 1");
        if ($row_data = $first_row->fetch_assoc()) {
            echo "<p>첫 번째 행: {$row_data['row_name']} (ID: {$row_data['id']}, 활성: " . ($row_data['is_active'] ? 'Y' : 'N') . ")</p>";
            
            // 해당 행의 컬럼 확인
            $columns_query = "SELECT lc.*, ds.name FROM layout_columns lc LEFT JOIN display_sections ds ON lc.section_id = ds.id WHERE lc.row_id = {$row_data['id']} ORDER BY lc.column_order";
            $columns_result = $conn->query($columns_query);
            
            echo "<p>첫 번째 행의 컬럼들:</p><ul>";
            while ($col = $columns_result->fetch_assoc()) {
                echo "<li>컬럼 {$col['column_order']}: 너비 {$col['column_width']}, 섹션 '{$col['name']}' (활성: " . ($col['is_active'] ? 'Y' : 'N') . ")</li>";
            }
            echo "</ul>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ 레이아웃 데이터가 없습니다.</p>";
        echo "<p><a href='../admin/setup_default_layout.php'>레이아웃 설정하기</a></p>";
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<p style='color: red;'>오류: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

<hr>
<a href="../admin/index.php">← 관리자 대시보드</a> | 
<a href="index_hmart.php">메인 페이지 확인 →</a>