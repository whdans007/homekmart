<?php
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../lib/inventory_service.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (!has_permission('product_management') && !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'], true))) {
    http_response_code(403); echo json_encode(['success' => false, 'message' => '권한이 없습니다.']); exit;
}
$product_id = (int)($_POST['product_id'] ?? 0);
$store_id = (int)($_POST['store_id'] ?? 0);
$counted_raw = trim((string)($_POST['counted_quantity'] ?? ''));
$counted = is_numeric($counted_raw) ? (float)$counted_raw : null;
$reason = trim((string)($_POST['reason'] ?? ''));
if ($product_id <= 0 || $store_id <= 0 || $counted === null || $reason === '') {
    http_response_code(422); echo json_encode(['success' => false, 'message' => '상품, 점포, 실사 수량, 사유를 확인해 주세요.']); exit;
}
try {
    $conn = get_db_connection();
    $source_id = inventory_ledger_scoped_source_id($product_id, 2000000000 + (int)($_SESSION['user_id'] ?? 0));
    $current_stmt = $conn->prepare('SELECT quantity FROM inventory WHERE store_id = ? AND product_id = ? FOR UPDATE');
    $current_stmt->bind_param('ii', $store_id, $product_id);
    $conn->begin_transaction();
    $current_stmt->execute();
    $current_row = $current_stmt->get_result()->fetch_assoc();
    $current_stmt->close();
    $current_quantity = (float)($current_row['quantity'] ?? 0);
    $delta = round((float)$counted - $current_quantity, 2);
    if (abs($delta) < 0.01) {
        $conn->commit();
        $conn->close();
        echo json_encode(['success' => true, 'skipped' => true, 'message' => '현재 재고와 실사 수량이 같습니다.']);
        exit;
    }
    $result = inventory_apply_delta($conn, [
        'store_id' => $store_id, 'product_id' => $product_id, 'quantity_change' => $delta,
        'event_type' => $delta > 0 ? 'ADJUSTMENT_IN' : 'ADJUSTMENT_OUT', 'source_type' => 'manual_adjustment', 'source_id' => $source_id,
        'user_id' => (int)$_SESSION['user_id'], 'remarks' => $reason,
        'manage_transaction' => false,
    ]);
    if (!$result['success']) {
        $conn->rollback();
    } else {
        $conn->commit();
    }
    $conn->close();
    echo json_encode(['success' => (bool)$result['success'], 'skipped' => (bool)($result['skipped'] ?? false), 'message' => $result['success'] ? '재고 조정이 저장되었습니다.' : ($result['error'] ?? '재고 조정에 실패했습니다.')]);
} catch (Throwable $e) {
    http_response_code(500); echo json_encode(['success' => false, 'message' => '재고 조정 중 오류가 발생했습니다.']);
}
