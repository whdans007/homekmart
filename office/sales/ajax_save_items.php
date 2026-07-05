<?php
// Handles Delivery K and Whole Sale line item saves
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$store_id  = get_office_store_id();
$sale_date = post_date('sale_date');
$item_type = in_array($_POST['item_type'] ?? '', ['delivery_k','whole_sale'])
             ? $_POST['item_type'] : null;

if (!$sale_date || !$item_type) {
    echo json_encode(['success'=>false,'error'=>'Invalid parameters']);
    exit;
}

$amounts = array_map('floatval', (array)($_POST['amount'] ?? []));
$descs   = (array)($_POST['description'] ?? []);
$by      = (int)($_SESSION['user_id'] ?? 0) ?: null;

$items = [];
$total = 0.0;
foreach ($amounts as $i => $amt) {
    if ($amt > 0) {
        $desc = htmlspecialchars(trim($descs[$i] ?? ''), ENT_QUOTES, 'UTF-8');
        $items[] = ['desc' => $desc, 'amount' => $amt];
        $total += $amt;
    }
}

$conn = get_db_connection();

// Replace line items for this date/type
$del = $conn->prepare("DELETE FROM sales_daily_items WHERE store_id=? AND sale_date=? AND item_type=?");
$del->bind_param('iss', $store_id, $sale_date, $item_type);
$del->execute();
$del->close();

if ($items) {
    $ins = $conn->prepare(
        "INSERT INTO sales_daily_items (store_id, sale_date, item_type, description, amount) VALUES (?,?,?,?,?)"
    );
    foreach ($items as $item) {
        $ins->bind_param('isssd', $store_id, $sale_date, $item_type, $item['desc'], $item['amount']);
        $ins->execute();
    }
    $ins->close();
}

// Update aggregated total in sales_daily
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

echo json_encode(['success'=>true, 'total'=>$total, 'count'=>count($items), 'date'=>$sale_date]);
