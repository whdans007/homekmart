<?php
/**
 * POST mall/admin/ajax/confirm_fresh_weight.php
 * 준비중(preparing) 주문의 weight 타입 신선상품 라인에 실측 무게(g)를 입력해 확정금액을 계산한다.
 * Design Ref: mall-fresh-products.design.md §4.2
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../lib/session_helper.php';
require_once __DIR__ . '/../../../lib/permission_helper.php';
require_once __DIR__ . '/../../../config/db_config.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../lib/order.php';

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

$mall_fresh_order_item_id = (int)($_POST['mall_fresh_order_item_id'] ?? 0);
$actual_weight_g = (int)($_POST['actual_weight_g'] ?? 0);
if ($mall_fresh_order_item_id <= 0) {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}

$error_messages = [
    'INVALID_STATE' => '무게 상품이 아닙니다',
];

$result = mall_fresh_confirm_weight($mall_fresh_order_item_id, $actual_weight_g);
if (!$result['success']) {
    $code = $result['error']['code'] ?? 'SERVER_ERROR';
    json_error($code, $result['error']['message'] ?? ($error_messages[$code] ?? '처리 중 오류가 발생했습니다'));
}

echo json_encode(['success' => true, 'data' => $result['data']]);
