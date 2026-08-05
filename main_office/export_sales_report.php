<?php
// Design Ref: sales-report-main-office — exports the exact same get_monthly_sales_report()
// data shown on screen (sales_report.php) to Excel. office/sales/export_monthly.php uses the
// old expected_cash basis, which doesn't match the on-screen figures, so it's kept separate
// (not reused here).
require_once __DIR__ . '/lib/auth.php';
mo_require_admin();
require_once __DIR__ . '/../office/lib/sales_report_helper.php';

$store_id = (int)($_GET['store_id'] ?? 0);
$year     = (int)($_GET['year']  ?? date('Y'));
$month    = (int)($_GET['month'] ?? date('n'));

$conn = get_db_connection();
$store_stmt = $conn->prepare(
    "SELECT name FROM stores
     WHERE id=? AND name NOT IN ('CENTER (물류센터)', 'KIMS MALL WHEREHOUSE (킴스몰 창고)')"
);
$store_stmt->bind_param('i', $store_id);
$store_stmt->execute();
$store_row = $store_stmt->get_result()->fetch_assoc();
$store_stmt->close();
$conn->close();

if (!$store_row) {
    http_response_code(404);
    exit('Invalid store');
}

$store_display = $store_row['name'];
if (($_p = strpos($store_display, ' (')) !== false) {
    $store_display = substr($store_display, 0, $_p);
}
$store_display = strtoupper(trim($store_display));

$report = get_monthly_sales_report($store_id, $year, $month);
$days   = $report['days'];
$rows   = $report['rows'];
$totals = $report['col_totals'];
$month_label = date('F Y', mktime(0, 0, 0, $month, 1, $year));
$days_en = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

// XML helpers
function xesc(string $v): string {
    return htmlspecialchars($v, ENT_XML1, 'UTF-8');
}
function xnum(float $n, bool $zeroBlank = true): string {
    if ($zeroBlank && $n == 0) return '';
    return number_format($n, 2);
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

$filename = 'SalesReport_' . htmlspecialchars($store_display, ENT_QUOTES) . '_' . date('Y_m', mktime(0, 0, 0, $month, 1, $year)) . '.xls';
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$xml .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
$xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
$xml .= '  xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
$xml .= '  xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n";
$xml .= '  xmlns:o="urn:schemas-microsoft-com:office:office">' . "\n";

$xml .= '<Styles>' . "\n";
$xml .= '<Style ss:ID="s_def"><Alignment ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_title"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="13" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1a5276" ss:Pattern="Solid"/></Style>' . "\n";
$xml .= '<Style ss:ID="s_month"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#2e86c1" ss:Pattern="Solid"/></Style>' . "\n";
$xml .= '<Style ss:ID="s_hdr"><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/><Font ss:FontName="Arial" ss:Size="8" ss:Bold="1"/><Interior ss:Color="#f3f4f6" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_date"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_num"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_tot"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1" ss:Color="#991b1b"/><Interior ss:Color="#fee2e2" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_neg"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Color="#dc2626"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#dddddd"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_grand"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#fee2e2" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_grand_date"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#fee2e2" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>' . "\n";
$xml .= '</Styles>' . "\n";

$xml .= '<Worksheet ss:Name="Sales Report">' . "\n";
$xml .= '<Table ss:DefaultRowHeight="16">' . "\n";
$widths = [120, 72,72, 72,72, 72,72, 72, 72,82, 72, 85, 78, 78, 78, 82];
foreach ($widths as $w) { $xml .= '<Column ss:Width="' . $w . '"/>' . "\n"; }

$report_title = 'HOME K MART' . ($store_display !== '' ? ' ' . $store_display : '') . ' SALES REPORT';
$xml .= xrow(26, xcell($report_title, 's_title', 15));
$xml .= xrow(18, xcell($month_label, 's_month', 15));
$hdr_cells =
    xcell('DATE',        's_hdr') .
    xcell('GY POS1',     's_hdr') .
    xcell('GY POS2',     's_hdr') .
    xcell('MRN POS1',    's_hdr') .
    xcell('MRN POS2',    's_hdr') .
    xcell('MID POS1',    's_hdr') .
    xcell('MID POS2',    's_hdr') .
    xcell('DELIVERY K',  's_hdr') .
    xcell('POS (Credit)', 's_hdr') .
    xcell('Credit Invoice', 's_hdr') .
    xcell('WHOLE SALE',  's_hdr') .
    xcell('SALES TOTAL', 's_hdr') .
    xcell('PURCHASE',    's_hdr') .
    xcell('STORE EXP',   's_hdr') .
    xcell('TRANSFER',    's_hdr') .
    xcell('NET',         's_hdr');
$xml .= xrow(22, $hdr_cells);

for ($d = 1; $d <= $days; $d++) {
    $r   = $rows[$d];
    $dt  = mktime(0, 0, 0, $month, $d, $year);
    $lbl = date('M j', $dt) . ' ' . $days_en[date('w', $dt)];
    $ns  = 's_num';
    $xml .= xrow(16,
        xcell($lbl, 's_date') .
        xcell(xnum($r['gy1']), $ns, 0, 'Number') .
        xcell(xnum($r['gy2']), $ns, 0, 'Number') .
        xcell(xnum($r['mo1']), $ns, 0, 'Number') .
        xcell(xnum($r['mo2']), $ns, 0, 'Number') .
        xcell(xnum($r['mi1']), $ns, 0, 'Number') .
        xcell(xnum($r['mi2']), $ns, 0, 'Number') .
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

$xml .= xrow(18,
    xcell('TOTAL', 's_grand_date') .
    xcell(xnum($totals['gy_pos1'],     false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['gy_pos2'],     false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['morning_pos1'],false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['morning_pos2'],false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['mid_pos1'],    false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['mid_pos2'],    false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['delivery_k'],  false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['pos_credit'],  false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['credit_doc'],  false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['whole_sale'],  false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['sales_total'], false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['purchase'],    false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['equip'],       false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['transfer'],    false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['net'],         false), 's_grand', 0, 'Number')
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
