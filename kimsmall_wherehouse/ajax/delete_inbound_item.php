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

if (!$inbound_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

try {
    $conn = get_lc_db();

    $st = $conn->prepare(
        "SELECT i.batch_id, b.is_confirmed,
                COALESCE(inv.quantity_out, 0) AS quantity_out
         FROM kw_inbound i
         JOIN kw_inbound_batches b ON i.batch_id = b.id
         LEFT JOIN kw_inventory inv ON inv.inbound_id = i.id
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
    if ($row['quantity_out'] > 0) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Cannot delete: this item has already been shipped out.']);
        exit;
    }

    $batch_id = (int)$row['batch_id'];

    $conn->autocommit(false);
    $conn->query("DELETE FROM kw_inventory WHERE inbound_id = " . $inbound_id);
    $st = $conn->prepare("DELETE FROM kw_inbound WHERE id = ?");
    $st->bind_param('i', $inbound_id);
    $st->execute();
    $st->close();
    $conn->commit();

    $remaining = $conn->query("SELECT COUNT(*) FROM kw_inbound WHERE batch_id = $batch_id")->fetch_row()[0];
    $conn->close();

    echo json_encode(['success' => true, 'remaining' => (int)$remaining]);
} catch (Exception $e) {
    if (isset($conn)) { $conn->rollback(); $conn->close(); }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
