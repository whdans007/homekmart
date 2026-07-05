<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();

$date_str = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
$store_id = get_office_store_id();
$ts       = strtotime($date_str);
$days_en  = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
$date_label = date('M j, Y ', $ts) . '(' . $days_en[date('w',$ts)] . ')';

$conn = get_db_connection();

$stmt = $conn->prepare(
    "SELECT pp.supplier_name, pp.delivery_content, pp.amount, r.cv_no
     FROM office_product_purchases pp
     LEFT JOIN office_receipts r ON r.linked_purchase_type='product' AND r.linked_purchase_id=pp.id
     WHERE pp.store_id=? AND pp.payment_type='cash' AND pp.payment_date=? ORDER BY pp.id"
);
$stmt->bind_param('is', $store_id, $date_str); $stmt->execute();
$cash_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$stmt = $conn->prepare(
    "SELECT pp.supplier_name, pp.delivery_content, pp.amount, r.cv_no
     FROM office_product_purchases pp
     LEFT JOIN office_receipts r ON r.linked_purchase_type='product' AND r.linked_purchase_id=pp.id
     WHERE pp.store_id=? AND pp.payment_type='check' AND pp.check_issued_date=? ORDER BY pp.id"
);
$stmt->bind_param('is', $store_id, $date_str); $stmt->execute();
$check_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$chk2 = $conn->query("SHOW COLUMNS FROM office_equipment_purchases LIKE 'expense_type'");
$has_expense_type = ($chk2 && $chk2->num_rows > 0);

if ($has_expense_type) {
    $eq_sql = "SELECT ep.supplier_name, ep.delivery_content, ep.expense_type, ep.expense_category, ep.amount, r.cv_no
               FROM office_equipment_purchases ep
               LEFT JOIN office_receipts r ON r.linked_purchase_type='equipment' AND r.linked_purchase_id=ep.id
               WHERE ep.store_id=? AND ep.payment_date=? ORDER BY ep.expense_type, ep.id";
} else {
    $eq_sql = "SELECT ep.supplier_name, ep.delivery_content, 'consumable' AS expense_type, '' AS expense_category, ep.amount, r.cv_no
               FROM office_equipment_purchases ep
               LEFT JOIN office_receipts r ON r.linked_purchase_type='equipment' AND r.linked_purchase_id=ep.id
               WHERE ep.store_id=? AND ep.payment_date=? ORDER BY ep.id";
}
$stmt = $conn->prepare($eq_sql);
$stmt->bind_param('is', $store_id, $date_str); $stmt->execute();
$all_equip = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
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
$grand_total      = $cash_total + $consumable_total; // excludes other_expense

$MAX_DATA  = max(10, count($cash_rows), count($consumable_rows));
$MAX_CHECK = max(10, count($check_rows), count($other_exp_rows));

function xe($s) { return htmlspecialchars((string)($s ?? ''), ENT_XML1, 'UTF-8'); }
function fmtN($n) { return number_format((float)$n, 2); }
// SpreadsheetML row/cell helpers
function xmlRow($height, $cells_xml) {
    $h = $height ? " ss:Height=\"{$height}\"" : '';
    return "<Row{$h}>{$cells_xml}</Row>\n";
}
// $cells: array of [value, styleID, mergeAcross, mergeDown, type, index]
function xmlCells(array $cells) {
    $out = '';
    foreach ($cells as $c) {
        $val   = $c[0] ?? '';
        $sid   = $c[1] ?? 'Default';
        $ma    = isset($c[2]) && $c[2] > 0 ? " ss:MergeAcross=\"{$c[2]}\"" : '';
        $md    = isset($c[3]) && $c[3] > 0 ? " ss:MergeDown=\"{$c[3]}\"" : '';
        $type  = $c[4] ?? 'String';
        $idx   = isset($c[5]) ? " ss:Index=\"{$c[5]}\"" : '';
        if ($val === '' || $val === null) {
            $out .= "<Cell{$idx} ss:StyleID=\"{$sid}\"{$ma}{$md}/>";
        } else {
            $out .= "<Cell{$idx} ss:StyleID=\"{$sid}\"{$ma}{$md}><Data ss:Type=\"{$type}\">" . xe($val) . "</Data></Cell>";
        }
    }
    return $out;
}

ob_end_clean();

// ── 출력 헤더 ────────────────────────────────────────────
$filename = 'DailyExpense_' . $date_str . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('Cache-Control: max-age=0');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:o="urn:schemas-microsoft-com:office:office">

<DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">
 <Title>Daily Expense Report</Title>
</DocumentProperties>

<ExcelWorkbook xmlns="urn:schemas-microsoft-com:office:excel">
 <WindowHeight>8000</WindowHeight>
 <WindowWidth>16000</WindowWidth>
 <ActiveSheet>0</ActiveSheet>
</ExcelWorkbook>

<Styles>
 <Style ss:ID="Default" ss:Name="Normal">
  <Alignment ss:Vertical="Center"/>
  <Font ss:FontName="Arial" ss:Size="8"/>
 </Style>
 <!-- s_title: title cell -->
 <Style ss:ID="s_title">
  <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
  <Font ss:FontName="Arial" ss:Size="10" ss:Bold="1"/>
  <Interior ss:Color="#B8D4E8" ss:Pattern="Solid"/>
  <Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders>
 </Style>
 <!-- s_hdr: PREPARED BY header -->
 <Style ss:ID="s_hdr">
  <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  <Font ss:FontName="Arial" ss:Size="7" ss:Bold="1"/>
  <Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders>
 </Style>
 <!-- s_name: Name row -->
 <Style ss:ID="s_name">
  <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  <Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/>
  <Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders>
 </Style>
 <!-- s_banner: Section banner -->
 <Style ss:ID="s_banner">
  <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  <Font ss:FontName="Arial" ss:Size="11" ss:Bold="1" ss:Color="#1F3864"/>
  <Interior ss:Color="#B8D4E8" ss:Pattern="Solid"/>
  <Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders>
 </Style>
 <!-- s_date: Date row -->
 <Style ss:ID="s_date">
  <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  <Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/>
  <Interior ss:Color="#D9D9D9" ss:Pattern="Solid"/>
  <Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders>
 </Style>
 <!-- s_bal: Balance rows -->
 <Style ss:ID="s_bal">
  <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
  <Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/>
  <Interior ss:Color="#D9D9D9" ss:Pattern="Solid"/>
  <Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders>
 </Style>
 <!-- s_bal_val: Balance value -->
 <Style ss:ID="s_bal_val">
  <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
  <Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/>
  <Interior ss:Color="#D9D9D9" ss:Pattern="Solid"/>
  <Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders>
  <NumberFormat ss:Format="#,##0.00"/>
 </Style>
 <!-- s_colhd: Column header -->
 <Style ss:ID="s_colhd">
  <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  <Font ss:FontName="Arial" ss:Size="8" ss:Bold="1"/>
  <Interior ss:Color="#C6D9F1" ss:Pattern="Solid"/>
  <Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders>
 </Style>
 <!-- s_data: Data cell -->
 <Style ss:ID="s_data">
  <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  <Font ss:FontName="Arial" ss:Size="8"/>
  <Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders>
 </Style>
 <!-- s_amt: Amount 셀 -->
 <Style ss:ID="s_amt">
  <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
  <Font ss:FontName="Courier New" ss:Size="8"/>
  <Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders>
  <NumberFormat ss:Format="#,##0.00"/>
 </Style>
 <!-- s_total: TOTAL row -->
 <Style ss:ID="s_total">
  <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
  <Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/>
  <Interior ss:Color="#FFFF99" ss:Pattern="Solid"/>
  <Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders>
 </Style>
 <!-- s_total_amt: TOTAL Amount -->
 <Style ss:ID="s_total_amt">
  <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
  <Font ss:FontName="Courier New" ss:Size="9" ss:Bold="1"/>
  <Interior ss:Color="#FFFF99" ss:Pattern="Solid"/>
  <Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders>
  <NumberFormat ss:Format="#,##0.00"/>
 </Style>
 <!-- s_chkhd: CHECK header -->
 <Style ss:ID="s_chkhd">
  <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  <Font ss:FontName="Arial" ss:Size="8" ss:Bold="1"/>
  <Interior ss:Color="#FFC000" ss:Pattern="Solid"/>
  <Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders>
 </Style>
 <!-- s_note: NOTE header -->
 <Style ss:ID="s_note">
  <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
  <Font ss:FontName="Arial" ss:Size="7.5" ss:Bold="1" ss:Color="#CC0000"/>
  <Interior ss:Color="#FFFFCC" ss:Pattern="Solid"/>
  <Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders>
 </Style>
 <!-- s_coh: CASH ON HAND -->
 <Style ss:ID="s_coh">
  <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  <Font ss:FontName="Arial" ss:Size="8" ss:Bold="1" ss:Color="#CC0000"/>
  <Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders>
 </Style>
 <!-- s_empty: No background -->
 <Style ss:ID="s_empty">
  <Alignment ss:Vertical="Center"/>
  <Font ss:FontName="Arial" ss:Size="8"/>
 </Style>
</Styles>

<Worksheet ss:Name="Daily Expense Report">
<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
 <PageSetup>
  <Layout x:Orientation="Landscape"/>
  <Paper>9</Paper>
  <PageMargins x:Bottom="0.3" x:Left="0.2" x:Right="0.2" x:Top="0.3"/>
 </PageSetup>
 <FitToPage/>
 <Print>
  <FitWidth>1</FitWidth>
  <FitHeight>32767</FitHeight>
  <ValidPrinterInfo/>
  <PaperSizeIndex>9</PaperSizeIndex>
  <HorizontalResolution>600</HorizontalResolution>
  <VerticalResolution>600</VerticalResolution>
 </Print>
</WorksheetOptions>
<Table ss:DefaultRowHeight="14">
<?php
// Column width definitions (18컬럼: A-R, 1-based in SpreadsheetML)
// 좌: A(1)CV B(2-3)Supplier D(4-7)Content H(8-9)Amount | 우: J(10)CV K(11-12)Supplier M(13-16)Content Q(17-18)Amount
$cols = [80, 80, 65, 65, 55, 55, 55, 65, 65, 80, 80, 65, 65, 55, 55, 55, 65, 65];
foreach ($cols as $w) {
    echo "<Column ss:AutoFitWidth=\"0\" ss:Width=\"{$w}\"/>\n";
}

// ── Row 2: 제목 (MergeDown=1 for A-C span rows 2-3) ─────────
// 좌: A-C (merge 2col + 1row), D-E PREPARED BY, F-G CHECKED, H-I APPROVED
// 우: J-L (merge 2col + 1row), M-N PREPARED BY, O-P CHECKED, Q-R APPROVED
$r2 = xmlCells([
    ['HOME PLUS (SUNSET)', 's_title', 2, 1],   // A2:C3
    ['PREPARED BY:', 's_hdr', 1],               // D2:E2
    ['CHECKED:', 's_hdr', 1],                   // F2:G2
    ['APPROVED:', 's_hdr', 1],                  // H2:I2
    ['HOME PLUS (SUNSET)', 's_title', 2, 1, 'String', 10], // J2:L3
    ['PREPARED BY:', 's_hdr', 1],               // M2:N2
    ['CHECKED:', 's_hdr', 1],                   // O2:P2
    ['APPROVED:', 's_hdr', 1],                  // Q2:R2
]);
echo xmlRow(18, $r2);

// ── Row 3: 이름 ────────────────────────────────────────────
// A-C already merged (skip), D-E ROMALYN, F-G blank, H-I SIR MIN
// J-L already merged (skip), M-N ROMALYN, O-P blank, Q-R SIR MIN
$r3 = xmlCells([
    ['ROMALYN', 's_name', 1, 0, 'String', 4],  // D3:E3
    ['' , 's_hdr', 1],                           // F3:G3
    ['SIR MIN', 's_name', 1],                   // H3:I3
    ['ROMALYN', 's_name', 1, 0, 'String', 13], // M3:N3
    ['' , 's_hdr', 1],                           // O3:P3
    ['SIR MIN', 's_name', 1],                   // Q3:R3
]);
echo xmlRow(35, $r3);

// ── Row 4: 배너 ────────────────────────────────────────────
$r4 = xmlCells([
    ['BUYING EXPENSES', 's_banner', 8],         // A4:I4
    ['OTHER EXPENSES',  's_banner', 8, 0, 'String', 10], // J4:R4
]);
echo xmlRow(17, $r4);

// ── Row 5: 얇은 빈 ────────────────────────────────────────
echo xmlRow(4, xmlCells([['' , 's_empty', 17]]));

// ── Row 6: DATE ────────────────────────────────────────────
$r6 = xmlCells([
    ['DATE:', 's_date', 2],
    [xe($date_label), 's_date', 5],
    ['DATE:', 's_date', 2, 0, 'String', 10],
    [xe($date_label), 's_date', 5],
]);
echo xmlRow(14, $r6);

// ── Row 7: BEGINNING BALANCE ────────────────────────────────
$r7 = xmlCells([
    ['BEGINNING BALANCE:', 's_bal', 2],
    ['', 's_bal_val', 5],
    ['BEGINNING BALANCE:', 's_bal', 2, 0, 'String', 10],
    ['', 's_bal_val', 5],
]);
echo xmlRow(14, $r7);

// ── Row 8: 좌=빈 / 우=ADDITIONAL AMOUNT ────────────────────
$r8 = xmlCells([
    ['', 's_empty', 2],
    ['', 's_empty', 5],
    ['ADDITIONAL AMOUNT:', 's_bal', 2, 0, 'String', 10],
    ['', 's_bal_val', 5],
]);
echo xmlRow(14, $r8);

// ── Row 9: TOTAL AMOUNT ────────────────────────────────────
$r9 = xmlCells([
    ['TOTAL AMOUNT:', 's_bal', 2],
    [fmtN($cash_total + $check_total), 's_bal_val', 5, 0, 'Number'],
    ['TOTAL AMOUNT:', 's_bal', 2, 0, 'String', 10],
    [fmtN($equip_total), 's_bal_val', 5, 0, 'Number'],
]);
echo xmlRow(14, $r9);

// ── Row 10: 얇은 빈 ────────────────────────────────────────
echo xmlRow(4, xmlCells([['' , 's_empty', 17]]));

// ── Row 11: Column header ──────────────────────────────────────
$r11 = xmlCells([
    ['CV NO.', 's_colhd'],
    ['PARTICULARS  (CASH SELLING)', 's_colhd', 5],
    ['AMOUNT', 's_colhd', 1],
    ['CV NO.', 's_colhd', 0, 0, 'String', 10],
    ['PARTICULARS  (CASH NOT SELLING)', 's_colhd', 5],
    ['AMOUNT', 's_colhd', 1],
]);
echo xmlRow(15, $r11);

// ── 데이터 행 ──────────────────────────────────────────────
for ($i = 0; $i < $MAX_DATA; $i++) {
    $lft = $cash_rows[$i]       ?? null;
    $rgt = $consumable_rows[$i] ?? null;
    $row = xmlCells([
        [$lft ? xe($lft['cv_no'] ?? '') : '', 's_data'],
        [$lft ? xe(strtoupper($lft['supplier_name'])) : '', 's_data', 2],
        [$lft ? xe(strtoupper($lft['delivery_content'])) : '', 's_data', 2],
        [$lft ? (float)$lft['amount'] : '', $lft ? 's_amt' : 's_data', 1, 0, 'Number'],
        [$rgt ? xe($rgt['cv_no'] ?? '') : '', 's_data', 0, 0, 'String', 10],
        [$rgt ? xe(strtoupper($rgt['supplier_name'])) : '', 's_data', 2],
        [$rgt ? xe(strtoupper($rgt['delivery_content'])) : '', 's_data', 2],
        [$rgt ? (float)$rgt['amount'] : '', $rgt ? 's_amt' : 's_data', 1, 0, 'Number'],
    ]);
    echo xmlRow(13, $row);
}

// ── TOTAL row ───────────────────────────────────────────────
echo xmlRow(14, xmlCells([
    ['TOTAL:', 's_total', 6],
    [(float)$cash_total, 's_total_amt', 1, 0, 'Number'],
    ['TOTAL:', 's_total', 6, 0, 'String', 10],
    [(float)$consumable_total, 's_total_amt', 1, 0, 'Number'],
]));

// ── 좌=빈 / 우=CASH TOTAL AMOUNT ────────────────────────────
echo xmlRow(14, xmlCells([
    ['', 's_empty', 8],
    ['CASH TOTAL AMOUNT', 's_total', 6, 0, 'String', 10],
    [(float)$grand_total, 's_total_amt', 1, 0, 'Number'],
]));

// ── CHECK header / OTHER EXPENSES header ────────────────────────
echo xmlRow(15, xmlCells([
    ['CHECK NO.', 's_chkhd'],
    ['SUPLIERS  (PAY THRU CHECK)', 's_chkhd', 5],
    ['AMOUNT CHECK', 's_chkhd', 1],
    ['CV NO.', 's_chkhd', 0, 0, 'String', 10],
    ['OTHER EXPENSES  (SALARY/ELECTRIC/WATER/RENT)', 's_chkhd', 5],
    ['AMOUNT', 's_chkhd', 1],
]));

// ── 수표 / 기타비용 데이터 행 ──────────────────────────────
for ($i = 0; $i < $MAX_CHECK; $i++) {
    $chk = $check_rows[$i]    ?? null;
    $oth = $other_exp_rows[$i] ?? null;
    $oth_label = $oth ? xe(strtoupper($oth['supplier_name'])) : '';
    echo xmlRow(13, xmlCells([
        [$chk ? xe($chk['cv_no'] ?? '') : '', 's_data'],
        [$chk ? xe(strtoupper($chk['supplier_name'])) : '', 's_data', 2],
        [$chk ? xe(strtoupper($chk['delivery_content'])) : '', 's_data', 2],
        [$chk ? (float)$chk['amount'] : '', $chk ? 's_amt' : 's_data', 1, 0, 'Number'],
        [$oth ? xe($oth['cv_no'] ?? '') : '', 's_data', 0, 0, 'String', 10],
        [$oth_label, 's_data', 2],
        [$oth ? xe(strtoupper($oth['delivery_content'])) : '', 's_data', 2],
        [$oth ? (float)$oth['amount'] : '', $oth ? 's_amt' : 's_data', 1, 0, 'Number'],
    ]));
}

// ── 수표 TOTAL / 기타비용 TOTAL ─────────────────────────────
echo xmlRow(14, xmlCells([
    ['TOTAL:', 's_total', 6],
    [$check_total ? (float)$check_total : '', $check_total ? 's_total_amt' : 's_total', 1, 0, 'Number'],
    ['TOTAL:', 's_total', 6, 0, 'String', 10],
    [$other_exp_total ? (float)$other_exp_total : '', $other_exp_total ? 's_total_amt' : 's_total', 1, 0, 'Number'],
]));

// ── CASH ON HAND ────────────────────────────────────────────
echo xmlRow(14, xmlCells([
    ['CASH ON HAND : 50,000.00  (FOR BILLS)', 's_coh', 8],
    ['CASH ON HAND : 50,000.00  (FOR BILLS)', 's_coh', 8, 0, 'String', 10],
]));
?>
</Table>
</Worksheet>
</Workbook>
<?php exit; ?>
