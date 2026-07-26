<?php
require_once __DIR__ . '/../lib/auth.php';
header('Content-Type: application/json; charset=utf-8');

kw_require_staff();

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['kw_csrf'] ?? '', $token)) {
    echo json_encode(['success' => false, 'message' => 'Security error']);
    exit;
}

$inbound_id = (int)($_POST['inbound_id'] ?? 0);
$location   = trim($_POST['storage_location'] ?? '') ?: null;

if (!$inbound_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

try {
    $conn = get_lc_db();

    $st = $conn->prepare(
        "SELECT b.is_confirmed FROM kw_inbound i
         JOIN kw_inbound_batches b ON i.batch_id = b.id
         WHERE i.id = ?"
    );
    $st->bind_param('i', $inbound_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }
    if ($row['is_confirmed']) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'This inbound record is locked and cannot be edited.']);
        exit;
    }

    $st = $conn->prepare("UPDATE kw_inventory SET storage_location = ? WHERE inbound_id = ?");
    $st->bind_param('si', $location, $inbound_id);
    $st->execute();
    $conn->close();

    echo json_encode(['success' => true, 'storage_location' => $location ?? '']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
