<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
$buffered = ob_get_clean();

header('Content-Type: application/json; charset=utf-8');

if ($buffered) {
    echo json_encode(['success'=>false,'error'=>'Buffer: ' . $buffered]);
    exit;
}

try {
$store_id = get_office_store_id();
$cer_date = trim($_GET['date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cer_date)) {
    $cer_date = date('Y-m-d');
}
$conn = get_db_connection();

// 각 컬럼 존재 여부 확인 (PHP 7 호환)
$r1 = $conn->query("SHOW COLUMNS FROM office_product_purchases LIKE 'is_cer_placed'");
$has_placed = ($r1 && $r1->num_rows > 0);
$r2 = $conn->query("SHOW COLUMNS FROM office_product_purchases LIKE 'is_cer_returned'");
$has_ret    = ($r2 && $r2->num_rows > 0);
$r3 = $conn->query("SHOW COLUMNS FROM office_product_purchases LIKE 'cer_check_no'");
$has_cno    = ($r3 && $r3->num_rows > 0);

// is_cer_placed 없으면 미발행 수표까지 노출되므로 반드시 필요
if (!$has_placed) {
    $conn->close();
    echo json_encode(['success'=>false, 'error'=>'마이그레이션 필요: run_cer_migration.php 를 먼저 실행하세요.']);
    exit;
}

$select_cno = $has_cno ? "pp.cer_check_no" : "'' AS cer_check_no";
$where_ret  = $has_ret ? "AND pp.is_cer_returned=0" : "";

$stmt = $conn->prepare(
    "SELECT pp.id, pp.supplier_name, pp.delivery_content, pp.amount,
            pp.check_issued_date, $select_cno,
            COALESCE(r.cv_no,'') AS sales_invoice
     FROM office_product_purchases pp
     LEFT JOIN office_receipts r
       ON r.linked_purchase_type='product' AND r.linked_purchase_id=pp.id
     WHERE pp.store_id=? AND pp.payment_type='check'
       AND pp.is_cer_placed=1 AND pp.check_issued_date<=?
       $where_ret
     ORDER BY pp.check_issued_date DESC, pp.id DESC"
);

$rows = [];
if ($stmt) {
    $stmt->bind_param('is', $store_id, $cer_date);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $rows[] = [
            'id'            => (int)$r['id'],
            'supplier_name' => $r['supplier_name'],
            'particular'    => $r['delivery_content'],
            'amount'        => (float)$r['amount'],
            'date'          => $r['check_issued_date'],
            'check_no'      => $r['cer_check_no'] ?? '',
            'sales_invoice' => $r['sales_invoice'],
        ];
    }
    $stmt->close();
}
$conn->close();

echo json_encode(['success'=>true, 'items'=>$rows], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['success'=>false, 'error'=>$e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()]);
}
