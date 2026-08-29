<?php
// Design Ref: report.php 성능 개선 — 점포 전체 기준 진단정보 + 부서목록 (기간 무관, 비동기 로드)
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
require_once __DIR__ . '/../lib/pos_report_helper.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$store_id = get_office_store_id();
$conn     = get_db_connection();

$diag      = pos_report_get_diag($conn, $store_id);
$dept_list = pos_report_get_dept_list($conn, $store_id);

$conn->close();

echo json_encode([
    'success'   => true,
    'diag'      => $diag,
    'dept_list' => $dept_list,
]);
