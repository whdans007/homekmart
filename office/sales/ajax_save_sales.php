<?php
// Design Ref: §4.1 — AJAX upsert for POS daily sales (shifts only)
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('{"error":"Method not allowed"}'); }

$store_id  = get_office_store_id();
$sale_date = post_date('sale_date');
if (!$sale_date) { echo json_encode(['success'=>false,'error'=>'Invalid date']); exit; }

$gy1   = post_float('gy_pos1');
$gy2   = post_float('gy_pos2');
$mo1   = post_float('morning_pos1');
$mo2   = post_float('morning_pos2');
$mi1   = post_float('mid_pos1');
$mi2   = post_float('mid_pos2');
$notes = post_str('notes');
$by    = (int)($_SESSION['user_id'] ?? 0) ?: null;

$total = $gy1 + $gy2 + $mo1 + $mo2 + $mi1 + $mi2;

$conn = get_db_connection();
$stmt = $conn->prepare(
    "INSERT INTO sales_daily
     (store_id, sale_date, gy_pos1, gy_pos2, morning_pos1, morning_pos2,
      mid_pos1, mid_pos2, notes, created_by)
     VALUES (?,?,?,?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE
       gy_pos1=VALUES(gy_pos1), gy_pos2=VALUES(gy_pos2),
       morning_pos1=VALUES(morning_pos1), morning_pos2=VALUES(morning_pos2),
       mid_pos1=VALUES(mid_pos1), mid_pos2=VALUES(mid_pos2),
       notes=VALUES(notes), updated_at=NOW()"
);
// types: i=store_id, s=sale_date, d*6=pos values, s=notes, i=created_by
$stmt->bind_param('isddddddsi',
    $store_id, $sale_date, $gy1, $gy2, $mo1, $mo2, $mi1, $mi2, $notes, $by
);
$stmt->execute();
$stmt->close();
$conn->close();

echo json_encode(['success'=>true, 'total'=>$total, 'date'=>$sale_date]);
