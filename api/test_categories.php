<?php
// 모든 에러 표시
ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/db_config.php';
$conn = get_db_connection();

// categories 테이블 구조 확인
$sql = "DESCRIBE categories";
$result = $conn->query($sql);

$columns = [];
while ($row = $result->fetch_assoc()) {
    $columns[] = $row;
}

echo json_encode([
    'success' => true,
    'table' => 'categories',
    'columns' => $columns
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
