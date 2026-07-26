<?php
require_once __DIR__ . '/../lib/auth.php';
header('Content-Type: application/json; charset=utf-8');

// Design Ref: outbound-price-edit - Outbound History 단가 인라인 수정
kw_require_staff();

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['kw_csrf'] ?? '', $token)) {
    echo json_encode(['success' => false, 'message' => 'Security error']);
    exit;
}

$item_id    = (int)($_POST['item_id'] ?? 0);
$unit_price = $_POST['unit_price'] ?? null;

if (!$item_id || !is_numeric($unit_price) || (float)$unit_price < 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}
$unit_price = round((float)$unit_price, 2);

try {
    $conn = get_lc_db();

    $st = $conn->prepare(
        "SELECT oi.id, oi.order_id, o.status
         FROM kw_order_items oi
         JOIN kw_orders o ON oi.order_id = o.id
         WHERE oi.id = ?"
    );
    $st->bind_param('i', $item_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row || !in_array($row['status'], ['shipped', 'delivered'], true)) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }

    $st = $conn->prepare("UPDATE kw_order_items SET unit_price = ? WHERE id = ?");
    $st->bind_param('di', $unit_price, $item_id);
    $st->execute();
    $st->close();

    // 단가 수정에 따른 주문 총액(kw_orders.total_amount) 재계산
    $order_id = (int)$row['order_id'];
    $conn->query(
        "UPDATE kw_orders SET total_amount =
         (SELECT COALESCE(SUM(total_amount),0) FROM kw_order_items WHERE order_id = $order_id)
         WHERE id = $order_id"
    );

    $st = $conn->prepare("SELECT quantity, unit_price, total_amount FROM kw_order_items WHERE id = ?");
    $st->bind_param('i', $item_id);
    $st->execute();
    $updated = $st->get_result()->fetch_assoc();
    $st->close();
    $conn->close();

    echo json_encode([
        'success'          => true,
        'unit_price'       => (float)$updated['unit_price'],
        'unit_price_display' => number_format($updated['unit_price'], 2),
        'subtotal_display' => number_format($updated['total_amount'], 2),
    ]);
} catch (Exception $e) {
    if (isset($conn)) { $conn->close(); }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
