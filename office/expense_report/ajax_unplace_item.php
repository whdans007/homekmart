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
$item_id  = trim($_POST['item_id'] ?? '');

if (!$item_id) { echo json_encode(['success'=>false,'error'=>'Missing item_id']); exit; }

$conn = get_db_connection();
$ok   = false;

// is_er_placed=0 으로 복원 (미사용 영수증 상태로)
if (strncmp($item_id, 'pc_', 3) === 0 || strncmp($item_id, 'p_', 2) === 0) {
    $n   = strncmp($item_id, 'pc_', 3) === 0 ? (int)substr($item_id, 3) : (int)substr($item_id, 2);
    $chk = $conn->query("SHOW COLUMNS FROM office_product_purchases LIKE 'is_er_placed'");
    if ($chk && $chk->num_rows > 0) {
        $s = $conn->prepare("UPDATE office_product_purchases SET is_er_placed=0 WHERE id=? AND store_id=?");
        $s->bind_param('ii', $n, $store_id); $s->execute(); $ok = true; $s->close();
    } else { $ok = true; }

} elseif (strncmp($item_id, 'e_', 2) === 0) {
    $n   = (int)substr($item_id, 2);
    $chk = $conn->query("SHOW COLUMNS FROM office_equipment_purchases LIKE 'is_er_placed'");
    if ($chk && $chk->num_rows > 0) {
        $s = $conn->prepare("UPDATE office_equipment_purchases SET is_er_placed=0 WHERE id=? AND store_id=?");
        $s->bind_param('ii', $n, $store_id); $s->execute(); $ok = true; $s->close();
    } else { $ok = true; }

} elseif (strncmp($item_id, 'r_', 2) === 0) {
    $n = (int)substr($item_id, 2);
    $s = $conn->prepare("UPDATE office_receipts SET er_section=NULL WHERE id=? AND store_id=?");
    $s->bind_param('ii', $n, $store_id); $s->execute(); $ok = true; $s->close();
}

$conn->close();
echo json_encode(['success' => $ok]);
