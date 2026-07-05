<?php
ob_start();
require_once __DIR__ . '/lib/office_helper.php';
require_office_permission();

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

$store_id      = get_office_store_id();
$supplier_name = trim($_GET['supplier_name'] ?? '');
$date_from     = trim($_GET['date_from'] ?? '');
$date_to       = trim($_GET['date_to'] ?? '');

if ($date_from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = '';
if ($date_to   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = '';

$conn   = get_db_connection();
$where  = "store_id=? AND linked_purchase_id IS NULL";
$params = [$store_id];
$types  = 'i';

if ($supplier_name !== '') {
    $where   .= " AND supplier_name LIKE ?";
    $params[] = '%' . $supplier_name . '%';
    $types   .= 's';
}
if ($date_from !== '') {
    $where   .= " AND receipt_date >= ?";
    $params[] = $date_from;
    $types   .= 's';
}
if ($date_to !== '') {
    $where   .= " AND receipt_date <= ?";
    $params[] = $date_to;
    $types   .= 's';
}

$stmt = $conn->prepare(
    "SELECT id, supplier_name, description, amount, receipt_date,
            (file_path IS NOT NULL) AS has_file
     FROM office_receipts
     WHERE $where
     ORDER BY receipt_date DESC, id DESC
     LIMIT 100"
);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

foreach ($rows as &$r) {
    $r['amount']   = (float)$r['amount'];
    $r['has_file'] = (bool)$r['has_file'];
}

echo json_encode(['success' => true, 'receipts' => $rows], JSON_UNESCAPED_UNICODE);
