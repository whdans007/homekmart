<?php
/**
 * POST mall/ajax/delete_address.php
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
if ($address_id <= 0) {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}

$result = mall_address_delete($member['id'], $address_id);
if (!$result['success']) {
    json_error('NOT_FOUND', '배송지를 찾을 수 없습니다', 404);
}

echo json_encode(['success' => true]);
