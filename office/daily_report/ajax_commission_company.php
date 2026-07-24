<?php
// Design Ref: daily-report.design.md §4.2 재검토 — 수수료 코너 업체 등록/삭제
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

// Plan/Design §7 — store 스코프 강제: super_admin만 store_id override 가능
$store_id = get_office_store_id();
$is_super_admin = ($_SESSION['role'] ?? '') === 'super_admin';
if ($is_super_admin) {
    $req_store_id = (int)($_POST['store_id'] ?? 0);
    if ($req_store_id > 0) $store_id = $req_store_id;
}

$action = $_POST['action'] ?? '';
$conn   = get_db_connection();

if ($action === 'add') {
    $supplier_name = trim($_POST['supplier_name'] ?? '');
    if ($supplier_name === '') {
        echo json_encode(['success' => false, 'error' => '업체명을 입력하세요.']);
        exit;
    }

    $check = $conn->prepare("SELECT id FROM daily_report_commission_companies WHERE store_id=? AND supplier_name=?");
    $check->bind_param('is', $store_id, $supplier_name);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();
    if ($existing) {
        $conn->close();
        echo json_encode(['success' => false, 'error' => '이미 등록된 업체입니다.']);
        exit;
    }

    $created_by = (int)($_SESSION['user_id'] ?? 0) ?: null;
    $stmt = $conn->prepare(
        "INSERT INTO daily_report_commission_companies (store_id, supplier_name, created_by) VALUES (?,?,?)"
    );
    $stmt->bind_param('isi', $store_id, $supplier_name, $created_by);
    $stmt->execute();
    $new_id = $conn->insert_id;
    $stmt->close();
    $conn->close();
    echo json_encode(['success' => true, 'id' => $new_id]);
    exit;
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        $conn->close();
        echo json_encode(['success' => false, 'error' => 'Invalid id']);
        exit;
    }
    $stmt = $conn->prepare("DELETE FROM daily_report_commission_companies WHERE id=? AND store_id=?");
    $stmt->bind_param('ii', $id, $store_id);
    $stmt->execute();
    $stmt->close();
    $conn->close();
    echo json_encode(['success' => true]);
    exit;
}

$conn->close();
echo json_encode(['success' => false, 'error' => 'Unknown action']);
