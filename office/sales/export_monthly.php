<?php
// Design Ref: homekmart-store-config §5.2
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();

$store_id    = get_office_store_id();
$year        = (int)($_GET['year']  ?? date('Y'));
$month       = (int)($_GET['month'] ?? date('n'));
$days        = (int)date('t', mktime(0,0,0,$month,1,$year));
$month_label = date('F Y', mktime(0,0,0,$month,1,$year));

$conn = get_db_connection();

// 리포트 제목에 표시할 현재 지점명 조회
// 예) "SUNSET (선셋점)" → 브랜딩 형식에 맞춰 앞의 영문 부분만 대문자로 표시
$store_display = '';
$st_name = $conn->prepare("SELECT name FROM stores WHERE id=? LIMIT 1");
$st_name->bind_param('i', $store_id);
$st_name->execute();
if ($sr = $st_name->get_result()->fetch_assoc()) {
    $store_display = $sr['name'] ?? '';
}
$st_name->close();
$conn->close();
if (($_p = strpos($store_display, ' (')) !== false) {
    $store_display = substr($store_display, 0, $_p);
}
$store_display = strtoupper(trim($store_display));

// Design Ref: sales-report-main-office — 화면(monthly_report.php)과 동일한 공용 집계 함수를 사용해
// 엑셀 다운로드 데이터가 화면 데이터와 어긋나지 않도록 한다 (계산식 drift 방지).
require_once __DIR__ . '/../lib/sales_report_helper.php';
$report     = get_monthly_sales_report($store_id, $year, $month);
$rows       = $report['rows'];
$col_totals = $report['col_totals'];

$days_en = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

// XML helpers
function xesc(string $v): string {
    return htmlspecialchars($v, ENT_XML1, 'UTF-8');
}
function xnum(float $n, bool $zeroBlank = true): string {
    if ($zeroBlank && $n == 0) return '';
    return number_format($n, 2, '.', '');
}
function xrow(int $h, string $cells): string {
    return '<Row ss:Height="' . $h . '">' . $cells . "</Row>\n";
}
function xcell(string $val, string $style, int $mergeAcross = 0, string $type = 'String'): string {
    $ma  = $mergeAcross > 0 ? ' ss:MergeAcross="' . $mergeAcross . '"' : '';
    $sid = ' ss:StyleID="' . $style . '"';
    if ($val === '') return '<Cell' . $sid . $ma . '/>';
    return '<Cell' . $sid . $ma . '><Data ss:Type="' . $type . '">' . xesc($val) . '</Data></Cell>';
}
function xnum_cell(float $n, string $style, bool $blank = true): string {
    return xcell(xnum($n, $blank), $style, 0, 'Number');
}

ob_end_clean();

// Output headers
$filename = 'SalesReport_' . date('Y_m', mktime(0,0,0,$month,1,$year)) . '.xls';
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Build XML entirely as a string to avoid any stray whitespace
$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$xml .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
$xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
$xml .= '  xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
$xml .= '  xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n";
$xml .= '  xmlns:o="urn:schemas-microsoft-com:office:office">' . "\n";

// Styles
$xml .= '<Styles>' . "\n";
// Default
$xml .= '<Style ss:ID="s_def"><Alignment ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/></Borders></Style>' . "\n";
// Title
$xml .= '<Style ss:ID="s_title"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="13" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1a5276" ss:Pattern="Solid"/></Style>' . "\n";
// Month
$xml .= '<Style ss:ID="s_month"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#2e86c1" ss:Pattern="Solid"/></Style>' . "\n";
// Header
$xml .= '<Style ss:ID="s_hdr"><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/><Font ss:FontName="Arial" ss:Size="8" ss:Bold="1"/><Interior ss:Color="#f3f4f6" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>' . "\n";
// Date cell
$xml .= '<Style ss:ID="s_date"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/></Borders></Style>' . "\n";
// Data number
$xml .= '<Style ss:ID="s_num"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><NumberFormat ss:Format="#,##0.00"/><Font ss:FontName="Arial" ss:Size="9"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/></Borders></Style>' . "\n";
// Sales total (red bg)
$xml .= '<Style ss:ID="s_tot"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><NumberFormat ss:Format="#,##0.00"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1" ss:Color="#991b1b"/><Interior ss:Color="#fee2e2" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/></Borders></Style>' . "\n";
// Negative net
$xml .= '<Style ss:ID="s_neg"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><NumberFormat ss:Format="#,##0.00"/><Font ss:FontName="Arial" ss:Size="9" ss:Color="#dc2626"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/></Borders></Style>' . "\n";
// Grand total row
$xml .= '<Style ss:ID="s_grand"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><NumberFormat ss:Format="#,##0.00"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#fee2e2" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_grand_date"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#fee2e2" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>' . "\n";
$xml .= '</Styles>' . "\n";

// Worksheet
$xml .= '<Worksheet ss:Name="Sales Report">' . "\n";
$xml .= '<Table ss:DefaultRowHeight="16">' . "\n";
// 날짜 + 가변 POS 열 + 나머지 매출 항목
$widths = array_merge([120], array_fill(0, count($report['pos_keys']), 72), [72, 72, 82, 72, 85, 78, 78, 78, 82]);
foreach ($widths as $w) { $xml .= '<Column ss:Width="' . $w . '"/>' . "\n"; }

// 제목은 현재 보고서 전체 열에 걸쳐 병합
$report_title = 'HOME K MART' . ($store_display !== '' ? ' ' . $store_display : '') . ' SALES REPORT';
$xml .= xrow(26, xcell($report_title, 's_title', count($widths) - 1));
// Row 2: Month label
$xml .= xrow(18, xcell($month_label, 's_month', count($widths) - 1));
// Row 3: Headers (single row, simplified — no MergeDown)
$hdr_cells = xcell('DATE', 's_hdr');
foreach ($report['pos_keys'] as $pos_key) {
    [$shift_key, $pos_label] = explode('_', $pos_key, 2);
    $hdr_cells .= xcell(strtoupper($shift_key === 'morning' ? 'MRN' : $shift_key) . ' ' . strtoupper($pos_label), 's_hdr');
}
$hdr_cells .=
    xcell('DELIVERY K',    's_hdr') .
    xcell('POS (외상)',     's_hdr') .
    xcell('거래명세서',      's_hdr') .
    xcell('WHOLE SALE',    's_hdr') .
    xcell('SALES TOTAL',   's_hdr') .
    xcell('PURCHASE',      's_hdr') .
    xcell('STORE EXP',     's_hdr') .
    xcell('TRANSFER',      's_hdr') .
    xcell('NET',           's_hdr');
$xml .= xrow(22, $hdr_cells);

// Data rows
for ($d = 1; $d <= $days; $d++) {
    $r   = $rows[$d];
    $dow = $days_en[date('w', mktime(0,0,0,$month,$d,$year))];
    $lbl = date('M j', mktime(0,0,0,$month,$d,$year)) . ' ' . $dow;
    $ns  = 's_num';
    $pos_cells = '';
    foreach ($report['pos_keys'] as $pos_key) {
        $pos_cells .= xcell(xnum($r[$pos_key]), $ns, 0, 'Number');
    }
    $xml .= xrow(16,
        xcell($lbl, 's_date') .
        $pos_cells .
        xcell(xnum($r['dk']),  $ns, 0, 'Number') .
        xcell(xnum($r['pc']),  $ns, 0, 'Number') .
        xcell(xnum($r['cd']),  $ns, 0, 'Number') .
        xcell(xnum($r['ws']),  $ns, 0, 'Number') .
        xcell(xnum($r['st']),  's_tot', 0, 'Number') .
        xcell(xnum($r['pu']),  $ns, 0, 'Number') .
        xcell(xnum($r['eq']),  $ns, 0, 'Number') .
        xcell(xnum($r['tr']),  $ns, 0, 'Number') .
        xcell(xnum($r['net']), $r['net'] < 0 ? 's_neg' : $ns, 0, 'Number')
    );
}

// Total row
$pos_total_cells = '';
foreach ($report['pos_keys'] as $pos_key) {
    $pos_total_cells .= xcell(xnum($col_totals[$pos_key], false), 's_grand', 0, 'Number');
}
$xml .= xrow(18,
    xcell('TOTAL', 's_grand_date') .
    $pos_total_cells .
    xcell(xnum($col_totals['delivery_k'],  false), 's_grand', 0, 'Number') .
    xcell(xnum($col_totals['pos_credit'],  false), 's_grand', 0, 'Number') .
    xcell(xnum($col_totals['credit_doc'],  false), 's_grand', 0, 'Number') .
    xcell(xnum($col_totals['whole_sale'],  false), 's_grand', 0, 'Number') .
    xcell(xnum($col_totals['sales_total'], false), 's_grand', 0, 'Number') .
    xcell(xnum($col_totals['purchase'],    false), 's_grand', 0, 'Number') .
    xcell(xnum($col_totals['equip'],       false), 's_grand', 0, 'Number') .
    xcell(xnum($col_totals['transfer'],    false), 's_grand', 0, 'Number') .
    xcell(xnum($col_totals['net'],         false), 's_grand', 0, 'Number')
);

$xml .= '</Table>' . "\n";
$xml .= '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">' . "\n";
$xml .= '<PageSetup><Layout x:Orientation="Landscape"/><PageMargins x:Top="0.5" x:Bottom="0.5" x:Left="0.5" x:Right="0.5"/></PageSetup>' . "\n";
$xml .= '<FitToPage/><Print><FitWidth>1</FitWidth><FitHeight>0</FitHeight><ValidPrinterInfo/><PaperSizeIndex>9</PaperSizeIndex></Print>' . "\n";
$xml .= '<FreezePanes/><FrozenNoSplit/><SplitHorizontal>3</SplitHorizontal><TopRowBottomPane>3</TopRowBottomPane><ActivePane>2</ActivePane>' . "\n";
$xml .= '</WorksheetOptions>' . "\n";
$xml .= '</Worksheet>' . "\n";
$xml .= '</Workbook>';

echo $xml;
