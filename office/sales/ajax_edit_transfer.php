<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success'=>false,'error'=>'POST required']); exit;
}

$store_id = get_office_store_id();
$id       = (int)($_POST['id'] ?? 0);
$amount   = max(0.01, (float)($_POST['amount'] ?? 0));
$category = in_array($_POST['category']??'', ['grocery','meat','seafood','fruit'])
            ? $_POST['category'] : null;
$notes    = trim($_POST['notes'] ?? '');
$date     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['transfer_date']??'')
            ? $_POST['transfer_date'] : null;
$other_store_id = (int)($_POST['other_store_id'] ?? 0);

if (!$id || !$category || !$date || !$other_store_id) {
    echo json_encode(['success'=>false,'error'=>'Invalid params']); exit;
}

$conn = get_db_connection();

// 업체명(other_store_id)이 실제 존재하는 점포인지 검증
$chk = $conn->prepare("SELECT id FROM stores WHERE id=?");
$chk->bind_param('i', $other_store_id);
$chk->execute();
$chk->store_result();
$valid_store = $chk->num_rows > 0;
$chk->close();
if (!$valid_store) {
    echo json_encode(['success'=>false,'error'=>'Invalid store']); $conn->close(); exit;
}

// 본인 점포 항목만 수정 가능
$stmt = $conn->prepare(
    "UPDATE sales_transfers
     SET amount=?, category=?, notes=?, transfer_date=?, other_store_id=?
     WHERE id=? AND store_id=? AND direction='in'"
);
$stmt->bind_param('dsssiii', $amount, $category, $notes, $date, $other_store_id, $id, $store_id);
$ok = $stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();
$conn->close();

echo json_encode(['success' => $ok && $affected >= 0]);
