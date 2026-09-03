<?php
/**
 * POST mall/driver/ajax/send_order_chat_message.php
 * Design Ref: mall-order-chat.design.md 확장 — 기사 배정 시 같은 주문톡 대화방에 참여
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../lib/driver.php';
require_once __DIR__ . '/../../lib/delivery.php';
require_once __DIR__ . '/../../lib/order_chat.php';
require_once __DIR__ . '/../../lib/csrf.php';

function json_error($code, $message, $http = 400) {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
    exit;
}

$driver = mall_driver_current();
if (!$driver) {
    json_error('UNAUTHORIZED', '로그인이 필요합니다', 401);
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

// IDOR 방지 + 배송 종료 시 채팅 차단: 본인에게 "현재 활성 배정"된 주문이 아니면 거부한다.
// 배송이 완료/실패로 끝나면 그 즉시 기사는 이 주문에 메시지를 보낼 수 없어야 한다.
$assignment = mall_delivery_get_active_assignment($order_id);
if (!$assignment || (int)$assignment['driver_id'] !== (int)$driver['id']) {
    json_error('VALIDATION_ERROR', '대상 주문을 찾을 수 없습니다', 404);
}

try {
    $row = mall_order_chat_send($order_id, 'driver', (int)$driver['id'], $message);
    echo json_encode(['success' => true, 'data' => $row]);
} catch (Exception $e) {
    error_log('driver send_order_chat_message.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
