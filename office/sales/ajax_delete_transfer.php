<?php
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: transfer.php'); exit; }
$store_id = get_office_store_id();
$id = (int)($_POST['id'] ?? 0);
$year  = (int)($_POST['year']  ?? date('Y'));
$month = (int)($_POST['month'] ?? date('n'));
$conn = get_db_connection();
$stmt = $conn->prepare("DELETE FROM sales_transfers WHERE id=? AND store_id=?");
$stmt->bind_param('ii', $id, $store_id);
$stmt->execute();
$stmt->close();
$conn->close();
header("Location: transfer.php?year={$year}&month={$month}");
exit;
