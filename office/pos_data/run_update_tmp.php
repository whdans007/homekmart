<?php
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
$store_id = get_office_store_id();
$conn = get_db_connection();
header('Content-Type: text/html; charset=utf-8');

$stmt = $conn->prepare(
    "UPDATE pos_sales_data d
     JOIN pos_sales_uploads u ON u.id = d.upload_id
     SET d.item_code = '2300041', d.supplier = 'PATCHER', d.department = 'VEGETABLES'
     WHERE u.store_id = ? AND d.item_name = 'ONION LEEK'"
);
$stmt->bind_param('i', $store_id);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();
$conn->close();

echo "<p style='font-family:sans-serif;padding:20px'>✅ ONION LEEK {$affected}건 완료<br>
ITEMCODE→2300041 / SUPPLIER→PATCHER / DEPARTMENT→VEGETABLES</p>
<p style='font-family:sans-serif;padding:0 20px;color:#6b7280;font-size:13px'>이 파일을 삭제하세요: <code>run_update_tmp.php</code></p>";
