<?php
/**
 * 주문 취소 API
 * POST /api/orders/cancel.php
 * 테스트용 - 인증 없음
 *
 * 요청 본문:
 * {
 *   "order_id": 1,
 *   "user_id": 1,
 *   "cancellation_reason": "Changed my mind"
 * }
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/db_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => ['message' => 'Method not allowed']], JSON_UNESCAPED_UNICODE);
    exit();
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!isset($data['order_id']) || !isset($data['user_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => ['message' => 'order_id and user_id are required']
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$order_id = intval($data['order_id']);
$user_id = intval($data['user_id']);
$cancellation_reason = $data['cancellation_reason'] ?? 'Customer request';

try {
    $conn = get_db_connection();
    $conn->autocommit(false);

    // 주문 조회
    $order_sql = "
        SELECT id, order_status, payment_status, store_id
        FROM delivery_orders
        WHERE id = $order_id AND user_id = $user_id
    ";
    $order_result = $conn->query($order_sql);

    if ($order_result->num_rows === 0) {
        throw new Exception('Order not found');
    }

    $order = $order_result->fetch_assoc();

    // 취소 가능한 상태 확인
    $cancellable_statuses = ['pending', 'confirmed', 'preparing'];
    if (!in_array($order['order_status'], $cancellable_statuses)) {
        throw new Exception("Cannot cancel order with status: {$order['order_status']}");
    }

    // 주문 상품 조회 (재고 복구용)
    $items_sql = "SELECT product_id, quantity FROM delivery_order_items WHERE order_id = $order_id";
    $items_result = $conn->query($items_sql);

    // 재고 복구
    while ($item = $items_result->fetch_assoc()) {
        $restore_sql = "
            UPDATE inventory
            SET quantity = quantity + {$item['quantity']}
            WHERE product_id = {$item['product_id']} AND store_id = {$order['store_id']}
        ";
        $conn->query($restore_sql);
    }

    // 주문 상태 업데이트
    $escaped_reason = $conn->real_escape_string($cancellation_reason);
    $update_sql = "
        UPDATE delivery_orders
        SET order_status = 'cancelled',
            cancellation_reason = '$escaped_reason'
        WHERE id = $order_id
    ";

    if (!$conn->query($update_sql)) {
        throw new Exception('Failed to update order status');
    }

    // 배송 추적 추가
    $tracking_sql = "
        INSERT INTO delivery_tracking (order_id, status, status_message, updated_by_user_id)
        VALUES ($order_id, 'cancelled', '$escaped_reason', $user_id)
    ";
    $conn->query($tracking_sql);

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Order cancelled successfully',
        'data' => [
            'order_id' => intval($order_id),
            'order_status' => 'cancelled',
            'cancellation_reason' => $cancellation_reason
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    error_log("Order cancel error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => ['message' => $e->getMessage()]
    ], JSON_UNESCAPED_UNICODE);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
