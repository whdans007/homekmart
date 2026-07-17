<?php
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

// 본인 점포가 발신/수신 당사자인 이동만 조회 가능
$stmt = $conn->prepare(
    "SELECT st.id, st.transfer_date, st.total_amount, st.final_amount, st.status, st.notes,
            st.from_store_id, st.to_store_id,
            fs.name AS from_store_name, ts.name AS to_store_name,
            u.full_name AS user_name
     FROM store_transfers st
     LEFT JOIN stores fs ON st.from_store_id = fs.id
     LEFT JOIN stores ts ON st.to_store_id = ts.id
     LEFT JOIN users u ON st.user_id = u.id
     WHERE st.id = ? AND (st.from_store_id = ? OR st.to_store_id = ?)"
);
$stmt->bind_param('iii', $id, $store_id, $store_id);
$stmt->execute();
$transfer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$transfer) {
    $conn->close();
    echo json_encode(['success' => false, 'error' => 'Transfer not found']); exit;
}

$items_stmt = $conn->prepare(
    "SELECT sti.product_id, sti.quantity, sti.unit_cost_price, sti.total_price, sti.remarks,
            p.sku, p.name_ko, p.name_en, COALESCE(p.pieces_per_box, 1) AS pieces_per_box
     FROM store_transfer_items sti
     LEFT JOIN products p ON sti.product_id = p.id
     WHERE sti.transfer_id = ?
     ORDER BY p.name_en, p.name_ko"
);
$items_stmt->bind_param('i', $id);
$items_stmt->execute();
$items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$items_stmt->close();
$conn->close();

echo json_encode(['success' => true, 'transfer' => $transfer, 'items' => $items], JSON_UNESCAPED_UNICODE);
