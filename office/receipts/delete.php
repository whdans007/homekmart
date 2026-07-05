<?php
// Design Ref: §6.4 — 등록됨 상태 보호 + 파일 삭제
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: list.php');
    exit;
}

$id       = (int)($_POST['id'] ?? 0);
$store_id = get_office_store_id();

// 삭제 후 검색 컨텍스트 복원용 파라미터
$back_params = http_build_query(array_filter([
    'year'   => $_POST['year']   ?? '',
    'month'  => $_POST['month']  ?? '',
    'status' => $_POST['status'] ?? '',
    'search' => $_POST['search'] ?? '',
], fn($v) => $v !== ''));

$conn = get_db_connection();
$stmt = $conn->prepare(
    "SELECT file_path, linked_purchase_id FROM office_receipts WHERE id=? AND store_id=?"
);
$stmt->bind_param('ii', $id, $store_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    $conn->close();
    header('Location: list.php' . ($back_params ? '?' . $back_params : ''));
    exit;
}

// Plan SC: Cannot delete a linked receipt (delete the expense first)
if ($row['linked_purchase_id'] !== null) {
    $conn->close();
    $err = $back_params ? $back_params . '&error=linked' : 'error=linked';
    header('Location: list.php?' . $err);
    exit;
}

// Delete physical file
if ($row['file_path']) {
    $full_path = __DIR__ . '/../../' . ltrim($row['file_path'], '/');
    if (is_file($full_path)) unlink($full_path);
}

$stmt2 = $conn->prepare("DELETE FROM office_receipts WHERE id=? AND store_id=?");
$stmt2->bind_param('ii', $id, $store_id);
$stmt2->execute();
$stmt2->close();
$conn->close();

header('Location: list.php' . ($back_params ? '?' . $back_params : ''));
exit;
