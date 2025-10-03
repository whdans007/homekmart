<?php
/**
 * 주문 상세 조회 API
 * GET /api/orders/{id}
 * 인증 필요
 */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiError(405, 'Method not allowed');
}

// 인증 확인
$auth = requireAuth();
$user_id = $auth['user_id'];

if (!isset($_GET['id'])) {
    apiError(400, 'Order ID is required');
}

$order_id = intval($_GET['id']);

try {
    $pdo = getApiDbConnection();

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
            da.longitude
        FROM delivery_orders do
        LEFT JOIN delivery_addresses da ON do.delivery_address_id = da.id
        WHERE do.id = ? AND do.user_id = ?
    ";
    $order_stmt = $pdo->prepare($order_sql);
    $order_stmt->execute([$order_id, $user_id]);
    $order = $order_stmt->fetch();

    if (!$order) {
        apiError(404, 'Order not found or access denied');
    }

    // 숫자 필드 형변환
    $order['id'] = intval($order['id']);
    $order['user_id'] = intval($order['user_id']);
    $order['store_id'] = intval($order['store_id']);
    $order['delivery_address_id'] = intval($order['delivery_address_id']);
    $order['subtotal'] = floatval($order['subtotal']);
    $order['delivery_fee'] = floatval($order['delivery_fee']);
    $order['points_used'] = intval($order['points_used']);
    $order['total_amount'] = floatval($order['total_amount']);
    if ($order['latitude']) $order['latitude'] = floatval($order['latitude']);
    if ($order['longitude']) $order['longitude'] = floatval($order['longitude']);

    // 주문 상품 조회
    $items_sql = "
        SELECT
            id,
            product_id,
            product_name,
            barcode,
            quantity,
            unit_price,
            subtotal
        FROM delivery_order_items
        WHERE order_id = ?
        ORDER BY id
    ";
    $items_stmt = $pdo->prepare($items_sql);
    $items_stmt->execute([$order_id]);
    $items = $items_stmt->fetchAll();

    foreach ($items as &$item) {
        $item['id'] = intval($item['id']);
        $item['product_id'] = intval($item['product_id']);
        $item['quantity'] = intval($item['quantity']);
        $item['unit_price'] = floatval($item['unit_price']);
        $item['subtotal'] = floatval($item['subtotal']);
    }

    $order['items'] = $items;

    // 배송 추적 정보 조회
    $tracking_sql = "
        SELECT
            dt.id,
            dt.status,
            dt.notes,
            dt.created_at,
            COALESCE(u.full_name, u.username, u.email) as updated_by_name
        FROM delivery_tracking dt
        LEFT JOIN users u ON dt.updated_by = u.id
        WHERE dt.order_id = ?
        ORDER BY dt.created_at DESC
    ";
    $tracking_stmt = $pdo->prepare($tracking_sql);
    $tracking_stmt->execute([$order_id]);
    $tracking = $tracking_stmt->fetchAll();

    foreach ($tracking as &$track) {
        $track['id'] = intval($track['id']);
    }

    $order['tracking'] = $tracking;

    apiSuccess($order);

} catch (PDOException $e) {
    error_log("Order detail error: " . $e->getMessage());
    apiError(500, 'Failed to retrieve order details');
}
?>
