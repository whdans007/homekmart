<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false]); exit; }

$store_id = get_office_store_id();
$id       = (int)($_POST['id'] ?? 0);

if (!$id) { echo json_encode(['success'=>false]); exit; }

$conn = get_db_connection();
$stmt = $conn->prepare("DELETE FROM deferred_entries WHERE id=? AND store_id=?");
$stmt->bind_param('ii', $id, $store_id);
$ok = $stmt->execute();
$stmt->close();
$conn->close();

echo json_encode(['success'=>$ok]);
