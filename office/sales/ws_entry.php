<?php
$page_title      = 'Whole Sale';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id  = get_office_store_id();
$today     = date('Y-m-d');
$year      = (int)($_GET['year']  ?? date('Y'));
$month     = (int)($_GET['month'] ?? date('n'));
$sales_tab = 'ws';

// Read from wholesale_sales (admin-managed data)
$conn = get_db_connection();
$stmt = $conn->prepare(
    "SELECT ws.id, ws.sale_date, ws.final_amount, ws.total_amount, ws.status,
            wc.name AS customer_name
     FROM wholesale_sales ws
     LEFT JOIN wholesale_customers wc ON ws.customer_id = wc.id
     WHERE ws.store_id=? AND ws.status != 'cancelled'
       AND YEAR(ws.sale_date)=? AND MONTH(ws.sale_date)=?
     ORDER BY ws.sale_date DESC, ws.id DESC"
);
$stmt->bind_param('iii', $store_id, $year, $month);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

$by_date     = [];
foreach ($rows as $r) { $by_date[$r['sale_date']][] = $r; }
$month_total = array_sum(array_column($rows, 'final_amount'));

$month_options = [];
for ($i = 0; $i < 12; $i++) {
    $ts = mktime(0,0,0,date('n')-$i,1,date('Y'));
    $month_options[] = ['y'=>(int)date('Y',$ts),'m'=>(int)date('n',$ts),'label'=>date('M Y',$ts)];
}
$days_en = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
?>
<?php require __DIR__ . '/partials/sales_nav.php'; ?>

<div class="flex items-center justify-between mb-4">
  <h2 class="text-lg font-bold text-gray-800">
    <i class="fa-solid fa-boxes-stacked mr-2 text-indigo-600"></i>Whole Sale
  </h2>
  <a href="../../admin/wholesale_sales.php"
     target="_blank"
     class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
    <i class="fa-solid fa-arrow-up-right-from-square mr-1"></i>Admin — Add Sale
  </a>
</div>

<!-- Month filter -->
<form method="GET" class="flex gap-3 mb-4 bg-white p-3 rounded-xl shadow-sm border border-gray-100 items-center flex-wrap">
  <select name="month" id="month_sel" class="border border-gray-300 rounded-lg px-3 py-2 text-sm" onchange="syncYear()">
    <?php foreach ($month_options as $opt): ?>
    <option value="<?php echo $opt['m']; ?>" data-year="<?php echo $opt['y']; ?>"
      <?php echo ($opt['y']===$year && $opt['m']===$month) ? 'selected' : ''; ?>>
      <?php echo $opt['label']; ?>
    </option>
    <?php endforeach; ?>
  </select>
  <input type="hidden" name="year" id="year_hidden" value="<?php echo $year; ?>">
  <button type="submit" class="px-3 py-2 bg-gray-600 text-white rounded-lg text-sm">Search</button>
  <div class="ml-auto text-sm font-semibold text-indigo-700">
    Month Total: <?php echo format_amount($month_total); ?>
  </div>
</form>

<!-- Info notice -->
<div class="mb-3 px-4 py-2.5 bg-indigo-50 border border-indigo-100 rounded-lg text-xs text-indigo-600 flex items-center gap-2">
  <i class="fa-solid fa-circle-info"></i>
  Data is sourced from Admin &gt; Wholesale Sales. To add or edit entries, use the Admin panel.
</div>

<!-- List -->
<?php if (empty($by_date)): ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 text-center py-16 text-gray-400">
  <i class="fa-solid fa-boxes-stacked text-4xl mb-3 block text-gray-200"></i>
  No wholesale sales for this period.
</div>
<?php else: ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-gray-50 border-b border-gray-200">
      <tr>
        <th class="px-4 py-3 text-left text-gray-600 font-medium w-36">Date</th>
        <th class="px-4 py-3 text-left text-gray-600 font-medium">Customer</th>
        <th class="px-4 py-3 text-right text-gray-600 font-medium w-36">Amount</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($by_date as $date => $items):
      $ts      = strtotime($date);
      $dow     = $days_en[date('w',$ts)];
      $lbl     = date('M j',$ts) . ' ' . $dow;
      $sub     = array_sum(array_column($items, 'final_amount'));
      $is_today= ($date === $today);
    ?>
      <tr class="bg-indigo-50 border-t border-gray-200">
        <td colspan="2" class="px-4 py-2 font-semibold text-indigo-800 text-xs">
          <?php echo $lbl; ?><?php if ($is_today) echo ' <span class="text-indigo-400">●</span>'; ?>
        </td>
        <td class="px-4 py-2 text-right font-bold text-indigo-700 text-xs">
          <?php echo format_amount($sub); ?>
          <button onclick="printDaily('<?php echo $date; ?>')"
                  class="ml-2 text-xs px-2 py-0.5 rounded border border-indigo-300 text-indigo-500 hover:bg-indigo-600 hover:text-white hover:border-indigo-600 transition-colors"
                  title="일일집계 출력">
            <i class="fa-solid fa-print"></i>
          </button>
        </td>
      </tr>
      <?php foreach ($items as $item): ?>
      <tr class="border-t border-gray-100 hover:bg-gray-50">
        <td class="px-4 py-2.5 text-gray-400 pl-8 text-xs">—</td>
        <td class="px-4 py-2.5 text-gray-700">
          <?php echo $item['customer_name'] ? htmlspecialchars($item['customer_name']) : '<span class="text-gray-300 italic text-xs">—</span>'; ?>
        </td>
        <td class="px-4 py-2.5 text-right font-mono text-gray-800"><?php echo format_amount((float)$item['final_amount']); ?></td>
      </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<script>
function syncYear(){const s=document.getElementById('month_sel');document.getElementById('year_hidden').value=s.options[s.selectedIndex].dataset.year;s.form.submit();}
function printDaily(date){window.open('print_daily_combined.php?date='+date,'_blank','width=700,height=700');}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
