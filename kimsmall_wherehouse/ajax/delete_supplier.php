<?php
require_once __DIR__ . '/../lib/auth.php';
header('Content-Type: application/json; charset=utf-8');

kw_require_staff();
kw_verify_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Invalid supplier ID.']);
    exit;
}

try {
    $conn = get_lc_db();

    // 입고 기록에 참조된 거래처는 삭제 불가
    $st = $conn->prepare("SELECT COUNT(*) FROM kw_inbound_batches WHERE supplier_id = ?");
    $st->bind_param('i', $id);
    $st->execute();
    $used = (int)$st->get_result()->fetch_row()[0];
    $st->close();

    if ($used > 0) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => "Cannot delete: this supplier is referenced in {$used} inbound record(s)."]);
        exit;
    }

    $st = $conn->prepare("DELETE FROM kw_suppliers WHERE id = ?");
    $st->bind_param('i', $id);
    $st->execute();
    $st->close();
    $conn->close();

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
