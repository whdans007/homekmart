<?php
/**
 * POST mall/driver/ajax/update_location.php
 * Design Ref: mall-delivery-dispatch.design.md §4.2 — delivering 상태에서만 위치 기록(IDOR+상태 검증은 lib에서)
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../lib/driver.php';
require_once __DIR__ . '/../../lib/delivery.php';
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
$lat = isset($_POST['lat']) ? (float)$_POST['lat'] : null;
$lng = isset($_POST['lng']) ? (float)$_POST['lng'] : null;

if ($order_id <= 0 || $lat === null || $lng === null) {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}

$error_messages = [
    'NOT_ASSIGNED_TO_YOU' => '본인에게 배정된 주문이 아닙니다',
    'INVALID_STATE_TRANSITION' => '배송중 상태가 아닙니다',
    'SERVER_ERROR' => '처리 중 오류가 발생했습니다',
];

$result = mall_delivery_record_location($driver['id'], $order_id, $lat, $lng);
if (!$result['success']) {
    $http = $result['error'] === 'NOT_ASSIGNED_TO_YOU' ? 403 : 400;
    json_error($result['error'], $error_messages[$result['error']] ?? '처리 중 오류가 발생했습니다', $http);
}

echo json_encode(['success' => true]);
