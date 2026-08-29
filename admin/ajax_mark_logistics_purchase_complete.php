<?php
// Design Ref: purchase-from-logistics — 실제 매입(purchases) 레코드를 만들지 않고,
// 물류센터 배송완료 건을 "매입등록 완료" 상태로만 표시 처리한다.
// converted_purchase_id 컬럼을 재사용: NULL=미처리, 0=매입등록 없이 완료 처리, >0=실제 매입 레코드 id.
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !has_permission('purchase_management')) {
    echo json_encode(['success' => false, 'error' => '권한이 없습니다.']);
    exit;
}

$lc_order_id = (int)($_POST['lc_order_id'] ?? 0);
if (!$lc_order_id) {
    echo json_encode(['success' => false, 'error' => '필수 값이 누락되었습니다.']);
    exit;
}

$conn = get_db_connection();

$stmt = $conn->prepare("SELECT id, store_id, status, converted_purchase_id FROM lc_orders WHERE id = ?");
$stmt->bind_param('i', $lc_order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order || $order['status'] !== 'delivered') {
    $conn->close();
    echo json_encode(['success' => false, 'error' => '유효하지 않은 주문입니다.']);
    exit;
}
if ($order['converted_purchase_id'] !== null) {
    $conn->close();
    echo json_encode(['success' => false, 'error' => '이미 처리된 주문입니다.']);
    exit;
}

// 점포 스코프 검증 (super_admin이 아니면 자기 점포 건만 처리 가능) — ajax_save_logistics_purchase.php와 동일 규칙
$store_id = (int)$order['store_id'];
if ($_SESSION['role'] !== 'super_admin') {
    $u_stmt = $conn->prepare("SELECT store_id FROM users WHERE id = ?");
    $u_stmt->bind_param('i', $_SESSION['user_id']);
    $u_stmt->execute();
    $u_row = $u_stmt->get_result()->fetch_assoc();
    $u_stmt->close();
    if ((int)($u_row['store_id'] ?? 0) !== $store_id) {
        $conn->close();
        echo json_encode(['success' => false, 'error' => '권한이 없습니다.']);
        exit;
    }
}

$mark_stmt = $conn->prepare("UPDATE lc_orders SET converted_purchase_id = 0 WHERE id = ? AND converted_purchase_id IS NULL");
$mark_stmt->bind_param('i', $lc_order_id);
$mark_stmt->execute();
$done = $mark_stmt->affected_rows > 0;
$mark_stmt->close();
$conn->close();

if ($done) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => '이미 처리된 주문입니다.']);
}
