<?php
/**
 * 장바구니 항목 삭제 API
 * DELETE /api/cart?id={cart_id}
 * 인증 필요
 */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    apiError(405, 'Method not allowed');
}

// 인증 확인
$auth = requireAuth();
$user_id = $auth['user_id'];

if (!isset($_GET['id'])) {
    apiError(400, 'Cart item ID is required');
}

$cart_id = intval($_GET['id']);

try {
    $pdo = getApiDbConnection();

    // 장바구니 항목 소유권 확인
    $check_sql = "SELECT id FROM shopping_cart WHERE id = ? AND user_id = ?";
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute([$cart_id, $user_id]);
    $cart_item = $check_stmt->fetch();

    if (!$cart_item) {
        apiError(404, 'Cart item not found or access denied');
    }

    // 삭제 (실제 삭제)
    $delete_sql = "DELETE FROM shopping_cart WHERE id = ?";
    $delete_stmt = $pdo->prepare($delete_sql);
    $delete_stmt->execute([$cart_id]);

    apiSuccess(['cart_id' => $cart_id], 'Cart item deleted');

} catch (PDOException $e) {
    error_log("Cart delete error: " . $e->getMessage());
    apiError(500, 'Failed to delete cart item');
}
?>
