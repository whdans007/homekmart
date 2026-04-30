<?php
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: list.php');
    exit;
}

$id       = (int)($_POST['id'] ?? 0);
$store_id = get_office_store_id();
$year     = (int)($_POST['year']  ?? date('Y'));
$month    = (int)($_POST['month'] ?? date('n'));

$conn = get_db_connection();
$stmt = $conn->prepare("DELETE FROM office_product_purchases WHERE id=? AND store_id=?");
$stmt->bind_param('ii', $id, $store_id);
$stmt->execute();
$stmt->close();
$conn->close();

header("Location: list.php?year={$year}&month={$month}");
exit;
