<?php
require_once __DIR__ . '/config/db_config.php';

$conn = get_db_connection();

echo "<h2>store_order_list_items 테이블 구조</h2>";
echo "<pre>";
$result = $conn->query('DESCRIBE store_order_list_items');
while($row = $result->fetch_assoc()) {
    echo $row['Field'] . " (" . $row['Type'] . ")" . ($row['Null'] === 'NO' ? ' NOT NULL' : '') . " DEFAULT: " . $row['Default'] . "\n";
}
echo "</pre>";

echo "<h2>remarks vs order_status 확인</h2>";
$remarks_check = $conn->query("SHOW COLUMNS FROM store_order_list_items LIKE 'remarks'");
$order_status_check = $conn->query("SHOW COLUMNS FROM store_order_list_items LIKE 'order_status'");

echo "remarks 컬럼: " . ($remarks_check->num_rows > 0 ? "존재함 (삭제 필요)" : "없음 ✓") . "<br>";
echo "order_status 컬럼: " . ($order_status_check->num_rows > 0 ? "존재함 ✓" : "없음 (생성 필요)") . "<br>";

if ($remarks_check->num_rows > 0 || $order_status_check->num_rows === 0) {
    echo "<br><h3>마이그레이션 실행 필요</h3>";
    echo "<p><a href='run_migration.php'>마이그레이션 실행하기</a></p>";
}

echo "<h2>샘플 데이터</h2>";
$sample = $conn->query("SELECT * FROM store_order_list_items LIMIT 3");
echo "<pre>";
while($row = $sample->fetch_assoc()) {
    print_r($row);
}
echo "</pre>";

$conn->close();
?>
