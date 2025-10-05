<?php
/**
 * 장바구니 수량 변경 API
 * PUT /api/cart?id={cart_id}
 * 인증 필요
 */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    apiError(405, 'Method not allowed');
}

// 인증 확인
$auth = requireAuth();
$user_id = $auth['user_id'];

if (!isset($_GET['id'])) {
    apiError(400, 'Cart item ID is required');
}

$cart_id = intval($_GET['id']);
$data = getRequestBody();

validateRequired($data, ['quantity']);

$quantity = intval($data['quantity']);

if ($quantity <= 0) {
    apiError(400, 'Quantity must be greater than 0');
}

try {
    $pdo = getApiDbConnection();

    // 장바구니 항목 소유권 확인
    $check_sql = "SELECT product_id, store_id FROM shopping_cart WHERE id = ? AND user_id = ?";
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute([$cart_id, $user_id]);
    $cart_item = $check_stmt->fetch();

    if (!$cart_item) {
        apiError(404, 'Cart item not found or access denied');
    }

    // 재고 확인
    $stock_sql = "SELECT quantity FROM inventory WHERE product_id = ? AND store_id = ?";
    $stock_stmt = $pdo->prepare($stock_sql);
    $stock_stmt->execute([$cart_item['product_id'], $cart_item['store_id']]);
    $stock = $stock_stmt->fetch();

    if ($stock['quantity'] < $quantity) {
        apiError(400, 'Insufficient stock. Available: ' . $stock['quantity']);
    }

    // 수량 업데이트
    $update_sql = "UPDATE shopping_cart SET quantity = ?, updated_at = NOW() WHERE id = ?";
    $update_stmt = $pdo->prepare($update_sql);
    $update_stmt->execute([$quantity, $cart_id]);

    apiSuccess(['cart_id' => $cart_id, 'quantity' => $quantity], 'Cart item updated');

} catch (PDOException $e) {
    error_log("Cart update error: " . $e->getMessage());
    apiError(500, 'Failed to update cart item');
}
?>
