<?php
/**
 * POST mall/ajax/submit_order.php
 * Design Ref: shopping-mall.design.md §4.2 상세 스펙
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/order.php';
require_once __DIR__ . '/../lib/csrf.php';

function json_error($code, $message, $http = 400, $details = null) {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message, 'details' => $details]]);
    exit;
}

if (!mall_is_logged_in()) {
    json_error('UNAUTHORIZED', '로그인이 필요합니다', 401);
}
if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
    json_error('CSRF_INVALID', '요청이 만료되었습니다. 새로고침 후 다시 시도해주세요', 403);
}

$member = mall_current_member();
$channel = ($_POST['channel'] ?? '') === 'wholesale' ? 'wholesale' : 'retail';
$memo = trim($_POST['memo'] ?? '');

$result = mall_create_order($member['id'], $member, $channel, $memo);

if (!$result['success']) {
    $http_map = ['UNAUTHORIZED' => 401, 'WHOLESALE_NOT_APPROVED' => 403, 'EMPTY_CART' => 400, 'OUT_OF_STOCK' => 409, 'NO_ADDRESS' => 400];
    json_error(
        $result['error']['code'],
        $result['error']['message'],
        $http_map[$result['error']['code']] ?? 400,
        $result['error']['details'] ?? null
    );
}

echo json_encode(['success' => true, 'data' => $result['data']]);
