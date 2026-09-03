<?php
ob_start();
set_time_limit(120);
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();

$store_id = get_office_store_id();
$conn     = get_db_connection();

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

$all_items = [];

// 1) Cash product purchases
$stmt = $conn->prepare(
    "SELECT supplier_name, delivery_content AS details, amount, payment_date AS ref_date
     FROM office_product_purchases
     WHERE store_id=? AND payment_type='cash'
       AND YEAR(payment_date)=? AND MONTH(payment_date)=?
     ORDER BY payment_date, id"
);
$stmt->bind_param('iii', $store_id, $year, $month);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $all_items[] = ['date'=>$r['ref_date'],'supplier'=>trim($r['supplier_name']),'details'=>$r['details'],'amount'=>(float)$r['amount']];
}
$stmt->close();

// 2) Check product purchases
$stmt = $conn->prepare(
    "SELECT supplier_name, delivery_content AS details, amount, check_issued_date AS ref_date
     FROM office_product_purchases
     WHERE store_id=? AND payment_type='check'
       AND YEAR(check_issued_date)=? AND MONTH(check_issued_date)=?
     ORDER BY check_issued_date, id"
);
$stmt->bind_param('iii', $store_id, $year, $month);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $all_items[] = ['date'=>$r['ref_date'],'supplier'=>trim($r['supplier_name']),'details'=>$r['details'],'amount'=>(float)$r['amount']];
}
$stmt->close();

// 3) ER 저장 상태의 r_ 영수증 아이템
$er_tbl = $conn->query("SHOW TABLES LIKE 'er_saved_state'");
if ($er_tbl && $er_tbl->num_rows > 0) {
    $first    = sprintf('%04d-%02d-01', $year, $month);
    $last_day = date('Y-m-t', strtotime($first));
    $er_q = $conn->prepare(
        "SELECT save_date, state_json FROM er_saved_state
         WHERE store_id=? AND save_date BETWEEN ? AND ?"
    );
    if ($er_q) {
        $er_q->bind_param('iss', $store_id, $first, $last_day);
        $er_q->execute();
        foreach ($er_q->get_result()->fetch_all(MYSQLI_ASSOC) as $er_row) {
            $state = json_decode($er_row['state_json'], true);
            if (!$state || !isset($state['sections'])) continue;
            foreach (['selling', 'check_sup'] as $sec) {
                $sec_rows = office_refresh_supplier_names($conn, $state['sections'][$sec] ?? []);
                foreach ($sec_rows as $row) {
                    $bid = (string)($row['item_id'] ?? '');
                    if (strncmp($bid, 'r_', 2) !== 0) continue;
                    $amt = (float)($row['amount'] ?? 0);
                    if ($amt <= 0) continue;
                    $all_items[] = ['date'=>$er_row['save_date'],'supplier'=>trim($row['supplier']??''),'details'=>$row['details']??'','amount'=>$amt];
                }
            }
        }
        $er_q->close();
    }
}

// 인쇄 제목에 사용할 실제 회사명(상호) — stores.company_name 우선, 없으면 점포명으로 대체
$company_display = '';
if ($store_id > 0) {
    $st_row = $conn->prepare("SELECT company_name, name FROM stores WHERE id = ?");
    $st_row->bind_param('i', $store_id);
    $st_row->execute();
    $store_row = $st_row->get_result()->fetch_assoc();
    $st_row->close();
    $company_display = trim($store_row['company_name'] ?? '') ?: trim($store_row['name'] ?? '');
}
if ($company_display === '') {
    $company_display = trim($_SESSION['store_name'] ?? '') ?: 'HOME K MART';
}

$conn->close();

usort($all_items, fn($a, $b) => strcmp($a['date'], $b['date']));

$groups      = [];
$grand_total = 0.0;
foreach ($all_items as $item) {
    if ($item['amount'] <= 0) continue;
    $key = mb_strtolower($item['supplier']);
    if (!isset($groups[$key])) $groups[$key] = ['label' => $item['supplier'], 'items' => []];
    $groups[$key]['items'][] = $item;
    $grand_total += $item['amount'];
}
ksort($groups);

function xe($s) { return htmlspecialchars((string)($s ?? ''), ENT_XML1, 'UTF-8'); }
function xn($n) { return number_format((float)$n, 2, '.', ''); }

$filename = sprintf('Purchase_Report_%d_%d.xls', $year, $month);
ob_end_clean();
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:x="urn:schemas-microsoft-com:office:excel">
<Styles>
  <Style ss:ID="Default"><Alignment ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="10"/></Style>
  <Style ss:ID="title"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="16" ss:Bold="1"/></Style>
  <Style ss:ID="company"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1"/></Style>
  <Style ss:ID="totlbl"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1"/></Style>
  <Style ss:ID="totval"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="12" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#DC2626" ss:Pattern="Solid"/><NumberFormat ss:Format="#,##0.00"/></Style>
  <Style ss:ID="hdr"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="10" ss:Bold="1"/><Interior ss:Color="#E5E7EB" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
  <Style ss:ID="supplier"><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/><Font ss:FontName="Arial" ss:Size="10" ss:Bold="1"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
  <Style ss:ID="date"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="10"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
  <Style ss:ID="detail"><Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:WrapText="1"/><Font ss:FontName="Arial" ss:Size="10"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
  <Style ss:ID="amt"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="10"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><NumberFormat ss:Format="#,##0.00"/></Style>
  <Style ss:ID="subtotal"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="10" ss:Bold="1"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><NumberFormat ss:Format="#,##0.00"/></Style>
  <Style ss:ID="grandlbl"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#DC2626" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
  <Style ss:ID="grandval"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#DC2626" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><NumberFormat ss:Format="#,##0.00"/></Style>
  <Style ss:ID="s_empty"><Font ss:FontName="Arial" ss:Size="10"/></Style>
</Styles>
<Worksheet ss:Name="Purchase Report">
<Table>
  <Column ss:Width="160"/>
  <Column ss:Width="70"/>
  <Column ss:Width="320"/>
  <Column ss:Width="85"/>
  <Column ss:Width="85"/>

  <Row ss:Height="26">
    <Cell ss:MergeAcross="4" ss:StyleID="title"><Data ss:Type="String"><?php echo xe(sprintf('%d년 %d월 업체별 입고 보고서', $year, $month)); ?></Data></Cell>
  </Row>
  <Row ss:Height="20">
    <Cell ss:MergeAcross="2" ss:StyleID="company"><Data ss:Type="String"><?php echo xe($company_display); ?></Data></Cell>
    <Cell ss:StyleID="totlbl"><Data ss:Type="String">전체 합계 :</Data></Cell>
    <Cell ss:StyleID="totval"><Data ss:Type="Number"><?php echo xn($grand_total); ?></Data></Cell>
  </Row>
  <Row ss:Height="6"><Cell ss:StyleID="s_empty"/></Row>

  <Row ss:Height="16">
    <Cell ss:StyleID="hdr"><Data ss:Type="String">업체명</Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String">날짜</Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String">내용</Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String">금액</Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String">합계금액</Data></Cell>
  </Row>

<?php foreach ($groups as $group):
    $supplier = $group['label'];
    $items    = $group['items'];
    $count    = count($items);
    $subtotal = array_sum(array_column($items, 'amount'));
?>
<?php foreach ($items as $idx => $item): ?>
  <Row ss:Height="16">
<?php if ($idx === 0): ?>
    <Cell ss:StyleID="supplier"<?php echo $count > 1 ? " ss:MergeDown=\"" . ($count - 1) . "\"" : ''; ?>><Data ss:Type="String"><?php echo xe($supplier); ?></Data></Cell>
<?php endif; ?>
    <Cell ss:Index="2" ss:StyleID="date"><Data ss:Type="String"><?php echo xe(date('m/d (D)', strtotime($item['date']))); ?></Data></Cell>
    <Cell ss:StyleID="detail"><Data ss:Type="String"><?php echo xe($item['details']); ?></Data></Cell>
    <Cell ss:StyleID="amt"><Data ss:Type="Number"><?php echo xn($item['amount']); ?></Data></Cell>
<?php if ($idx === 0): ?>
    <Cell ss:StyleID="subtotal"<?php echo $count > 1 ? " ss:MergeDown=\"" . ($count - 1) . "\"" : ''; ?>><Data ss:Type="Number"><?php echo xn($subtotal); ?></Data></Cell>
<?php endif; ?>
  </Row>
<?php endforeach; ?>
<?php endforeach; ?>

  <Row ss:Height="18">
    <Cell ss:MergeAcross="2" ss:StyleID="grandlbl"><Data ss:Type="String"><?php echo xe(sprintf('총 합계 (%d건)', count($all_items))); ?></Data></Cell>
    <Cell ss:StyleID="grandlbl"><Data ss:Type="String"></Data></Cell>
    <Cell ss:StyleID="grandval"><Data ss:Type="Number"><?php echo xn($grand_total); ?></Data></Cell>
  </Row>
</Table>
<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
  <PageSetup>
    <Layout ss:Orientation="Portrait"/>
    <Header ss:Margin="0.2"/>
    <Footer ss:Margin="0.2"/>
    <PageMargins ss:Bottom="0.3" ss:Left="0.3" ss:Right="0.3" ss:Top="0.3"/>
  </PageSetup>
  <Print>
    <FitWidth>1</FitWidth>
    <FitHeight>0</FitHeight>
  </Print>
  <FitToPage/>
</WorksheetOptions>
</Worksheet>
</Workbook>
<?php exit;
