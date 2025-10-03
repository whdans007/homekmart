<?php
/**
 * 주문 생성 API
 * POST /api/orders
 * 인증 필요
 *
 * 요청 본문:
 * {
 *   "delivery_address_id": 1,
 *   "payment_method": "cod",
 *   "delivery_notes": "문 앞에 놔주세요",
 *   "use_points": 0
 * }
 */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError(405, 'Method not allowed');
}

// 인증 확인
$auth = requireAuth();
$user_id = $auth['user_id'];

$data = getRequestBody();
validateRequired($data, ['delivery_address_id', 'payment_method']);

$delivery_address_id = intval($data['delivery_address_id']);
$payment_method = $data['payment_method'];
$delivery_notes = $data['delivery_notes'] ?? '';
$use_points = isset($data['use_points']) ? intval($data['use_points']) : 0;

// 결제 방법 검증
$valid_payment_methods = ['cod', 'gcash', 'paymaya'];
if (!in_array($payment_method, $valid_payment_methods)) {
    apiError(400, 'Invalid payment method. Must be: cod, gcash, or paymaya');
}

try {
    $pdo = getApiDbConnection();
    $pdo->beginTransaction();

    // 1. 장바구니 조회
    $cart_sql = "
        SELECT
            sc.id as cart_id,
            sc.product_id,
            sc.quantity,
            sc.store_id,
            p.name as product_name,
            p.barcode,
            i.price as unit_price,
            i.quantity as stock_quantity
        FROM shopping_cart sc
        INNER JOIN products p ON sc.product_id = p.id
        INNER JOIN inventory i ON sc.product_id = i.product_id AND sc.store_id = i.store_id
        WHERE sc.user_id = ?
    ";
    $cart_stmt = $pdo->prepare($cart_sql);
    $cart_stmt->execute([$user_id]);
    $cart_items = $cart_stmt->fetchAll();

    if (empty($cart_items)) {
        $pdo->rollBack();
        apiError(400, 'Cart is empty');
    }

    // 2. 재고 검증
    foreach ($cart_items as $item) {
        if ($item['stock_quantity'] < $item['quantity']) {
            $pdo->rollBack();
            apiError(400, "Insufficient stock for {$item['product_name']}. Available: {$item['stock_quantity']}");
        }
    }

    // 3. 배송지 확인
    $address_sql = "SELECT * FROM delivery_addresses WHERE id = ? AND user_id = ? AND is_active = TRUE";
    $address_stmt = $pdo->prepare($address_sql);
    $address_stmt->execute([$delivery_address_id, $user_id]);
    $address = $address_stmt->fetch();

    if (!$address) {
        $pdo->rollBack();
        apiError(404, 'Delivery address not found or inactive');
    }

    // 4. 배송비 계산 (구역 기반)
    $zone_sql = "
        SELECT delivery_fee, min_order_amount, free_delivery_threshold
        FROM delivery_zones
        WHERE city = ? AND province = ? AND is_active = TRUE
        LIMIT 1
    ";
    $zone_stmt = $pdo->prepare($zone_sql);
    $zone_stmt->execute([$address['city'], $address['province']]);
    $zone = $zone_stmt->fetch();

    $default_delivery_fee = 50.00;
    $delivery_fee = $zone ? floatval($zone['delivery_fee']) : $default_delivery_fee;
    $min_order_amount = $zone ? floatval($zone['min_order_amount']) : 0;
    $free_delivery_threshold = $zone ? floatval($zone['free_delivery_threshold']) : 1000.00;

    // 5. 주문 금액 계산
    $subtotal = 0;
    foreach ($cart_items as $item) {
        $subtotal += $item['unit_price'] * $item['quantity'];
    }

    // 최소 주문 금액 확인
    if ($subtotal < $min_order_amount) {
        $pdo->rollBack();
        apiError(400, "Minimum order amount is PHP {$min_order_amount}");
    }

    // 무료 배송 조건 확인
    if ($subtotal >= $free_delivery_threshold) {
        $delivery_fee = 0;
    }

    // 포인트 사용 검증 및 적용
    $points_used = 0;
    if ($use_points > 0) {
        // 사용자 포인트 조회
        $user_sql = "SELECT points FROM users WHERE id = ?";
        $user_stmt = $pdo->prepare($user_sql);
        $user_stmt->execute([$user_id]);
        $user = $user_stmt->fetch();

        if (!$user || $user['points'] < $use_points) {
            $pdo->rollBack();
            apiError(400, 'Insufficient points');
        }

        $points_used = $use_points;
    }

    $total_amount = $subtotal + $delivery_fee - $points_used;

    // 6. 주문 번호 생성 (DO-YYYYMMDD-XXXXX)
    $order_number = 'DO-' . date('Ymd') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);

    // 7. 주문 생성
    $store_id = $cart_items[0]['store_id']; // 첫 번째 상품의 점포

    $order_sql = "
        INSERT INTO delivery_orders (
            order_number, user_id, store_id, delivery_address_id,
            subtotal, delivery_fee, points_used, total_amount,
            payment_method, payment_status, order_status,
            delivery_notes, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', ?, NOW(), NOW())
    ";
    $order_stmt = $pdo->prepare($order_sql);
    $order_stmt->execute([
        $order_number, $user_id, $store_id, $delivery_address_id,
        $subtotal, $delivery_fee, $points_used, $total_amount,
        $payment_method, $delivery_notes
    ]);

    $order_id = $pdo->lastInsertId();

    // 8. 주문 상품 생성 및 재고 차감
    $order_item_sql = "
        INSERT INTO delivery_order_items (
            order_id, product_id, product_name, barcode, quantity, unit_price, subtotal
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ";
    $order_item_stmt = $pdo->prepare($order_item_sql);

    $inventory_update_sql = "
        UPDATE inventory
        SET quantity = quantity - ?
        WHERE product_id = ? AND store_id = ?
    ";
    $inventory_update_stmt = $pdo->prepare($inventory_update_sql);

    foreach ($cart_items as $item) {
        $item_subtotal = $item['unit_price'] * $item['quantity'];

        // 주문 상품 추가
        $order_item_stmt->execute([
            $order_id,
            $item['product_id'],
            $item['product_name'],
            $item['barcode'],
            $item['quantity'],
            $item['unit_price'],
            $item_subtotal
        ]);

        // 재고 차감
        $inventory_update_stmt->execute([
            $item['quantity'],
            $item['product_id'],
            $item['store_id']
        ]);
    }

    // 9. 포인트 차감
    if ($points_used > 0) {
        $points_update_sql = "UPDATE users SET points = points - ? WHERE id = ?";
        $points_update_stmt = $pdo->prepare($points_update_sql);
        $points_update_stmt->execute([$points_used, $user_id]);
    }

    // 10. 배송 추적 초기 상태 생성
    $tracking_sql = "
        INSERT INTO delivery_tracking (
            order_id, status, notes, created_at
        ) VALUES (?, 'pending', 'Order placed', NOW())
    ";
    $tracking_stmt = $pdo->prepare($tracking_sql);
    $tracking_stmt->execute([$order_id]);

    // 11. 장바구니 비우기
    $clear_cart_sql = "DELETE FROM shopping_cart WHERE user_id = ?";
    $clear_cart_stmt = $pdo->prepare($clear_cart_sql);
    $clear_cart_stmt->execute([$user_id]);

    $pdo->commit();

    // 12. 생성된 주문 정보 반환
    $result = [
        'order_id' => $order_id,
        'order_number' => $order_number,
        'subtotal' => $subtotal,
        'delivery_fee' => $delivery_fee,
        'points_used' => $points_used,
        'total_amount' => $total_amount,
        'payment_method' => $payment_method,
        'order_status' => 'pending'
    ];

    apiSuccess($result, 'Order created successfully');

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Order creation error: " . $e->getMessage());
    apiError(500, 'Failed to create order');
}
?>
