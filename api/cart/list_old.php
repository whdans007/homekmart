<?php
/**
 * 장바구니 목록 조회 API
 * GET /api/cart/list.php?user_id={user_id}
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

// OPTIONS 요청 처리
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => ['message' => 'Method not allowed']], JSON_UNESCAPED_UNICODE);
    exit();
}

// user_id 필수
if (!isset($_GET['user_id']) || empty($_GET['user_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => ['message' => 'user_id is required']
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$user_id = intval($_GET['user_id']);

try {
    $conn = get_db_connection();

    // 장바구니 목록 조회
    $sql = "
        SELECT
            sc.id,
            sc.user_id,
            sc.product_id,
            sc.store_id,
            sc.quantity,
            p.name_ko,
            p.name_en,
            p.sku,
            p.description,
            p.category_id,
            c.name as category_name,
            p.brand_id,
            b.name_ko as brand_name,
            p.image_url,
            i.selling_price,
            i.cost_price,
            i.quantity as stock,
            p.is_active,
            sc.added_at,
            sc.updated_at
        FROM shopping_cart sc
        INNER JOIN products p ON sc.product_id = p.id
        INNER JOIN inventory i ON p.id = i.product_id AND sc.store_id = i.store_id
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        WHERE sc.user_id = $user_id
        ORDER BY sc.updated_at DESC
    ";

    $result = $conn->query($sql);

    $items = [];
    $total_items = 0;
    $subtotal = 0;

    while ($row = $result->fetch_assoc()) {
        // 타입 변환
        $row['id'] = intval($row['id']);
        $row['user_id'] = intval($row['user_id']);
        $row['product_id'] = intval($row['product_id']);
        $row['store_id'] = intval($row['store_id']);
        $row['quantity'] = intval($row['quantity']);
        $row['selling_price'] = floatval($row['selling_price']);
        $row['cost_price'] = floatval($row['cost_price']);
        $row['stock'] = intval($row['stock']);
        $row['is_active'] = (bool) intval($row['is_active']);

        // 아이템 합계
        $item_total = $row['selling_price'] * $row['quantity'];
        $row['item_total'] = $item_total;

        $items[] = $row;
        $total_items += $row['quantity'];
        $subtotal += $item_total;
    }

    // 배달비 (예시: 50 페소)
    $delivery_fee = 50.00;
    $free_delivery_threshold = 1000.00;

    // 무료 배달 조건
    if ($subtotal >= $free_delivery_threshold) {
        $delivery_fee = 0.00;
    }

    $total = $subtotal + $delivery_fee;

    echo json_encode([
        'success' => true,
        'data' => [
            'items' => $items,
            'summary' => [
                'total_items' => $total_items,
                'item_count' => count($items),
                'subtotal' => $subtotal,
                'delivery_fee' => $delivery_fee,
                'total' => $total,
                'free_delivery_threshold' => $free_delivery_threshold,
                'is_free_delivery' => $subtotal >= $free_delivery_threshold
            ]
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    error_log("Cart list error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'message' => 'Failed to fetch cart',
            'details' => $e->getMessage()
        ]
    ], JSON_UNESCAPED_UNICODE);
}
?>
