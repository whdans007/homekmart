<?php
/**
 * 주문 생성 디버그 - 직접 실행
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/db_config.php';

$user_id = 13;
$delivery_address_id = 1;

try {
    $conn = get_db_connection();

    // Step 1: 사용자 확인
    $user_result = $conn->query("SELECT id, username, store_id FROM users WHERE id = $user_id");
    $user = $user_result ? $user_result->fetch_assoc() : null;

    // Step 2: 장바구니 확인
    $cart_result = $conn->query("
        SELECT sc.product_id, sc.quantity, p.name_en, i.selling_price, i.quantity as stock
        FROM shopping_cart sc
        JOIN products p ON sc.product_id = p.id
        JOIN inventory i ON p.id = i.product_id AND sc.store_id = i.store_id
        WHERE sc.user_id = $user_id
    ");

    $cart_items = [];
    if ($cart_result) {
        while ($row = $cart_result->fetch_assoc()) {
            $cart_items[] = $row;
        }
    }

    // Step 3: 배달 주소 확인
    $address_result = $conn->query("
        SELECT * FROM delivery_addresses
        WHERE id = $delivery_address_id AND user_id = $user_id
    ");
    $address = $address_result ? $address_result->fetch_assoc() : null;

    echo json_encode([
        'success' => true,
        'debug' => [
            'user' => $user,
            'cart_items_count' => count($cart_items),
            'cart_items' => $cart_items,
            'delivery_address' => $address,
            'can_create_order' => ($user && count($cart_items) > 0 && $address)
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>
