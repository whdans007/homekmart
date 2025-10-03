<?php
/**
 * 장바구니 추가 API
 * POST /api/cart
 * 인증 필요
 */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError(405, 'Method not allowed');
}

// 인증 확인
$auth = requireAuth();
$user_id = $auth['user_id'];

$data = getRequestBody();

// 필수 필드 검증
validateRequired($data, ['product_id', 'store_id', 'quantity']);

$product_id = intval($data['product_id']);
$store_id = intval($data['store_id']);
$quantity = intval($data['quantity']);

if ($quantity <= 0) {
    apiError(400, 'Quantity must be greater than 0');
}

try {
    $pdo = getApiDbConnection();

    // 재고 확인
    $stock_sql = "SELECT quantity FROM inventory WHERE product_id = ? AND store_id = ?";
    $stock_stmt = $pdo->prepare($stock_sql);
    $stock_stmt->execute([$product_id, $store_id]);
    $stock = $stock_stmt->fetch();

    if (!$stock) {
        apiError(404, 'Product not found in this store');
    }

    if ($stock['quantity'] < $quantity) {
        apiError(400, 'Insufficient stock. Available: ' . $stock['quantity']);
    }

    // 이미 장바구니에 있는지 확인
    $check_sql = "SELECT id, quantity FROM shopping_cart
                  WHERE user_id = ? AND product_id = ? AND store_id = ?";
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute([$user_id, $product_id, $store_id]);
    $existing = $check_stmt->fetch();

    if ($existing) {
        // 기존 항목 수량 업데이트
        $new_quantity = $existing['quantity'] + $quantity;

        if ($stock['quantity'] < $new_quantity) {
            apiError(400, 'Total quantity exceeds stock. Available: ' . $stock['quantity']);
        }

        $update_sql = "UPDATE shopping_cart SET quantity = ?, updated_at = NOW() WHERE id = ?";
        $update_stmt = $pdo->prepare($update_sql);
        $update_stmt->execute([$new_quantity, $existing['id']]);

        $cart_id = $existing['id'];
        $message = 'Cart item quantity updated';
    } else {
        // 새 항목 추가
        $insert_sql = "INSERT INTO shopping_cart (user_id, product_id, store_id, quantity)
                       VALUES (?, ?, ?, ?)";
        $insert_stmt = $pdo->prepare($insert_sql);
        $insert_stmt->execute([$user_id, $product_id, $store_id, $quantity]);

        $cart_id = $pdo->lastInsertId();
        $message = 'Item added to cart';
    }

    // 추가/업데이트된 항목 조회
    $select_sql = "
        SELECT
            sc.id,
            sc.product_id,
            sc.quantity,
            p.name as product_name,
            p.image_url,
            i.selling_price,
            (sc.quantity * i.selling_price) as subtotal
        FROM shopping_cart sc
        INNER JOIN products p ON sc.product_id = p.id
        INNER JOIN inventory i ON p.id = i.product_id AND i.store_id = sc.store_id
        WHERE sc.id = ?
    ";
    $select_stmt = $pdo->prepare($select_sql);
    $select_stmt->execute([$cart_id]);
    $cart_item = $select_stmt->fetch();

    apiSuccess($cart_item, $message);

} catch (PDOException $e) {
    error_log("Cart add error: " . $e->getMessage());
    apiError(500, 'Failed to add item to cart');
}
?>
