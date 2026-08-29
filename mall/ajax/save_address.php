<?php
/**
 * POST mall/ajax/save_address.php
 * 배송지 생성/수정 — address_id가 있으면 수정, 없으면 신규 생성.
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
$data = [
    'recipient_name' => $_POST['recipient_name'] ?? '',
    'phone' => $_POST['phone'] ?? '',
    'region' => $_POST['region'] ?? '',
    'city' => $_POST['city'] ?? '',
    'barangay' => $_POST['barangay'] ?? '',
    'detail_address' => $_POST['detail_address'] ?? '',
    'landmark' => $_POST['landmark'] ?? '',
    'lat' => $_POST['lat'] ?? null,
    'lng' => $_POST['lng'] ?? null,
    'is_default' => ($_POST['is_default'] ?? '') === '1',
];

$error_messages = [
    'VALIDATION_ERROR' => '수령인, 휴대폰, 랜드마크는 필수 입력 항목입니다',
    'PIN_REQUIRED' => '지도에서 배송 위치를 먼저 선택해주세요',
];

if ($address_id > 0) {
    if (!mall_address_get($member['id'], $address_id)) {
        json_error('NOT_FOUND', '배송지를 찾을 수 없습니다', 404);
    }
    $result = mall_address_update($member['id'], $address_id, $data);
} else {
    $result = mall_address_create($member['id'], $data);
}

if (!$result['success']) {
    json_error($result['error'], $error_messages[$result['error']] ?? '저장 중 오류가 발생했습니다');
}

echo json_encode(['success' => true, 'data' => ['id' => $result['id'] ?? $address_id]]);
