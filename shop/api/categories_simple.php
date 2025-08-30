<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../config/db_config.php';
$conn = get_db_connection();

$sql = "SELECT id, name FROM categories LIMIT 5";
$result = $conn->query($sql);

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode(['success' => true, 'categories' => $data]);
?>