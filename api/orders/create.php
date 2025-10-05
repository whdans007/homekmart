<?php
/**
 * 주문 생성 API
 * POST /api/orders/create.php
 * 테스트용 - 인증 없음
 *
 * 요청 본문:
 * {
 *   "user_id": 1,
 *   "delivery_address_id": 1,
 *   "payment_method": "cod",
 *   "delivery_notes": "문 앞에 놔주세요"
 * }
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/db_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => ['message' => 'Method not allowed']], JSON_UNESCAPED_UNICODE);
    exit();
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!isset($data['user_id']) || !isset($data['delivery_address_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => ['message' => 'user_id and delivery_address_id are required']
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$user_id = intval($data['user_id']);

$delivery_address_id = intval($data['delivery_address_id']);
$payment_method = $data['payment_method'] ?? 'cod';
$special_instructions = $data['special_instructions'] ?? null;
$cod_amount = isset($data['cod_amount']) ? floatval($data['cod_amount']) : null;

try {
    $conn = get_db_connection();
    $conn->autocommit(false);

    // 1. 사용자 점포 확인
    $user_check_sql = "SELECT store_id FROM users WHERE id = $user_id";
    $user_result = $conn->query($user_check_sql);

    if ($user_result->num_rows === 0) {
        throw new Exception('User not found');
    }

    $user = $user_result->fetch_assoc();
    $store_id = $user['store_id'];

    // 2. 배달 주소 확인
    $address_sql = "
        SELECT * FROM delivery_addresses
        WHERE id = $delivery_address_id AND user_id = $user_id AND is_active = 1
    ";
    $address_result = $conn->query($address_sql);

    if ($address_result->num_rows === 0) {
        throw new Exception('Invalid or inactive delivery address');
    }

    $address = $address_result->fetch_assoc();

    // 3. 배달 지역 및 배달비 확인
    $delivery_zone_id = null;
    $delivery_fee = 50.00;
    $free_delivery_threshold = 1000.00;

    $zone_sql = "
        SELECT id, delivery_fee, free_delivery_threshold
        FROM delivery_zones
        WHERE city = '{$address['city']}'
        AND province = '{$address['province']}'
        AND is_active = 1
        LIMIT 1
    ";
    $zone_result = $conn->query($zone_sql);

    if ($zone_result->num_rows > 0) {
        $zone = $zone_result->fetch_assoc();
        $delivery_zone_id = $zone['id'];
        $delivery_fee = floatval($zone['delivery_fee']);
        if ($zone['free_delivery_threshold']) {
            $free_delivery_threshold = floatval($zone['free_delivery_threshold']);
        }
    }

    // 4. 장바구니 아이템 조회
    $cart_sql = "
        SELECT
            sc.id as cart_id,
            sc.product_id,
            sc.quantity,
            p.name_en,
            i.selling_price,
            i.quantity as stock
        FROM shopping_cart sc
        INNER JOIN products p ON sc.product_id = p.id
        INNER JOIN inventory i ON p.id = i.product_id AND sc.store_id = i.store_id
        WHERE sc.user_id = $user_id AND sc.store_id = $store_id
    ";
    $cart_result = $conn->query($cart_sql);

    if ($cart_result->num_rows === 0) {
        throw new Exception('Cart is empty');
    }

    $cart_items = [];
    $subtotal = 0.00;

    while ($item = $cart_result->fetch_assoc()) {
        if ($item['stock'] < $item['quantity']) {
            throw new Exception("Insufficient stock for {$item['name_en']}");
        }

        $item_subtotal = floatval($item['selling_price']) * intval($item['quantity']);
        $subtotal += $item_subtotal;

        $cart_items[] = [
            'product_id' => $item['product_id'],
            'product_name' => $item['name_en'],
            'product_price' => floatval($item['selling_price']),
            'quantity' => intval($item['quantity']),
            'subtotal' => $item_subtotal
        ];
    }

    // 5. 배달비 계산
    if ($subtotal >= $free_delivery_threshold) {
        $delivery_fee = 0.00;
    }

    $total_amount = $subtotal + $delivery_fee;

    // 6. 주문 번호 생성
    $order_number = 'ORD' . date('Ymd') . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));

    // 7. 예상 배달 시간 (+60분)
    $estimated_delivery_time = date('Y-m-d H:i:s', strtotime('+60 minutes'));

    // 8. 주문 생성
    $insert_order_sql = "
        INSERT INTO delivery_orders (
            order_number, user_id, store_id, delivery_address_id, delivery_zone_id,
            subtotal, delivery_fee, discount_amount, total_amount,
            payment_method, payment_status, cod_amount,
            order_status, estimated_delivery_time, special_instructions
        ) VALUES (
            '$order_number', $user_id, $store_id, $delivery_address_id, " . ($delivery_zone_id ? $delivery_zone_id : 'NULL') . ",
            $subtotal, $delivery_fee, 0.00, $total_amount,
            '$payment_method', 'pending', " . ($cod_amount ? $cod_amount : 'NULL') . ",
            'pending', '$estimated_delivery_time', " . ($special_instructions ? "'" . $conn->real_escape_string($special_instructions) . "'" : 'NULL') . "
        )
    ";

    if (!$conn->query($insert_order_sql)) {
        throw new Exception('Failed to create order: ' . $conn->error);
    }

    $order_id = $conn->insert_id;

    // 9. 주문 아이템 생성
    foreach ($cart_items as $item) {
        $insert_item_sql = "
            INSERT INTO delivery_order_items (
                order_id, product_id, product_name, product_price, quantity, subtotal
            ) VALUES (
                $order_id, {$item['product_id']}, '" . $conn->real_escape_string($item['product_name']) . "',
                {$item['product_price']}, {$item['quantity']}, {$item['subtotal']}
            )
        ";

        if (!$conn->query($insert_item_sql)) {
            throw new Exception('Failed to create order item: ' . $conn->error);
        }

        // 재고 차감
        $update_stock_sql = "
            UPDATE inventory
            SET quantity = quantity - {$item['quantity']}
            WHERE product_id = {$item['product_id']} AND store_id = $store_id
        ";
        $conn->query($update_stock_sql);
    }

    // 10. 배달 추적 레코드 생성
    $insert_tracking_sql = "
        INSERT INTO delivery_tracking (
            order_id, status, status_message, updated_by_user_id
        ) VALUES (
            $order_id, 'order_placed', 'Order placed successfully', $user_id
        )
    ";
    $conn->query($insert_tracking_sql);

    // 11. 장바구니 비우기
    $delete_cart_sql = "DELETE FROM shopping_cart WHERE user_id = $user_id AND store_id = $store_id";
    $conn->query($delete_cart_sql);

    $conn->commit();

    // 12. 생성된 주문 반환
    echo json_encode([
        'success' => true,
        'message' => 'Order created successfully',
        'data' => [
            'order_id' => intval($order_id),
            'order_number' => $order_number,
            'subtotal' => floatval($subtotal),
            'delivery_fee' => floatval($delivery_fee),
            'total_amount' => floatval($total_amount),
            'payment_method' => $payment_method,
            'order_status' => 'pending',
            'estimated_delivery_time' => $estimated_delivery_time
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    error_log("Order creation error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => ['message' => $e->getMessage()]
    ], JSON_UNESCAPED_UNICODE);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
