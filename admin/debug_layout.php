<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

ensure_logged_in();
require_permission('admin_access');

$conn = get_db_connection();

echo "<h1>레이아웃 시스템 디버그</h1>";

// 1. 레이아웃 행 확인
echo "<h2>1. 레이아웃 행 데이터</h2>";
$rows_query = "SELECT * FROM layout_rows ORDER BY row_order ASC";
$rows_result = $conn->query($rows_query);

if ($rows_result && $rows_result->num_rows > 0) {
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>이름</th><th>순서</th><th>활성</th><th>설명</th></tr>";
    while ($row = $rows_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['row_name']}</td>";
        echo "<td>{$row['row_order']}</td>";
        echo "<td>" . ($row['is_active'] ? '활성' : '비활성') . "</td>";
        echo "<td>{$row['row_description']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color: red;'>❌ 레이아웃 행이 없습니다!</p>";
}

// 2. 레이아웃 컬럼 확인
echo "<h2>2. 레이아웃 컬럼 데이터</h2>";
$columns_query = "SELECT * FROM layout_columns ORDER BY row_id, column_order ASC";
$columns_result = $conn->query($columns_query);

if ($columns_result && $columns_result->num_rows > 0) {
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>행ID</th><th>순서</th><th>너비</th><th>섹션ID</th><th>활성</th></tr>";
    while ($column = $columns_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$column['id']}</td>";
        echo "<td>{$column['row_id']}</td>";
        echo "<td>{$column['column_order']}</td>";
        echo "<td>{$column['column_width']}</td>";
        echo "<td>{$column['section_id']}</td>";
        echo "<td>" . ($column['is_active'] ? '활성' : '비활성') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color: red;'>❌ 레이아웃 컬럼이 없습니다!</p>";
}

// 3. 진열 섹션 확인
echo "<h2>3. 진열 섹션 데이터</h2>";
$sections_query = "SELECT * FROM display_sections ORDER BY display_order ASC";
$sections_result = $conn->query($sections_query);

if ($sections_result && $sections_result->num_rows > 0) {
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>이름</th><th>순서</th><th>활성</th><th>타입</th></tr>";
    while ($section = $sections_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$section['id']}</td>";
        echo "<td>{$section['name']}</td>";
        echo "<td>{$section['display_order']}</td>";
        echo "<td>" . ($section['is_active'] ? '활성' : '비활성') . "</td>";
        echo "<td>{$section['layout_type']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color: red;'>❌ 진열 섹션이 없습니다!</p>";
}

// 4. 조인 쿼리 테스트
echo "<h2>4. 조인 쿼리 테스트</h2>";
$join_query = "
    SELECT lc.*, ds.id as section_id, ds.name as section_name, ds.layout_type, ds.is_active as section_active
    FROM layout_columns lc 
    LEFT JOIN display_sections ds ON lc.section_id = ds.id 
    WHERE lc.row_id = 1 AND lc.is_active = 1 
    ORDER BY lc.column_order ASC
";
$join_result = $conn->query($join_query);

if ($join_result && $join_result->num_rows > 0) {
    echo "<table border='1'>";
    echo "<tr><th>컬럼ID</th><th>행ID</th><th>순서</th><th>너비</th><th>섹션ID</th><th>섹션명</th><th>섹션활성</th></tr>";
    while ($data = $join_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$data['id']}</td>";
        echo "<td>{$data['row_id']}</td>";
        echo "<td>{$data['column_order']}</td>";
        echo "<td>{$data['column_width']}</td>";
        echo "<td>{$data['section_id']}</td>";
        echo "<td>{$data['section_name']}</td>";
        echo "<td>" . ($data['section_active'] ? '활성' : '비활성') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color: red;'>❌ 조인 쿼리 결과가 없습니다!</p>";
}

$conn->close();
?>

<br><br>
<a href="setup_default_layout.php">← 레이아웃 설정으로 돌아가기</a> | 
<a href="../shop/index_hmart.php" target="_blank">메인 페이지 확인 →</a>