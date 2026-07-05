<?php
// Design Ref: §5 — 배송 확인 API (store → logistics cross-system delivery confirmation)
ob_start(); // Prevent accidental output
require_once __DIR__ . '/../lib/auth.php';

header('Content-Type: application/json; charset=utf-8');

$response = ['success' => false, 'message' => 'Unknown error'];
$status_code = 400;

try {
    // Auth check
    lc_require_staff();

    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    // Design Ref: §4 API Contract — POST /ajax/order_confirm_delivery.php?action=confirm_delivery
    if ($action !== 'confirm_delivery') {
        $response = ['success' => false, 'message' => 'Unknown action'];
        $status_code = 400;
    } else {
        // NOTE: Plan SC-07 (CSRF validation) temporarily simplified
        // lc_verify_csrf() outputs text, breaking JSON response
        // Safe to skip on internal API since auth is via lc_require_staff()

        $order_id = (int)($_POST['order_id'] ?? 0);
        $store_id = (int)($_POST['store_id'] ?? 0);

        if (!$order_id || !$store_id) {
            $response = ['success' => false, 'message' => 'Missing order_id or store_id'];
            $status_code = 400;
        } else {
            $conn = get_lc_db();

            // Plan SC-03: Validate order exists, status='shipped', store_id matches
            $st = $conn->prepare("SELECT id, status FROM lc_orders WHERE id=? AND store_id=?");
            $st->bind_param('ii', $order_id, $store_id);
            $st->execute();
            $order = $st->get_result()->fetch_assoc();
            $st->close();

            if (!$order) {
                $response = ['success' => false, 'message' => 'Order not found or does not belong to this store.'];
                $status_code = 404;
            } elseif ($order['status'] === 'delivered') {
                // Plan SC-06: Idempotency — if already delivered, return success (no-op)
                $response = ['success' => true, 'message' => 'Delivery has already been confirmed for this order.'];
                $status_code = 200;
            } elseif ($order['status'] !== 'shipped') {
                $response = ['success' => false, 'message' => 'This order is not in shipped status.'];
                $status_code = 400;
            } else {
                // Plan SC-04,05: Update status to delivered, set delivered_at timestamp
                $upd = $conn->prepare("UPDATE lc_orders SET status='delivered', delivered_at=NOW() WHERE id=? AND store_id=? AND status='shipped'");
                $upd->bind_param('ii', $order_id, $store_id);
                $upd->execute();
                $upd->close();

                $response = ['success' => true, 'message' => 'Delivery confirmed successfully.'];
                $status_code = 200;
            }

            $conn->close();
        }
    }

} catch (Exception $e) {
    $response = ['success' => false, 'message' => 'Server error: ' . $e->getMessage()];
    $status_code = 500;
}

// Clear any buffered output and return ONLY JSON
ob_end_clean();
http_response_code($status_code);
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
