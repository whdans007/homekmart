<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['data'])) {
    $payload  = json_decode($_POST['data'], true);
    $date_str = preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload['date'] ?? '') ? $payload['date'] : date('Y-m-d');
    $secs     = $payload['sections'] ?? ['korean'=>[],'local'=>[],'fixed'=>[],'others'=>[]];
} else {
    $date_str = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
    $secs     = ['korean'=>[],'local'=>[],'fixed'=>[],'others'=>[]];
}

$ts    = strtotime($date_str);
$year  = date('Y', $ts);
$month = date('n', $ts);
$day   = date('j', $ts);

// 인쇄 제목에 사용할 실제 회사명(상호) — stores.company_name 우선, 없으면 점포명으로 대체
$company_display = '';
$store_id = get_office_store_id();
if ($store_id > 0) {
    $conn = get_db_connection();
    $stmt = $conn->prepare("SELECT company_name, name FROM stores WHERE id = ?");
    $stmt->bind_param('i', $store_id);
    $stmt->execute();
    $store_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    $company_display = trim($store_row['company_name'] ?? '') ?: trim($store_row['name'] ?? '');
}
if ($company_display === '') {
    $company_display = trim($_SESSION['store_name'] ?? '') ?: 'HOME K MART';
}

// PREPARED: 현재 접속자 / APPROVED: 해당 점포 점장(branch_manager)
$prepared_by = trim($_SESSION['full_name'] ?? '') ?: trim($_SESSION['username'] ?? '') ?: '-';
$approved_by = get_store_manager_name($store_id) ?: '-';

function e($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function a($n) { return $n != 0 ? number_format((float)$n, 2) : ''; }
function aT($n) { return number_format((float)$n, 2); }
function fmtD($s) {
    if (!$s) return '';
    $p = explode('-', $s);
    return ($p[1] ?? '') . '/' . ($p[2] ?? '');
}

$section_config = [
    'korean' => ['label'=>'1. KOREAN',        'rows'=>8],
    'local'  => ['label'=>'2. LOCAL',          'rows'=>8],
    'fixed'  => ['label'=>'3. FIXED EXPENSE',  'rows'=>5],
    'others' => ['label'=>'4. OTHERS',          'rows'=>8],
];

$sumSec = fn($s) => array_sum(array_map(
    fn($r) => ($r['returned'] ?? false) ? 0 : (float)($r['amount'] ?? 0),
    $secs[$s] ?? []
));
$grand = array_sum(array_map($sumSec, array_keys($section_config)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Cheque Expense Report — <?php echo e($date_str); ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:Arial,sans-serif; font-size:7.5pt; background:#ddd; }
@page { size:A4 landscape; margin:6mm; }
@media print {
    body { background:white; }
    .no-print { display:none !important; }
}
.no-print {
    text-align:center; padding:8px; background:#fef3c7;
    margin-bottom:8px; border-bottom:1px solid #fcd34d;
}
.no-print button {
    padding:5px 16px; border:none; border-radius:4px;
    cursor:pointer; font-size:11px; margin:0 3px; color:white;
}
.btn-pr { background:#7c3aed; }
.btn-cl { background:#6b7280; }

.sheet { width:285mm; margin:0 auto; background:white; }
table { border-collapse:collapse; width:100%; }
td, th {
    border:0.6pt solid #555;
    padding:1pt 3pt;
    font-size:7pt;
    vertical-align:middle;
    overflow:hidden;
}

/* ── Title ── */
.title-main {
    background:#E8A598; font-size:17pt; font-weight:bold;
    text-align:center; height:45pt; letter-spacing:0.5pt;
    vertical-align:middle;
}
.title-prep {
    background:white; font-size:7pt; font-weight:bold;
    text-align:center;
}

/* ── Meta (YEAR/MONTH/DAY) ── */
.meta {
    background:#f0f0f0; font-size:7.5pt; font-weight:bold;
    text-align:center; height:14pt;
}

/* ── Column headers ── */
.colhdr {
    background:#d9d9d9; font-size:7pt; font-weight:bold;
    text-align:center; height:14pt;
}

/* ── Section label ── */
.sec-lbl {
    background:white; font-size:6pt; font-weight:bold;
    text-align:center; vertical-align:top;
    white-space:normal; word-break:break-word;
    line-height:1.3; padding:2pt 1pt;
}
.sec-lbl .sec-total {
    display:block; font-size:5.5pt; font-weight:bold;
    font-family:'Courier New',monospace; margin-top:2pt;
    color:#991b1b;
}

/* ── Data rows ── */
.dr           { height:15pt; }
.dr td        { font-size:7pt; }
.n            { text-align:center; color:#333; }
.ctr          { text-align:center; }
.r            { text-align:right; font-family:'Courier New',monospace; white-space:nowrap; }
.amt-big      { font-weight:bold; font-size:8pt; }
.dr.ret-row   { background:#fff1f2; }
.dr.rei-row   { background:#eff6ff; border-left:2pt solid #3b82f6; }
.reissued     { color:#7c3aed; font-weight:bold; }

/* ── Totals ── */
.tot-lbl { text-align:right; font-weight:bold; font-size:7.5pt; height:14pt; }
.tot-val { text-align:right; font-family:'Courier New',monospace; font-weight:bold; font-size:7.5pt; white-space:nowrap; }

/* ── Note ── */
.note-row { height:18pt; vertical-align:top; padding-top:2pt; }
</style>
</head>
<body>

<div class="no-print">
  <button class="btn-pr" onclick="window.print()">&#128438; Print</button>
  <button class="btn-cl" onclick="window.close()">Close</button>
  &nbsp;<span style="font-size:11px;color:#555;">A4 Landscape &mdash; Cheque Expense Report &mdash; <?php echo e($date_str); ?></span>
</div>

<div class="sheet">
<table>

<!-- ── COLGROUP (10 physical columns) ── -->
<!-- 1:SecLabel 2:NO 3:CheckNo 4:Sup-A 5:Sup-B 6:Date 7:SalesInv 8:Par-A 9:Par-B 10:Amount -->
<colgroup>
  <col style="width:45pt">  <!-- 1: Section label -->
  <col style="width:20pt">  <!-- 2: NO. -->
  <col style="width:68pt">    <!-- 3: CHECK NUMBER (-20%) -->
  <col style="width:89.6pt">  <!-- 4: SUPPLIER A (-20%) -->
  <col style="width:89.6pt">  <!-- 5: SUPPLIER B (-20%) -->
  <col style="width:48pt">    <!-- 6: DATE -->
  <col style="width:133.8pt"> <!-- 7: SALES INVOICE (+61.8pt = 줄어든 폭만큼 증가) -->
  <col style="width:112pt"> <!-- 8: PARTICULAR A -->
  <col style="width:65pt">  <!-- 9: PARTICULAR B -->
  <col style="width:65pt">  <!-- 10: AMOUNT -->
</colgroup>

<!-- ── TITLE: colspan=8 rowspan=2 + PREPARED | APPROVED ── -->
<tr>
  <td colspan="8" rowspan="2" class="title-main">
    CHEQUE EXPENSE REPORT<br>
    <span style="font-size:13pt;font-weight:bold">(<?php echo e(strtoupper($company_display)); ?>)</span>
  </td>
  <td class="title-prep">PREPARED</td>
  <td class="title-prep">APPROVED</td>
</tr>
<tr>
  <td class="title-prep" rowspan="2"><?php echo e($prepared_by); ?></td>
  <td class="title-prep" rowspan="2"><?php echo e($approved_by); ?></td>
</tr>

<!-- ── META ROW: 날짜 병합 표시 ── -->
<tr>
  <td colspan="8" class="meta" style="font-size:11pt;"><?php echo date('d M Y', $ts); ?></td>
</tr>

<!-- ── COLUMN HEADERS ── -->
<tr>
  <th class="colhdr"></th>
  <th class="colhdr">NO.</th>
  <th class="colhdr">CHECK NUMBER</th>
  <th class="colhdr" colspan="2">SUPPLIER NAME</th>
  <th class="colhdr">DATE</th>
  <th class="colhdr">SALES INVOICE</th>
  <th class="colhdr" colspan="2">PARTICULAR</th>
  <th class="colhdr">AMOUNT</th>
</tr>

<!-- ── SECTION DATA ROWS ── -->
<?php
$row_no = 1;
foreach ($section_config as $sec_key => $sc):
    $data_rows = $secs[$sec_key] ?? [];
    $max_rows  = $sc['rows'];
    $sec_total = $sumSec($sec_key);
    for ($i = 0; $i < $max_rows; $i++):
        $row    = $data_rows[$i] ?? null;
        $cur_no = $row_no++;
        $is_ret = $row && (!empty($row['returned']) || !empty($row['is_return']));
        $is_rei = $row && !empty($row['is_reissue']);

        $chk = '';
        if ($row) {
            if ($is_ret && !empty($row['new_check_no'])) {
                $chk = '<s>' . e($row['check_no'] ?? '') . '</s> <span class="reissued">RE:' . e($row['new_check_no']) . '</span>';
            } elseif ($is_ret) {
                $chk = '<s>' . e($row['check_no'] ?? '') . '</s>';
            } elseif ($is_rei) {
                $chk = '<span class="reissued">' . e($row['check_no'] ?? '') . '</span>';
            } else {
                $chk = e($row['check_no'] ?? '');
            }
        }
        $tr_class = 'dr' . ($is_ret ? ' ret-row' : '') . ($is_rei ? ' rei-row' : '');
?>
<tr class="<?php echo $tr_class; ?>">
  <?php if ($i === 0): ?>
  <td class="sec-lbl" rowspan="<?php echo $max_rows; ?>">
    <?php echo e($sc['label']); ?>
    <?php if ($sec_total > 0): ?>
    <span class="sec-total"><?php echo aT($sec_total); ?></span>
    <?php endif; ?>
  </td>
  <?php endif; ?>
  <td class="n"><?php echo $cur_no; ?></td>
  <td class="ctr"><?php echo $row ? $chk : '&nbsp;'; ?></td>
  <td colspan="2"><?php echo $row ? e(strtoupper($row['supplier'] ?? '')) : '&nbsp;'; ?></td>
  <td class="ctr"><?php echo $row ? fmtD($row['date'] ?? '') : '&nbsp;'; ?></td>
  <td class="ctr"><?php echo $row ? e($row['sales_invoice'] ?? '') : '&nbsp;'; ?></td>
  <td colspan="2"><?php echo $row ? e($row['particular'] ?? '') : '&nbsp;'; ?></td>
  <?php $is_big = $row && !$is_ret && (float)($row['amount'] ?? 0) >= 50000; ?>
  <td class="r<?php echo $is_big ? ' amt-big' : ''; ?>"><?php echo $row ? ($is_ret ? '' : a($row['amount'] ?? 0)) : '&nbsp;'; ?></td>
</tr>
<?php
    endfor;
endforeach;
?>

<!-- ── TOTAL ── -->
<tr>
  <td colspan="9" class="tot-lbl">TOTAL EXPENSES:</td>
  <td class="tot-val"><?php echo aT($grand); ?></td>
</tr>

<!-- ── NOTE ── -->
<tr>
  <td class="note-row" colspan="10">Note:</td>
</tr>

</table>
</div>

</body>
</html>
