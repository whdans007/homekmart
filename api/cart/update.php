<?php
/**
 * 장바구니 수량 업데이트 API
 * PUT /api/cart/update.php
 * 테스트용 - 인증 없음
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

// OPTIONS 요청 처리
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/db_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => ['message' => 'Method not allowed']], JSON_UNESCAPED_UNICODE);
    exit();
}

// 요청 본문 파싱
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// 필수 필드 검증
if (!isset($data['cart_item_id']) || !isset($data['quantity'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => ['message' => 'cart_item_id and quantity are required']
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$cart_item_id = intval($data['cart_item_id']);
$quantity = intval($data['quantity']);

// 수량 검증
if ($quantity < 1) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => ['message' => 'Quantity must be at least 1']
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    $conn = get_db_connection();

    // 장바구니 아이템 확인
    $check_sql = "
        SELECT sc.id, sc.product_id, sc.store_id, i.quantity as stock
        FROM shopping_cart sc
        INNER JOIN inventory i ON sc.product_id = i.product_id AND sc.store_id = i.store_id
        WHERE sc.id = $cart_item_id
    ";
    $check_result = $conn->query($check_sql);

    if ($check_result->num_rows === 0) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => ['message' => 'Cart item not found']
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $cart_item = $check_result->fetch_assoc();

    // 재고 확인
    if ($cart_item['stock'] < $quantity) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => [
                'message' => 'Quantity exceeds available stock',
                'available_stock' => intval($cart_item['stock'])
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // 수량 업데이트
    $update_sql = "
        UPDATE shopping_cart
        SET quantity = $quantity, updated_at = NOW()
        WHERE id = $cart_item_id
    ";
    $conn->query($update_sql);

    // 업데이트된 아이템 정보 조회
    $item_sql = "
        SELECT
            sc.id,
            sc.user_id,
            sc.product_id,
            sc.store_id,
            sc.quantity,
            p.name_ko,
            p.name_en,
            p.sku,
            p.image_url,
            i.selling_price,
            i.quantity as stock,
            sc.added_at,
            sc.updated_at
        FROM shopping_cart sc
        INNER JOIN products p ON sc.product_id = p.id
        INNER JOIN inventory i ON p.id = i.product_id AND sc.store_id = i.store_id
        WHERE sc.id = $cart_item_id
    ";
    $item_result = $conn->query($item_sql);
    $item = $item_result->fetch_assoc();

    // 타입 변환
    $item['id'] = intval($item['id']);
    $item['user_id'] = intval($item['user_id']);
    $item['product_id'] = intval($item['product_id']);
    $item['store_id'] = intval($item['store_id']);
    $item['quantity'] = intval($item['quantity']);
    $item['selling_price'] = floatval($item['selling_price']);
    $item['stock'] = intval($item['stock']);

    echo json_encode([
        'success' => true,
        'message' => 'Cart item updated',
        'data' => $item
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    error_log("Cart update error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'message' => 'Failed to update cart',
            'details' => $e->getMessage()
        ]
    ], JSON_UNESCAPED_UNICODE);
}
?>
