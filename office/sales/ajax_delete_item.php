<?php
// Delete single DK or WS item and update sales_daily aggregate
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$store_id = get_office_store_id();
$id       = (int)($_POST['id'] ?? 0);

if ($id <= 0) { echo json_encode(['success'=>false,'error'=>'Invalid ID']); exit; }

$conn = get_db_connection();

// Get item info before delete
$sel = $conn->prepare("SELECT sale_date, item_type FROM sales_daily_items WHERE id=? AND store_id=?");
$sel->bind_param('ii', $id, $store_id);
$sel->execute();
$item = $sel->get_result()->fetch_assoc();
$sel->close();

if (!$item) { echo json_encode(['success'=>false,'error'=>'Item not found']); $conn->close(); exit; }

$sale_date = $item['sale_date'];
$item_type = $item['item_type'];

// Delete
$del = $conn->prepare("DELETE FROM sales_daily_items WHERE id=? AND store_id=?");
$del->bind_param('ii', $id, $store_id);
$del->execute();
$del->close();

// Recalculate aggregate
$sum = $conn->prepare(
    "SELECT COALESCE(SUM(amount),0) AS total FROM sales_daily_items
     WHERE store_id=? AND sale_date=? AND item_type=?"
);
$sum->bind_param('iss', $store_id, $sale_date, $item_type);
$sum->execute();
$total = (float)$sum->get_result()->fetch_assoc()['total'];
$sum->close();

// Update sales_daily
if ($item_type === 'delivery_k') {
    $upd = $conn->prepare(
        "INSERT INTO sales_daily (store_id, sale_date, delivery_k) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE delivery_k=VALUES(delivery_k), updated_at=NOW()"
    );
} else {
    $upd = $conn->prepare(
        "INSERT INTO sales_daily (store_id, sale_date, whole_sale) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE whole_sale=VALUES(whole_sale), updated_at=NOW()"
    );
}
$upd->bind_param('isd', $store_id, $sale_date, $total);
$upd->execute();
$upd->close();
$conn->close();

echo json_encode(['success'=>true, 'date_total'=>$total]);
