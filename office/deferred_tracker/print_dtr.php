<?php
ob_start();
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();
ob_end_clean();

if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['data'])) {
    $payload = json_decode($_POST['data'], true);
} else {
    $payload = ['year'=>date('Y'),'month'=>date('n'),'suppliers'=>[],'rows'=>[]];
}
$year      = (int)($payload['year']  ?? date('Y'));
$month     = (int)($payload['month'] ?? date('n'));
$suppliers = $payload['suppliers'] ?? [];
$rows      = $payload['rows']      ?? [];

$today = date('Y-m-d');
$days  = (int)date('t', mktime(0,0,0,$month,1,$year));
$months_en = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
$month_label = $months_en[$month] . ' ' . $year;

function e($s) { return htmlspecialchars((string)($s??''), ENT_QUOTES, 'UTF-8'); }
function a($n) { return $n ? number_format((float)$n,2) : ''; }

// Build grid
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Deferred Tracker — <?php echo e($month_label); ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:Arial,sans-serif;font-size:7.5pt;background:#ccc;}
@page{size:A4 landscape;margin:7mm;}
@media print{body{background:white;}.no-print{display:none!important;}}
.no-print{text-align:center;padding:8px;background:#eef2ff;margin-bottom:8px;}
.no-print button{padding:5px 16px;border:none;border-radius:4px;cursor:pointer;font-size:11px;margin:0 3px;color:white;}
.btn-pr{background:#4f46e5;}.btn-cl{background:#6b7280;}
.sheet{max-width:283mm;margin:0 auto;background:white;}
table{border-collapse:collapse;width:100%;}
td,th{border:0.5pt solid #666;padding:1.5pt 2.5pt;font-size:7pt;vertical-align:top;}
.title{font-size:11pt;font-weight:bold;text-align:center;background:#e0e7ff;height:18pt;vertical-align:middle;}
.sub{font-size:8pt;text-align:center;background:#f0f4ff;height:13pt;vertical-align:middle;}
.hdr{background:#c7d2fe;font-weight:bold;text-align:center;height:13pt;vertical-align:middle;}
.date-col{background:#f9fafb;font-weight:bold;text-align:center;width:40pt;}
.amount-cell{text-align:right;font-family:'Courier New',monospace;}
.sum-row{background:#d1fae5;font-weight:bold;}
.sum-row td{text-align:right;font-family:'Courier New',monospace;}
.sum-row .date-col{text-align:center;}
</style>
</head>
<body>
<div class="no-print">
  <button class="btn-pr" onclick="window.print()">🖨 Print</button>
  <button class="btn-cl" onclick="window.close()">Close</button>
</div>
<div class="sheet">
<table>
  <tr><td class="title" colspan="<?php echo count($suppliers)+1; ?>">DEFERRED PAYMENT TRACKER — HOME K MART</td></tr>
  <tr><td class="sub" colspan="<?php echo count($suppliers)+1; ?>"><?php echo e($month_label); ?></td></tr>
  <tr>
    <th class="hdr date-col">DATE</th>
    <?php foreach ($suppliers as $s): ?>
    <th class="hdr"><?php echo e($s); ?></th>
    <?php endforeach; ?>
  </tr>
<?php
for ($d=1; $d<=$days; $d++):
    $ds = sprintf('%04d-%02d-%02d',$year,$month,$d);
    $has_data = false;
    foreach ($suppliers as $s) {
        if (!empty($grid[$ds][$s])) { $has_data = true; break; }
    }
    $dt = new DateTime($ds);
    $date_lbl = $dt->format('M j');
?>
  <tr>
    <td class="date-col"><?php echo $date_lbl; ?></td>
    <?php foreach ($suppliers as $s): ?>
    <td class="amount-cell">
      <?php foreach ($grid[$ds][$s] as $item): ?>
      <div><?php echo e($item['details']??''); ?> <strong>₱<?php echo a($item['amount']); ?></strong></div>
      <?php endforeach; ?>
      <?php if (count($grid[$ds][$s]) > 1): $sum = array_sum(array_column($grid[$ds][$s],'amount')); ?>
      <div style="border-top:0.5pt solid #6ee7b7;color:#059669;font-weight:bold;">= ₱<?php echo a($sum); ?></div>
      <?php endif; ?>
    </td>
    <?php endforeach; ?>
  </tr>
<?php endfor; ?>
  <tr class="sum-row">
    <td class="date-col">TOTAL</td>
    <?php foreach ($suppliers as $s): ?>
    <td>₱<?php echo a($totals[$s]); ?></td>
    <?php endforeach; ?>
  </tr>
</table>
</div>
</body>
</html>
