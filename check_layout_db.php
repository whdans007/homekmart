<?php
require_once __DIR__ . '/config/db_config.php';

$conn = get_db_connection();

// 현재 레이아웃 행 데이터 확인
echo "<h2>LAYOUT ROWS</h2>";
$rows_result = $conn->query("SELECT * FROM layout_rows WHERE is_active = 1 ORDER BY row_order ASC");
if ($rows_result && $rows_result->num_rows > 0) {
    echo "<table border='1'><tr><th>ID</th><th>Row Name</th><th>Order</th><th>Description</th></tr>";
    while ($row = $rows_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['row_name']) . "</td>";
        echo "<td>" . $row['row_order'] . "</td>";
        echo "<td>" . htmlspecialchars($row['row_description']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No active layout rows found</p>";
}

echo "<h2>LAYOUT COLUMNS</h2>";
$columns_result = $conn->query("
    SELECT lc.*, lr.row_name, ds.name as section_name 
    FROM layout_columns lc 
    LEFT JOIN layout_rows lr ON lc.row_id = lr.id 
    LEFT JOIN display_sections ds ON lc.section_id = ds.id
    WHERE lc.is_active = 1 
    ORDER BY lr.row_order ASC, lc.column_order ASC
");
if ($columns_result && $columns_result->num_rows > 0) {
    echo "<table border='1'><tr><th>ID</th><th>Row</th><th>Column Name</th><th>Width</th><th>Section</th><th>Order</th></tr>";
    while ($col = $columns_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $col['id'] . "</td>";
        echo "<td>" . htmlspecialchars($col['row_name']) . "</td>";
        echo "<td>" . htmlspecialchars($col['column_name']) . "</td>";
        echo "<td>" . $col['column_width'] . "</td>";
        echo "<td>" . ($col['section_name'] ?: 'None') . "</td>";
        echo "<td>" . $col['column_order'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No active layout columns found</p>";
}

echo "<h2>DISPLAY SECTIONS</h2>";
$sections_result = $conn->query("SELECT * FROM display_sections WHERE is_active = 1 ORDER BY display_order ASC");
if ($sections_result && $sections_result->num_rows > 0) {
    echo "<table border='1'><tr><th>ID</th><th>Name</th><th>Type</th><th>Layout</th><th>Order</th></tr>";
    while ($section = $sections_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $section['id'] . "</td>";
        echo "<td>" . htmlspecialchars($section['name']) . "</td>";
        echo "<td>" . htmlspecialchars($section['section_type']) . "</td>";
        echo "<td>" . htmlspecialchars($section['layout_type']) . "</td>";
        echo "<td>" . $section['display_order'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No active display sections found</p>";
}

$conn->close();
?>