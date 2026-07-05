<?php
// Design Ref: §4 — Save supplier→section mapping (upsert)
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$store_id      = get_office_store_id();
$supplier_name = post_str('supplier_name');
$section       = in_array($_POST['section'] ?? '', ['korean','local','fixed','maintenance','others'])
                 ? $_POST['section'] : null;

if (!$supplier_name || !$section) {
    echo json_encode(['success'=>false,'error'=>'Invalid parameters']);
    exit;
}

$conn = get_db_connection();

// Check if table exists
$chk = $conn->query("SHOW TABLES LIKE 'cd_supplier_section_map'");
if (!$chk || $chk->num_rows === 0) {
    $conn->close();
    echo json_encode(['success'=>true,'skipped'=>true]); // graceful degradation
    exit;
}

$stmt = $conn->prepare(
    "INSERT INTO cd_supplier_section_map (store_id, supplier_name, section)
     VALUES (?,?,?)
     ON DUPLICATE KEY UPDATE section=VALUES(section), updated_at=NOW()"
);
$stmt->bind_param('iss', $store_id, $supplier_name, $section);
$stmt->execute();
$stmt->close();
$conn->close();

echo json_encode(['success'=>true]);
