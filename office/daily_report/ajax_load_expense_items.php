<?php
// Design Ref: daily-report.design.md §4.2 — 기타지출 소스 항목 + 현재 배치 상태 조회
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
require_once __DIR__ . '/../lib/daily_report_helper.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$date = trim($_GET['date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => false, 'error' => 'Invalid date']);
    exit;
}

$store_id = get_office_store_id();
$is_super_admin = ($_SESSION['role'] ?? '') === 'super_admin';
if ($is_super_admin) {
    $req_store_id = (int)($_GET['store_id'] ?? 0);
    if ($req_store_id > 0) $store_id = $req_store_id;
}

$conn = get_db_connection();
$result = get_daily_other_expense_categories($conn, $store_id, $date);
$conn->close();

echo json_encode([
    'success'        => true,
    'items'          => $result['items'],
    'categories'     => $result['categories'],
    'by_category'    => $result['by_category'],
    'unplaced_count' => $result['unplaced_count'],
    'total_placed'   => $result['total_placed'],
]);
