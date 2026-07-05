<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']); exit;
}

$store_id       = get_office_store_id();
$year           = (int)($_POST['year']           ?? 0);
$month          = (int)($_POST['month']          ?? 0);
$korean_salary  = (float)($_POST['korean_salary'] ?? 0);
$monthly_rent   = (float)($_POST['monthly_rent']  ?? 0);

if ($year < 2020 || $month < 1 || $month > 12) {
    echo json_encode(['success' => false, 'error' => 'Invalid params']); exit;
}

$conn = get_db_connection();

$conn->query("CREATE TABLE IF NOT EXISTS office_monthly_fixed (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id       INT UNSIGNED    NOT NULL,
  year           SMALLINT UNSIGNED NOT NULL,
  month          TINYINT UNSIGNED  NOT NULL,
  korean_salary  DECIMAL(15,2)   NOT NULL DEFAULT 0,
  monthly_rent   DECIMAL(15,2)   NOT NULL DEFAULT 0,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_store_month (store_id, year, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$stmt = $conn->prepare(
    "INSERT INTO office_monthly_fixed (store_id, year, month, korean_salary, monthly_rent)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE korean_salary=VALUES(korean_salary), monthly_rent=VALUES(monthly_rent)"
);
$stmt->bind_param('iiidd', $store_id, $year, $month, $korean_salary, $monthly_rent);
$stmt->execute();
$stmt->close();
$conn->close();

echo json_encode(['success' => true]);
