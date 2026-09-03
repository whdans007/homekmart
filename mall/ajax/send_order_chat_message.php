<?php
/**
 * POST mall/ajax/send_order_chat_message.php
 * Design Ref: mall-order-chat.design.md §4.2 — 고객용 메시지 발송
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/order_chat.php';
require_once __DIR__ . '/../lib/csrf.php';

function json_error($code, $message, $http = 400) {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
    exit;
}

if (!mall_is_logged_in()) {
    json_error('UNAUTHORIZED', '로그인이 필요합니다', 401);
}
if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
    json_error('CSRF_INVALID', '요청이 만료되었습니다. 새로고침 후 다시 시도해주세요', 403);
}

$member = mall_current_member();
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
    // 소유권 검증: 본인 주문에만 발송 가능(IDOR 방지)
    $stmt = $conn->prepare('SELECT id FROM mall_orders WHERE id = ? AND member_id = ?');
    $stmt->bind_param('ii', $order_id, $member['id']);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$order) {
        json_error('VALIDATION_ERROR', '대상 주문을 찾을 수 없습니다', 404);
    }

    $row = mall_order_chat_send($order_id, 'member', $member['id'], $message);

    echo json_encode(['success' => true, 'data' => $row]);
} catch (Exception $e) {
    error_log('send_order_chat_message.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
