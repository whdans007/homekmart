<?php
/**
 * POST mall/admin/ajax/mark_ready.php
 * 상품준비중 주문을 준비완료로 처리한다(preparing → ready).
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../lib/session_helper.php';
require_once __DIR__ . '/../../../lib/permission_helper.php';
require_once __DIR__ . '/../../../config/db_config.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../lib/delivery.php';
require_once __DIR__ . '/../../lib/order_chat.php';

function json_error($code, $message, $http = 400) {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
    exit;
}

if (!is_logged_in()) {
    json_error('UNAUTHORIZED', '로그인이 필요합니다', 401);
}
if (!has_mall_permission('mall_management')) {
    json_error('UNAUTHORIZED', '권한이 없습니다', 403);
}
if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
    json_error('CSRF_INVALID', '요청이 만료되었습니다. 새로고침 후 다시 시도해주세요', 403);
}

$order_id = (int)($_POST['order_id'] ?? 0);
if ($order_id <= 0) {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}

$error_messages = [
    'NOT_FOUND' => '대상 주문을 찾을 수 없습니다',
    'INVALID_STATE_TRANSITION' => '상품준비중 상태의 주문만 준비완료 처리할 수 있습니다',
    'PREPARING_BLOCKED' => '무게 상품의 실측 입력이 끝나지 않아 준비완료로 처리할 수 없습니다',
];

$result = mall_order_mark_ready($order_id);
if (!$result['success']) {
    json_error($result['error'], $error_messages[$result['error']] ?? '처리 중 오류가 발생했습니다');
}

if (!empty($_SESSION['user_id'])) {
    mall_order_chat_send($order_id, 'admin', (int)$_SESSION['user_id'], '상품 준비가 완료되었습니다.');
}

echo json_encode(['success' => true, 'data' => ['order_id' => $order_id, 'status' => 'ready']]);
