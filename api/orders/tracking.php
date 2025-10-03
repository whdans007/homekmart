<?php
/**
 * 주문 배송 추적 조회 API
 * GET /api/orders/{id}/tracking
 * 인증 필요
 */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiError(405, 'Method not allowed');
}

// 인증 확인
$auth = requireAuth();
$user_id = $auth['user_id'];

if (!isset($_GET['id'])) {
    apiError(400, 'Order ID is required');
}

$order_id = intval($_GET['id']);

try {
    $pdo = getApiDbConnection();

    // 주문 소유권 확인
    $order_sql = "SELECT id, order_number, order_status FROM delivery_orders WHERE id = ? AND user_id = ?";
    $order_stmt = $pdo->prepare($order_sql);
    $order_stmt->execute([$order_id, $user_id]);
    $order = $order_stmt->fetch();

    if (!$order) {
        apiError(404, 'Order not found or access denied');
    }

    // 배송 추적 정보 조회
    $tracking_sql = "
        SELECT
            dt.id,
            dt.status,
            dt.notes,
            dt.location,
            dt.created_at,
            COALESCE(u.full_name, u.username, u.email) as updated_by_name
        FROM delivery_tracking dt
        LEFT JOIN users u ON dt.updated_by = u.id
        WHERE dt.order_id = ?
        ORDER BY dt.created_at ASC
    ";
    $tracking_stmt = $pdo->prepare($tracking_sql);
    $tracking_stmt->execute([$order_id]);
    $tracking = $tracking_stmt->fetchAll();

    foreach ($tracking as &$track) {
        $track['id'] = intval($track['id']);
    }

    $result = [
        'order_id' => intval($order['id']),
        'order_number' => $order['order_number'],
        'current_status' => $order['order_status'],
        'tracking_history' => $tracking
    ];

    apiSuccess($result);

} catch (PDOException $e) {
    error_log("Order tracking error: " . $e->getMessage());
    apiError(500, 'Failed to retrieve tracking information');
}
?>
