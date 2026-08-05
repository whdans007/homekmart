<?php
// Design Ref: office/sales/transfer.php — CENTER(물류센터) 배송완료 주문의 품목 상세 조회.
// ajax_get_store_transfer.php와 동일한 패턴이나 lc_orders/lc_order_items(물류센터 주문 시스템) 기준.
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$store_id = get_office_store_id();
$id       = (int)($_GET['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Invalid id']); exit;
}

$conn = get_db_connection();

// 본인 점포의 배송완료 주문만 조회 가능
$stmt = $conn->prepare(
    "SELECT o.id, o.delivered_at, o.status
     FROM lc_orders o
     WHERE o.id = ? AND o.store_id = ? AND o.status = 'delivered'"
);
$stmt->bind_param('ii', $id, $store_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    $conn->close();
    echo json_encode(['success' => false, 'error' => 'Order not found']); exit;
}

$items_stmt = $conn->prepare(
    "SELECT oi.quantity, oi.order_unit, oi.unit_price, oi.total_amount,
            p.name_en, p.name_ko
     FROM lc_order_items oi
     JOIN lc_products p ON p.id = oi.product_id
     WHERE oi.order_id = ?
     ORDER BY p.name_en, p.name_ko"
);
$items_stmt->bind_param('i', $id);
$items_stmt->execute();
$items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$items_stmt->close();
$conn->close();

echo json_encode(['success' => true, 'order' => $order, 'items' => $items], JSON_UNESCAPED_UNICODE);
