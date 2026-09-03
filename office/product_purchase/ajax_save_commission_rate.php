<?php
// 수수료 매장 업체별 고정 수수료율(%) 저장 — daily_report_commission_companies.commission_rate
// (Daily Report 수수료 코너 업체 등록 테이블을 monthly_closing.php와 공유)
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']); exit;
}

$store_id = get_office_store_id();
$id       = (int)($_POST['id']   ?? 0);
$rate     = (float)($_POST['rate'] ?? 0);

if ($id <= 0 || $rate < 0 || $rate > 100) {
    echo json_encode(['success' => false, 'error' => 'Invalid params']); exit;
}

$conn = get_db_connection();

$col_check = $conn->query("SHOW COLUMNS FROM daily_report_commission_companies LIKE 'commission_rate'");
if (!$col_check || $col_check->num_rows === 0) {
    $conn->close();
    echo json_encode(['success' => false, 'error' => 'commission_rate 컬럼이 없습니다. sql/run_add_commission_rate_migration.php를 먼저 실행하세요.']);
    exit;
}

$stmt = $conn->prepare("UPDATE daily_report_commission_companies SET commission_rate=? WHERE id=? AND store_id=?");
$stmt->bind_param('dii', $rate, $id, $store_id);
$stmt->execute();
$stmt->close();
$conn->close();

echo json_encode(['success' => true]);
