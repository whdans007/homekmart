<?php
/**
 * POST mall/ajax/add_fresh_to_cart.php
 * Design Ref: mall-fresh-products.design.md §4.2
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/cart.php';
require_once __DIR__ . '/../lib/fresh_cart.php';
require_once __DIR__ . '/../lib/csrf.php';

function json_error($code, $message, $http = 400) {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
    exit;
}

$member = mall_current_member();
$member_id = $member ? (int)$member['id'] : null;
$guest_token = $member ? null : mall_guest_token();
if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
    json_error('CSRF_INVALID', '요청이 만료되었습니다. 새로고침 후 다시 시도해주세요', 403);
}
$product_id = (int)($_POST['mall_fresh_product_id'] ?? 0);
$product = $product_id > 0 ? mall_fresh_product_get($product_id) : null;

if (!$product || $product['status'] !== 'active') {
    json_error('NOT_FOUND', '판매 중인 신선상품이 아닙니다', 404);
}
if ((int)$product['is_sold_out'] === 1) {
    json_error('SOLD_OUT', '품절된 상품입니다', 409);
}

$weight_g = null;
$quantity = null;
if ($product['sale_type'] === 'weight') {
    $weight_g = (int)($_POST['weight_g'] ?? 0);
    if ($weight_g <= 0 || $weight_g % 100 !== 0) {
        json_error('VALIDATION_ERROR', '무게는 100g 단위로 선택해주세요');
    }
} else {
    $quantity = (int)($_POST['quantity'] ?? 0);
    if ($quantity <= 0) {
        json_error('VALIDATION_ERROR', '수량을 확인해주세요');
    }
}

$result = mall_fresh_cart_add($member_id, $guest_token, $product_id, $weight_g, $quantity);
if (!$result['success']) {
    $code = $result['error'] ?? 'SERVER_ERROR';
    $http = $code === 'SOLD_OUT' ? 409 : ($code === 'NOT_FOUND' ? 404 : 400);
    json_error($code, $code === 'SOLD_OUT' ? '품절된 상품입니다' : '장바구니 담기에 실패했습니다', $http);
}

echo json_encode(['success' => true, 'data' => array_merge($result['data'], [
    'cart_count' => mall_cart_count($member_id, $guest_token) + mall_fresh_cart_count($member_id, $guest_token),
])]);
