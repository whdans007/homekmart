<?php
// Design Ref: sales-expenses-report.design.md §10.2 — export_sales_report.php와 동일하게
// PhpSpreadsheet가 아닌 SpreadsheetML(XML) 직접 생성 방식을 사용한다 (기존 컨벤션 재사용).
require_once __DIR__ . '/lib/auth.php';
mo_require_admin();
require_once __DIR__ . '/../office/lib/sales_expenses_report_helper.php';

$store_id = (int)($_GET['store_id'] ?? 0);
$year     = (int)($_GET['year']  ?? date('Y'));
$month    = (int)($_GET['month'] ?? date('n'));

$conn = get_db_connection();
$store_stmt = $conn->prepare(
    "SELECT name, company_name FROM stores
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

// 실제 회사명(상호) — stores.company_name 우선, 없으면 점포명으로 대체 (print_cd.php/print_cer.php와 동일 로직)
$company_display = trim($store_row['company_name'] ?? '') ?: trim($store_row['name'] ?? '');

$report = get_monthly_sales_expenses_report($store_id, $year, $month);
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
function xcell(
    string $val, string $style, int $mergeAcross = 0, string $type = 'String',
    int $mergeDown = 0, int $index = 0
): string {
    $ma  = $mergeAcross > 0 ? ' ss:MergeAcross="' . $mergeAcross . '"' : '';
    $md  = $mergeDown > 0 ? ' ss:MergeDown="' . $mergeDown . '"' : '';
    $idx = $index > 0 ? ' ss:Index="' . $index . '"' : '';
    $sid = ' ss:StyleID="' . $style . '"';
    if ($val === '') return '<Cell' . $idx . $sid . $ma . $md . '/>';
    return '<Cell' . $idx . $sid . $ma . $md . '><Data ss:Type="' . $type . '">' . xesc($val) . '</Data></Cell>';
}

$filename = 'SalesExpensesReport_' . htmlspecialchars($store_display, ENT_QUOTES) . '_' . date('Y_m', mktime(0, 0, 0, $month, 1, $year)) . '.xls';
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
$xml .= '<Style ss:ID="s_def"><Alignment ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_title"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="13" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#c2410c" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_month"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#ea580c" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_hdr"><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/><Font ss:FontName="Arial" ss:Size="8" ss:Bold="1"/><Interior ss:Color="#f3f4f6" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_date"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_num"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_tot"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1" ss:Color="#991b1b"/><Interior ss:Color="#fee2e2" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_neg"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Color="#dc2626"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_grand"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#fee2e2" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_grand_date"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#fee2e2" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="2"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_particular"><Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:WrapText="1"/><Font ss:FontName="Arial" ss:Size="9"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#888888"/></Borders></Style>' . "\n";
$xml .= '<Style ss:ID="s_plossLbl"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="10" ss:Bold="1"/></Style>' . "\n";
$xml .= '<Style ss:ID="s_plossVal"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1" ss:Color="#15803d"/></Style>' . "\n";
$xml .= '<Style ss:ID="s_plossValNeg"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1" ss:Color="#dc2626"/></Style>' . "\n";
$xml .= '</Styles>' . "\n";

$xml .= '<Worksheet ss:Name="Sales and Expenses">' . "\n";
$xml .= '<Table ss:DefaultRowHeight="16">' . "\n";
$widths = [120, 85,85, 85,85, 200];
foreach ($widths as $w) { $xml .= '<Column ss:Width="' . $w . '"/>' . "\n"; }

$report_title = 'SALES AND EXPENSES REPORT (' . strtoupper($month_label) . ')';
$xml .= xrow(26, xcell($report_title, 's_title', 5));
$xml .= xrow(18, xcell($company_display, 's_month', 5));
// 화면(sales_expenses_report.php)과 동일하게 헤더를 2단으로 구성한다.
// 3번째 줄: DATE(세로병합) / SALES(가로병합) / EXPENSES(가로병합) / PARTICULAR(세로병합)
// 4번째 줄: CASH / CREDITS / CASH / CHEQUE
$xml .= xrow(18,
    xcell('DATE',       's_hdr', 0, 'String', 1) .
    xcell('SALES',      's_hdr', 1) .
    xcell('EXPENSES',   's_hdr', 1) .
    xcell('PARTICULAR', 's_hdr', 0, 'String', 1)
);
$xml .= xrow(18,
    xcell('CASH',    's_hdr', 0, 'String', 0, 2) .
    xcell('CREDITS', 's_hdr') .
    xcell('CASH',    's_hdr') .
    xcell('CHEQUE',  's_hdr')
);

for ($d = 1; $d <= $days; $d++) {
    $r   = $rows[$d];
    $dt  = mktime(0, 0, 0, $month, $d, $year);
    $lbl = date('M j', $dt) . ' ' . $days_en[date('w', $dt)];
    $ns  = 's_num';
    $xml .= xrow(16,
        xcell($lbl, 's_date') .
        xcell(xnum($r['cash_sales']),     $ns, 0, 'Number') .
        xcell(xnum($r['credit_sales']),   $ns, 0, 'Number') .
        xcell(xnum($r['cash_expense']),   $ns, 0, 'Number') .
        xcell(xnum($r['cheque_expense']), $ns, 0, 'Number') .
        xcell($r['particular'], 's_particular')
    );
}

$xml .= xrow(18,
    xcell('TOTAL', 's_grand_date') .
    xcell(xnum($totals['cash_sales'],     false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['credit_sales'],   false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['cash_expense'],   false), 's_grand', 0, 'Number') .
    xcell(xnum($totals['cheque_expense'], false), 's_grand', 0, 'Number') .
    xcell('', 's_grand')
);

// PROFIT/LOSS 요약 행 (빈 줄 + 라벨/값)
$xml .= xrow(10, '');
$xml .= xrow(20,
    xcell('', 's_def', 3) .
    xcell('PROFIT/LOSS:', 's_plossLbl') .
    xcell(xnum($report['profit_loss'], false), $report['profit_loss'] < 0 ? 's_plossValNeg' : 's_plossVal', 0, 'Number')
);

$xml .= '</Table>' . "\n";
$xml .= '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">' . "\n";
$xml .= '<PageSetup><Layout x:Orientation="Portrait"/><PageMargins x:Top="0.5" x:Bottom="0.5" x:Left="0.5" x:Right="0.5"/></PageSetup>' . "\n";
$xml .= '<FitToPage/><Print><FitWidth>1</FitWidth><FitHeight>0</FitHeight><ValidPrinterInfo/><PaperSizeIndex>9</PaperSizeIndex></Print>' . "\n";
$xml .= '<FreezePanes/><FrozenNoSplit/><SplitHorizontal>4</SplitHorizontal><TopRowBottomPane>4</TopRowBottomPane><ActivePane>2</ActivePane>' . "\n";
$xml .= '</WorksheetOptions>' . "\n";
$xml .= '</Worksheet>' . "\n";
$xml .= '</Workbook>';

echo $xml;
