<?php
/**
 * 주문 목록 조회 API
 * GET /api/orders?status={status}&page={page}&limit={limit}
 * 인증 필요
 */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiError(405, 'Method not allowed');
}

// 인증 확인
$auth = requireAuth();
$user_id = $auth['user_id'];

// 쿼리 파라미터
$status = $_GET['status'] ?? null;
$pagination = getPaginationParams();

try {
    $pdo = getApiDbConnection();

    // WHERE 조건 구성
    $where_clauses = ["do.user_id = ?"];
    $params = [$user_id];

    if ($status) {
        $valid_statuses = ['pending', 'confirmed', 'preparing', 'out_for_delivery', 'delivered', 'cancelled'];
        if (!in_array($status, $valid_statuses)) {
            apiError(400, 'Invalid status');
        }
        $where_clauses[] = "do.order_status = ?";
        $params[] = $status;
    }

    $where_sql = implode(' AND ', $where_clauses);

    // 전체 개수 조회
    $count_sql = "SELECT COUNT(*) as total FROM delivery_orders do WHERE {$where_sql}";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total = $count_stmt->fetch()['total'];

    // 주문 목록 조회
    $offset = ($pagination['page'] - 1) * $pagination['limit'];
    $list_sql = "
        SELECT
            do.id,
            do.order_number,
            do.subtotal,
            do.delivery_fee,
            do.points_used,
            do.total_amount,
            do.payment_method,
            do.payment_status,
            do.order_status,
            do.delivery_notes,
            do.created_at,
            do.updated_at,
            da.address_name,
            da.city,
            da.province,
            da.barangay,
            COUNT(doi.id) as item_count
        FROM delivery_orders do
        LEFT JOIN delivery_addresses da ON do.delivery_address_id = da.id
        LEFT JOIN delivery_order_items doi ON do.id = doi.order_id
        WHERE {$where_sql}
        GROUP BY do.id
        ORDER BY do.created_at DESC
        LIMIT ? OFFSET ?
    ";

    $params[] = $pagination['limit'];
    $params[] = $offset;

    $list_stmt = $pdo->prepare($list_sql);
    $list_stmt->execute($params);
    $orders = $list_stmt->fetchAll();

    // 숫자 필드 형변환
    foreach ($orders as &$order) {
        $order['id'] = intval($order['id']);
        $order['subtotal'] = floatval($order['subtotal']);
        $order['delivery_fee'] = floatval($order['delivery_fee']);
        $order['points_used'] = intval($order['points_used']);
        $order['total_amount'] = floatval($order['total_amount']);
        $order['item_count'] = intval($order['item_count']);
    }

    $response = paginatedResponse($orders, $total, $pagination['page'], $pagination['limit']);
    apiSuccess($response);

} catch (PDOException $e) {
    error_log("Order list error: " . $e->getMessage());
    apiError(500, 'Failed to retrieve orders');
}
?>
