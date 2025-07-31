<?php
require_once __DIR__ . '/config/db_config.php';

$conn = get_db_connection();

echo "<h2>purchase_items 테이블 구조:</h2>";
$result = $conn->query("DESCRIBE purchase_items");
if ($result) {
    echo "<table border='1'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        foreach ($row as $value) {
            echo "<td>" . htmlspecialchars($value) . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "테이블 정보를 가져올 수 없습니다: " . $conn->error;
}

echo "<h2>inventory_transactions 테이블 구조:</h2>";
$result = $conn->query("DESCRIBE inventory_transactions");
if ($result) {
    echo "<table border='1'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        foreach ($row as $value) {
            echo "<td>" . htmlspecialchars($value) . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "테이블 정보를 가져올 수 없습니다: " . $conn->error;
}

$conn->close();
?>