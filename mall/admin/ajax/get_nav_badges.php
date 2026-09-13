<?php
/**
 * GET mall/admin/ajax/get_nav_badges.php
 * 상단 메뉴 배지(접수대기 주문 수, 주문톡 안읽음 수) 폴링용 — sidebar.php가 30초 주기로 호출한다.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../lib/session_helper.php';
require_once __DIR__ . '/../../../lib/permission_helper.php';
require_once __DIR__ . '/../../../config/db_config.php';
require_once __DIR__ . '/../../config/mall_config.php';
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

$pending_orders = 0;
try {
    $result = mall_get_db_connection()->query(
        "SELECT COUNT(*) AS cnt FROM mall_orders WHERE store_id = " . (int)MALL_STORE_ID . " AND status = 'pending'"
    );
    $pending_orders = (int)($result->fetch_assoc()['cnt'] ?? 0);
} catch (Throwable $e) {
    $pending_orders = 0;
}

$delivering_orders = 0;
try {
    $result = mall_get_db_connection()->query(
        "SELECT COUNT(*) AS cnt FROM mall_orders WHERE store_id = " . (int)MALL_STORE_ID . " AND status = 'delivering'"
    );
    $delivering_orders = (int)($result->fetch_assoc()['cnt'] ?? 0);
} catch (Throwable $e) {
    $delivering_orders = 0;
}

$chat_unread = mall_order_chat_unread_total_for_admin();

$latest_chat = null;
try {
    $stmt = mall_get_db_connection()->prepare(
        "SELECT msg.id, msg.order_id, msg.message, msg.sender_type,
                o.order_number, mem.name AS member_name
         FROM mall_order_messages msg
         INNER JOIN mall_orders o ON o.id = msg.order_id
         INNER JOIN mall_members mem ON mem.id = o.member_id
         WHERE msg.sender_type = 'member'
           AND msg.is_read_by_admin = 0
         ORDER BY msg.id DESC
         LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $latest_chat = [
            'id' => (int)$row['id'],
            'order_id' => (int)$row['order_id'],
            'order_number' => (string)$row['order_number'],
            'member_name' => (string)$row['member_name'],
            'sender_type' => (string)$row['sender_type'],
            'message' => mb_substr(preg_replace('/\s+/u', ' ', trim((string)$row['message'])), 0, 100),
        ];
    }
} catch (Throwable $e) {
    $latest_chat = null;
}

echo json_encode(['success' => true, 'data' => [
    'pending_orders' => $pending_orders,
    'delivering_orders' => $delivering_orders,
    'chat_unread' => $chat_unread,
    'latest_chat' => $latest_chat,
]]);
