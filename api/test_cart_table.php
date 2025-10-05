<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/db_config.php';
$conn = get_db_connection();

// shopping_cart 테이블 존재 확인
$check_sql = "SHOW TABLES LIKE 'shopping_cart'";
$check_result = $conn->query($check_sql);

if ($check_result->num_rows > 0) {
    // 테이블 구조 확인
    $sql = "DESCRIBE shopping_cart";
    $result = $conn->query($sql);

    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row;
    }

    // 데이터 개수 확인
    $count_result = $conn->query("SELECT COUNT(*) as count FROM shopping_cart");
    $count = $count_result->fetch_assoc()['count'];

    echo json_encode([
        'success' => true,
        'table_exists' => true,
        'table' => 'shopping_cart',
        'columns' => $columns,
        'row_count' => intval($count)
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} else {
    echo json_encode([
        'success' => false,
        'table_exists' => false,
        'message' => 'shopping_cart 테이블이 존재하지 않습니다. delivery_app_schema.sql을 실행해야 합니다.'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>
