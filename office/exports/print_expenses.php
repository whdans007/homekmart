<?php
// Expense Daily Report Print 미리보기 — 단일 테이블로 좌/우 행 정렬 보장
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();

$date_str = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
$store_id = get_office_store_id();
$ts       = strtotime($date_str);
$days_en  = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
$date_label = date('M j, Y ', $ts) . '(' . $days_en[date('w', $ts)] . ')';

$conn = get_db_connection();

$stmt = $conn->prepare(
    "SELECT pp.supplier_name, pp.delivery_content, pp.amount, r.cv_no
     FROM office_product_purchases pp
     LEFT JOIN office_receipts r ON r.linked_purchase_type='product' AND r.linked_purchase_id=pp.id
     WHERE pp.store_id=? AND pp.payment_type='cash' AND pp.payment_date=?
     ORDER BY pp.id"
);
$stmt->bind_param('is', $store_id, $date_str);
$stmt->execute();
$cash_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare(
    "SELECT pp.supplier_name, pp.delivery_content, pp.amount, r.cv_no
     FROM office_product_purchases pp
     LEFT JOIN office_receipts r ON r.linked_purchase_type='product' AND r.linked_purchase_id=pp.id
     WHERE pp.store_id=? AND pp.payment_type='check' AND pp.check_issued_date=?
     ORDER BY pp.id"
);
$stmt->bind_param('is', $store_id, $date_str);
$stmt->execute();
$check_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Check if expense_type column exists (migration may not have run yet)
$chk = $conn->query("SHOW COLUMNS FROM office_equipment_purchases LIKE 'expense_type'");
$has_expense_type = ($chk && $chk->num_rows > 0);

if ($has_expense_type) {
    $eq_sql = "SELECT ep.supplier_name, ep.delivery_content, ep.expense_type, ep.expense_category, ep.amount, r.cv_no
               FROM office_equipment_purchases ep
               LEFT JOIN office_receipts r ON r.linked_purchase_type='equipment' AND r.linked_purchase_id=ep.id
               WHERE ep.store_id=? AND ep.payment_date=?
               ORDER BY ep.expense_type, ep.id";
} else {
    $eq_sql = "SELECT ep.supplier_name, ep.delivery_content, 'consumable' AS expense_type, '' AS expense_category, ep.amount, r.cv_no
               FROM office_equipment_purchases ep
               LEFT JOIN office_receipts r ON r.linked_purchase_type='equipment' AND r.linked_purchase_id=ep.id
               WHERE ep.store_id=? AND ep.payment_date=?
               ORDER BY ep.id";
}
$stmt = $conn->prepare($eq_sql);
$stmt->bind_param('is', $store_id, $date_str);
$stmt->execute();
$all_equip = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

$consumable_rows = [];
$other_exp_rows  = [];
foreach ($all_equip as $r) {
    if (($r['expense_type'] ?? 'consumable') === 'other_expense') {
        $other_exp_rows[] = $r;
    } else {
        $consumable_rows[] = $r;
    }
}

$cash_total       = array_sum(array_column($cash_rows,       'amount'));
$check_total      = array_sum(array_column($check_rows,      'amount'));
$consumable_total = array_sum(array_column($consumable_rows, 'amount'));
$other_exp_total  = array_sum(array_column($other_exp_rows,  'amount'));
$equip_total      = $consumable_total + $other_exp_total;
$grand_total      = $cash_total + $consumable_total; // excludes other_expense (salary, rent, etc.)

$MAX_DATA  = max(10, count($cash_rows), count($consumable_rows));
$MAX_CHECK = max(10, count($check_rows), count($other_exp_rows));

function fmt($n) { return number_format((float)$n, 2); }
function esc($s) { return htmlspecialchars((string)($s ?? '')); }
function cell($r, $key) { return esc($r[$key] ?? ''); }
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
.btn-xl { background:#16a34a; }
.btn-pr { background:#1d4ed8; }
.btn-cl { background:#6b7280; }

.sheet {
  width: 278mm;
  margin: 0 auto;
  background: white;
}

/* 단일 테이블: 좌우 컬럼 수 고정 */
table { border-collapse:collapse; width:100%; table-layout:fixed; }

/* 좌 8열 + 구분 1열 + 우 8열 = 17열 */
/* 좌: CV(13%) Sup(17%) Con(30%) Amt(18%) = 78%, gap 2%, 우: 20% */
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

/* 구분 갭 열 */
td.gap { border:none; background:white; padding:0; }

/* 색상 */
.bg-teal   { background:#B8D4E8; }
.bg-blue   { background:#C6D9F1; }
.bg-yellow { background:#FFFF99; }
.bg-orange { background:#FFC000; }
.bg-gray   { background:#D9D9D9; }
.bg-note   { background:#FFFFCC; }
.bg-white  { background:white; }

.bold { font-weight:bold; }
.ctr  { text-align:center; }
.rgt  { text-align:right; }
.lft  { text-align:left; }
.red  { color:#CC0000; }
.dk   { color:#1F3864; }
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
    var minPx = 5.5 * 96 / 72; // ~7.3px (≈5.5pt)
    document.querySelectorAll('td').forEach(function (td) {
        // Skip truly decorative/gap cells and very wide spanning header cells (colspan >= 7)
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
  <button class="btn-xl" onclick="location.href='export_expenses.php?date=<?php echo esc($date_str); ?>'">&#128196; Save Excel</button>
  <button class="btn-pr" onclick="window.print()">&#128438; Print</button>
  <button class="btn-cl" onclick="window.close()">✕ Close</button>
  &nbsp; Date: <strong><?php echo esc($date_label); ?></strong>
</div>

<div class="sheet">
<table>
<colgroup>
  <!-- 좌측 9열 -->
  <col class="cv"><col class="sup"><col class="sup2">
  <col class="con"><col class="con2"><col class="con3"><col class="con4">
  <col class="amt"><col class="amt2">
  <!-- 구분 -->
  <col class="gap">
  <!-- 우측 9열 -->
  <col class="rcv"><col class="rsp"><col class="rsp2">
  <col class="rco"><col class="rc2"><col class="rc3"><col class="rc4">
  <col class="rat"><col class="ra2">
</colgroup>

<?php
// ──────────── 헬퍼: 좌 셀 9개 + gap + 우 셀 9개 ────────────
// $L = 좌측 셀 배열 [colspan, class, text, extra_style?]
// $R = 우측 셀 배열
function row($L, $R, $rowClass='') {
    echo "<tr" . ($rowClass ? " class=\"$rowClass\"" : '') . ">";
    foreach ($L as $c) {
        $cs  = $c[0] > 1 ? " colspan=\"{$c[0]}\"" : '';
        $cls = isset($c[1]) && $c[1] ? " class=\"{$c[1]}\"" : '';
        $sty = isset($c[3]) ? " style=\"{$c[3]}\"" : '';
        echo "<td{$cs}{$cls}{$sty}>" . ($c[2] ?? '') . "</td>";
    }
    echo "<td class=\"gap\"></td>";
    foreach ($R as $c) {
        $cs  = $c[0] > 1 ? " colspan=\"{$c[0]}\"" : '';
        $cls = isset($c[1]) && $c[1] ? " class=\"{$c[1]}\"" : '';
        $sty = isset($c[3]) ? " style=\"{$c[3]}\"" : '';
        echo "<td{$cs}{$cls}{$sty}>" . ($c[2] ?? '') . "</td>";
    }
    echo "</tr>\n";
}
?>



<!-- ══ Row 2: 제목 ══ -->
<tr>
  <td colspan="3" rowspan="2" class="h-title bg-teal">HOME PLUS (SUNSET)</td>
  <td colspan="2" class="h-hdr">PREPARED BY:</td>
  <td colspan="2" class="h-hdr">CHECKED:</td>
  <td colspan="2" class="h-hdr">APPROVED:</td>
  <td class="gap"></td>
  <td colspan="3" rowspan="2" class="h-title bg-teal">HOME PLUS (SUNSET)</td>
  <td colspan="2" class="h-hdr">PREPARED BY:</td>
  <td colspan="2" class="h-hdr">CHECKED:</td>
  <td colspan="2" class="h-hdr">APPROVED:</td>
</tr>
<!-- ══ Row 3: 이름 ══ -->
<tr class="h-name">
  <td colspan="2" class="bold ctr" style="font-size:9pt;">ROMALYN</td>
  <td colspan="2" class="ctr"></td>
  <td colspan="2" class="bold ctr" style="font-size:9pt;">SIR MIN</td>
  <td class="gap"></td>
  <td colspan="2" class="bold ctr" style="font-size:9pt;">ROMALYN</td>
  <td colspan="2" class="ctr"></td>
  <td colspan="2" class="bold ctr" style="font-size:9pt;">SIR MIN</td>
</tr>

<!-- ══ Row 4: 배너 ══ -->
<tr>
  <td colspan="9" class="h-banner bg-teal">BUYING EXPENSES</td>
  <td class="gap"></td>
  <td colspan="9" class="h-banner bg-teal">OTHER EXPENSES</td>
</tr>

<!-- ══ Row 5: 얇은 빈 행 ══ -->
<tr>
  <td colspan="9" class="h-thin bg-white" style="border:none;"></td>
  <td class="gap"></td>
  <td colspan="9" class="h-thin bg-white" style="border:none;"></td>
</tr>

<!-- ══ Row 6: DATE ══ -->
<tr>
  <td colspan="3" class="bold ctr bg-gray">DATE:</td>
  <td colspan="6" class="bold ctr bg-gray"><?php echo esc($date_label); ?></td>
  <td class="gap"></td>
  <td colspan="3" class="bold ctr bg-gray">DATE:</td>
  <td colspan="6" class="bold ctr bg-gray"><?php echo esc($date_label); ?></td>
</tr>

<!-- ══ Row 7: BEGINNING BALANCE ══ -->
<tr>
  <td colspan="3" class="bold lft bg-gray">BEGINNING BALANCE:</td>
  <td colspan="6" class="bg-gray"></td>
  <td class="gap"></td>
  <td colspan="3" class="bold lft bg-gray">BEGINNING BALANCE:</td>
  <td colspan="6" class="bg-gray"></td>
</tr>

<!-- ══ Row 8: 좌=빈 / 우=ADDITIONAL AMOUNT ══ -->
<tr>
  <td colspan="3" class="bg-gray" style="border:none;"></td>
  <td colspan="6" class="bg-gray" style="border:none;"></td>
  <td class="gap"></td>
  <td colspan="3" class="bold lft bg-gray">ADDITIONAL AMOUNT:</td>
  <td colspan="6" class="bg-gray"></td>
</tr>

<!-- ══ Row 9: TOTAL: ══ -->
<tr>
  <td colspan="3" class="bold lft bg-gray">TOTAL AMOUNT:</td>
  <td colspan="6" class="bold rgt mono bg-gray"><?php echo fmt($cash_total + $check_total); ?></td>
  <td class="gap"></td>
  <td colspan="3" class="bold lft bg-gray">TOTAL AMOUNT:</td>
  <td colspan="6" class="bold rgt mono bg-gray"><?php echo fmt($equip_total); ?></td>
</tr>

<!-- ══ Row 10: 얇은 빈 행 ══ -->
<tr>
  <td colspan="9" class="h-thin" style="border:none;background:white;"></td>
  <td class="gap"></td>
  <td colspan="9" class="h-thin" style="border:none;background:white;"></td>
</tr>

<!-- ══ Row 11: 컬럼 헤더 ══ -->
<tr>
  <td class="h-colhd bg-blue">CV NO.</td>
  <td colspan="6" class="h-colhd bg-blue">PARTICULARS&nbsp;&nbsp;(CASH SELLING)</td>
  <td colspan="2" class="h-colhd bg-blue">AMOUNT</td>
  <td class="gap"></td>
  <td class="h-colhd bg-blue">CV NO.</td>
  <td colspan="6" class="h-colhd bg-blue">PARTICULARS&nbsp;&nbsp;(CASH NOT SELLING)</td>
  <td colspan="2" class="h-colhd bg-blue">AMOUNT</td>
</tr>

<!-- ══ 데이터 행: 현금(좌) / 소모품(우) — MAX_DATA 행 고정 ══ -->
<?php for ($i = 0; $i < $MAX_DATA; $i++):
  $lft = $cash_rows[$i]       ?? null;
  $rgt = $consumable_rows[$i] ?? null;
?>
<tr>
  <td class="ctr"><?php echo $lft ? esc($lft['cv_no']) : ''; ?></td>
  <td colspan="3" class="ctr"><?php echo $lft ? esc(strtoupper($lft['supplier_name'])) : ''; ?></td>
  <td colspan="3" class="ctr"><?php echo $lft ? esc(strtoupper($lft['delivery_content'])) : ''; ?></td>
  <td colspan="2" class="rgt mono"><?php echo $lft ? fmt($lft['amount']) : ''; ?></td>
  <td class="gap"></td>
  <td class="ctr"><?php echo $rgt ? esc($rgt['cv_no']) : ''; ?></td>
  <td colspan="3" class="ctr"><?php echo $rgt ? esc(strtoupper($rgt['supplier_name'])) : ''; ?></td>
  <td colspan="3" class="ctr"><?php echo $rgt ? esc(strtoupper($rgt['delivery_content'])) : ''; ?></td>
  <td colspan="2" class="rgt mono"><?php echo $rgt ? fmt($rgt['amount']) : ''; ?></td>
</tr>
<?php endfor; ?>

<!-- ══ TOTAL 행 ══ -->
<tr class="bold">
  <td colspan="7" class="rgt bg-yellow bold">TOTAL:</td>
  <td colspan="2" class="rgt mono bg-yellow bold"><?php echo fmt($cash_total); ?></td>
  <td class="gap"></td>
  <td colspan="7" class="rgt bg-yellow bold">TOTAL:</td>
  <td colspan="2" class="rgt mono bg-yellow bold"><?php echo $consumable_total ? fmt($consumable_total) : ''; ?></td>
</tr>

<!-- ══ 좌=빈 / 우=CASH TOTAL: ══ -->
<tr>
  <td colspan="9" style="border:none;background:white;"></td>
  <td class="gap"></td>
  <td colspan="7" class="rgt bg-yellow bold">CASH TOTAL:</td>
  <td colspan="2" class="rgt mono bg-yellow bold"><?php echo fmt($grand_total); ?></td>
</tr>

<!-- ══ 섹션 2 헤더: CHECK(좌) / OTHER EXPENSES(우) ══ -->
<tr>
  <td class="h-colhd bg-orange">CHECK NO.</td>
  <td colspan="6" class="h-colhd bg-orange">SUPLIERS&nbsp;&nbsp;(PAY THRU CHECK)</td>
  <td colspan="2" class="h-colhd bg-orange">AMOUNT CHECK</td>
  <td class="gap"></td>
  <td class="h-colhd bg-orange">CV NO.</td>
  <td colspan="6" class="h-colhd bg-orange">OTHER EXPENSES&nbsp;&nbsp;(SALARY/ELECTRIC/WATER/RENT)</td>
  <td colspan="2" class="h-colhd bg-orange">AMOUNT</td>
</tr>

<!-- ══ 수표(좌) / 기타비용(우) — MAX_CHECK 행 고정 ══ -->
<?php for ($i = 0; $i < $MAX_CHECK; $i++):
  $chk = $check_rows[$i]    ?? null;
  $oth = $other_exp_rows[$i] ?? null;
?>
<tr>
  <td class="ctr"><?php echo $chk ? esc($chk['cv_no']) : ''; ?></td>
  <td colspan="3" class="ctr"><?php echo $chk ? esc(strtoupper($chk['supplier_name'])) : ''; ?></td>
  <td colspan="3" class="ctr"><?php echo $chk ? esc(strtoupper($chk['delivery_content'])) : ''; ?></td>
  <td colspan="2" class="rgt mono"><?php echo $chk ? fmt($chk['amount']) : ''; ?></td>
  <td class="gap"></td>
  <td class="ctr"><?php echo $oth ? esc($oth['cv_no']) : ''; ?></td>
  <td colspan="3" class="ctr"><?php echo $oth ? esc(strtoupper($oth['supplier_name'])) : ''; ?></td>
  <td colspan="3" class="ctr"><?php echo $oth ? esc(strtoupper($oth['delivery_content'])) : ''; ?></td>
  <td colspan="2" class="rgt mono"><?php echo $oth ? fmt($oth['amount']) : ''; ?></td>
</tr>
<?php endfor; ?>

<!-- ══ TOTAL: 행 ══ -->
<tr class="bold">
  <td colspan="7" class="rgt bg-yellow bold">TOTAL:</td>
  <td colspan="2" class="rgt mono bg-yellow bold"><?php echo $check_total ? fmt($check_total) : ''; ?></td>
  <td class="gap"></td>
  <td colspan="7" class="rgt bg-yellow bold">TOTAL:</td>
  <td colspan="2" class="rgt mono bg-yellow bold"><?php echo $other_exp_total ? fmt($other_exp_total) : ''; ?></td>
</tr>

<!-- ══ CASH ON HAND ══ -->
<tr>
  <td colspan="9" class="bold ctr red" style="font-size:7.5pt;">
    CASH ON HAND : 50,000.00&nbsp;&nbsp;(FOR BILLS)
  </td>
  <td class="gap"></td>
  <td colspan="9" class="bold ctr red" style="font-size:7.5pt;">
    CASH ON HAND : 50,000.00&nbsp;&nbsp;(FOR BILLS)
  </td>
</tr>

</table>
</div>

</body>
</html>
