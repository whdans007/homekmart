<?php
/**
 * GET mall/driver/ajax/get_order_chat_messages.php?order_id=X&after_id=0
 * Design Ref: mall-order-chat.design.md 확장 — 기사 배정 시 같은 주문톡 대화방에 참여
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../lib/driver.php';
require_once __DIR__ . '/../../lib/delivery.php';
require_once __DIR__ . '/../../lib/order_chat.php';

function json_error($code, $message, $http = 400) {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
    exit;
}

$driver = mall_driver_current();
if (!$driver) {
    json_error('UNAUTHORIZED', '로그인이 필요합니다', 401);
}

$order_id = (int)($_GET['order_id'] ?? 0);
$after_id = (int)($_GET['after_id'] ?? 0);

if ($order_id <= 0) {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}

// IDOR 방지 + 배송 종료 시 채팅 차단: 본인에게 "현재 활성 배정"된 주문이 아니면 거부한다.
// 배송이 완료/실패로 끝나면 그 즉시 기사는 이 주문의 채팅을 볼 수 없어야 하므로, 배정 이력 전체가
// 아니라 활성 배정 여부만 기준으로 삼는다.
$assignment = mall_delivery_get_active_assignment($order_id);
if (!$assignment || (int)$assignment['driver_id'] !== (int)$driver['id']) {
    json_error('VALIDATION_ERROR', '대상 주문을 찾을 수 없습니다', 404);
}

try {
    $messages = mall_order_chat_list($order_id, $after_id);
    mall_order_chat_mark_read($order_id, 'driver');
    // 내(기사)가 보낸 메시지 중 고객이 읽은 가장 최근 id — 수신확인("읽음") 표시용
    $my_read_upto = mall_order_chat_sent_read_upto($order_id, 'driver', 'member');

    echo json_encode(['success' => true, 'data' => ['messages' => $messages, 'my_read_upto' => $my_read_upto]]);
} catch (Exception $e) {
    error_log('driver get_order_chat_messages.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
