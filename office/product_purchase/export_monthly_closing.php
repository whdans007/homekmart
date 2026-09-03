<?php
// Design Ref: 이 프로젝트의 기존 export 컨벤션(office/daily_report/export_daily_report.php,
// office/expense_report/export_er.php 등)을 따라 PhpSpreadsheet 대신 SpreadsheetML(XML)을
// 직접 생성한다. 화면(monthly_closing.php)과 숫자가 항상 일치하도록 monthly_closing_helper.php의
// get_monthly_closing_report()만을 통해 데이터를 가져온다 (SSOT).
ob_start();
require_once __DIR__ . '/../lib/monthly_closing_helper.php';
require_office_permission();
ob_end_clean();

$store_id = get_office_store_id();
$year     = (int)($_GET['year']  ?? date('Y'));
$month    = (int)($_GET['month'] ?? date('n'));

$conn = get_db_connection();
$store_stmt = $conn->prepare("SELECT name AS label FROM stores WHERE id=?");
$store_stmt->bind_param('i', $store_id);
$store_stmt->execute();
$store_label = $store_stmt->get_result()->fetch_assoc()['label'] ?? 'STORE';
$store_stmt->close();
$conn->close();

$report = get_monthly_closing_report($store_id, $year, $month);

function mc_xe($s) { return htmlspecialchars((string)($s ?? ''), ENT_XML1, 'UTF-8'); }
function mc_xml_row($height, $cells_xml) {
    $h = $height ? " ss:Height=\"{$height}\"" : '';
    return "<Row{$h}>{$cells_xml}</Row>\n";
}
function mc_xml_cells(array $cells) {
    $out = '';
    foreach ($cells as $c) {
        $val  = $c[0] ?? '';
        $sid  = $c[1] ?? 'Default';
        $ma   = isset($c[2]) && $c[2] > 0 ? " ss:MergeAcross=\"{$c[2]}\"" : '';
        $md   = isset($c[3]) && $c[3] > 0 ? " ss:MergeDown=\"{$c[3]}\"" : '';
        $type = $c[4] ?? 'String';
        if ($val === '' || $val === null) {
            $out .= "<Cell ss:StyleID=\"{$sid}\"{$ma}{$md}/>";
        } else {
            $out .= "<Cell ss:StyleID=\"{$sid}\"{$ma}{$md}><Data ss:Type=\"{$type}\">" . mc_xe($val) . "</Data></Cell>";
        }
    }
    return $out;
}
function mc_blank_row($n) { return mc_xml_cells(array_fill(0, $n, ['', 's_empty'])); }

$rows = '';

// 제목
$rows .= mc_xml_row(26, mc_xml_cells([[sprintf('%s %d년 %d월 결산서', $store_label, $year, $month), 's_title', 5]]));
$rows .= mc_xml_row(8, mc_blank_row(6));

// ── 총 매출 / 지출 항목 / 총 지출 ──
$rows .= mc_xml_row(22, mc_xml_cells([
    ['총 매출', 's_revenue', 2],
    [(float)$report['total_sales'], 's_revenue_val', 2, 0, 'Number'],
]));

foreach ($report['items'] as $item) {
    $rows .= mc_xml_row(20, mc_xml_cells([
        [$item['label'], 's_item', 2],
        [(float)$item['amount'], 's_item_val', 2, 0, 'Number'],
    ]));
}

$rows .= mc_xml_row(24, mc_xml_cells([
    ['총 지출', 's_expense', 2],
    [(float)$report['total_expense'], 's_expense_val', 2, 0, 'Number'],
]));

$rows .= mc_xml_row(10, mc_blank_row(6));
$rows .= mc_xml_row(10, mc_blank_row(6));

// ── 수수료 매장 ──
$rows .= mc_xml_row(20, mc_xml_cells([['수수료 매장', 's_sec', 5]]));
$rows .= mc_xml_row(18, mc_xml_cells([
    ['상호명', 's_colhd'], ['%', 's_colhd'], ['총매출', 's_colhd'],
    ['수수료', 's_colhd'], ['세금(12%)', 's_colhd'], ['지급액', 's_colhd'],
]));

if (empty($report['commission_rows'])) {
    $rows .= mc_xml_row(18, mc_xml_cells([['등록된 수수료 매장이 없습니다.', 's_data', 5]]));
} else {
    foreach ($report['commission_rows'] as $crow) {
        $rows .= mc_xml_row(18, mc_xml_cells([
            [$crow['supplier_name'], 's_data'],
            [(float)$crow['rate'], 's_amt', 0, 0, 'Number'],
            [(float)$crow['amount'], 's_amt', 0, 0, 'Number'],
            [(float)$crow['commission'], 's_amt', 0, 0, 'Number'],
            [$crow['tax'] > 0 ? (float)$crow['tax'] : '', 's_amt', 0, 0, 'Number'],
            [(float)$crow['payout'], 's_amt_bold', 0, 0, 'Number'],
        ]));
    }
}

$rows .= mc_xml_row(20, mc_xml_cells([
    ['합계', 's_total', 1],
    [(float)$report['commission_sales_total'], 's_total_amt', 0, 0, 'Number'],
    [(float)$report['commission_fee_total'], 's_total_amt', 0, 0, 'Number'],
    [(float)$report['commission_tax_total'], 's_total_amt', 0, 0, 'Number'],
    [(float)$report['commission_payout_total'], 's_total_amt', 0, 0, 'Number'],
]));

$rows .= mc_xml_row(10, mc_blank_row(6));
$rows .= mc_xml_row(10, mc_blank_row(6));

// ── 하단 요약 ──
$summary = [
    ['총 매출', (float)$report['total_sales']],
    ['수수료매장 매출', (float)$report['commission_sales_total']],
    ['총 도매 + DELIVERY K 매출', (float)$report['total_wholesale_sales']],
    ['총 소매매출', (float)$report['total_retail_sales']],
    ['총 구매지출', (float)$report['total_purchase_with_transfer']],
];
foreach ($summary as [$label, $amount]) {
    $rows .= mc_xml_row(20, mc_xml_cells([
        [$label, 's_sumlabel', 2],
        [$amount, 's_sumval', 2, 0, 'Number'],
    ]));
}

$filename = sprintf('MonthlyClosing_%s_%d_%d.xls', preg_replace('/[^A-Za-z0-9_-]/', '', $store_label), $year, $month);
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
 <Style ss:ID="Default"><Alignment ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11"/></Style>
 <Style ss:ID="s_title"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="16" ss:Bold="1"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2"/></Borders></Style>
 <Style ss:ID="s_revenue"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="12" ss:Bold="1"/><Interior ss:Color="#DDEBF7" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_revenue_val"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="12" ss:Bold="1"/><Interior ss:Color="#DDEBF7" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><NumberFormat ss:Format="&quot;₱&quot;#,##0.00"/></Style>
 <Style ss:ID="s_item"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11"/><Interior ss:Color="#FCE4D6" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_item_val"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1"/><Interior ss:Color="#FCE4D6" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><NumberFormat ss:Format="&quot;₱&quot;#,##0.00"/></Style>
 <Style ss:ID="s_expense"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="12" ss:Bold="1"/><Interior ss:Color="#FFFF00" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_expense_val"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="12" ss:Bold="1"/><Interior ss:Color="#FFFF00" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><NumberFormat ss:Format="&quot;₱&quot;#,##0.00"/></Style>
 <Style ss:ID="s_sec"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="12" ss:Bold="1"/><Interior ss:Color="#D9D9D9" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_colhd"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="10" ss:Bold="1"/><Interior ss:Color="#F2F2F2" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_data"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="10"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_amt"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="10"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><NumberFormat ss:Format="#,##0.00;;&quot;-&quot;"/></Style>
 <Style ss:ID="s_amt_bold"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="10" ss:Bold="1"/><Interior ss:Color="#FFFF00" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><NumberFormat ss:Format="#,##0.00;;&quot;-&quot;"/></Style>
 <Style ss:ID="s_total"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="10" ss:Bold="1"/><Interior ss:Color="#FCE4D6" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_total_amt"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="10" ss:Bold="1"/><Interior ss:Color="#FCE4D6" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><NumberFormat ss:Format="#,##0.00;;&quot;-&quot;"/></Style>
 <Style ss:ID="s_sumlabel"><Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:Indent="1"/><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1"/><Interior ss:Color="#F2F2F2" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="s_sumval"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><NumberFormat ss:Format="&quot;₱&quot;#,##0.00"/></Style>
 <Style ss:ID="s_empty"><Alignment ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11"/></Style>
</Styles>
<Worksheet ss:Name="Monthly Closing">
<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
 <PageSetup>
  <Layout x:Orientation="Portrait"/>
  <PageMargins x:Bottom="0.4" x:Left="0.3" x:Right="0.3" x:Top="0.4"/>
 </PageSetup>
 <FitToPage/>
 <Print><FitWidth>1</FitWidth><FitHeight>32767</FitHeight></Print>
</WorksheetOptions>
<Table ss:DefaultRowHeight="18">
<Column ss:AutoFitWidth="0" ss:Width="150"/>
<Column ss:AutoFitWidth="0" ss:Width="60"/>
<Column ss:AutoFitWidth="0" ss:Width="90"/>
<Column ss:AutoFitWidth="0" ss:Width="90"/>
<Column ss:AutoFitWidth="0" ss:Width="90"/>
<Column ss:AutoFitWidth="0" ss:Width="100"/>
<?php echo $rows; ?>
</Table>
</Worksheet>
</Workbook>
