<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/inventory_helper.php';

lc_require_staff();
lc_verify_csrf();

$order_id = (int)($_POST['order_id'] ?? 0);
$action   = $_POST['action'] ?? '';
$uid      = lc_current_user_id();
$now      = date('Y-m-d H:i:s');

if (!$order_id || !in_array($action, ['approve','ship','deliver','cancel'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

try {
    $conn = get_lc_db();

    // 대책 B: 재고 차감은 승인(approve) 시점에 수행. 출고(ship)는 상태 전환만.
    if ($action === 'approve') {
        $conn->autocommit(false);
        $st = $conn->prepare("UPDATE lc_orders SET status='approved', approved_by=?, approved_at=? WHERE id=? AND status='pending'");
        $st->bind_param('isi', $uid, $now, $order_id);
        $st->execute();
        if ($st->affected_rows < 1) {
            $st->close(); $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Only pending orders can be approved.']);
            exit;
        }
        $st->close();
        // 대책 C: store/order.php가 접수 시점에 이미 차감했을 수 있으므로 중복 차감 방지.
        if (!lc_order_stock_allocated($conn, $order_id)) {
            lc_allocate_order_stock($conn, $order_id, true);
        }
        $conn->query("UPDATE lc_orders SET total_amount=(SELECT COALESCE(SUM(total_amount),0) FROM lc_order_items WHERE order_id=$order_id) WHERE id=$order_id");
        $conn->commit();
    } elseif ($action === 'ship') {
        $st = $conn->prepare("UPDATE lc_orders SET status='shipped', shipped_at=? WHERE id=? AND status='approved'");
        $st->bind_param('si', $now, $order_id);
        $st->execute();
        $st->close();
    } elseif ($action === 'cancel') {
        $conn->autocommit(false);
        $st = $conn->prepare("UPDATE lc_orders SET status='cancelled' WHERE id=? AND status IN ('pending','approved')");
        $st->bind_param('i', $order_id);
        $st->execute();
        $affected = $st->affected_rows; $st->close();
        if ($affected > 0) { lc_restore_order_stock($conn, $order_id); }
        $conn->commit();
    } else { // deliver
        $st = $conn->prepare("UPDATE lc_orders SET status='delivered', delivered_at=? WHERE id=? AND status='shipped'");
        $st->bind_param('si', $now, $order_id);
        $st->execute();
        $st->close();
    }

    $conn->close();
    echo json_encode(['success' => true, 'message' => 'Processed successfully.']);
} catch (Exception $e) {
    if (isset($conn)) { $conn->rollback(); $conn->close(); }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
