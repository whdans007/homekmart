<?php
// Add single DK or WS line item and update sales_daily aggregate
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$store_id  = get_office_store_id();
$sale_date = post_date('sale_date');
$item_type = in_array($_POST['item_type'] ?? '', ['delivery_k','whole_sale']) ? $_POST['item_type'] : null;
$desc      = post_str('description');
$amount    = post_float('amount');

if (!$sale_date || !$item_type || $amount <= 0) {
    echo json_encode(['success'=>false,'error'=>'Invalid parameters']);
    exit;
}

$conn = get_db_connection();

// Insert item
$ins = $conn->prepare(
    "INSERT INTO sales_daily_items (store_id, sale_date, item_type, description, amount) VALUES (?,?,?,?,?)"
);
$ins->bind_param('isssd', $store_id, $sale_date, $item_type, $desc, $amount);
$ins->execute();
$new_id = $conn->insert_id;
$ins->close();

// Recalculate aggregate total for this date
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

echo json_encode(['success'=>true, 'id'=>$new_id, 'date_total'=>$total]);
