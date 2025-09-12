<?php
require_once 'config/db_config.php';

$conn = get_db_connection();
if (!$conn) {
    die('데이터베이스 연결 실패: ' . mysqli_connect_error());
}

echo "<h2>products 테이블 구조 확인</h2>";

echo "<h3>컬럼 정보:</h3>";
$result = $conn->query('DESCRIBE products');
echo "<table border='1' style='border-collapse: collapse;'>";
echo "<tr><th>컬럼명</th><th>타입</th><th>NULL</th><th>키</th><th>기본값</th><th>Extra</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . $row['Field'] . "</td>";
    echo "<td>" . $row['Type'] . "</td>";
    echo "<td>" . $row['Null'] . "</td>";
    echo "<td>" . $row['Key'] . "</td>";
    echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
    echo "<td>" . ($row['Extra'] ?? '') . "</td>";
    echo "</tr>";
}
echo "</table>";

echo "<h3>샘플 데이터 (처음 3개):</h3>";
$sample = $conn->query('SELECT * FROM products LIMIT 3');
if ($sample && $sample->num_rows > 0) {
    echo "<table border='1' style='border-collapse: collapse;'>";
    $first = true;
    while ($row = $sample->fetch_assoc()) {
        if ($first) {
            echo "<tr>";
            foreach (array_keys($row) as $column) {
                echo "<th>" . $column . "</th>";
            }
            echo "</tr>";
            $first = false;
        }
        echo "<tr>";
        foreach ($row as $value) {
            echo "<td>" . htmlspecialchars($value ?? '') . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "데이터가 없습니다.";
}

$conn->close();
?>