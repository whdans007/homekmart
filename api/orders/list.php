<?php
/**
 * 주문 목록 조회 API
 * GET /api/orders/list.php?user_id={user_id}&status={status}
 * 테스트용 - 인증 없음
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/db_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => ['message' => 'Method not allowed']], JSON_UNESCAPED_UNICODE);
    exit();
}

if (!isset($_GET['user_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => ['message' => 'user_id is required']
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$user_id = intval($_GET['user_id']);
$status = $_GET['status'] ?? null;

try {
    $conn = get_db_connection();

    // WHERE 조건 구성
    $where_conditions = ["do.user_id = $user_id"];

    if ($status) {
        $valid_statuses = ['pending', 'confirmed', 'preparing', 'ready_for_delivery', 'out_for_delivery', 'delivered', 'cancelled'];
        if (!in_array($status, $valid_statuses)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => ['message' => 'Invalid status']
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $where_conditions[] = "do.order_status = '$status'";
    }

    $where_sql = implode(' AND ', $where_conditions);

    // 주문 목록 조회
    $list_sql = "
        SELECT
            do.id,
            do.order_number,
            do.subtotal,
            do.delivery_fee,
            do.discount_amount,
            do.total_amount,
            do.payment_method,
            do.payment_status,
            do.order_status,
            do.estimated_delivery_time,
            do.actual_delivery_time,
            do.created_at,
            da.address_name,
            da.city,
            da.province,
            da.barangay,
            (SELECT COUNT(*) FROM delivery_order_items WHERE order_id = do.id) as item_count
        FROM delivery_orders do
        LEFT JOIN delivery_addresses da ON do.delivery_address_id = da.id
        WHERE {$where_sql}
        ORDER BY do.created_at DESC
    ";

    $result = $conn->query($list_sql);

    $orders = [];
    while ($row = $result->fetch_assoc()) {
        $orders[] = [
            'id' => intval($row['id']),
            'order_number' => $row['order_number'],
            'subtotal' => floatval($row['subtotal']),
            'delivery_fee' => floatval($row['delivery_fee']),
            'discount_amount' => floatval($row['discount_amount']),
            'total_amount' => floatval($row['total_amount']),
            'payment_method' => $row['payment_method'],
            'payment_status' => $row['payment_status'],
            'order_status' => $row['order_status'],
            'estimated_delivery_time' => $row['estimated_delivery_time'],
            'actual_delivery_time' => $row['actual_delivery_time'],
            'created_at' => $row['created_at'],
            'delivery_address' => [
                'address_name' => $row['address_name'],
                'city' => $row['city'],
                'province' => $row['province'],
                'barangay' => $row['barangay']
            ],
            'item_count' => intval($row['item_count'])
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => $orders,
        'count' => count($orders)
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    error_log("Order list error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => ['message' => 'Failed to retrieve orders']
    ], JSON_UNESCAPED_UNICODE);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
