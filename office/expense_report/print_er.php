<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';

// Design Ref: main-office-reports — 메인 오피스(전 점포 열람)에서 mo_store_id로 특정 점포를
// 지정해 조회하는 경우, 쓰기 권한(office_permission) 없이도 main_office_admin 이상이면 읽기 전용 접근 허용.
$mo_store_id = (int)($_GET['mo_store_id'] ?? 0);
$is_mo_view  = $mo_store_id > 0 && is_main_office_admin();

if (!$is_mo_view) {
    require_office_permission();
}
ob_end_clean();

if ($is_mo_view) {
    $date_str = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
    $mo_state = get_saved_report_state('er_saved_state', $mo_store_id, $date_str);
    $secs     = $mo_state['sections'] ?? [];
} elseif ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['data'])) {
    $payload  = json_decode($_POST['data'], true);
    $date_str = preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload['date']??'') ? $payload['date'] : date('Y-m-d');
    $secs     = $payload['sections'] ?? [];
} else {
    $date_str = date('Y-m-d');
    $secs     = [];
}

$ts       = strtotime($date_str);
$days_en  = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
$date_label = date('M j, Y ', $ts) . '(' . $days_en[date('w', $ts)] . ')';

// PREPARED BY = 현재 로그인 사용자(단, main_office 열람 시엔 그 점포의 대표직원), APPROVED = 점포 점장(센터장)
// Design Ref: main_office 결제란(PREPARED) 대표직원 지정 기능
$office_store_id = $is_mo_view ? $mo_store_id : get_office_store_id();
$prepared_by = $is_mo_view
    ? (get_store_representative_name($office_store_id) ?: trim($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''))
    : trim($_SESSION['full_name'] ?? $_SESSION['username'] ?? '');
$approved_by = get_store_manager_name($office_store_id);

// 제목에 사용할 실제 회사명(상호) — stores.company_name 우선, 없으면 점포명으로 대체
$store_name = '';
if ($office_store_id > 0) {
    $conn = get_db_connection();
    $store_stmt = $conn->prepare("SELECT company_name, name FROM stores WHERE id = ?");
    $store_stmt->bind_param('i', $office_store_id);
    $store_stmt->execute();
    $store_row = $store_stmt->get_result()->fetch_assoc();
    $store_stmt->close();
    $conn->close();
    $store_name = trim($store_row['company_name'] ?? '') ?: trim($store_row['name'] ?? '');
}
if ($store_name === '') $store_name = 'SUNSET';

// State JSON → 기존 형식에 맞는 배열로 변환
$cash_rows            = $secs['selling']          ?? [];
$consumable_rows      = $secs['not_selling']       ?? [];
$check_rows           = $secs['check_sup']         ?? [];
// 구 데이터 하위 호환: other_exp → other_exp_cash
$other_exp_check_rows = $secs['other_exp_check']   ?? [];
$other_exp_cash_rows  = array_merge($secs['other_exp_cash'] ?? [], $secs['other_exp'] ?? []);

$cash_total             = array_sum(array_column($cash_rows,            'amount'));
$check_total            = array_sum(array_column($check_rows,           'amount'));
$consumable_total       = array_sum(array_column($consumable_rows,      'amount'));
$other_exp_check_total  = array_sum(array_column($other_exp_check_rows, 'amount'));
$other_exp_cash_total   = array_sum(array_column($other_exp_cash_rows,  'amount'));
$other_exp_total        = $other_exp_check_total + $other_exp_cash_total;
$equip_total            = $consumable_total + $other_exp_total;
$grand_total            = $cash_total + $consumable_total;

$MAX_DATA  = max(10, count($cash_rows), count($consumable_rows));

// OTHER EXPENSES 우측 테이블: sub-header + data 혼합 배열
$has_oe_check = !empty($other_exp_check_rows);
$has_oe_cash  = !empty($other_exp_cash_rows);
$right_items  = [];
if ($has_oe_check) {
    if ($has_oe_cash) $right_items[] = ['_t'=>'hdr', 'label'=>'수표 (CHECK)'];
    foreach ($other_exp_check_rows as $r) $right_items[] = $r + ['_t'=>'data'];
    if ($has_oe_cash) $right_items[] = ['_t'=>'sub', 'amount'=>$other_exp_check_total, 'label'=>'소계(수표)'];
}
if ($has_oe_cash) {
    if ($has_oe_check) $right_items[] = ['_t'=>'hdr', 'label'=>'현금 (CASH)'];
    foreach ($other_exp_cash_rows as $r) $right_items[] = $r + ['_t'=>'data'];
    if ($has_oe_check) $right_items[] = ['_t'=>'sub', 'amount'=>$other_exp_cash_total, 'label'=>'소계(현금)'];
}
$MAX_CHECK = max(10, count($check_rows), count($right_items));

function fmt($n) { return number_format((float)$n, 2); }
function esc($s) { return htmlspecialchars((string)($s ?? '')); }
// 원본 날짜가 ER 날짜와 다르면 details 앞에 날짜 표기 (예: [05/03] )
function row_details($row, $er_date) {
    $d = $row['date'] ?? '';
    $prefix = ($d && $d !== $er_date) ? '[' . date('m/d', strtotime($d)) . '] ' : '';
    return esc($prefix . ($row['details'] ?? ''));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Expense Daily Report — <?php echo esc($date_label); ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: Arial, sans-serif; font-size: 8pt; background:#f5f5f5; }
@page { size: A4 landscape; margin: 5mm; }
@media print {
  body { margin:0; background:white; }
  .no-print { display:none !important; }
}
.no-print {
  text-align:center; padding:8px; background:#e0e7ff; margin-bottom:6px;
  border-bottom:1px solid #c7d2fe;
}
.no-print button {
  padding:5px 16px; border:none; border-radius:4px; cursor:pointer;
  font-size:11px; margin:0 3px; color:white;
}
.btn-pr { background:#1d4ed8; }
.btn-cl { background:#6b7280; }
.sheet { width: 278mm; margin: 0 auto; background: white; }
table { border-collapse:collapse; width:100%; table-layout:fixed; }
col.cv  { width: 13%; }
col.sup { width: 8.5%; }
col.sup2{ width: 8.5%; }
col.con { width: 7.5%; }
col.con2{ width: 7.5%; }
col.con3{ width: 7.5%; }
col.con4{ width: 7.5%; }
col.amt { width: 9%; }
col.amt2{ width: 9%; }
col.gap { width: 2%; }
col.rcv { width: 13%; }
col.rsp { width: 8.5%; }
col.rsp2{ width: 8.5%; }
col.rco { width: 7.5%; }
col.rc2 { width: 7.5%; }
col.rc3 { width: 7.5%; }
col.rc4 { width: 7.5%; }
col.rat { width: 9%; }
col.ra2 { width: 9%; }
td, th {
  border: 0.4pt solid #444;
  padding: 0 2pt;
  font-size: 7.5pt;
  vertical-align: middle;
  height: 14pt;
  overflow: hidden;
  white-space: nowrap;
}
td.gap { border:none; background:white; padding:0; }
.bg-teal   { background:#B8D4E8; }
.bg-blue   { background:#C6D9F1; }
.bg-yellow { background:#FFFF99; }
.bg-orange { background:#FFC000; }
.bg-gray   { background:#D9D9D9; }
.bg-white  { background:white; }
.bold { font-weight:bold; }
.ctr  { text-align:center; }
.rgt  { text-align:right; }
.lft  { text-align:left; }
.red  { color:#CC0000; }
.mono { font-family:'Courier New',monospace; }
.h-title { font-size:10pt; font-weight:bold; text-align:center; height:18pt; }
.h-banner{ font-size:10.5pt; font-weight:bold; text-align:center; height:17pt; color:#1F3864; }
.h-hdr   { font-size:7pt; font-weight:bold; text-align:center; }
.h-colhd { font-size:8pt; font-weight:bold; text-align:center; height:15pt; }
.h-name  { height:35pt; }
.h-thin  { height:4pt; }
</style>
<script>
window.addEventListener('load', function () {
    var minPx = 5.5 * 96 / 72;
    document.querySelectorAll('td').forEach(function (td) {
        if (td.classList.contains('gap')) return;
        if (td.colSpan >= 7) return;
        var iter = 0;
        while (td.scrollWidth > td.offsetWidth + 1 && iter < 30) {
            var cur = parseFloat(getComputedStyle(td).fontSize);
            if (cur <= minPx) break;
            td.style.fontSize = (cur - 0.5) + 'px';
            iter++;
        }
    });
});
</script>
</head>
<body>

<div class="no-print">
  <button class="btn-pr" onclick="window.print()">&#128438; Print</button>
  <button class="btn-cl" onclick="window.close()">✕ Close</button>
  &nbsp; Date: <strong><?php echo esc($date_label); ?></strong>
</div>

<div class="sheet">
<table>
<colgroup>
  <col class="cv"><col class="sup"><col class="sup2">
  <col class="con"><col class="con2"><col class="con3"><col class="con4">
  <col class="amt"><col class="amt2">
  <col class="gap">
  <col class="rcv"><col class="rsp"><col class="rsp2">
  <col class="rco"><col class="rc2"><col class="rc3"><col class="rc4">
  <col class="rat"><col class="ra2">
</colgroup>

<!-- Row 1: 제목 + 이름 -->
<tr>
  <td colspan="3" rowspan="2" class="h-title bg-teal"><?php echo esc(strtoupper($store_name)); ?></td>
  <td colspan="2" class="h-hdr">PREPARED BY:</td>
  <td colspan="2" class="h-hdr">CHECKED:</td>
  <td colspan="2" class="h-hdr">APPROVED:</td>
  <td class="gap"></td>
  <td colspan="3" rowspan="2" class="h-title bg-teal"><?php echo esc(strtoupper($store_name)); ?></td>
  <td colspan="2" class="h-hdr">PREPARED BY:</td>
  <td colspan="2" class="h-hdr">CHECKED:</td>
  <td colspan="2" class="h-hdr">APPROVED:</td>
</tr>
<tr class="h-name">
  <td colspan="2" class="bold ctr" style="font-size:9pt;"><?php echo esc($prepared_by); ?></td>
  <td colspan="2" class="ctr"></td>
  <td colspan="2" class="bold ctr" style="font-size:9pt;"><?php echo esc($approved_by); ?></td>
  <td class="gap"></td>
  <td colspan="2" class="bold ctr" style="font-size:9pt;"><?php echo esc($prepared_by); ?></td>
  <td colspan="2" class="ctr"></td>
  <td colspan="2" class="bold ctr" style="font-size:9pt;"><?php echo esc($approved_by); ?></td>
</tr>

<!-- 배너 -->
<tr>
  <td colspan="9" class="h-banner bg-teal">BUYING EXPENSES</td>
  <td class="gap"></td>
  <td colspan="9" class="h-banner bg-teal">OTHER EXPENSES</td>
</tr>
<tr><td colspan="9" class="h-thin bg-white" style="border:none;"></td><td class="gap"></td><td colspan="9" class="h-thin bg-white" style="border:none;"></td></tr>

<!-- DATE -->
<tr>
  <td colspan="3" class="bold ctr bg-gray">DATE:</td>
  <td colspan="6" class="bold ctr bg-gray"><?php echo esc($date_label); ?></td>
  <td class="gap"></td>
  <td colspan="3" class="bold ctr bg-gray">DATE:</td>
  <td colspan="6" class="bold ctr bg-gray"><?php echo esc($date_label); ?></td>
</tr>

<!-- BEGINNING BALANCE -->
<tr>
  <td colspan="3" class="bold lft bg-gray">BEGINNING BALANCE:</td>
  <td colspan="6" class="bg-gray"></td>
  <td class="gap"></td>
  <td colspan="3" class="bold lft bg-gray">BEGINNING BALANCE:</td>
  <td colspan="6" class="bg-gray"></td>
</tr>
<tr>
  <td colspan="3" class="bg-gray" style="border:none;"></td>
  <td colspan="6" class="bg-gray" style="border:none;"></td>
  <td class="gap"></td>
  <td colspan="3" class="bold lft bg-gray">ADDITIONAL AMOUNT:</td>
  <td colspan="6" class="bg-gray"></td>
</tr>
<tr>
  <td colspan="3" class="bold lft bg-gray">TOTAL AMOUNT:</td>
  <td colspan="6" class="bold rgt mono bg-gray"><?php echo fmt($cash_total + $check_total); ?></td>
  <td class="gap"></td>
  <td colspan="3" class="bold lft bg-gray">TOTAL AMOUNT:</td>
  <td colspan="6" class="bold rgt mono bg-gray"><?php echo fmt($equip_total); ?></td>
</tr>

<tr><td colspan="9" class="h-thin" style="border:none;background:white;"></td><td class="gap"></td><td colspan="9" class="h-thin" style="border:none;background:white;"></td></tr>

<!-- 컬럼 헤더 -->
<tr>
  <td class="h-colhd bg-blue">CV NO.</td>
  <td colspan="6" class="h-colhd bg-blue">PARTICULARS&nbsp;&nbsp;(CASH SELLING)</td>
  <td colspan="2" class="h-colhd bg-blue">AMOUNT</td>
  <td class="gap"></td>
  <td class="h-colhd bg-blue">CV NO.</td>
  <td colspan="6" class="h-colhd bg-blue">PARTICULARS&nbsp;&nbsp;(CASH NOT SELLING)</td>
  <td colspan="2" class="h-colhd bg-blue">AMOUNT</td>
</tr>

<!-- 현금(좌) / 소모품(우) -->
<?php for ($i = 0; $i < $MAX_DATA; $i++):
  $lft = $cash_rows[$i]       ?? null;
  $rgt = $consumable_rows[$i] ?? null;
?>
<tr>
  <td class="ctr"><?php echo $lft ? esc($lft['cv_no'] ?? '') : ''; ?></td>
  <td colspan="3" class="ctr"><?php echo $lft ? esc(strtoupper($lft['supplier'] ?? '')) : ''; ?></td>
  <td colspan="3" class="ctr"><?php echo $lft ? strtoupper(row_details($lft, $date_str)) : ''; ?></td>
  <td colspan="2" class="rgt mono<?php echo ($lft && (float)$lft['amount'] >= 50000) ? ' bold' : ''; ?>"><?php echo $lft ? fmt($lft['amount']) : ''; ?></td>
  <td class="gap"></td>
  <td class="ctr"><?php echo $rgt ? esc($rgt['cv_no'] ?? '') : ''; ?></td>
  <td colspan="3" class="ctr"><?php echo $rgt ? esc(strtoupper($rgt['supplier'] ?? '')) : ''; ?></td>
  <td colspan="3" class="ctr"><?php echo $rgt ? strtoupper(row_details($rgt, $date_str)) : ''; ?></td>
  <td colspan="2" class="rgt mono<?php echo ($rgt && (float)$rgt['amount'] >= 50000) ? ' bold' : ''; ?>"><?php echo $rgt ? fmt($rgt['amount']) : ''; ?></td>
</tr>
<?php endfor; ?>

<!-- TOTAL -->
<tr class="bold">
  <td colspan="7" class="rgt bg-yellow bold">TOTAL:</td>
  <td colspan="2" class="rgt mono bg-yellow bold"><?php echo fmt($cash_total); ?></td>
  <td class="gap"></td>
  <td colspan="7" class="rgt bg-yellow bold">TOTAL:</td>
  <td colspan="2" class="rgt mono bg-yellow bold"><?php echo $consumable_total ? fmt($consumable_total) : ''; ?></td>
</tr>
<tr>
  <td colspan="9" style="border:none;background:white;"></td>
  <td class="gap"></td>
  <td colspan="7" class="rgt bg-yellow bold">CASH TOTAL:</td>
  <td colspan="2" class="rgt mono bg-yellow bold"><?php echo fmt($grand_total); ?></td>
</tr>

<!-- CHECK(좌) / OTHER EXPENSES 통합(우) 헤더 -->
<tr>
  <td class="h-colhd bg-orange">CHECK NO.</td>
  <td colspan="6" class="h-colhd bg-orange">SUPLIERS&nbsp;&nbsp;(PAY THRU CHECK)</td>
  <td colspan="2" class="h-colhd bg-orange">AMOUNT CHECK</td>
  <td class="gap"></td>
  <td class="h-colhd bg-orange">CV NO.</td>
  <td colspan="6" class="h-colhd bg-orange">OTHER EXPENSES&nbsp;&nbsp;(SALARY/SSS/ELECTRIC/WATER/INTERNET/GARBAGE)</td>
  <td colspan="2" class="h-colhd bg-orange">AMOUNT</td>
</tr>

<!-- 수표공급처(좌) / 기타비용 통합(우) — right_items에 sub-header·subtotal 포함 -->
<?php for ($i = 0; $i < $MAX_CHECK; $i++):
  $chk = $check_rows[$i]  ?? null;
  $ri  = $right_items[$i] ?? null;
  $rt  = $ri['_t']        ?? null;
?>
<tr>
  <!-- LEFT: check suppliers -->
  <td class="ctr"><?php echo $chk ? esc($chk['cv_no'] ?? '') : ''; ?></td>
  <td colspan="3" class="ctr"><?php echo $chk ? esc(strtoupper($chk['supplier'] ?? '')) : ''; ?></td>
  <td colspan="3" class="ctr"><?php echo $chk ? strtoupper(row_details($chk, $date_str)) : ''; ?></td>
  <td colspan="2" class="rgt mono<?php echo ($chk && (float)$chk['amount'] >= 50000) ? ' bold' : ''; ?>"><?php echo $chk ? fmt($chk['amount']) : ''; ?></td>
  <td class="gap"></td>
  <!-- RIGHT: other expenses (sub-header / subtotal / data) -->
  <?php if ($rt === 'hdr'): ?>
  <td colspan="9" class="bold ctr" style="background:#FFE4B5;font-size:7pt;letter-spacing:.5px"><?php echo esc($ri['label']); ?></td>
  <?php elseif ($rt === 'sub'): ?>
  <td colspan="7" class="rgt bold" style="background:#FFFACD"><?php echo esc($ri['label']); ?>:</td>
  <td colspan="2" class="rgt mono bold" style="background:#FFFACD"><?php echo fmt($ri['amount']); ?></td>
  <?php else: ?>
  <td class="ctr"><?php echo $ri ? esc($ri['cv_no'] ?? '') : ''; ?></td>
  <td colspan="3" class="ctr"><?php echo $ri ? esc(strtoupper($ri['supplier'] ?? '')) : ''; ?></td>
  <td colspan="3" class="ctr"><?php echo $ri ? strtoupper(row_details($ri, $date_str)) : ''; ?></td>
  <td colspan="2" class="rgt mono<?php echo ($ri && (float)($ri['amount'] ?? 0) >= 50000) ? ' bold' : ''; ?>"><?php echo $ri ? fmt($ri['amount']) : ''; ?></td>
  <?php endif; ?>
</tr>
<?php endfor; ?>

<!-- TOTAL -->
<tr class="bold">
  <td colspan="7" class="rgt bg-yellow bold">TOTAL:</td>
  <td colspan="2" class="rgt mono bg-yellow bold"><?php echo $check_total ? fmt($check_total) : ''; ?></td>
  <td class="gap"></td>
  <td colspan="7" class="rgt bg-yellow bold">TOTAL:</td>
  <td colspan="2" class="rgt mono bg-yellow bold"><?php echo $other_exp_total ? fmt($other_exp_total) : ''; ?></td>
</tr>

<!-- CASH ON HAND -->
<tr>
  <td colspan="9" class="bold ctr red" style="font-size:7.5pt;">CASH ON HAND : 50,000.00&nbsp;&nbsp;(FOR BILLS)</td>
  <td class="gap"></td>
  <td colspan="9" class="bold ctr red" style="font-size:7.5pt;">CASH ON HAND : 50,000.00&nbsp;&nbsp;(FOR BILLS)</td>
</tr>

</table>
</div>
</body>
</html>
