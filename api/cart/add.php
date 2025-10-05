<?php
/**
 * 장바구니 추가 API
 * POST /api/cart/add.php
 * 테스트용 - 인증 없음
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

// OPTIONS 요청 처리
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

// 요청 본문 파싱
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// 필수 필드 검증
if (!isset($data['user_id']) || !isset($data['product_id']) || !isset($data['store_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => ['message' => 'user_id, product_id, store_id are required']
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$user_id = intval($data['user_id']);
$product_id = intval($data['product_id']);
$store_id = intval($data['store_id']);
$quantity = isset($data['quantity']) ? intval($data['quantity']) : 1;

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

    // 상품 존재 및 재고 확인
    $product_check_sql = "
        SELECT p.id, p.name_ko, p.name_en, i.quantity as stock, i.selling_price
        FROM products p
        INNER JOIN inventory i ON p.id = i.product_id
        WHERE p.id = $product_id AND i.store_id = $store_id
    ";
    $product_result = $conn->query($product_check_sql);

    if ($product_result->num_rows === 0) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => ['message' => 'Product not found in this store']
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $product = $product_result->fetch_assoc();

    // 재고 확인
    if ($product['stock'] < $quantity) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => [
                'message' => 'Insufficient stock',
                'available_stock' => intval($product['stock'])
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // 장바구니에 이미 있는지 확인
    $check_sql = "
        SELECT id, quantity
        FROM shopping_cart
        WHERE user_id = $user_id AND product_id = $product_id AND store_id = $store_id
    ";
    $check_result = $conn->query($check_sql);

    if ($check_result->num_rows > 0) {
        // 이미 존재하면 수량 업데이트
        $existing = $check_result->fetch_assoc();
        $new_quantity = $existing['quantity'] + $quantity;

        // 재고 확인
        if ($product['stock'] < $new_quantity) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => [
                    'message' => 'Total quantity exceeds available stock',
                    'available_stock' => intval($product['stock']),
                    'current_cart_quantity' => intval($existing['quantity'])
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }

        $update_sql = "
            UPDATE shopping_cart
            SET quantity = $new_quantity, updated_at = NOW()
            WHERE id = {$existing['id']}
        ";
        $conn->query($update_sql);

        $cart_item_id = $existing['id'];
        $message = 'Cart item quantity updated';
    } else {
        // 새로 추가
        $insert_sql = "
            INSERT INTO shopping_cart (user_id, product_id, store_id, quantity)
            VALUES ($user_id, $product_id, $store_id, $quantity)
        ";
        $conn->query($insert_sql);

        $cart_item_id = $conn->insert_id;
        $message = 'Product added to cart';
    }

    // 추가된/업데이트된 장바구니 아이템 정보 조회
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
        'message' => $message,
        'data' => $item
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    error_log("Cart add error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'message' => 'Failed to add to cart',
            'details' => $e->getMessage()
        ]
    ], JSON_UNESCAPED_UNICODE);
}
?>
