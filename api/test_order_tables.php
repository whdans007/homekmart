<?php
/**
 * 주문 관련 테이블 확인
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/db_config.php';
$conn = get_db_connection();

$tables_to_check = [
    'delivery_orders',
    'delivery_order_items',
    'delivery_addresses',
    'delivery_zones',
    'delivery_tracking'
];

$result = [];

foreach ($tables_to_check as $table) {
    $check_sql = "SHOW TABLES LIKE '$table'";
    $check_result = $conn->query($check_sql);

    if ($check_result->num_rows > 0) {
        // 테이블 존재 - 구조와 행 수 확인
        $structure_sql = "DESCRIBE $table";
        $structure_result = $conn->query($structure_sql);

        $columns = [];
        while ($row = $structure_result->fetch_assoc()) {
            $columns[] = $row;
        }

        $count_result = $conn->query("SELECT COUNT(*) as count FROM $table");
        $count = $count_result->fetch_assoc()['count'];

        $result[$table] = [
            'exists' => true,
            'columns' => $columns,
            'row_count' => intval($count)
        ];
    } else {
        $result[$table] = [
            'exists' => false
        ];
    }
}

echo json_encode([
    'success' => true,
    'tables' => $result
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

$conn->close();
?>
