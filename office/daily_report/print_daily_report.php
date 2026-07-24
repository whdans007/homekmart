<?php
// Design Ref: daily-report.design.md §11.2 step 6 — 인쇄용 뷰 (print_er.php 패턴 참고)
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
require_once __DIR__ . '/../lib/daily_report_helper.php';
ob_end_clean();

$is_super_admin = ($_SESSION['role'] ?? '') === 'super_admin';
$store_id = get_office_store_id();
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
if ($is_super_admin) {
    $req_store_id = (int)($_GET['store_id'] ?? 0);
    if ($req_store_id > 0) $store_id = $req_store_id;
}

$conn = get_db_connection();

$store_stmt = $conn->prepare("SELECT name AS label FROM stores WHERE id=?");
$store_stmt->bind_param('i', $store_id);
$store_stmt->execute();
$store_label = $store_stmt->get_result()->fetch_assoc()['label'] ?? 'SUNSET';
$store_stmt->close();

$pos_summary   = get_daily_pos_summary($conn, $store_id, $date);
$credit_detail = get_daily_credit_breakdown($conn, $store_id, $date);
$purchase      = get_daily_purchase_summary($conn, $store_id, $date);
$ar            = get_daily_ar_summary($conn, $store_id, $date);
$wholesale     = get_daily_wholesale_summary($conn, $store_id, $date);
$other_exp     = get_daily_other_expense_categories($conn, $store_id, $date);

$commission_tbl = $conn->query("SHOW TABLES LIKE 'daily_report_commission_companies'");
$commission = ($commission_tbl && $commission_tbl->num_rows > 0)
    ? get_daily_commission_summary($conn, $store_id, $date)
    : ['rows' => [], 'total' => 0.0];

$conn->close();

$grand_sales    = $pos_summary['totals']['total'] + $ar['credit_sales_total'];
$grand_purchase = $purchase['totals']['total'];
$grand_expense  = $other_exp['total_placed'];
$grand_profit   = $grand_sales - $grand_purchase - $grand_expense;

$ts = strtotime($date);
$days_en = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$date_label = date('Y-m-d', $ts) . ' ' . $days_en[date('w', $ts)];
$wholesale_rows = array_merge($wholesale['delivery_k'], $wholesale['whole_sale']);

function fmt2($n) { return number_format((float)$n, 2); }
function esc($s) { return htmlspecialchars((string)($s ?? '')); }
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>Daily Report — <?php echo esc($date_label); ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: Arial, sans-serif; font-size: 8pt; background:#f5f5f5; }
@page { size: A4 landscape; margin: 5mm; }
@media print { body { margin:0; background:white; } .no-print { display:none !important; } }
.no-print { text-align:center; padding:8px; background:#e0e7ff; margin-bottom:6px; border-bottom:1px solid #c7d2fe; }
.no-print button { padding:5px 16px; border:none; border-radius:4px; cursor:pointer; font-size:11px; margin:0 3px; color:white; }
.btn-pr { background:#1d4ed8; }
.btn-cl { background:#6b7280; }
.sheet { width: 277mm; margin: 0 auto; background: white; }
table { border-collapse:collapse; width:100%; table-layout:fixed; margin-bottom:6px; }
td, th { border: 0.4pt solid #444; padding: 2pt 3pt; font-size: 7.5pt; vertical-align: middle; height: 14pt; overflow: hidden; }
.h-title { font-size:16pt; font-weight:bold; font-style:italic; text-align:center; height:24pt; border:none; }
.h-subtitle { font-size:11pt; font-weight:bold; text-align:center; height:18pt; border:none; }
.h-hdr { font-weight:bold; text-align:center; background:#fff; }
.h-date { font-weight:bold; text-align:center; background:#FFFF99; }
.h-val { text-align:right; font-weight:bold; }
.h-profit { text-align:right; font-weight:bold; background:#C6E0B4; }
.sec-hdr { text-align:center; font-weight:bold; font-size:9pt; background:#D9D9D9; height:15pt; }
.col-hdr { text-align:center; font-weight:bold; background:#F2F2F2; }
.amt { text-align:right; }
.total-row { font-weight:bold; text-align:center; background:#F2F2F2; }
.total-amt { text-align:right; font-weight:bold; background:#F2F2F2; }
.gap-col { border:none; background:white; width:1%; }
.note { font-size:7pt; font-style:italic; color:#808080; text-align:center; border:none; }
</style>
</head>
<body>

<div class="no-print">
  <button class="btn-pr" onclick="window.print()">🖶 Print</button>
  <button class="btn-cl" onclick="window.close()">✕ Close</button>
  &nbsp; Date: <strong><?php echo esc($date_label); ?></strong> — <?php echo esc($store_label); ?>
</div>

<div class="sheet">

<table>
<tr><td class="h-subtitle" colspan="9"><?php echo esc(strtoupper($store_label)); ?> DAILY REPORT</td></tr>
</table>

<table>
<colgroup><col style="width:12%"><col style="width:11%"><col style="width:11%"><col style="width:11%"><col style="width:9%"><col style="width:9%"><col style="width:11%"><col style="width:13%"><col style="width:13%"></colgroup>
<tr>
  <td class="h-hdr" rowspan="2">날짜</td>
  <td class="h-hdr" colspan="3">매출</td>
  <td class="h-hdr" colspan="2">매입</td>
  <td class="h-hdr" rowspan="2">기타지출</td>
  <td class="h-hdr">거래처수금</td>
  <td class="h-hdr" rowspan="2">수익</td>
</tr>
<tr>
  <td class="h-hdr">포스+메뉴얼</td><td class="h-hdr">외상거래처</td><td class="h-hdr">수수료 코너</td>
  <td class="h-hdr">현금</td><td class="h-hdr">체크</td>
  <td class="h-hdr">현금</td>
</tr>
<tr>
  <td class="h-date"><?php echo esc($date_label); ?></td>
  <td class="h-val"><?php echo fmt2($pos_summary['totals']['total']); ?></td>
  <td class="h-val"><?php echo $ar['credit_sales_total'] > 0 ? fmt2($ar['credit_sales_total']) : '-'; ?></td>
  <td class="h-val"><?php echo $commission['total'] > 0 ? fmt2($commission['total']) : '-'; ?></td>
  <td class="h-val"><?php echo fmt2($purchase['totals']['cash']); ?></td>
  <td class="h-val"><?php echo fmt2($purchase['totals']['check']); ?></td>
  <td class="h-val"><?php echo fmt2($grand_expense); ?></td>
  <td class="h-val"><?php echo fmt2($ar['collections_total']); ?></td>
  <td class="h-profit"><?php echo fmt2($grand_profit); ?></td>
</tr>
</table>

<table>
<colgroup><col style="width:11%"><col style="width:8%"><col style="width:8%"><col style="width:9%"><col style="width:8%"><col class="gap-col"><col style="width:9%"><col style="width:9%"><col class="gap-col"><col style="width:11%"><col style="width:9%"></colgroup>
<tr>
  <td class="sec-hdr" colspan="5">포스매출</td><td class="gap-col"></td>
  <td class="sec-hdr" colspan="2">수수료 코너</td><td class="gap-col"></td>
  <td class="sec-hdr" colspan="2">기타지출</td>
</tr>
<tr>
  <td class="col-hdr">포스</td><td class="col-hdr">현금</td><td class="col-hdr">크레딧</td><td class="col-hdr">도매</td><td class="col-hdr">합계</td><td class="gap-col"></td>
  <td class="col-hdr">업체명</td><td class="col-hdr">매출</td><td class="gap-col"></td>
  <td class="col-hdr">사용내역</td><td class="col-hdr">사용금액</td>
</tr>
<?php
$categories = array_values($other_exp['categories']);
$cat_keys   = array_keys($other_exp['categories']);
$top_rows   = max(count($pos_summary['rows']), count($categories), count($commission['rows']));
for ($i = 0; $i < $top_rows; $i++):
    $pos = $pos_summary['rows'][$i] ?? null;
    $cat_key = $cat_keys[$i] ?? null;
    $cat_amt = $cat_key !== null ? $other_exp['by_category'][$cat_key] : null;
    $com = $commission['rows'][$i] ?? null;
?>
<tr>
  <td><?php echo $pos ? esc($pos['label']) : ''; ?></td>
  <td class="amt"><?php echo $pos ? fmt2($pos['cash']) : ''; ?></td>
  <td class="amt"><?php echo $pos ? fmt2($pos['credit']) : ''; ?></td>
  <td class="amt"><?php echo ($pos && $pos['delivery_slip'] > 0) ? fmt2($pos['delivery_slip']) : ''; ?></td>
  <td class="amt"><?php echo $pos ? fmt2($pos['total']) : ''; ?></td>
  <td class="gap-col"></td>
  <td><?php echo $com ? esc($com['supplier_name']) : ''; ?></td>
  <td class="amt"><?php echo ($com && $com['amount'] > 0) ? fmt2($com['amount']) : ''; ?></td>
  <td class="gap-col"></td>
  <td><?php echo $cat_key !== null ? esc($categories[$i]) : ''; ?></td>
  <td class="amt"><?php echo ($cat_key !== null && $cat_amt > 0) ? fmt2($cat_amt) : ''; ?></td>
</tr>
<?php endfor; ?>
<tr>
  <td class="total-row" colspan="4">합계</td><td class="total-amt"><?php echo fmt2($pos_summary['totals']['total']); ?></td><td class="gap-col"></td>
  <td class="total-row">합계</td><td class="total-amt"><?php echo fmt2($commission['total']); ?></td><td class="gap-col"></td>
  <td class="total-row">합계</td><td class="total-amt"><?php echo fmt2($other_exp['total_placed']); ?></td>
</tr>
<?php if ($other_exp['unplaced_count'] > 0): ?>
<tr><td class="note" colspan="11">* 미분류 <?php echo (int)$other_exp['unplaced_count']; ?>건은 합계에서 제외됨</td></tr>
<?php endif; ?>
</table>

<table>
<colgroup><col style="width:14%"><col style="width:9%"><col style="width:9%"><col style="width:9%"><col class="gap-col"><col style="width:11%"><col style="width:9%"><col class="gap-col"><col style="width:11%"><col style="width:9%"></colgroup>
<tr>
  <td class="sec-hdr" colspan="4">매입</td><td class="gap-col"></td>
  <td class="sec-hdr" colspan="2">크레딧(CARD, E-MONEY)</td><td class="gap-col"></td>
  <td class="sec-hdr" colspan="2">외상수금</td>
</tr>
<tr>
  <td class="col-hdr">거래처명</td><td class="col-hdr">현금매입</td><td class="col-hdr">체크매입</td><td class="col-hdr">합계</td><td class="gap-col"></td>
  <td class="col-hdr">거래처명</td><td class="col-hdr">금액</td><td class="gap-col"></td>
  <td class="col-hdr">거래처명</td><td class="col-hdr">금액</td>
</tr>
<?php
$credit_buckets = ['BDO', 'GCASH', 'MAYA', 'QR'];
// Layout Ref: 참고 파일은 매입 표에 실제 거래처 외 여유 행을 두어 표 전체가 약 20행 — 매입 표만 이 최소 행수로 패딩
$PURCHASE_MIN_ROWS = 20;
$mid_rows = max(count($purchase['rows']), $PURCHASE_MIN_ROWS, count($credit_buckets), count($ar['collections']));
for ($i = 0; $i < $mid_rows; $i++):
    $p = $purchase['rows'][$i] ?? null;
    $bucket = $credit_buckets[$i] ?? null;
    $col = $ar['collections'][$i] ?? null;
?>
<tr>
  <td><?php echo $p ? esc($p['supplier']) : ''; ?></td>
  <td class="amt"><?php echo ($p && $p['cash'] > 0) ? fmt2($p['cash']) : ''; ?></td>
  <td class="amt"><?php echo ($p && $p['check'] > 0) ? fmt2($p['check']) : ''; ?></td>
  <td class="amt"><?php echo $p ? fmt2($p['total']) : ''; ?></td>
  <td class="gap-col"></td>
  <td><?php echo esc($bucket ?? ''); ?></td>
  <td class="amt"><?php echo ($bucket && $credit_detail[$bucket] > 0) ? fmt2($credit_detail[$bucket]) : ''; ?></td>
  <td class="gap-col"></td>
  <td><?php echo $col ? esc($col['customer_name']) : ''; ?></td>
  <td class="amt"><?php echo $col ? fmt2($col['amount']) : ''; ?></td>
</tr>
<?php endfor; ?>
<tr>
  <td class="total-row" colspan="3">합계</td><td class="total-amt"><?php echo fmt2($purchase['totals']['total']); ?></td><td class="gap-col"></td>
  <td class="total-row">합계</td><td class="total-amt"><?php echo fmt2($credit_detail['total']); ?></td><td class="gap-col"></td>
  <td class="total-row">합계</td><td class="total-amt"><?php echo fmt2($ar['collections_total']); ?></td>
</tr>
</table>

<table>
<colgroup><col style="width:14%"><col style="width:9%"><col style="width:9%"><col class="gap-col"><col style="width:14%"><col style="width:9%"></colgroup>
<tr>
  <td class="sec-hdr" colspan="3">외상 판매</td><td class="gap-col"></td>
  <td class="sec-hdr" colspan="2">도매 판매(거래명세서)</td>
</tr>
<tr>
  <td class="col-hdr">거래처명</td><td class="col-hdr">포스등록</td><td class="col-hdr">거래명세서</td><td class="gap-col"></td>
  <td class="col-hdr">거래처명</td><td class="col-hdr">금액</td>
</tr>
<?php
$bot_rows = max(count($ar['credit_sales']), count($wholesale_rows), 1);
for ($i = 0; $i < $bot_rows; $i++):
    $cs = $ar['credit_sales'][$i] ?? null;
    $ws = $wholesale_rows[$i] ?? null;
?>
<tr>
  <td><?php echo $cs ? esc($cs['customer_name']) : ''; ?></td>
  <td></td>
  <td class="amt"><?php echo $cs ? fmt2($cs['amount']) : ''; ?></td>
  <td class="gap-col"></td>
  <td><?php echo $ws ? esc($ws['customer']) : ''; ?></td>
  <td class="amt"><?php echo $ws ? fmt2($ws['amount']) : ''; ?></td>
</tr>
<?php endfor; ?>
<tr>
  <td class="total-row" colspan="2">합계</td><td class="total-amt"><?php echo fmt2($ar['credit_sales_total']); ?></td><td class="gap-col"></td>
  <td class="total-row">합계</td><td class="total-amt"><?php echo fmt2($wholesale['total']); ?></td>
</tr>
</table>

</div>
</body>
</html>
