<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

if (empty($_POST['data'])) { http_response_code(400); exit; }
$payload   = json_decode($_POST['data'], true);
$year      = (int)($payload['year']  ?? date('Y'));
$month     = (int)($payload['month'] ?? date('n'));
$suppliers = $payload['suppliers'] ?? [];
$rows      = $payload['rows']      ?? [];

$today = date('Y-m-d');
$days  = (int)date('t', mktime(0,0,0,$month,1,$year));
$months_en = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
$month_label = $months_en[$month] . ' ' . $year;

function xe($s){return htmlspecialchars((string)($s??''),ENT_XML1,'UTF-8');}
function xn($n){return number_format((float)$n,2,'.','');}

$grid   = [];
$totals = array_fill_keys($suppliers, 0);
for ($d=1; $d<=$days; $d++) {
    $ds = sprintf('%04d-%02d-%02d',$year,$month,$d);
    $grid[$ds] = array_fill_keys($suppliers, []);
}
foreach ($rows as $itemId => $r) {
    if (!isset($grid[$r['date']])) continue;
    if (!in_array($r['supplier'], $suppliers)) continue;
    $grid[$r['date']][$r['supplier']][] = $r;
    $totals[$r['supplier']] += (float)$r['amount'];
}

$fn = 'DeferredTracker_'.sprintf('%04d_%02d',$year,$month).'.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.rawurlencode($fn).'"');
echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
echo '<?mso-application progid="Excel.Sheet"?>'."\n";
$col_count = count($suppliers) + 1;
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:x="urn:schemas-microsoft-com:office:excel">
<Styles>
 <Style ss:ID="Default"><Alignment ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="8"/></Style>
 <Style ss:ID="title"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1"/><Interior ss:Color="#E0E7FF" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="sub"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9"/><Interior ss:Color="#F0F4FF" ss:Pattern="Solid"/></Style>
 <Style ss:ID="hdr"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="8" ss:Bold="1"/><Interior ss:Color="#C7D2FE" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="date"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="8" ss:Bold="1"/><Interior ss:Color="#F9FAFB" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="cell"><Alignment ss:Horizontal="Right" ss:Vertical="Top" ss:WrapText="1"/><Font ss:FontName="Arial" ss:Size="8"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><NumberFormat ss:Format="#,##0.00"/></Style>
 <Style ss:ID="cell0"><Alignment ss:Horizontal="Right" ss:Vertical="Top"/><Font ss:FontName="Arial" ss:Size="8"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
 <Style ss:ID="total"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#D1FAE5" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders><NumberFormat ss:Format="#,##0.00"/></Style>
 <Style ss:ID="total_lbl"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Interior ss:Color="#D1FAE5" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
</Styles>
<Worksheet ss:Name="Deferred Tracker">
<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
 <PageSetup><Layout x:Orientation="Landscape"/><PageMargins x:Bottom="0.3" x:Left="0.2" x:Right="0.2" x:Top="0.3"/></PageSetup>
 <FitToPage/><Print><FitWidth>1</FitWidth><FitHeight>32767</FitHeight><PaperSizeIndex>9</PaperSizeIndex></Print>
</WorksheetOptions>
<Table>
 <Column ss:Width="45"/>
<?php foreach ($suppliers as $s) echo "<Column ss:Width=\"110\"/>\n"; ?>

 <Row ss:Height="18"><Cell ss:MergeAcross="<?php echo $col_count-1; ?>" ss:StyleID="title"><Data ss:Type="String">DEFERRED PAYMENT TRACKER — HOME K MART</Data></Cell></Row>
 <Row ss:Height="13"><Cell ss:MergeAcross="<?php echo $col_count-1; ?>" ss:StyleID="sub"><Data ss:Type="String"><?php echo xe($month_label); ?></Data></Cell></Row>
 <Row ss:Height="14">
  <Cell ss:StyleID="hdr"><Data ss:Type="String">DATE</Data></Cell>
  <?php foreach ($suppliers as $s): ?>
  <Cell ss:StyleID="hdr"><Data ss:Type="String"><?php echo xe($s); ?></Data></Cell>
  <?php endforeach; ?>
 </Row>

<?php
for ($d=1; $d<=$days; $d++):
    $ds = sprintf('%04d-%02d-%02d',$year,$month,$d);
    $dt = new DateTime($ds);
    $date_lbl = $dt->format('M j');
?>
 <Row>
  <Cell ss:StyleID="date"><Data ss:Type="String"><?php echo $date_lbl; ?></Data></Cell>
  <?php foreach ($suppliers as $s):
      $items = $grid[$ds][$s] ?? [];
      if (empty($items)):
  ?>
  <Cell ss:StyleID="cell0"/>
  <?php else:
      $sum = array_sum(array_column($items,'amount'));
      $txt = implode("\n", array_map(fn($i)=> ($i['details']??''). ' ₱'.number_format($i['amount'],2), $items));
  ?>
  <Cell ss:StyleID="cell"><Data ss:Type="Number"><?php echo xn($sum); ?></Data></Cell>
  <?php endif; endforeach; ?>
 </Row>
<?php endfor; ?>

 <Row ss:Height="15">
  <Cell ss:StyleID="total_lbl"><Data ss:Type="String">TOTAL</Data></Cell>
  <?php foreach ($suppliers as $s): ?>
  <Cell ss:StyleID="total"><Data ss:Type="Number"><?php echo xn($totals[$s]); ?></Data></Cell>
  <?php endforeach; ?>
 </Row>
</Table>
</Worksheet>
</Workbook>
