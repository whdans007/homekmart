<?php
/**
 * 주문 취소 API
 * POST /api/orders/{id}/cancel
 * 인증 필요
 *
 * 요청 본문:
 * {
 *   "cancel_reason": "고객 변심"
 * }
 */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError(405, 'Method not allowed');
}

// 인증 확인
$auth = requireAuth();
$user_id = $auth['user_id'];

if (!isset($_GET['id'])) {
    apiError(400, 'Order ID is required');
}

$order_id = intval($_GET['id']);
$data = getRequestBody();

$cancel_reason = $data['cancel_reason'] ?? 'Customer request';

try {
    $pdo = getApiDbConnection();
    $pdo->beginTransaction();

    // 주문 조회 및 소유권 확인
    $order_sql = "
        SELECT id, order_status, payment_status, store_id
        FROM delivery_orders
        WHERE id = ? AND user_id = ?
    ";
    $order_stmt = $pdo->prepare($order_sql);
    $order_stmt->execute([$order_id, $user_id]);
    $order = $order_stmt->fetch();

    if (!$order) {
        $pdo->rollBack();
        apiError(404, 'Order not found or access denied');
    }

    // 취소 가능한 상태 확인
    $cancellable_statuses = ['pending', 'confirmed', 'preparing'];
    if (!in_array($order['order_status'], $cancellable_statuses)) {
        $pdo->rollBack();
        apiError(400, "Cannot cancel order with status: {$order['order_status']}");
    }

    // 주문 상품 조회 (재고 복구용)
    $items_sql = "SELECT product_id, quantity FROM delivery_order_items WHERE order_id = ?";
    $items_stmt = $pdo->prepare($items_sql);
    $items_stmt->execute([$order_id]);
    $items = $items_stmt->fetchAll();

    // 재고 복구
    $inventory_restore_sql = "
        UPDATE inventory
        SET quantity = quantity + ?
        WHERE product_id = ? AND store_id = ?
    ";
    $inventory_restore_stmt = $pdo->prepare($inventory_restore_sql);

    foreach ($items as $item) {
        $inventory_restore_stmt->execute([
            $item['quantity'],
            $item['product_id'],
            $order['store_id']
        ]);
    }

    // 포인트 복구
    $points_restore_sql = "
        UPDATE users
        SET points = points + (
            SELECT points_used FROM delivery_orders WHERE id = ?
        )
        WHERE id = ?
    ";
    $points_restore_stmt = $pdo->prepare($points_restore_sql);
    $points_restore_stmt->execute([$order_id, $user_id]);

    // 주문 상태 업데이트
    $update_sql = "
        UPDATE delivery_orders
        SET order_status = 'cancelled',
            cancel_reason = ?,
            cancelled_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ";
    $update_stmt = $pdo->prepare($update_sql);
    $update_stmt->execute([$cancel_reason, $order_id]);

    // 배송 추적 추가
    $tracking_sql = "
        INSERT INTO delivery_tracking (order_id, status, notes, created_at)
        VALUES (?, 'cancelled', ?, NOW())
    ";
    $tracking_stmt = $pdo->prepare($tracking_sql);
    $tracking_stmt->execute([$order_id, $cancel_reason]);

    $pdo->commit();

    apiSuccess([
        'order_id' => $order_id,
        'order_status' => 'cancelled',
        'cancel_reason' => $cancel_reason
    ], 'Order cancelled successfully');

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Order cancel error: " . $e->getMessage());
    apiError(500, 'Failed to cancel order');
}
?>
