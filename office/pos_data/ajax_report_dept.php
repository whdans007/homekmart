<?php
// Design Ref: report.php 성능 개선 — 부서별 매출 breakdown (비동기 로드)
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
require_once __DIR__ . '/../lib/pos_report_helper.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$today        = date('Y-m-d');
$default_from = date('Y-m-01');
$default_to   = date('Y-m-d');

$date_from = trim($_GET['from'] ?? $default_from);
$date_to   = trim($_GET['to']   ?? $default_to);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = $default_from;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = $default_to;
if ($date_from > $date_to) [$date_from, $date_to] = [$date_to, $date_from];

$store_id = get_office_store_id();
$conn     = get_db_connection();

$dept_rows = pos_report_get_dept_breakdown($conn, $store_id, $date_from, $date_to);

$conn->close();

echo json_encode([
    'success'   => true,
    'dept_rows' => $dept_rows,
]);
