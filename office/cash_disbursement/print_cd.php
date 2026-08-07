<?php
// Design Ref: §7.1 — Cash Disbursement print — 1 page, 2 tables (1.KOREAN+2.LOCAL / 3.FIXED+4.OTHERS, each with its own 결제란), 8 lines per section
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

// Support POST (from Print button), main_office cross-store view, and GET (direct test access)
if ($is_mo_view) {
    $date_str = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
    $mo_state = get_saved_report_state('cd_saved_state', $mo_store_id, $date_str);
    $secs     = $mo_state['sections'] ?? ['korean'=>[],'local'=>[],'fixed'=>[],'others'=>[]];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['data'])) {
    $payload  = json_decode($_POST['data'], true);
    $date_str = preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload['date'] ?? '') ? $payload['date'] : date('Y-m-d');
    $secs     = $payload['sections'] ?? ['korean'=>[],'local'=>[],'fixed'=>[],'others'=>[]];
} else {
    // Blank form preview (GET access for testing)
    $date_str = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
    $secs     = ['korean'=>[],'local'=>[],'fixed'=>[],'others'=>[]];
}

$ts    = strtotime($date_str);
$year  = date('Y', $ts);
$month = date('n', $ts);
$day   = date('j', $ts);

// PREPARED = 현재 로그인 사용자(단, main_office 열람 시엔 그 점포의 대표직원), APPROVED = 점포 점장(센터장)
// Design Ref: main_office 결제란(PREPARED) 대표직원 지정 기능
$office_store_id = $is_mo_view ? $mo_store_id : get_office_store_id();
$prepared_by = $is_mo_view
    ? (get_store_representative_name($office_store_id) ?: trim($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''))
    : trim($_SESSION['full_name'] ?? $_SESSION['username'] ?? '');
$approved_by = get_store_manager_name($office_store_id);

// 제목에 사용할 실제 회사명(상호) — stores.company_name 우선, 없으면 점포명으로 대체
$company_display = '';
if ($office_store_id > 0) {
    $conn = get_db_connection();
    $store_stmt = $conn->prepare("SELECT company_name, name FROM stores WHERE id = ?");
    $store_stmt->bind_param('i', $office_store_id);
    $store_stmt->execute();
    $store_row = $store_stmt->get_result()->fetch_assoc();
    $store_stmt->close();
    $conn->close();
    $company_display = trim($store_row['company_name'] ?? '') ?: trim($store_row['name'] ?? '');
}
if ($company_display === '') {
    $company_display = trim($_SESSION['store_name'] ?? '') ?: 'HOME K MART';
}

function e($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function a($n) { return $n != 0 ? number_format((float)$n, 2) : ''; }
function aT($n) { return number_format((float)$n, 2); }

// ── Section labels ──
$labels = [
    'korean'      => '1. KOREAN',
    'local'       => '2. LOCAL',
    'fixed'       => '3. FIXED EXPENSES',
    'others'      => '4. OTHERS',
];

// ── Totals ──
$sum      = fn($s) => array_sum(array_column($secs[$s] ?? [], 'amount'));
$k=$sum('korean'); $l=$sum('local'); $f=$sum('fixed'); $o=$sum('others');
$supplier = $k + $l;
$other_e  = $f + $o;
$grand    = $supplier + $other_e;

// ── Table definitions ──
// 한 페이지에 별도의 테이블 2개: 1.KOREAN+2.LOCAL (결제란 1) / 3.FIXED+4.OTHERS (결제란 2) — 섹션당 8라인씩
$tables = [
    [
        'sections' => ['korean', 'local'],
        'target'   => 8,   // 섹션별 최소 출력 라인 수 (항목이 더 많으면 그만큼 늘어남)
        'totals'   => [ ['SUPPLIER TOTAL', $supplier] ],
    ],
    [
        'sections' => ['fixed', 'others'],
        'target'   => 8,
        'totals'   => [ ['OTHER EXPENSES', $other_e], ['GRAND TOTAL', $grand] ],
    ],
];

// 섹션별로 최소 $target 라인을 확보하고, 실제 항목 수가 더 많으면 그만큼 출력한다.
function alloc_rows($sec_keys, $secs, $target) {
    $rows = [];
    foreach ($sec_keys as $key) {
        $rows[$key] = max(count($secs[$key] ?? []), $target);
    }
    return $rows;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Cash Disbursement — <?php echo e($date_str); ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:Arial,sans-serif; font-size:7.5pt; background:#ddd; }
@page { size:A4 portrait; margin:6mm; }
@media print {
    body { background:white; }
    .no-print { display:none !important; }
    /* 두 테이블 모두 한 페이지 안에 출력 — 표 중간이 페이지 경계에서 잘리지 않도록만 방지 */
    .sheet { page-break-inside:avoid; }
}
.no-print {
    text-align:center; padding:8px; background:#fef3c7;
    margin-bottom:8px; border-bottom:1px solid #fcd34d;
}
.no-print button {
    padding:5px 16px; border:none; border-radius:4px;
    cursor:pointer; font-size:11px; margin:0 3px; color:white;
}
.btn-pr { background:#d97706; }
.btn-cl { background:#6b7280; }

/* 시트: 페이지 폭을 꽉 채움 */
.sheet { width:100%; max-width:190mm; margin:0 auto 8mm; background:white; padding:0; }

/* ── Table base ── */
table { border-collapse:collapse; width:100%; table-layout:fixed; }
td, th {
    border:0.6pt solid #555;
    padding:1pt 3pt;
    font-size:7pt;
    vertical-align:middle;
    overflow:hidden;
}

/* ── Title ── */
.title-main {
    background:#E8A598; font-size:11.5pt; font-weight:bold;
    text-align:center; height:45pt; letter-spacing:0.5pt;
    vertical-align:middle;
}
.title-prep {
    background:white; font-size:7pt; font-weight:bold;
    text-align:center; border-left:0.6pt solid #555;
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

/* ── Section label ── horizontal, no color ── */
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

/* ── Data rows — 18pt to fill A4 ── */
.dr         { height:18pt; }
.dr .n      { text-align:center; color:#333; font-size:7pt; }
.dr .c      { text-align:center; font-size:7pt; }
.dr .r      { text-align:right; font-family:'Courier New',monospace; font-size:7pt; white-space:nowrap; }
.amt-big    { font-weight:bold; }

/* ── Totals ── */
.tot-lbl    { text-align:right; font-weight:bold; font-size:7.5pt; height:14pt; }
.tot-val    { text-align:right; font-family:'Courier New',monospace; font-weight:bold; font-size:7.5pt; white-space:nowrap; }
</style>
</head>
<body>
<div class="no-print">
  <button class="btn-pr" onclick="window.print()">&#128438; Print</button>
  <button class="btn-cl" onclick="window.close()">✕ Close</button>
  &nbsp;&nbsp;Cash Disbursement &mdash; <?php echo e($date_str); ?>
</div>

<?php
foreach ($tables as $table):
    $sec_keys  = $table['sections'];
    $row_alloc = alloc_rows($sec_keys, $secs, $table['target']);
?>
<div class="sheet">
<table>

<!-- ── COLGROUP (9 physical columns, % 기반으로 페이지 폭 꽉 채움) ── -->
<colgroup>
  <col style="width:5%"><!--  1: Section label -->
  <col style="width:4%"><!--  2: NO. -->
  <col style="width:12%"><!-- 3: OR/SI NO. -->
  <col style="width:11%"><!-- 4: DATE -->
  <col style="width:13%"><!-- 5: COMPANY col A -->
  <col style="width:13%"><!-- 6: COMPANY col B -->
  <col style="width:13%"><!-- 7: DETAILS col A -->
  <col style="width:13%"><!-- 8: DETAILS col B -->
  <col style="width:16%"><!-- 9: AMOUNT -->
</colgroup>

<!-- ── TITLE + 결제란 (PREPARED | APPROVED) — 테이블마다 반복 ── -->
<tr>
  <td colspan="7" rowspan="2" class="title-main">
    Cash Disbursement<br>
    <span style="font-size:8.5pt;font-weight:bold">(<?php echo e($company_display); ?>)</span>
  </td>
  <td class="title-prep">PREPARED</td>
  <td class="title-prep">APPROVED</td>
</tr>
<tr>
  <td class="title-prep" rowspan="2"><?php echo e($prepared_by); ?></td>
  <td class="title-prep" rowspan="2"><?php echo e($approved_by); ?></td>
</tr>

<!-- ── META ROW ── -->
<tr>
  <td colspan="2" class="meta">YEAR</td>
  <td class="meta"><?php echo e($year); ?></td>
  <td class="meta">MONTH</td>
  <td class="meta"><?php echo e($month); ?></td>
  <td class="meta">DAY</td>
  <td class="meta"><?php echo e($day); ?></td>
</tr>

<!-- ── COLUMN HEADERS — 테이블마다 반복 ── -->
<tr>
  <th class="colhdr"></th>
  <th class="colhdr">NO.</th>
  <th class="colhdr">OR/SI NO.</th>
  <th class="colhdr">DATE OF PURCHASE</th>
  <th class="colhdr" colspan="2">COMPANY (SUPPLIER NAME)</th>
  <th class="colhdr" colspan="2">DETAILS</th>
  <th class="colhdr">AMOUNT</th>
</tr>

<!-- ── SECTION DATA ROWS ── -->
<?php
$row_no = 1;
foreach ($sec_keys as $sec_key):
    $items    = $secs[$sec_key] ?? [];
    $max_rows = $row_alloc[$sec_key];
    for ($i = 0; $i < $max_rows; $i++):
        $item   = $items[$i] ?? null;
        $cur_no = $row_no++;
?>
<tr class="dr">
  <?php if ($i === 0):
        $sec_total = array_sum(array_column($secs[$sec_key] ?? [], 'amount'));
  ?>
  <td class="sec-lbl" rowspan="<?php echo $max_rows; ?>">
    <?php echo e($labels[$sec_key]); ?>
    <?php if ($sec_total > 0): ?>
    <span class="sec-total"><?php echo aT($sec_total); ?></span>
    <?php endif; ?>
  </td>
  <?php endif; ?>
  <td class="n"><?php echo $cur_no; ?></td>
  <td><?php echo $item ? e($item['or_si_no'] ?? '') : '&nbsp;'; ?></td>
  <td class="c"><?php echo $item ? e($item['date'] ?? '') : '&nbsp;'; ?></td>
  <td colspan="2"><?php echo $item ? e(strtoupper($item['supplier'] ?? '')) : '&nbsp;'; ?></td>
  <td colspan="2"><?php echo $item ? e(strtoupper($item['details'] ?? '')) : '&nbsp;'; ?></td>
  <?php $is_big = $item && (float)($item['amount'] ?? 0) >= 50000; ?>
  <td class="r<?php echo $is_big ? ' amt-big' : ''; ?>"><?php echo $item ? a($item['amount'] ?? 0) : '&nbsp;'; ?></td>
</tr>
<?php
    endfor;
endforeach;
?>

<!-- ── TOTAL ROWS ── -->
<?php foreach ($table['totals'] as $tot): ?>
<tr>
  <td colspan="8" class="tot-lbl"><?php echo e($tot[0]); ?>:</td>
  <td class="tot-val"><?php echo aT($tot[1]); ?></td>
</tr>
<?php endforeach; ?>

</table>
</div>
<?php endforeach; ?>

</body>
</html>
