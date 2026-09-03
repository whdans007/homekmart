<?php
/**
 * POST mall/admin/ajax/send_order_chat_reply.php
 * Design Ref: mall-order-chat.design.md §4.2 — 관리자용 메시지 발송
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../lib/session_helper.php';
require_once __DIR__ . '/../../../lib/permission_helper.php';
require_once __DIR__ . '/../../../config/db_config.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../lib/order_chat.php';

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
$message = trim($_POST['message'] ?? '');

if ($order_id <= 0 || $message === '') {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}
if (mb_strlen($message) > 500) {
    json_error('VALIDATION_ERROR', '메시지는 500자 이내로 입력해주세요');
}

try {
    $conn = get_db_connection();
    $stmt = $conn->prepare('SELECT id FROM mall_orders WHERE id = ?');
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$order) {
        json_error('VALIDATION_ERROR', '대상 주문을 찾을 수 없습니다', 404);
    }

    $row = mall_order_chat_send($order_id, 'admin', (int)$_SESSION['user_id'], $message);

    echo json_encode(['success' => true, 'data' => $row]);
} catch (Exception $e) {
    error_log('send_order_chat_reply.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
