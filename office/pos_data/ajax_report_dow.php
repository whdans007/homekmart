<?php
// Design Ref: report.php 성능 개선 — 요일별 3개월 평균 매출 (기간 필터 무관, 비동기 로드)
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
require_once __DIR__ . '/../lib/pos_report_helper.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$store_id = get_office_store_id();
$conn     = get_db_connection();

$dow = pos_report_get_dow_averages($conn, $store_id);

$conn->close();

echo json_encode([
    'success' => true,
    'labels'  => $dow['labels'],
    'data'    => $dow['data'],
]);
