<?php
require_once __DIR__ . '/../lib/auth.php';
header('Content-Type: application/json; charset=utf-8');

lc_require_staff();

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['lc_csrf'] ?? '', $token)) {
    echo json_encode(['success' => false, 'message' => t('logistics.ajax_update_batch_supplier.security_error')]);
    exit;
}

$batch_id = (int)($_POST['batch_id'] ?? 0);
$sid      = (int)($_POST['supplier_id'] ?? 0) ?: null;

if (!$batch_id) {
    echo json_encode(['success' => false, 'message' => t('logistics.ajax_update_batch_supplier.invalid_request')]);
    exit;
}

try {
    $conn = get_lc_db();

    $st = $conn->prepare("SELECT is_confirmed FROM lc_inbound_batches WHERE id = ?");
    $st->bind_param('i', $batch_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => t('logistics.ajax_update_batch_supplier.invalid_request')]);
        exit;
    }
    if ($row['is_confirmed']) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => t('logistics.ajax_update_batch_supplier.locked')]);
        exit;
    }

    // 배치와 라인아이템(lc_inbound)의 supplier_id를 함께 갱신해야 한다.
    // (배치만 갱신하면 Inbound Items List 는 라인아이템 기준이라 이전 거래처가 남음)
    $conn->begin_transaction();
    try {
        $st = $conn->prepare("UPDATE lc_inbound_batches SET supplier_id = ? WHERE id = ?");
        $st->bind_param('ii', $sid, $batch_id);
        $st->execute();
        $st->close();

        $st = $conn->prepare("UPDATE lc_inbound SET supplier_id = ? WHERE batch_id = ?");
        $st->bind_param('ii', $sid, $batch_id);
        $st->execute();
        $st->close();

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

    $name = '-';
    if ($sid) {
        $r = $conn->prepare("SELECT name FROM lc_suppliers WHERE id = ?");
        $r->bind_param('i', $sid);
        $r->execute();
        $row = $r->get_result()->fetch_assoc();
        if ($row) $name = $row['name'];
    }
    $conn->close();

    echo json_encode(['success' => true, 'supplier_name' => $name]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => t('logistics.ajax_update_batch_supplier.db_error', ['error' => $e->getMessage()])]);
}
