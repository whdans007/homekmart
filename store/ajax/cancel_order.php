<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../../logistics/lib/inventory_helper.php';
header('Content-Type: application/json; charset=utf-8');

store_require_store_user();

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['store_csrf'] ?? '', $token)) {
    echo json_encode(['success' => false, 'message' => 'Security error']);
    exit;
}

$order_id = (int)($_POST['order_id'] ?? 0);
$store_id = store_current_store_id();

if (!$order_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

try {
    $conn = get_store_db();

    // 본인 점포 + pending 상태인지 확인
    $st = $conn->prepare(
        "SELECT id, status FROM lc_orders WHERE id = ? AND store_id = ?"
    );
    $st->bind_param('ii', $order_id, $store_id);
    $st->execute();
    $order = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$order) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Order not found.']);
        exit;
    }

    if ($order['status'] === 'pending') {
        // 대기 중 → 즉시 취소 + 주문 접수 시 차감했던 재고 복원
        $conn->autocommit(false);
        $st = $conn->prepare("UPDATE lc_orders SET status = 'cancelled' WHERE id = ? AND status = 'pending'");
        $st->bind_param('i', $order_id);
        $st->execute();
        $affected = $st->affected_rows;
        $st->close();

        if ($affected > 0) {
            lc_restore_order_stock($conn, $order_id);
            $conn->commit();
            $conn->close();
            echo json_encode(['success' => true, 'mode' => 'direct']);
        } else {
            $conn->rollback();
            $conn->close();
            echo json_encode(['success' => false, 'message' => 'Cannot cancel in current status.']);
        }

    } elseif ($order['status'] === 'approved') {
        // 승인됨 → 취소 요청 (물류 승인 필요)
        $st = $conn->prepare("UPDATE lc_orders SET status = 'cancel_requested' WHERE id = ? AND status = 'approved'");
        $st->bind_param('i', $order_id);
        $st->execute();
        $conn->close();
        echo json_encode(['success' => true, 'mode' => 'requested']);

    } else {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Cannot cancel in current status.']);
    }
} catch (Throwable $e) {
    if (isset($conn)) { $conn->rollback(); $conn->close(); }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
