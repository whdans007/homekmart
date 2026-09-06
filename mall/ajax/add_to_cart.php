<?php
/**
 * POST mall/ajax/add_to_cart.php
 * Design Ref: shopping-mall.design.md §4.1
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/cart.php';
require_once __DIR__ . '/../lib/fresh_cart.php';
require_once __DIR__ . '/../lib/pricing.php';

function json_error($code, $message, $http = 400) {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
    exit;
}

// 장바구니는 회원가입 없이도 담을 수 있다(주문 시에만 로그인 필요) — 비로그인이면 게스트 토큰으로 담는다.
$member = mall_current_member();
$member_id = $member ? $member['id'] : null;
$guest_token = $member ? null : mall_guest_token();
$product_id = (int)($_POST['product_id'] ?? 0);
$quantity = (int)($_POST['quantity'] ?? 1);
$channel = ($member && $member['member_type'] === 'wholesale') ? 'wholesale' : 'retail';

if ($product_id <= 0 || $quantity <= 0) {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}
if (!mall_is_product_eligible_for_channel($product_id, $channel)) {
    json_error('VALIDATION_ERROR', '판매 중인 상품이 아닙니다', 404);
}

$stock = mall_get_stock_quantity($product_id);
if ($stock <= 0) {
    json_error('OUT_OF_STOCK', '재고가 부족합니다', 409);
}

$result = mall_cart_add($member_id, $guest_token, $product_id, $channel, $quantity);

if (!$result['success']) {
    json_error('SERVER_ERROR', '장바구니 담기에 실패했습니다', 500);
}

echo json_encode(['success' => true, 'data' => [
    'cart_count' => mall_cart_count($member_id, $guest_token) + mall_fresh_cart_count($member_id, $guest_token),
    'cart_item_id' => $result['cart_item_id'],
    'quantity' => $result['quantity'],
]]);
