<?php
/**
 * 장바구니 조회 API
 * GET /api/cart?store_id={store_id}
 * 인증 필요
 */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiError(405, 'Method not allowed');
}

// 인증 확인
$auth = requireAuth();
$user_id = $auth['user_id'];

if (!isset($_GET['store_id'])) {
    apiError(400, 'store_id is required');
}

$store_id = intval($_GET['store_id']);

try {
    $pdo = getApiDbConnection();

    $sql = "
        SELECT
            sc.id,
            sc.product_id,
            sc.quantity,
            sc.added_at,
            p.name as product_name,
            p.barcode,
            p.sku,
            p.image_url,
            i.selling_price,
            i.quantity as stock_quantity,
            (sc.quantity * i.selling_price) as subtotal
        FROM shopping_cart sc
        INNER JOIN products p ON sc.product_id = p.id
        INNER JOIN inventory i ON p.id = i.product_id AND i.store_id = sc.store_id
        WHERE sc.user_id = ? AND sc.store_id = ?
        ORDER BY sc.added_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id, $store_id]);
    $cart_items = $stmt->fetchAll();

    // 타입 변환 및 총액 계산
    $total_amount = 0;
    foreach ($cart_items as &$item) {
        $item['quantity'] = intval($item['quantity']);
        $item['selling_price'] = floatval($item['selling_price']);
        $item['stock_quantity'] = intval($item['stock_quantity']);
        $item['subtotal'] = floatval($item['subtotal']);
        $total_amount += $item['subtotal'];
    }

    apiSuccess([
        'items' => $cart_items,
        'total_items' => count($cart_items),
        'total_amount' => $total_amount
    ]);

} catch (PDOException $e) {
    error_log("Cart fetch error: " . $e->getMessage());
    apiError(500, 'Failed to fetch cart');
}
?>
