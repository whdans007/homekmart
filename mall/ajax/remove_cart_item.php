<?php
/**
 * POST mall/ajax/remove_cart_item.php
 * Design Ref: shopping-mall.design.md §4.1
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/cart.php';

function json_error($code, $message, $http = 400) {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
    exit;
}

$member = mall_current_member();
$member_id = $member ? $member['id'] : null;
$guest_token = $member ? null : mall_guest_token();
$cart_item_id = (int)($_POST['cart_item_id'] ?? 0);

if ($cart_item_id <= 0) {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}

$result = mall_cart_remove($member_id, $guest_token, $cart_item_id);

if (!$result['success']) {
    json_error('VALIDATION_ERROR', '대상 장바구니 항목을 찾을 수 없습니다', 404);
}

echo json_encode(['success' => true, 'data' => ['cart_count' => mall_cart_count($member_id, $guest_token)]]);
