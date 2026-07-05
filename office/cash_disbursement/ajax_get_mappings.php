<?php
// Design Ref: §4 — Get all supplier→section mappings for store
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$store_id = get_office_store_id();
$conn     = get_db_connection();

$chk = $conn->query("SHOW TABLES LIKE 'cd_supplier_section_map'");
if (!$chk || $chk->num_rows === 0) {
    $conn->close();
    echo json_encode(['success'=>true,'mappings'=>[]]);
    exit;
}

$stmt = $conn->prepare(
    "SELECT supplier_name, section FROM cd_supplier_section_map WHERE store_id=?"
);
$stmt->bind_param('i', $store_id);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

$mappings = [];
foreach ($rows as $r) {
    $mappings[$r['supplier_name']] = $r['section'];
}

echo json_encode(['success'=>true,'mappings'=>$mappings], JSON_UNESCAPED_UNICODE);
