<?php
// Design Ref: list.php 인라인 Supplier 수정 — 잘못 선택된 공급처를 목록에서 바로 교정
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false]); exit; }

$store_id      = get_office_store_id();
$id            = (int)($_POST['id'] ?? 0);
$supplier_name = trim($_POST['supplier_name'] ?? '');

if (!$id || $supplier_name === '') {
    echo json_encode(['success' => false, 'error' => '필수 값이 누락되었습니다.']); exit;
}

$conn = get_db_connection();

$stmt = $conn->prepare("SELECT linked_purchase_type, linked_purchase_id FROM office_receipts WHERE id=? AND store_id=?");
$stmt->bind_param('ii', $id, $store_id);
$stmt->execute();
$receipt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$receipt) {
    $conn->close();
    echo json_encode(['success' => false, 'error' => '영수증을 찾을 수 없습니다.']); exit;
}

// 이미 구매(Product/Equipment Purchase)에 연결된 영수증도 공급처 수정 가능 —
// 단, 구매 테이블에는 supplier_name 이 링크 시점에 독립적으로 복사되어 있으므로
// 두 테이블을 함께 갱신해야 화면 표시가 어긋나지 않는다.
$purchase_table_map = ['product' => 'office_product_purchases', 'equipment' => 'office_equipment_purchases'];
$purchase_table = $purchase_table_map[$receipt['linked_purchase_type'] ?? ''] ?? null;
$purchase_id    = $receipt['linked_purchase_id'] !== null ? (int)$receipt['linked_purchase_id'] : null;

$conn->begin_transaction();
try {
    $stmt2 = $conn->prepare("UPDATE office_receipts SET supplier_name=? WHERE id=? AND store_id=?");
    $stmt2->bind_param('sii', $supplier_name, $id, $store_id);
    $stmt2->execute();
    $stmt2->close();

    if ($purchase_table && $purchase_id) {
        $stmt3 = $conn->prepare("UPDATE {$purchase_table} SET supplier_name=? WHERE id=? AND store_id=?");
        $stmt3->bind_param('sii', $supplier_name, $purchase_id, $store_id);
        $stmt3->execute();
        $stmt3->close();
    }

    $conn->commit();
    $conn->close();
    echo json_encode(['success' => true, 'supplier_name' => $supplier_name]);
} catch (Throwable $e) {
    $conn->rollback();
    $conn->close();
    echo json_encode(['success' => false, 'error' => '수정 중 오류가 발생했습니다.']);
}
