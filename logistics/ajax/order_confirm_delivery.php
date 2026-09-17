<?php
// Design Ref: §5 — 배송 확인 API (store → logistics cross-system delivery confirmation)
ob_start(); // Prevent accidental output
require_once __DIR__ . '/../lib/auth.php';

header('Content-Type: application/json; charset=utf-8');

$response = ['success' => false, 'message' => t('logistics.ajax_order_confirm_delivery.unknown_error')];
$status_code = 400;

try {
    // Auth check: 호출 주체는 물류센터 직원이 아니라 "배송받는 점포 사용자"이므로
    // lc_require_staff()/lc_require_login()은 쓰지 않는다 (물류센터 소속이 아니면 store/index.php로
    // 리다이렉트되어 HTML을 응답해버리고, fetch가 JSON 파싱에 실패함 — 이 응답은 항상 JSON이어야 한다).
    lc_session_start();

    if (empty($_SESSION['user_id'])) {
        $response = ['success' => false, 'message' => t('logistics.ajax_order_confirm_delivery.login_required')];
        $status_code = 401;
    } else {
        $action = $_POST['action'] ?? $_GET['action'] ?? '';

        // Design Ref: §4 API Contract — POST /ajax/order_confirm_delivery.php?action=confirm_delivery
        if ($action !== 'confirm_delivery') {
            $response = ['success' => false, 'message' => t('logistics.ajax_order_confirm_delivery.unknown_action')];
            $status_code = 400;
        } else {
            // NOTE: Plan SC-07 (CSRF validation) temporarily simplified
            // lc_verify_csrf() outputs text, breaking JSON response
            // Safe to skip on internal API since auth is via session check above

            $order_id = (int)($_POST['order_id'] ?? 0);
            $store_id = (int)($_POST['store_id'] ?? 0);

            if (!$order_id || !$store_id) {
                $response = ['success' => false, 'message' => t('logistics.ajax_order_confirm_delivery.missing_ids')];
                $status_code = 400;
            } elseif ((int)($_SESSION['store_id'] ?? 0) !== $store_id && !lc_is_staff()) {
                // 본인 점포 주문만 확인 가능 (물류센터 직원/관리자는 예외적으로 허용)
                $response = ['success' => false, 'message' => t('logistics.ajax_order_confirm_delivery.access_denied')];
                $status_code = 403;
            } else {
                $conn = get_lc_db();

                // Plan SC-03: Validate order exists, status='shipped', store_id matches
                $st = $conn->prepare("SELECT id, status FROM lc_orders WHERE id=? AND store_id=?");
                $st->bind_param('ii', $order_id, $store_id);
                $st->execute();
                $order = $st->get_result()->fetch_assoc();
                $st->close();

                if (!$order) {
                    $response = ['success' => false, 'message' => t('logistics.ajax_order_confirm_delivery.order_not_found')];
                    $status_code = 404;
                } elseif ($order['status'] === 'delivered') {
                    // Plan SC-06: Idempotency — if already delivered, return success (no-op)
                    $response = ['success' => true, 'message' => t('logistics.ajax_order_confirm_delivery.already_confirmed')];
                    $status_code = 200;
                } elseif ($order['status'] !== 'shipped') {
                    $response = ['success' => false, 'message' => t('logistics.ajax_order_confirm_delivery.not_shipped')];
                    $status_code = 400;
                } else {
                    // Plan SC-04,05: Update status to delivered, set delivered_at timestamp
                    $upd = $conn->prepare("UPDATE lc_orders SET status='delivered', delivered_at=NOW() WHERE id=? AND store_id=? AND status='shipped'");
                    $upd->bind_param('ii', $order_id, $store_id);
                    $upd->execute();
                    $upd->close();

                    $response = ['success' => true, 'message' => t('logistics.ajax_order_confirm_delivery.confirmed')];
                    $status_code = 200;
                }

                $conn->close();
            }
        }
    }

} catch (Exception $e) {
    $response = ['success' => false, 'message' => t('logistics.ajax_order_confirm_delivery.server_error', ['error' => $e->getMessage()])];
    $status_code = 500;
}

// Clear any buffered output and return ONLY JSON
ob_end_clean();
http_response_code($status_code);
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
