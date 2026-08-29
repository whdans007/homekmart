<?php
/**
 * POST mall/ajax/set_default_address.php
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/address.php';

function json_error($code, $message, $http = 400) {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
    exit;
}

$member = mall_current_member();
if (!$member) {
    json_error('UNAUTHORIZED', '로그인이 필요합니다', 401);
}

$address_id = (int)($_POST['address_id'] ?? 0);
if ($address_id <= 0 || !mall_address_get($member['id'], $address_id)) {
    json_error('NOT_FOUND', '배송지를 찾을 수 없습니다', 404);
}

$result = mall_address_set_default($member['id'], $address_id);
echo json_encode(['success' => $result['success']]);
