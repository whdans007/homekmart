<?php
/**
 * 주문 생성 API - 간소화 버전
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

try {
    $conn = get_db_connection();

    // 1. 사용자 점포 확인
    $user_result = $conn->query("SELECT store_id FROM users WHERE id = $user_id");
    if (!$user_result || $user_result->num_rows === 0) {
        throw new Exception('User not found');
    }
    $user = $user_result->fetch_assoc();
    $store_id = $user['store_id'];

    // 2. 장바구니 조회
    $cart_result = $conn->query("
        SELECT sc.product_id, sc.quantity, p.name_en, i.selling_price, i.quantity as stock
        FROM shopping_cart sc
        JOIN products p ON sc.product_id = p.id
        JOIN inventory i ON p.id = i.product_id AND sc.store_id = i.store_id
        WHERE sc.user_id = $user_id AND sc.store_id = $store_id
    ");

    if (!$cart_result || $cart_result->num_rows === 0) {
        throw new Exception('Cart is empty');
    }

    $items = [];
    $subtotal = 0;
    while ($item = $cart_result->fetch_assoc()) {
        if ($item['stock'] < $item['quantity']) {
            throw new Exception("Insufficient stock for {$item['name_en']}");
        }
        $item_total = $item['selling_price'] * $item['quantity'];
        $subtotal += $item_total;
        $items[] = [
            'product_id' => $item['product_id'],
            'name' => $item['name_en'],
            'price' => $item['selling_price'],
            'quantity' => $item['quantity'],
            'subtotal' => $item_total
        ];
    }

    // 3. 배달비 계산
    $delivery_fee = 50;
    if ($subtotal >= 1000) {
        $delivery_fee = 0;
    }
    $total = $subtotal + $delivery_fee;

    // 4. 주문 번호 생성
    $order_number = 'ORD' . date('Ymd') . strtoupper(substr(md5(uniqid()), 0, 6));

    // 트랜잭션 시작
    $conn->begin_transaction();

    // 5. 주문 생성
    $conn->query("
        INSERT INTO delivery_orders (
            order_number, user_id, store_id, delivery_address_id,
            subtotal, delivery_fee, total_amount, payment_method, order_status
        ) VALUES (
            '$order_number', $user_id, $store_id, $delivery_address_id,
            $subtotal, $delivery_fee, $total, '$payment_method', 'pending'
        )
    ");

    $order_id = $conn->insert_id;

    // 6. 주문 아이템 생성
    foreach ($items as $item) {
        $name = $conn->real_escape_string($item['name']);
        $conn->query("
            INSERT INTO delivery_order_items (
                order_id, product_id, product_name, product_price, quantity, subtotal
            ) VALUES (
                $order_id, {$item['product_id']}, '$name', {$item['price']}, {$item['quantity']}, {$item['subtotal']}
            )
        ");
    }

    // 7. 추적 생성
    $conn->query("
        INSERT INTO delivery_tracking (order_id, status, status_message)
        VALUES ($order_id, 'order_placed', 'Order placed successfully')
    ");

    // 8. 장바구니 비우기
    $conn->query("DELETE FROM shopping_cart WHERE user_id = $user_id AND store_id = $store_id");

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Order created successfully',
        'data' => [
            'order_id' => $order_id,
            'order_number' => $order_number,
            'subtotal' => $subtotal,
            'delivery_fee' => $delivery_fee,
            'total_amount' => $total
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
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
