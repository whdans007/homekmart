<?php
require_once __DIR__ . '/../lib/auth.php';
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
        // 대기 중 → 즉시 취소
        $st = $conn->prepare("UPDATE lc_orders SET status = 'cancelled' WHERE id = ? AND status = 'pending'");
        $st->bind_param('i', $order_id);
        $st->execute();
        $conn->close();
        echo json_encode(['success' => true, 'mode' => 'direct']);

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
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
