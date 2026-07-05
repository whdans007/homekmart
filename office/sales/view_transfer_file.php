<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

$id       = (int)($_GET['id'] ?? 0);
$store_id = get_office_store_id();

$conn = get_db_connection();
$stmt = $conn->prepare(
    "SELECT file_path, file_original_name, file_mime
     FROM sales_transfers WHERE id=? AND store_id=?"
);
$stmt->bind_param('ii', $id, $store_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if (!$row || !$row['file_path']) {
    http_response_code(404);
    exit('File not found.');
}

$full_path = __DIR__ . '/../../' . ltrim($row['file_path'], '/');
if (!is_file($full_path)) {
    http_response_code(404);
    exit('File not found.');
}

$mime     = $row['file_mime'] ?? 'application/octet-stream';
$orig     = $row['file_original_name'] ?? 'transfer_proof';
$download = isset($_GET['dl']);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($full_path));
$disposition = $download ? 'attachment' : 'inline';
header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($orig) . '"');
readfile($full_path);
exit;
