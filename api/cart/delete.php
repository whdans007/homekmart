<?php
/**
 * 장바구니 아이템 삭제 API
 * DELETE /api/cart/delete.php
 * 테스트용 - 인증 없음
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

// OPTIONS 요청 처리
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/db_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => ['message' => 'Method not allowed']], JSON_UNESCAPED_UNICODE);
    exit();
}

// 요청 본문 파싱
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// cart_item_id 필수
if (!isset($data['cart_item_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => ['message' => 'cart_item_id is required']
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$cart_item_id = intval($data['cart_item_id']);

try {
    $conn = get_db_connection();

    // 장바구니 아이템 존재 확인
    $check_sql = "SELECT id FROM shopping_cart WHERE id = $cart_item_id";
    $check_result = $conn->query($check_sql);

    if ($check_result->num_rows === 0) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => ['message' => 'Cart item not found']
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // 삭제
    $delete_sql = "DELETE FROM shopping_cart WHERE id = $cart_item_id";
    $conn->query($delete_sql);

    echo json_encode([
        'success' => true,
        'message' => 'Cart item deleted'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    error_log("Cart delete error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'message' => 'Failed to delete cart item',
            'details' => $e->getMessage()
        ]
    ], JSON_UNESCAPED_UNICODE);
}
?>
