<?php
// Design Ref: admin 슈퍼어드민 점포 선택/전환.
// admin/ 하위 다수의 ajax_*.php가 partials/header.php를 거치지 않고 $_SESSION['store_id']를
// 직접 읽어 데이터 범위를 결정하므로, 전환 시 그 변수 자체를 갱신해야 화면과 AJAX가 일관된다.
// super_admin만 전환 가능.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../config/db_config.php';

if (!is_logged_in() || ($_SESSION['role'] ?? '') !== 'super_admin') {
    echo json_encode(['success' => false, 'error' => 'permission_denied']);
    exit;
}

$store_id = (int)($_POST['store_id'] ?? 0);
if ($store_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'invalid_store']);
    exit;
}

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

$_SESSION['store_id'] = $store_id;

echo json_encode(['success' => true]);
