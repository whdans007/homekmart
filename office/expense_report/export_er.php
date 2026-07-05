<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

if (empty($_POST['data'])) { http_response_code(400); exit; }
$payload  = json_decode($_POST['data'], true);
$date_str = preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload['date']??'') ? $payload['date'] : date('Y-m-d');
$secs     = $payload['sections'] ?? [];

$ts       = strtotime($date_str);
$days_en  = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
$date_label = date('M j, Y ', $ts) . '(' . $days_en[date('w',$ts)] . ')';

// State JSON → 변수 매핑
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

// OTHER EXPENSES 우측: sub-header + data 혼합 배열
$has_oe_check = !empty($other_exp_check_rows);
$has_oe_cash  = !empty($other_exp_cash_rows);
$right_items  = [];
if ($has_oe_check) {
    if ($has_oe_cash) $right_items[] = ['_t'=>'hdr','label'=>'수표 (CHECK)'];
    foreach ($other_exp_check_rows as $r) $right_items[] = $r + ['_t'=>'data'];
    if ($has_oe_cash) $right_items[] = ['_t'=>'sub','amount'=>$other_exp_check_total,'label'=>'소계(수표)'];
}
if ($has_oe_cash) {
    if ($has_oe_check) $right_items[] = ['_t'=>'hdr','label'=>'현금 (CASH)'];
    foreach ($other_exp_cash_rows as $r) $right_items[] = $r + ['_t'=>'data'];
    if ($has_oe_check) $right_items[] = ['_t'=>'sub','amount'=>$other_exp_cash_total,'label'=>'소계(현금)'];
}
$MAX_CHECK = max(10, count($check_rows), count($right_items));

function xe($s) { return htmlspecialchars((string)($s ?? ''), ENT_XML1, 'UTF-8'); }
function fmtN($n) { return number_format((float)$n, 2); }

function xmlRow($height, $cells_xml) {
    $h = $height ? " ss:Height=\"{$height}\"" : '';
    return "<Row{$h}>{$cells_xml}</Row>\n";
}
function xmlCells(array $cells) {
    $out = '';
    foreach ($cells as $c) {
        $val  = $c[0] ?? '';
        $sid  = $c[1] ?? 'Default';
        $ma   = isset($c[2]) && $c[2] > 0 ? " ss:MergeAcross=\"{$c[2]}\"" : '';
        $md   = isset($c[3]) && $c[3] > 0 ? " ss:MergeDown=\"{$c[3]}\"" : '';
        $type = $c[4] ?? 'String';
        $idx  = isset($c[5]) ? " ss:Index=\"{$c[5]}\"" : '';
        if ($val === '' || $val === null) {
            $out .= "<Cell{$idx} ss:StyleID=\"{$sid}\"{$ma}{$md}/>";
        } else {
            $out .= "<Cell{$idx} ss:StyleID=\"{$sid}\"{$ma}{$md}><Data ss:Type=\"{$type}\">" . xe($val) . "</Data></Cell>";
        }
    }
    return $out;
}

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
<Styles>
 <Style ss:ID="Default"><Alignment ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="8"/></Style>
 <Style ss:ID="s_title"><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/><Font ss:FontName="Arial" ss:Size="10" ss:Bold="1"/><Interior ss:Color="#B8D4E8" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_hdr"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="7" ss:Bold="1"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_name"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_banner"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1" ss:Color="#1F3864"/><Interior ss:Color="#B8D4E8" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_date"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#D9D9D9" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_bal"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#D9D9D9" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_bal_val"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#D9D9D9" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><NumberFormat ss:Format="#,##0.00"/></Style>
 <Style ss:ID="s_colhd"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="8" ss:Bold="1"/><Interior ss:Color="#C6D9F1" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_data"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="8"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_amt"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Courier New" ss:Size="8"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><NumberFormat ss:Format="#,##0.00"/></Style>
 <Style ss:ID="s_total"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#FFFF99" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_total_amt"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Courier New" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#FFFF99" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><NumberFormat ss:Format="#,##0.00"/></Style>
 <Style ss:ID="s_chkhd"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="8" ss:Bold="1"/><Interior ss:Color="#FFC000" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_coh"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="8" ss:Bold="1" ss:Color="#CC0000"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_empty"><Alignment ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="8"/></Style>
</Styles>

<Worksheet ss:Name="Daily Expense Report">
<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
 <PageSetup>
  <Layout x:Orientation="Landscape"/>
  <PageMargins x:Bottom="0.3" x:Left="0.2" x:Right="0.2" x:Top="0.3"/>
 </PageSetup>
 <FitToPage/>
 <Print>
  <FitWidth>1</FitWidth>
  <FitHeight>32767</FitHeight>
  <PaperSizeIndex>9</PaperSizeIndex>
 </Print>
</WorksheetOptions>
<Table ss:DefaultRowHeight="14">
<?php
$cols = [80, 80, 65, 65, 55, 55, 55, 65, 65, 80, 80, 65, 65, 55, 55, 55, 65, 65];
foreach ($cols as $w) echo "<Column ss:AutoFitWidth=\"0\" ss:Width=\"{$w}\"/>\n";

// Row 1-2: 제목 + 이름
echo xmlRow(18, xmlCells([
    ['HOME PLUS (SUNSET)', 's_title', 2, 1],
    ['PREPARED BY:', 's_hdr', 1], ['CHECKED:', 's_hdr', 1], ['APPROVED:', 's_hdr', 1],
    ['HOME PLUS (SUNSET)', 's_title', 2, 1, 'String', 10],
    ['PREPARED BY:', 's_hdr', 1], ['CHECKED:', 's_hdr', 1], ['APPROVED:', 's_hdr', 1],
]));
echo xmlRow(35, xmlCells([
    ['ROMALYN', 's_name', 1, 0, 'String', 4], ['', 's_hdr', 1], ['SIR MIN', 's_name', 1],
    ['ROMALYN', 's_name', 1, 0, 'String', 13], ['', 's_hdr', 1], ['SIR MIN', 's_name', 1],
]));
echo xmlRow(17, xmlCells([
    ['BUYING EXPENSES', 's_banner', 8], ['OTHER EXPENSES', 's_banner', 8, 0, 'String', 10],
]));
echo xmlRow(4, xmlCells([['', 's_empty', 17]]));
echo xmlRow(14, xmlCells([
    ['DATE:', 's_date', 2], [xe($date_label), 's_date', 5],
    ['DATE:', 's_date', 2, 0, 'String', 10], [xe($date_label), 's_date', 5],
]));
echo xmlRow(14, xmlCells([
    ['BEGINNING BALANCE:', 's_bal', 2], ['', 's_bal_val', 5],
    ['BEGINNING BALANCE:', 's_bal', 2, 0, 'String', 10], ['', 's_bal_val', 5],
]));
echo xmlRow(14, xmlCells([
    ['', 's_empty', 2], ['', 's_empty', 5],
    ['ADDITIONAL AMOUNT:', 's_bal', 2, 0, 'String', 10], ['', 's_bal_val', 5],
]));
echo xmlRow(14, xmlCells([
    ['TOTAL AMOUNT:', 's_bal', 2], [fmtN($cash_total + $check_total), 's_bal_val', 5, 0, 'Number'],
    ['TOTAL AMOUNT:', 's_bal', 2, 0, 'String', 10], [fmtN($equip_total), 's_bal_val', 5, 0, 'Number'],
]));
echo xmlRow(4, xmlCells([['', 's_empty', 17]]));
echo xmlRow(15, xmlCells([
    ['CV NO.', 's_colhd'], ['PARTICULARS  (CASH SELLING)', 's_colhd', 5], ['AMOUNT', 's_colhd', 1],
    ['CV NO.', 's_colhd', 0, 0, 'String', 10], ['PARTICULARS  (CASH NOT SELLING)', 's_colhd', 5], ['AMOUNT', 's_colhd', 1],
]));

for ($i = 0; $i < $MAX_DATA; $i++) {
    $lft = $cash_rows[$i]       ?? null;
    $rgt = $consumable_rows[$i] ?? null;
    echo xmlRow(13, xmlCells([
        [$lft ? xe($lft['cv_no']??'') : '', 's_data'],
        [$lft ? xe(strtoupper($lft['supplier']??'')) : '', 's_data', 2],
        [$lft ? xe(strtoupper($lft['details']??'')) : '', 's_data', 2],
        [$lft ? (float)$lft['amount'] : '', $lft ? 's_amt' : 's_data', 1, 0, 'Number'],
        [$rgt ? xe($rgt['cv_no']??'') : '', 's_data', 0, 0, 'String', 10],
        [$rgt ? xe(strtoupper($rgt['supplier']??'')) : '', 's_data', 2],
        [$rgt ? xe(strtoupper($rgt['details']??'')) : '', 's_data', 2],
        [$rgt ? (float)$rgt['amount'] : '', $rgt ? 's_amt' : 's_data', 1, 0, 'Number'],
    ]));
}
echo xmlRow(14, xmlCells([
    ['TOTAL:', 's_total', 6], [(float)$cash_total, 's_total_amt', 1, 0, 'Number'],
    ['TOTAL:', 's_total', 6, 0, 'String', 10], [(float)$consumable_total, 's_total_amt', 1, 0, 'Number'],
]));
echo xmlRow(14, xmlCells([
    ['', 's_empty', 8],
    ['CASH TOTAL AMOUNT', 's_total', 6, 0, 'String', 10], [(float)$grand_total, 's_total_amt', 1, 0, 'Number'],
]));
// CHECK(좌) / OTHER EXPENSES 통합(우) 헤더
echo xmlRow(15, xmlCells([
    ['CHECK NO.', 's_chkhd'], ['SUPLIERS  (PAY THRU CHECK)', 's_chkhd', 5], ['AMOUNT CHECK', 's_chkhd', 1],
    ['CV NO.', 's_chkhd', 0, 0, 'String', 10], ['OTHER EXPENSES  (SALARY/ELECTRIC/WATER/RENT)', 's_chkhd', 5], ['AMOUNT', 's_chkhd', 1],
]));

for ($i = 0; $i < $MAX_CHECK; $i++) {
    $chk = $check_rows[$i]  ?? null;
    $ri  = $right_items[$i] ?? null;
    $rt  = $ri['_t']        ?? null;

    $left = [
        [$chk ? xe($chk['cv_no']??'') : '', 's_data'],
        [$chk ? xe(strtoupper($chk['supplier']??'')) : '', 's_data', 2],
        [$chk ? xe(strtoupper($chk['details']??'')) : '', 's_data', 2],
        [$chk ? (float)$chk['amount'] : '', $chk ? 's_amt' : 's_data', 1, 0, 'Number'],
    ];

    if ($rt === 'hdr') {
        $right = [[$ri['label'], 's_chkhd', 8, 0, 'String', 10]];
    } elseif ($rt === 'sub') {
        $right = [
            [$ri['label'] . ':', 's_total', 6, 0, 'String', 10],
            [(float)$ri['amount'], 's_total_amt', 1, 0, 'Number'],
        ];
    } else {
        $right = [
            [$ri ? xe($ri['cv_no']??'') : '', 's_data', 0, 0, 'String', 10],
            [$ri ? xe(strtoupper($ri['supplier']??'')) : '', 's_data', 2],
            [$ri ? xe(strtoupper($ri['details']??'')) : '', 's_data', 2],
            [$ri ? (float)$ri['amount'] : '', $ri ? 's_amt' : 's_data', 1, 0, 'Number'],
        ];
    }
    echo xmlRow(13, xmlCells(array_merge($left, $right)));
}
echo xmlRow(14, xmlCells([
    ['TOTAL:', 's_total', 6], [$check_total ? (float)$check_total : '', $check_total ? 's_total_amt' : 's_total', 1, 0, 'Number'],
    ['TOTAL:', 's_total', 6, 0, 'String', 10], [$other_exp_total ? (float)$other_exp_total : '', $other_exp_total ? 's_total_amt' : 's_total', 1, 0, 'Number'],
]));
echo xmlRow(14, xmlCells([
    ['CASH ON HAND : 50,000.00  (FOR BILLS)', 's_coh', 8],
    ['CASH ON HAND : 50,000.00  (FOR BILLS)', 's_coh', 8, 0, 'String', 10],
]));
?>
</Table>
</Worksheet>
</Workbook>
