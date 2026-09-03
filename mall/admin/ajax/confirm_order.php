<?php
/**
 * POST mall/admin/ajax/confirm_order.php
 * 주문 관리 화면 "접수확인" 버튼 — 주문을 준비중으로 전환하면서 예상 준비완료 시각을 기록한다.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../lib/session_helper.php';
require_once __DIR__ . '/../../../lib/permission_helper.php';
require_once __DIR__ . '/../../../config/db_config.php';
require_once __DIR__ . '/../../lib/csrf.php';

function json_error($code, $message, $http = 400) {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
    exit;
}

if (!is_logged_in()) {
    json_error('UNAUTHORIZED', '로그인이 필요합니다', 401);
}
if (!has_permission('mall_management')) {
    json_error('UNAUTHORIZED', '권한이 없습니다', 403);
}
if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
    json_error('CSRF_INVALID', '요청이 만료되었습니다. 새로고침 후 다시 시도해주세요', 403);
}

$order_id = (int)($_POST['order_id'] ?? 0);
$prep_minutes = (int)($_POST['prep_minutes'] ?? -1);

if ($order_id <= 0 || $prep_minutes < 0) {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}

try {
    $conn = get_db_connection();

    $check = $conn->prepare('SELECT status FROM mall_orders WHERE id = ?');
    $check->bind_param('i', $order_id);
    $check->execute();
    $order = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$order) {
        $conn->close();
        json_error('VALIDATION_ERROR', '대상 주문을 찾을 수 없습니다', 404);
    }
    if ($order['status'] !== 'pending') {
        $conn->close();
        json_error('INVALID_STATE_TRANSITION', '접수대기 상태의 주문만 접수확인할 수 있습니다');
    }

    $stmt = $conn->prepare(
        "UPDATE mall_orders SET status = 'preparing', confirmed_at = NOW(), estimated_ready_at = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?"
    );
    $stmt->bind_param('ii', $prep_minutes, $order_id);
    $stmt->execute();
    $stmt->close();

    $result_stmt = $conn->prepare('SELECT estimated_ready_at FROM mall_orders WHERE id = ?');
    $result_stmt->bind_param('i', $order_id);
    $result_stmt->execute();
    $row = $result_stmt->get_result()->fetch_assoc();
    $result_stmt->close();
    $conn->close();

    echo json_encode(['success' => true, 'data' => ['order_id' => $order_id, 'status' => 'preparing', 'estimated_ready_at' => $row['estimated_ready_at']]]);
} catch (Exception $e) {
    error_log('confirm_order.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
