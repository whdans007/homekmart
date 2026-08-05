<?php
// Design Ref: office 슈퍼어드민 점포 선택/전환. super_admin만 세션에 조회 대상 점포를 override.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../config/db_config.php';

if (!is_logged_in() || ($_SESSION['role'] ?? '') !== 'super_admin') {
    echo json_encode(['success' => false, 'error' => 'permission_denied']);
    exit;
}

$store_id = (int)($_POST['store_id'] ?? 0);

if ($store_id > 0) {
    $conn = get_db_connection();
    $stmt = $conn->prepare("SELECT id FROM stores WHERE id=?");
    $stmt->bind_param('i', $store_id);
    $stmt->execute();
    $valid = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$valid) {
        echo json_encode(['success' => false, 'error' => 'invalid_store']);
        exit;
    }
    $_SESSION['office_store_override_id'] = $store_id;
} else {
    // store_id=0 → 본인 소속 점포로 되돌리기
    unset($_SESSION['office_store_override_id']);
}

echo json_encode(['success' => true]);
