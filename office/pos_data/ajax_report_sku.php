<?php
// Design Ref: report.php 성능 개선 — SKU(품목)별 판매 수량/금액 분석 (정렬/limit 지원, 비동기 로드)
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

$dept_filter = trim($_GET['dept'] ?? '');

$sort_col = trim($_GET['sort'] ?? 'net');
if (!in_array($sort_col, ['net', 'pcs', 'gp', 'tx'], true)) $sort_col = 'net';

$sort_dir = strtoupper(trim($_GET['dir'] ?? 'desc'));
if (!in_array($sort_dir, ['ASC', 'DESC'], true)) $sort_dir = 'DESC';

$limit = (int)($_GET['limit'] ?? 100);
if ($limit <= 0) $limit = 100;

$store_id = get_office_store_id();
$conn     = get_db_connection();

$rows   = pos_report_get_sku_breakdown($conn, $store_id, $date_from, $date_to, $dept_filter, $sort_col, $sort_dir, $limit);
$totals = pos_report_get_sku_totals($conn, $store_id, $date_from, $date_to, $dept_filter);

$conn->close();

echo json_encode([
    'success' => true,
    'rows'    => $rows,
    'totals'  => $totals,
    'sort'    => $sort_col,
    'dir'     => strtolower($sort_dir),
]);
