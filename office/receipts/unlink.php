<?php
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: list.php');
    exit;
}

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['super_admin', 'admin'])) {
    header('Location: list.php?error=permission_denied');
    exit;
}

$id       = (int)($_POST['id'] ?? 0);
$store_id = get_office_store_id();
$year     = (int)($_POST['year']  ?? date('Y'));
$month    = (int)($_POST['month'] ?? date('n'));
$status   = $_POST['status'] ?? 'all';
$search   = trim($_POST['search'] ?? '');

if ($id > 0) {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "UPDATE office_receipts
         SET linked_purchase_type=NULL, linked_purchase_id=NULL
         WHERE id=? AND store_id=?"
    );
    $stmt->bind_param('ii', $id, $store_id);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

$qs = "year={$year}&month={$month}&status={$status}";
if ($search !== '') $qs .= '&search=' . urlencode($search);
header("Location: list.php?{$qs}");
exit;
