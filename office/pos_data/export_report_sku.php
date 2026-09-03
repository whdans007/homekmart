<?php
// Design Ref: report.php SKU별 판매 분석 — 현재 화면에 표시된 정렬/필터 그대로 Excel 다운로드.
// 이 프로젝트의 기존 export 컨벤션(office/expense_report/export_er.php, office/daily_report/export_daily_report.php)을
// 따라 PhpSpreadsheet 대신 SpreadsheetML(XML) 직접 생성 방식을 사용한다 — 진짜 .xls 파일로 열림 (CSV 아님).
ob_start();
set_time_limit(120);
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
require_once __DIR__ . '/../lib/pos_report_helper.php';
ob_end_clean();

$today        = date('Y-m-d');
$default_from = date('Y-m-01');
$default_to   = date('Y-m-d');

$date_from = trim($_GET['from'] ?? $default_from);
$date_to   = trim($_GET['to']   ?? $default_to);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = $default_from;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = $default_to;
if ($date_from > $date_to) [$date_from, $date_to] = [$date_to, $date_from];

$dept_filter = trim($_GET['dept'] ?? '');

$sort_col = trim($_GET['sort'] ?? 'net');
if (!in_array($sort_col, ['net', 'pcs', 'gp', 'tx'], true)) $sort_col = 'net';

$sort_dir = strtoupper(trim($_GET['dir'] ?? 'desc'));
if (!in_array($sort_dir, ['ASC', 'DESC'], true)) $sort_dir = 'DESC';

$limit = (int)($_GET['limit'] ?? 20000);
if ($limit <= 0) $limit = 20000;

$store_id = get_office_store_id();
$conn     = get_db_connection();

$rows   = pos_report_get_sku_breakdown($conn, $store_id, $date_from, $date_to, $dept_filter, $sort_col, $sort_dir, $limit);
$totals = pos_report_get_sku_totals($conn, $store_id, $date_from, $date_to, $dept_filter);

$conn->close();

function xe($s) { return htmlspecialchars((string)($s ?? ''), ENT_XML1, 'UTF-8'); }

function xmlRow($cells_xml) { return "<Row>{$cells_xml}</Row>\n"; }

function xmlCells(array $cells) {
    $out = '';
    foreach ($cells as $c) {
        $val  = $c[0] ?? '';
        $sid  = $c[1] ?? 'Default';
        $type = $c[2] ?? 'String';
        if ($val === '' || $val === null) {
            $out .= "<Cell ss:StyleID=\"{$sid}\"/>";
        } else {
            $out .= "<Cell ss:StyleID=\"{$sid}\"><Data ss:Type=\"{$type}\">" . xe($val) . "</Data></Cell>";
        }
    }
    return $out;
}

$suffix = $dept_filter !== '' ? '_' . preg_replace('/[^a-zA-Z0-9가-힣]/u', '', $dept_filter) : '';
$filename = 'POS_SKU_Analysis_' . $date_from . '_' . $date_to . $suffix . '.xls';

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
 <Style ss:ID="Default"><Alignment ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9"/></Style>
 <Style ss:ID="s_title"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="12" ss:Bold="1"/></Style>
 <Style ss:ID="s_sub"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Color="#666666"/></Style>
 <Style ss:ID="s_colhd"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#C6D9F1" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_data"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_num"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Courier New" ss:Size="9"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><NumberFormat ss:Format="#,##0.00"/></Style>
 <Style ss:ID="s_pct"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Courier New" ss:Size="9"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><NumberFormat ss:Format="0.00&quot;%&quot;"/></Style>
 <Style ss:ID="s_total"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#FFFF99" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_total_num"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Courier New" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#FFFF99" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><NumberFormat ss:Format="#,##0.00"/></Style>
</Styles>
<Worksheet ss:Name="SKU Analysis">
<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
 <PageSetup>
  <Layout x:Orientation="Landscape"/>
  <PageMargins x:Bottom="0.3" x:Left="0.2" x:Right="0.2" x:Top="0.3"/>
 </PageSetup>
 <FitToPage/>
 <Print><FitWidth>1</FitWidth><FitHeight>32767</FitHeight><PaperSizeIndex>9</PaperSizeIndex></Print>
</WorksheetOptions>
<Table ss:DefaultRowHeight="15">
<Column ss:AutoFitWidth="0" ss:Width="70"/>
<Column ss:AutoFitWidth="0" ss:Width="220"/>
<Column ss:AutoFitWidth="0" ss:Width="90"/>
<Column ss:AutoFitWidth="0" ss:Width="55"/>
<Column ss:AutoFitWidth="0" ss:Width="55"/>
<Column ss:AutoFitWidth="0" ss:Width="75"/>
<Column ss:AutoFitWidth="0" ss:Width="75"/>
<Column ss:AutoFitWidth="0" ss:Width="85"/>
<Column ss:AutoFitWidth="0" ss:Width="60"/>
<Column ss:AutoFitWidth="0" ss:Width="85"/>
<?php
echo xmlRow(xmlCells([['SKU별 판매 분석', 's_title']]));
$sub = $date_from . ' ~ ' . $date_to . ($dept_filter !== '' ? '  |  DEPT: ' . $dept_filter : '');
echo xmlRow(xmlCells([[$sub, 's_sub']]));
echo xmlRow('');

echo xmlRow(xmlCells([
    ['ITEMCODE', 's_colhd'], ['ITEMNAME', 's_colhd'], ['DEPARTMENT', 's_colhd'],
    ['TX', 's_colhd'], ['PCS', 's_colhd'], ['원가(현재)', 's_colhd'], ['판매가(현재)', 's_colhd'],
    ['NET SALES', 's_colhd'], ['%', 's_colhd'], ['GROSS PROFIT', 's_colhd'],
]));

$total_net = $totals['net'];
foreach ($rows as $r) {
    $net = (float)$r['net_sales'];
    $pct = $total_net > 0 ? round($net / $total_net * 100, 2) : 0;
    echo xmlRow(xmlCells([
        [$r['item_code'], 's_data'],
        [$r['item_name'], 's_data'],
        [$r['department'], 's_data'],
        [(int)$r['tx_count'], 's_num', 'Number'],
        [(float)$r['total_pcs'], 's_num', 'Number'],
        [$r['cur_unit_cost'] !== null ? (float)$r['cur_unit_cost'] : '', 's_num', 'Number'],
        [$r['cur_selling_price'] !== null ? (float)$r['cur_selling_price'] : '', 's_num', 'Number'],
        [$net, 's_num', 'Number'],
        [$pct, 's_pct', 'Number'],
        [(float)$r['gross_profit'], 's_num', 'Number'],
    ]));
}

echo xmlRow(xmlCells([
    ['TOTAL (' . count($rows) . '건 / SKU ' . $totals['sku_count'] . '종)', 's_total'],
    ['', 's_total'], ['', 's_total'], ['', 's_total'],
    [(float)$totals['pcs'], 's_total_num', 'Number'],
    ['', 's_total'], ['', 's_total'],
    [(float)$totals['net'], 's_total_num', 'Number'],
    [100, 's_total_num', 'Number'],
    ['', 's_total'],
]));
?>
</Table>
</Worksheet>
</Workbook>
