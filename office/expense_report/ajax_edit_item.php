<?php
// Plan SC: F2 — Source List 항목 Edit (item_id prefix로 테이블 구분)
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
$item_id         = $_POST['item_id']          ?? '';
$supplier_name   = trim($_POST['supplier_name']    ?? '');
$delivery_content= trim($_POST['delivery_content'] ?? '');
$amount          = (float)($_POST['amount']   ?? 0);
$date            = $_POST['date']             ?? '';
$payment_type    = $_POST['payment_type']     ?? '';

if ($item_id === '' || $supplier_name === '' || $amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => false, 'error' => 'Invalid date']);
    exit;
}

// item_id prefix로 테이블/레코드 구분
if (preg_match('/^pc_(\d+)$/', $item_id, $m)) {
    $table = 'office_product_purchases';
    $id    = (int)$m[1];
    $type  = 'product_check';
} elseif (preg_match('/^p_(\d+)$/', $item_id, $m)) {
    $table = 'office_product_purchases';
    $id    = (int)$m[1];
    $type  = 'product_cash';
} elseif (preg_match('/^e_(\d+)$/', $item_id, $m)) {
    $table = 'office_equipment_purchases';
    $id    = (int)$m[1];
    $type  = 'equipment';
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid item_id']);
    exit;
}

$conn = get_db_connection();

if ($table === 'office_product_purchases') {
    $new_payment = in_array($payment_type, ['cash', 'check']) ? $payment_type : null;
    if ($new_payment) {
        $check_date = ($new_payment === 'check') ? $date : null;
        $stmt = $conn->prepare(
            "UPDATE office_product_purchases
             SET supplier_name=?, delivery_content=?, amount=?, payment_date=?,
                 payment_type=?, check_issued_date=?
             WHERE id=? AND store_id=?"
        );
        $stmt->bind_param('ssdsssii',
            $supplier_name, $delivery_content, $amount, $date,
            $new_payment, $check_date, $id, $store_id
        );
    } else {
        $stmt = $conn->prepare(
            "UPDATE office_product_purchases
             SET supplier_name=?, delivery_content=?, amount=?, payment_date=?
             WHERE id=? AND store_id=?"
        );
        $stmt->bind_param('ssdsii', $supplier_name, $delivery_content, $amount, $date, $id, $store_id);
    }
} else {
    $stmt = $conn->prepare(
        "UPDATE office_equipment_purchases
         SET supplier_name=?, delivery_content=?, amount=?, payment_date=?
         WHERE id=? AND store_id=?"
    );
    $stmt->bind_param('ssdsii', $supplier_name, $delivery_content, $amount, $date, $id, $store_id);
}

$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();
$conn->close();

echo json_encode(['success' => $affected >= 0], JSON_UNESCAPED_UNICODE);
