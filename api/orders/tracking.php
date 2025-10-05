<?php
/**
 * 주문 배송 추적 조회 API
 * GET /api/orders/tracking.php?order_id={order_id}&user_id={user_id}
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

if (!isset($_GET['order_id']) || !isset($_GET['user_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => ['message' => 'order_id and user_id are required']
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$order_id = intval($_GET['order_id']);
$user_id = intval($_GET['user_id']);

try {
    $conn = get_db_connection();

    // 주문 소유권 확인
    $order_sql = "
        SELECT id, order_number, order_status
        FROM delivery_orders
        WHERE id = $order_id AND user_id = $user_id
    ";
    $order_result = $conn->query($order_sql);

    if ($order_result->num_rows === 0) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => ['message' => 'Order not found']
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $order = $order_result->fetch_assoc();

    // 배송 추적 정보 조회
    $tracking_sql = "
        SELECT
            dt.id,
            dt.status,
            dt.status_message,
            dt.notes,
            dt.latitude,
            dt.longitude,
            dt.timestamp,
            u.username as updated_by_name
        FROM delivery_tracking dt
        LEFT JOIN users u ON dt.updated_by_user_id = u.id
        WHERE dt.order_id = $order_id
        ORDER BY dt.timestamp ASC
    ";
    $tracking_result = $conn->query($tracking_sql);

    $tracking = [];
    while ($track = $tracking_result->fetch_assoc()) {
        $tracking[] = [
            'id' => intval($track['id']),
            'status' => $track['status'],
            'status_message' => $track['status_message'],
            'notes' => $track['notes'],
            'latitude' => $track['latitude'] ? floatval($track['latitude']) : null,
            'longitude' => $track['longitude'] ? floatval($track['longitude']) : null,
            'timestamp' => $track['timestamp'],
            'updated_by_name' => $track['updated_by_name']
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'order_id' => intval($order['id']),
            'order_number' => $order['order_number'],
            'current_status' => $order['order_status'],
            'tracking_history' => $tracking
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    error_log("Order tracking error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => ['message' => 'Failed to retrieve tracking information']
    ], JSON_UNESCAPED_UNICODE);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
