<?php
$page_title      = 'Office Dashboard';
$css_base        = '../admin/';
$office_nav_base = './';
require_once __DIR__ . '/partials/header.php';

$store_id = get_office_store_id();
$year     = (int)($_GET['year']  ?? date('Y'));
$month    = (int)($_GET['month'] ?? date('n'));

// 당월 Total Expenses
$totals = get_purchase_monthly_total($store_id, $year, $month);
$pending_checks = get_pending_checks_count($store_id);

// 최근 6개월 추이 (Chart.js용)
$trend = get_purchase_6month_trend($store_id);
$trend_labels = array_column($trend, 'ym');
$trend_data   = array_map(fn($r) => (float)$r['total'], $trend);

// 월 선택 옵션
$month_options = [];
for ($i = 0; $i < 12; $i++) {
    $ts = mktime(0, 0, 0, date('n') - $i, 1, date('Y'));
    $month_options[] = [
        'y'     => date('Y', $ts),
        'm'     => (int)date('n', $ts),
        'label' => date('Y n월', $ts),
    ];
}
?>

<!-- 월 선택 -->
<form method="GET" class="flex items-center gap-3 mb-8">
  <h2 class="text-xl font-bold text-gray-800 mr-2">
    <i class="fa-solid fa-chart-line mr-2 text-blue-600"></i>Office Dashboard
  </h2>
  <select name="year" id="sel_year" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
    <?php foreach ($month_options as $opt): ?>
    <option value="<?php echo $opt['y']; ?>" data-m="<?php echo $opt['m']; ?>"
      <?php echo ($opt['y']==$year && $opt['m']==$month) ? 'selected':''; ?>>
      <?php echo htmlspecialchars($opt['label']); ?>
    </option>
    <?php endforeach; ?>
  </select>
  <input type="hidden" name="month" id="sel_month" value="<?php echo $month; ?>">
  <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm">Search</button>
</form>

<!-- 요약 카드 -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
  <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
    <div class="flex items-center gap-3 mb-3">
      <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
        <i class="fa-solid fa-money-bill-wave text-green-600"></i>
      </div>
      <span class="text-sm text-gray-500">Product Purchase (Cash)</span>
    </div>
    <div class="text-xl font-bold text-gray-800"><?php echo format_amount($totals['product_cash']); ?></div>
  </div>

  <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
    <div class="flex items-center gap-3 mb-3">
      <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
        <i class="fa-solid fa-file-invoice text-purple-600"></i>
      </div>
      <span class="text-sm text-gray-500">Product Purchase (Check)</span>
    </div>
    <div class="text-xl font-bold text-gray-800"><?php echo format_amount($totals['product_check']); ?></div>
  </div>

  <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
    <div class="flex items-center gap-3 mb-3">
      <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center">
        <i class="fa-solid fa-box text-orange-600"></i>
      </div>
      <span class="text-sm text-gray-500">Equipment Purchase (Cash)</span>
    </div>
    <div class="text-xl font-bold text-gray-800"><?php echo format_amount($totals['equipment']); ?></div>
  </div>

  <div class="bg-white rounded-xl p-5 shadow-sm border border-gray-100">
    <div class="flex items-center gap-3 mb-3">
      <div class="w-10 h-10 <?php echo $pending_checks > 0 ? 'bg-red-100' : 'bg-gray-100'; ?> rounded-lg flex items-center justify-center">
        <i class="fa-solid fa-clock <?php echo $pending_checks > 0 ? 'text-red-500' : 'text-gray-400'; ?>"></i>
      </div>
      <span class="text-sm text-gray-500">Pending Checks</span>
    </div>
    <div class="text-xl font-bold <?php echo $pending_checks > 0 ? 'text-red-600' : 'text-gray-800'; ?>">
      <?php echo $pending_checks; ?>
    </div>
  </div>
</div>

<!-- 총 Total Expenses -->
<div class="bg-blue-50 border border-blue-100 rounded-xl px-6 py-4 mb-8 flex items-center justify-between">
  <span class="text-sm text-blue-700 font-medium">
    <?php echo $year; ?> <?php echo $month; ?>Total Expenses
  </span>
  <span class="text-2xl font-bold text-blue-800">
    <?php echo format_amount($totals['product_cash'] + $totals['product_check'] + $totals['equipment']); ?>
  </span>
</div>

<!-- 6개월 추이 차트 -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-8">
  <h3 class="text-sm font-semibold text-gray-700 mb-4">Last 6 Months Expense Trend</h3>
  <?php if (empty($trend)): ?>
  <p class="text-center text-gray-400 py-8 text-sm">No data available.</p>
  <?php else: ?>
  <canvas id="trendChart" height="80"></canvas>
  <?php endif; ?>
</div>

<!-- 바로가기 -->
<div class="grid grid-cols-3 gap-4">
  <a href="product_purchase/list.php" class="bg-white rounded-xl p-5 shadow-sm border border-gray-100 hover:border-blue-300 hover:shadow-md transition-all text-center">
    <i class="fa-solid fa-cart-shopping text-blue-600 text-2xl mb-2 block"></i>
    <span class="text-sm font-medium text-gray-700">Product Purchase</span>
  </a>
  <a href="equipment_purchase/list.php" class="bg-white rounded-xl p-5 shadow-sm border border-gray-100 hover:border-orange-300 hover:shadow-md transition-all text-center">
    <i class="fa-solid fa-box text-orange-600 text-2xl mb-2 block"></i>
    <span class="text-sm font-medium text-gray-700">Equipment Purchase</span>
  </a>
  <a href="schedule/employees.php" class="bg-white rounded-xl p-5 shadow-sm border border-gray-100 hover:border-green-300 hover:shadow-md transition-all text-center">
    <i class="fa-solid fa-calendar-days text-green-600 text-2xl mb-2 block"></i>
    <span class="text-sm font-medium text-gray-700">Employee Schedule</span>
  </a>
</div>

<?php
$inline_script = "
const ctx = document.getElementById('trendChart');
if (ctx) {
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: " . json_encode($trend_labels) . ",
      datasets: [{
        label: 'Total Expenses',
        data: " . json_encode($trend_data) . ",
        backgroundColor: 'rgba(59,130,246,0.5)',
        borderColor: 'rgba(59,130,246,1)',
        borderWidth: 1,
        borderRadius: 4
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            callback: v => '₱ ' + v.toLocaleString()
          }
        }
      }
    }
  });
}
document.getElementById('sel_year').addEventListener('change', function() {
  document.getElementById('sel_month').value = this.options[this.selectedIndex].dataset.m;
});
";
?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
