<?php
// 수수료 매장 세금(월별 수동 입력) 저장 — office_monthly_commission_tax
// %(commission_rate)와 달리 세금은 업체별 고정값이 아니라 월마다 달라질 수 있어 연/월 단위로 저장한다.
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']); exit;
}

$store_id   = get_office_store_id();
$year       = (int)($_POST['year']       ?? 0);
$month      = (int)($_POST['month']      ?? 0);
$company_id = (int)($_POST['company_id'] ?? 0);
$tax_amount = (float)($_POST['tax_amount'] ?? 0);

if ($year < 2020 || $month < 1 || $month > 12 || $company_id <= 0 || $tax_amount < 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid params']); exit;
}

$conn = get_db_connection();

$conn->query("CREATE TABLE IF NOT EXISTS office_monthly_commission_tax (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id    INT UNSIGNED   NOT NULL,
  year        SMALLINT UNSIGNED NOT NULL,
  month       TINYINT UNSIGNED  NOT NULL,
  company_id  INT UNSIGNED   NOT NULL,
  tax_amount  DECIMAL(15,2)  NOT NULL DEFAULT 0,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_store_month_company (store_id, year, month, company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$stmt = $conn->prepare(
    "INSERT INTO office_monthly_commission_tax (store_id, year, month, company_id, tax_amount)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE tax_amount=VALUES(tax_amount)"
);
$stmt->bind_param('iiiid', $store_id, $year, $month, $company_id, $tax_amount);
$stmt->execute();
$stmt->close();
$conn->close();

echo json_encode(['success' => true]);
