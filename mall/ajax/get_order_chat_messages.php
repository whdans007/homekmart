<?php
/**
 * GET mall/ajax/get_order_chat_messages.php?order_id=X&after_id=0
 * Design Ref: mall-order-chat.design.md §4.2 — 고객용 채팅 증분 조회 + 열람 중 읽음처리(폴링)
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/order_chat.php';

function json_error($code, $message, $http = 400) {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
    exit;
}

if (!mall_is_logged_in()) {
    json_error('UNAUTHORIZED', '로그인이 필요합니다', 401);
}

$member = mall_current_member();
$order_id = (int)($_GET['order_id'] ?? 0);
$after_id = (int)($_GET['after_id'] ?? 0);

if ($order_id <= 0) {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}

try {
    $conn = get_db_connection();
    // 소유권 검증: 본인 주문만 조회 가능(IDOR 방지)
    $stmt = $conn->prepare('SELECT id FROM mall_orders WHERE id = ? AND member_id = ?');
    $stmt->bind_param('ii', $order_id, $member['id']);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$order) {
        json_error('VALIDATION_ERROR', '대상 주문을 찾을 수 없습니다', 404);
    }

    $messages = mall_order_chat_list($order_id, $after_id);
    mall_order_chat_mark_read($order_id, 'member');
    // 내(고객)가 보낸 메시지 중 관리자가 읽은 가장 최근 id — 수신확인("읽음") 표시용
    $my_read_upto = mall_order_chat_sent_read_upto($order_id, 'member', 'admin');

    echo json_encode(['success' => true, 'data' => ['messages' => $messages, 'my_read_upto' => $my_read_upto]]);
} catch (Exception $e) {
    error_log('get_order_chat_messages.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
