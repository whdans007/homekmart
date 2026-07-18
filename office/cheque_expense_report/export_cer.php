<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

if (empty($_POST['data'])) { http_response_code(400); exit; }
$payload  = json_decode($_POST['data'], true);
$date_str = preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload['date'] ?? '') ? $payload['date'] : date('Y-m-d');
$secs     = $payload['sections'] ?? ['korean'=>[],'local'=>[],'fixed'=>[],'others'=>[]];

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

function xe($s) { return htmlspecialchars((string)($s ?? ''), ENT_XML1, 'UTF-8'); }
function xn($n) { return number_format((float)$n, 2, '.', ''); }
function fmtD2($s) {
    if (!$s) return '';
    $p = explode('-', $s);
    return ($p[1] ?? '') . '/' . ($p[2] ?? '') . '/' . ($p[0] ?? '');
}

$section_config = [
    'korean' => ['label' => '1. KOREAN',        'rows' => 8],
    'local'  => ['label' => '2. LOCAL',          'rows' => 8],
    'fixed'  => ['label' => '3. FIXED EXPENSE',  'rows' => 5],
    'others' => ['label' => '4. OTHERS',          'rows' => 8],
];

$grand = 0;
foreach ($section_config as $sk => $sc) {
    foreach ($secs[$sk] ?? [] as $r) {
        if (empty($r['returned'])) $grand += (float)($r['amount'] ?? 0);
    }
}

$filename = 'ChequeExpenseReport_' . $year . '_' . str_pad($month, 2, '0', STR_PAD_LEFT) . '_' . str_pad($day, 2, '0', STR_PAD_LEFT) . '.xls';

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:x="urn:schemas-microsoft-com:office:excel">
<Styles>
  <Style ss:ID="title"><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/><Font ss:Bold="1" ss:Size="11"/><Interior ss:Color="#E8968A" ss:Pattern="Solid"/></Style>
  <Style ss:ID="prep"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:Bold="1" ss:Size="8"/></Style>
  <Style ss:ID="name"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:Bold="1" ss:Size="10" ss:Color="#CC5544"/></Style>
  <Style ss:ID="meta"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:Bold="1"/><Interior ss:Color="#F0F0F0" ss:Pattern="Solid"/></Style>
  <Style ss:ID="hdr"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:Bold="1"/><Interior ss:Color="#D9D9D9" ss:Pattern="Solid"/></Style>
  <Style ss:ID="sec"><Font ss:Bold="1"/><Interior ss:Color="#EDE9FE" ss:Pattern="Solid"/></Style>
  <Style ss:ID="num"><Alignment ss:Horizontal="Right"/><NumberFormat ss:Format="#,##0.00"/></Style>
  <Style ss:ID="ctr"><Alignment ss:Horizontal="Center"/></Style>
  <Style ss:ID="tot"><Alignment ss:Horizontal="Right"/><Font ss:Bold="1"/><Interior ss:Color="#F0F0F0" ss:Pattern="Solid"/><NumberFormat ss:Format="#,##0.00"/></Style>
  <Style ss:ID="totlbl"><Alignment ss:Horizontal="Right"/><Font ss:Bold="1"/><Interior ss:Color="#F0F0F0" ss:Pattern="Solid"/></Style>
  <Style ss:ID="ret"><Font ss:Strikethrough="1" ss:Color="#999999"/></Style>
</Styles>
<Worksheet ss:Name="Cheque Expense Report">
<Table>
  <Column ss:Width="20"/>
  <Column ss:Width="70"/>
  <Column ss:Width="130"/>
  <Column ss:Width="50"/>
  <Column ss:Width="80"/>
  <Column ss:Width="130"/>
  <Column ss:Width="70"/>

  <!-- Row 1: Title (merged 2 rows × 5 cols) + PREPARED + APPROVED -->
  <Row ss:Height="30">
    <Cell ss:MergeAcross="4" ss:MergeDown="1" ss:StyleID="title"><Data ss:Type="String">CHEQUE EXPENSE REPORT&#10;(<?php echo xe(strtoupper($company_display)); ?>)</Data></Cell>
    <Cell ss:StyleID="prep"><Data ss:Type="String">PREPARED</Data></Cell>
    <Cell ss:StyleID="prep"><Data ss:Type="String">APPROVED</Data></Cell>
  </Row>
  <!-- Row 2: (Title continues cols 1-5) + LIZA + SIR MIN -->
  <Row ss:Height="18">
    <Cell ss:Index="6" ss:StyleID="name"><Data ss:Type="String"><?php echo xe($prepared_by); ?></Data></Cell>
    <Cell ss:StyleID="name"><Data ss:Type="String"><?php echo xe($approved_by); ?></Data></Cell>
  </Row>
  <!-- Row 3: Year / Month / Day -->
  <Row ss:Height="14">
    <Cell ss:StyleID="meta"><Data ss:Type="String">YEAR</Data></Cell>
    <Cell ss:StyleID="meta"><Data ss:Type="Number"><?php echo $year; ?></Data></Cell>
    <Cell ss:StyleID="meta"><Data ss:Type="String">MONTH</Data></Cell>
    <Cell ss:StyleID="meta"><Data ss:Type="Number"><?php echo $month; ?></Data></Cell>
    <Cell ss:StyleID="meta"><Data ss:Type="String">DAY</Data></Cell>
    <Cell ss:StyleID="meta"><Data ss:Type="Number"><?php echo $day; ?></Data></Cell>
    <Cell ss:StyleID="meta"><Data ss:Type="String"></Data></Cell>
  </Row>
  <Row ss:Height="14">
    <Cell ss:StyleID="hdr"><Data ss:Type="String">NO.</Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String">CHECK NUMBER</Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String">SUPPLIER NAME</Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String">DATE</Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String">SALES INVOICE</Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String">PARTICULAR</Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String">AMOUNT</Data></Cell>
  </Row>

<?php
$row_no = 1;
foreach ($section_config as $sec_key => $sc):
    $data_rows = $secs[$sec_key] ?? [];
?>
  <Row ss:Height="13">
    <Cell ss:MergeAcross="6" ss:StyleID="sec"><Data ss:Type="String"><?php echo xe($sc['label']); ?></Data></Cell>
  </Row>
<?php
    $filled = 0;
    foreach ($data_rows as $row):
        $is_ret     = !empty($row['returned']);
        $check_disp = $row['check_no'] ?? '';
        if ($is_ret && !empty($row['new_check_no'])) $check_disp = $check_disp . ' RE:' . $row['new_check_no'];
        $style = $is_ret ? 'ret' : 'ctr';
        $amt   = $is_ret ? 0 : (float)($row['amount'] ?? 0);
?>
  <Row ss:Height="13">
    <Cell ss:StyleID="ctr"><Data ss:Type="Number"><?php echo $row_no++; ?></Data></Cell>
    <Cell ss:StyleID="<?php echo $style; ?>"><Data ss:Type="String"><?php echo xe($check_disp); ?></Data></Cell>
    <Cell ss:StyleID="<?php echo $style; ?>"><Data ss:Type="String"><?php echo xe($row['supplier'] ?? ''); ?></Data></Cell>
    <Cell ss:StyleID="ctr"><Data ss:Type="String"><?php echo xe(fmtD2($row['date'] ?? '')); ?></Data></Cell>
    <Cell ss:StyleID="ctr"><Data ss:Type="String"><?php echo xe($row['sales_invoice'] ?? ''); ?></Data></Cell>
    <Cell><Data ss:Type="String"><?php echo xe($row['particular'] ?? ''); ?></Data></Cell>
    <Cell ss:StyleID="num"><Data ss:Type="Number"><?php echo $is_ret ? '' : xn($amt); ?></Data></Cell>
  </Row>
<?php
        $filled++;
    endforeach;
    for ($i = $filled; $i < $sc['rows']; $i++):
?>
  <Row ss:Height="13">
    <Cell ss:StyleID="ctr"><Data ss:Type="Number"><?php echo $row_no++; ?></Data></Cell>
    <Cell/><Cell/><Cell/><Cell/><Cell/><Cell/>
  </Row>
<?php endfor; endforeach; ?>

  <Row ss:Height="14">
    <Cell ss:MergeAcross="5" ss:StyleID="totlbl"><Data ss:Type="String">TOTAL EXPENSES:</Data></Cell>
    <Cell ss:StyleID="tot"><Data ss:Type="Number"><?php echo xn($grand); ?></Data></Cell>
  </Row>
  <Row ss:Height="18">
    <Cell ss:MergeAcross="6"><Data ss:Type="String">Note:</Data></Cell>
  </Row>
</Table>
<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
  <PageSetup>
    <Layout ss:Orientation="Landscape"/>
    <Header ss:Margin="0.1"/>
    <Footer ss:Margin="0.1"/>
    <PageMargins ss:Bottom="0.25" ss:Left="0.25" ss:Right="0.25" ss:Top="0.25"/>
  </PageSetup>
  <Print>
    <PaperSizeIndex>9</PaperSizeIndex>
    <FitWidth>1</FitWidth>
    <FitHeight>0</FitHeight>
  </Print>
  <FitToPage/>
</WorksheetOptions>
</Worksheet>
</Workbook>
