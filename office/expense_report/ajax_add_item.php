<?php
// Plan SC: F1 — Source List에서 직접 Add (product/equipment 구분 INSERT)
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$store_id        = get_office_store_id();
$type            = $_POST['type']            ?? '';   // 'product' | 'equipment'
$payment_type    = $_POST['payment_type']    ?? 'cash'; // 'cash' | 'check'
$date            = $_POST['date']            ?? '';
$supplier_name   = trim($_POST['supplier_name']   ?? '');
$delivery_content= trim($_POST['delivery_content'] ?? '');
$amount          = (float)($_POST['amount'] ?? 0);

if (!in_array($type, ['product', 'equipment'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid type']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => false, 'error' => 'Invalid date']);
    exit;
}
if ($supplier_name === '') {
    echo json_encode(['success' => false, 'error' => 'Supplier name required']);
    exit;
}
if ($amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Amount must be positive']);
    exit;
}

$conn = get_db_connection();
$new_id = 0;

if ($type === 'product') {
    $payment_type = in_array($payment_type, ['cash', 'check']) ? $payment_type : 'cash';
    $check_date   = ($payment_type === 'check') ? $date : null;
    $stmt = $conn->prepare(
        "INSERT INTO office_product_purchases
         (store_id, supplier_name, delivery_content, amount, payment_type, payment_date, check_issued_date, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    $stmt->bind_param('issdssss',
        $store_id, $supplier_name, $delivery_content, $amount,
        $payment_type, $date, $check_date
    );
    $stmt->execute();
    $new_id = (int)$conn->insert_id;
    $stmt->close();

    $prefix       = ($payment_type === 'check') ? 'pc_' : 'p_';
    $auto_section = ($payment_type === 'check') ? 'check_sup' : 'selling';
    $item_type    = ($payment_type === 'check') ? 'product_check' : 'product_cash';
} else {
    $stmt = $conn->prepare(
        "INSERT INTO office_equipment_purchases
         (store_id, supplier_name, delivery_content, amount, payment_date, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())"
    );
    $stmt->bind_param('issds', $store_id, $supplier_name, $delivery_content, $amount, $date);
    $stmt->execute();
    $new_id = (int)$conn->insert_id;
    $stmt->close();

    $prefix       = 'e_';
    $auto_section = 'not_selling';
    $item_type    = 'equipment';
}

$conn->close();

echo json_encode([
    'success' => true,
    'item' => [
        'id'           => $prefix . $new_id,
        'type'         => $item_type,
        'supplier'     => $supplier_name,
        'details'      => $delivery_content,
        'amount'       => $amount,
        'date'         => $date,
        'cv_no'        => '',
        'auto_section' => $auto_section,
    ],
], JSON_UNESCAPED_UNICODE);
