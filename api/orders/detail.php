<?php
/**
 * 주문 상세 조회 API
 * GET /api/orders/detail.php?order_id={order_id}&user_id={user_id}
 * 테스트용 - 인증 없음
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/db_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => ['message' => 'Method not allowed']], JSON_UNESCAPED_UNICODE);
    exit();
}

if (!isset($_GET['order_id']) || !isset($_GET['user_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => ['message' => 'order_id and user_id are required']
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$order_id = intval($_GET['order_id']);
$user_id = intval($_GET['user_id']);

try {
    $conn = get_db_connection();

    // 주문 기본 정보 조회
    $order_sql = "
        SELECT
            do.*,
            da.address_name,
            da.house_number,
            da.street,
            da.barangay,
            da.city,
            da.province,
            da.postal_code,
            da.detailed_address,
            da.landmark,
            da.latitude,
            da.longitude,
            da.delivery_notes as address_notes
        FROM delivery_orders do
        LEFT JOIN delivery_addresses da ON do.delivery_address_id = da.id
        WHERE do.id = $order_id AND do.user_id = $user_id
    ";
    $order_result = $conn->query($order_sql);

    if ($order_result->num_rows === 0) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => ['message' => 'Order not found']
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $order = $order_result->fetch_assoc();

    // 주문 상품 조회
    $items_sql = "
        SELECT
            doi.id,
            doi.product_id,
            doi.product_name,
            doi.product_price,
            doi.quantity,
            doi.subtotal,
            p.image_url,
            p.sku
        FROM delivery_order_items doi
        LEFT JOIN products p ON doi.product_id = p.id
        WHERE doi.order_id = $order_id
        ORDER BY doi.id
    ";
    $items_result = $conn->query($items_sql);

    $items = [];
    while ($item = $items_result->fetch_assoc()) {
        $items[] = [
            'id' => intval($item['id']),
            'product_id' => intval($item['product_id']),
            'product_name' => $item['product_name'],
            'product_price' => floatval($item['product_price']),
            'quantity' => intval($item['quantity']),
            'subtotal' => floatval($item['subtotal']),
            'image_url' => $item['image_url'],
            'sku' => $item['sku']
        ];
    }

    // 배송 추적 정보 조회
    $tracking_sql = "
        SELECT
            dt.id,
            dt.status,
            dt.status_message,
            dt.notes,
            dt.timestamp,
            u.username as updated_by_name
        FROM delivery_tracking dt
        LEFT JOIN users u ON dt.updated_by_user_id = u.id
        WHERE dt.order_id = $order_id
        ORDER BY dt.timestamp DESC
    ";
    $tracking_result = $conn->query($tracking_sql);

    $tracking = [];
    while ($track = $tracking_result->fetch_assoc()) {
        $tracking[] = [
            'id' => intval($track['id']),
            'status' => $track['status'],
            'status_message' => $track['status_message'],
            'notes' => $track['notes'],
            'timestamp' => $track['timestamp'],
            'updated_by_name' => $track['updated_by_name']
        ];
    }

    // 응답 구성
    $response = [
        'id' => intval($order['id']),
        'order_number' => $order['order_number'],
        'user_id' => intval($order['user_id']),
        'store_id' => intval($order['store_id']),
        'delivery_address_id' => intval($order['delivery_address_id']),
        'delivery_zone_id' => $order['delivery_zone_id'] ? intval($order['delivery_zone_id']) : null,
        'subtotal' => floatval($order['subtotal']),
        'delivery_fee' => floatval($order['delivery_fee']),
        'discount_amount' => floatval($order['discount_amount']),
        'total_amount' => floatval($order['total_amount']),
        'payment_method' => $order['payment_method'],
        'payment_status' => $order['payment_status'],
        'cod_amount' => $order['cod_amount'] ? floatval($order['cod_amount']) : null,
        'change_amount' => $order['change_amount'] ? floatval($order['change_amount']) : null,
        'order_status' => $order['order_status'],
        'delivery_date' => $order['delivery_date'],
        'delivery_time_slot' => $order['delivery_time_slot'],
        'estimated_delivery_time' => $order['estimated_delivery_time'],
        'actual_delivery_time' => $order['actual_delivery_time'],
        'special_instructions' => $order['special_instructions'],
        'delivery_notes' => $order['delivery_notes'],
        'cancellation_reason' => $order['cancellation_reason'],
        'created_at' => $order['created_at'],
        'updated_at' => $order['updated_at'],
        'delivery_address' => [
            'address_name' => $order['address_name'],
            'house_number' => $order['house_number'],
            'street' => $order['street'],
            'barangay' => $order['barangay'],
            'city' => $order['city'],
            'province' => $order['province'],
            'postal_code' => $order['postal_code'],
            'detailed_address' => $order['detailed_address'],
            'landmark' => $order['landmark'],
            'latitude' => $order['latitude'] ? floatval($order['latitude']) : null,
            'longitude' => $order['longitude'] ? floatval($order['longitude']) : null,
            'delivery_notes' => $order['address_notes']
        ],
        'items' => $items,
        'tracking' => $tracking
    ];

    echo json_encode([
        'success' => true,
        'data' => $response
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    error_log("Order detail error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => ['message' => 'Failed to retrieve order details']
    ], JSON_UNESCAPED_UNICODE);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
