<?php
$page_title      = 'POS Daily Sales Report';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id = get_office_store_id();
$conn     = get_db_connection();

// 기간 필터 (기본: 이번 달)
$today      = date('Y-m-d');
$default_from = date('Y-m-01');
$default_to   = date('Y-m-d');

$date_from = trim($_GET['from'] ?? $default_from);
$date_to   = trim($_GET['to']   ?? $default_to);

// 날짜 유효성 검사
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = $default_from;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = $default_to;
if ($date_from > $date_to) [$date_from, $date_to] = [$date_to, $date_from];

// 부서 필터
$dept_filter = trim($_GET['dept'] ?? '');

// 실제 저장된 sale_date 샘플 + 범위 확인
$diag = $conn->query(
    "SELECT COUNT(*) AS cnt, MIN(d.sale_date) AS min_date, MAX(d.sale_date) AS max_date,
            (SELECT sale_date FROM pos_sales_data WHERE upload_id IN
             (SELECT id FROM pos_sales_uploads WHERE store_id={$store_id}) LIMIT 1) AS sample
     FROM pos_sales_data d
     WHERE d.upload_id IN (SELECT id FROM pos_sales_uploads WHERE store_id={$store_id})"
)->fetch_assoc();

// sale_date 포맷 감지 및 파서 결정
// 지원 포맷: Y-m-d, m/d/Y, d/m/Y 등
function detect_date_format(string $sample): string {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $sample)) return 'Y-m-d';        // 2025-01-15
    if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $sample)) return 'm/d/Y';  // 1/15/2025
    if (preg_match('/^\d{1,2}-\d{1,2}-\d{4}$/', $sample)) return 'm-d-Y';    // 1-15-2025
    return 'Y-m-d'; // 기본값
}

$date_fmt    = !empty($diag['sample']) ? detect_date_format($diag['sample']) : 'Y-m-d';
$is_std_fmt  = ($date_fmt === 'Y-m-d'); // BETWEEN 그대로 쓸 수 있는지

// 포맷에 맞게 date_from / date_to 를 DB 비교용 표현으로 변환
function to_db_date(string $ymd, string $fmt): string {
    // $ymd 는 항상 Y-m-d 형식 (필터 입력값)
    if ($fmt === 'Y-m-d') return $ymd;
    if ($fmt === 'm/d/Y') {
        $p = explode('-', $ymd); // [Y, m, d]
        return ltrim($p[1],'0').'/'.ltrim($p[2],'0').'/'.$p[0];
    }
    if ($fmt === 'm-d-Y') {
        $p = explode('-', $ymd);
        return ltrim($p[1],'0').'-'.ltrim($p[2],'0').'-'.$p[0];
    }
    return $ymd;
}


// DB 비교용 날짜값
$db_from = to_db_date($date_from, $date_fmt);
$db_to   = to_db_date($date_to,   $date_fmt);

// 표준 포맷이면 BETWEEN, 비표준이면 STR_TO_DATE 로 변환 후 비교
if ($is_std_fmt) {
    $date_cond = "d.sale_date BETWEEN '{$date_from}' AND '{$date_to}'";
} else {
    $mysql_fmt = ($date_fmt === 'm/d/Y') ? '%c/%e/%Y' : '%c-%e-%Y';
    $date_cond = "STR_TO_DATE(d.sale_date, '{$mysql_fmt}') BETWEEN '{$date_from}' AND '{$date_to}'";
}

// 부서 목록 조회
$dept_list = [];
$dr = $conn->query(
    "SELECT DISTINCT d.department FROM pos_sales_data d
     WHERE d.upload_id IN (SELECT id FROM pos_sales_uploads WHERE store_id={$store_id})
       AND d.department IS NOT NULL AND d.department != ''
     ORDER BY d.department ASC"
);
while ($row = $dr->fetch_row()) $dept_list[] = $row[0];

$dept_cond = '';
if ($dept_filter !== '') {
    $dept_cond = " AND d.department = '" . $conn->real_escape_string($dept_filter) . "'";
}

// 일별 집계 쿼리
$rows = $conn->query(
    "SELECT
        d.sale_date,
        COUNT(DISTINCT d.si_no)          AS tx_count,
        SUM(d.pcs)                       AS total_pcs,
        SUM(d.net_sales)                 AS net_sales,
        SUM(d.gross_profit)              AS gross_profit,
        SUM(d.total_cost)                AS total_cost,
        SUM(d.discount)                  AS discount,
        SUM(d.sc_discount)               AS sc_discount,
        SUM(d.pwd_discount)              AS pwd_discount
     FROM pos_sales_data d
     WHERE d.upload_id IN (SELECT id FROM pos_sales_uploads WHERE store_id={$store_id})
       AND {$date_cond}
       {$dept_cond}
     GROUP BY d.sale_date
     ORDER BY d.sale_date ASC"
)->fetch_all(MYSQLI_ASSOC);

// 부서별 집계 (선택된 기간, 필터 무관)
$dept_rows = $conn->query(
    "SELECT
        d.department,
        SUM(d.net_sales) AS net_sales
     FROM pos_sales_data d
     WHERE d.upload_id IN (SELECT id FROM pos_sales_uploads WHERE store_id={$store_id})
       AND {$date_cond}
     GROUP BY d.department
     ORDER BY net_sales DESC"
)->fetch_all(MYSQLI_ASSOC);

$conn->close();

// 합계
$sum_net    = array_sum(array_column($rows, 'net_sales'));
$sum_gp     = array_sum(array_column($rows, 'gross_profit'));
$sum_cost   = array_sum(array_column($rows, 'total_cost'));
$sum_disc   = array_sum(array_column($rows, 'discount'));
$sum_tx     = array_sum(array_column($rows, 'tx_count'));
$sum_pcs    = array_sum(array_column($rows, 'total_pcs'));

// 차트용 JSON 데이터
$chart_labels = json_encode(array_column($rows, 'sale_date'));
$chart_net    = json_encode(array_map(fn($r) => round((float)$r['net_sales'], 2), $rows));
$chart_gp     = json_encode(array_map(fn($r) => round((float)$r['gross_profit'], 2), $rows));
?>

<div class="flex items-center justify-between mb-4">
  <h2 class="text-xl font-bold text-gray-800">
    <i class="fa-solid fa-chart-line mr-2 text-blue-600"></i>POS Daily Sales Report
  </h2>
</div>

<!-- 필터 -->
<form method="GET" class="flex flex-wrap gap-2 mb-5 items-end">
  <div class="flex flex-col gap-1">
    <label class="text-xs text-gray-500 font-medium">From</label>
    <input type="date" name="from" value="<?php echo htmlspecialchars($date_from); ?>"
           class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-400">
  </div>
  <div class="flex flex-col gap-1">
    <label class="text-xs text-gray-500 font-medium">To</label>
    <input type="date" name="to" value="<?php echo htmlspecialchars($date_to); ?>"
           class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-400">
  </div>
  <?php if (!empty($dept_list)): ?>
  <div class="flex flex-col gap-1">
    <label class="text-xs text-gray-500 font-medium">Department</label>
    <select name="dept" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-400">
      <option value="">All Departments</option>
      <?php foreach ($dept_list as $d): ?>
      <option value="<?php echo htmlspecialchars($d); ?>" <?php echo $dept_filter === $d ? 'selected' : ''; ?>>
        <?php echo htmlspecialchars($d); ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>

  <!-- 빠른 기간 선택 버튼 -->
  <div class="flex flex-col gap-1">
    <label class="text-xs text-gray-500 font-medium invisible">Quick</label>
    <div class="flex gap-1">
      <?php
      $quick = [
          'This Month' => [date('Y-m-01'), date('Y-m-d')],
          'Last Month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
          'This Year'  => [date('Y-01-01'), date('Y-m-d')],
      ];
      foreach ($quick as $label => [$f, $t]):
          $active = ($date_from === $f && $date_to === $t && $dept_filter === '');
          $cls = $active ? 'bg-blue-600 text-white' : 'bg-gray-100 hover:bg-gray-200 text-gray-600';
      ?>
      <a href="?from=<?php echo $f; ?>&to=<?php echo $t; ?>"
         class="px-3 py-2 rounded-lg text-xs font-medium <?php echo $cls; ?>"><?php echo $label; ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm self-end">
    <i class="fa-solid fa-magnifying-glass mr-1"></i>조회
  </button>
</form>

<?php if (empty($rows)): ?>
<div class="text-center py-12 text-gray-400">
  <i class="fa-solid fa-chart-line text-4xl mb-3"></i>
  <p>해당 기간에 데이터가 없습니다.</p>
</div>
<?php if ($diag['cnt'] > 0): ?>
<div class="mt-4 bg-yellow-50 border border-yellow-200 rounded-xl p-4 text-sm text-yellow-800 max-w-lg mx-auto">
  <p class="font-semibold mb-1"><i class="fa-solid fa-triangle-exclamation mr-1"></i>데이터 범위 안내</p>
  <p>전체 <strong><?php echo number_format($diag['cnt']); ?></strong>건의 데이터가 있습니다.</p>
  <p>날짜 범위: <strong><?php echo htmlspecialchars($diag['min_date']); ?></strong> ~ <strong><?php echo htmlspecialchars($diag['max_date']); ?></strong></p>
  <p>감지된 날짜 포맷: <code class="bg-yellow-100 px-1 rounded"><?php echo htmlspecialchars($date_fmt); ?></code>
     &nbsp;(샘플: <code class="bg-yellow-100 px-1 rounded"><?php echo htmlspecialchars($diag['sample'] ?? ''); ?></code>)</p>
  <p class="mt-2 text-xs text-yellow-600">위 날짜 범위로 조회 기간을 맞춰주세요.</p>
  <div class="mt-3">
    <a href="?from=<?php echo urlencode($diag['min_date']); ?>&to=<?php echo urlencode($diag['max_date']); ?>"
       class="px-4 py-2 bg-yellow-600 hover:bg-yellow-700 text-white rounded-lg text-xs font-medium">
      전체 기간 조회
    </a>
  </div>
</div>
<?php else: ?>
<div class="mt-4 text-center text-sm text-gray-400">
  <p>POS Sales Data가 아직 업로드되지 않았습니다.</p>
  <a href="index.php" class="mt-2 inline-block text-blue-600 hover:underline">업로드 바로가기</a>
</div>
<?php endif; ?>
<?php else: ?>

<!-- 요약 카드 -->
<div class="grid gap-3 mb-5" style="grid-template-columns:repeat(5,minmax(0,1fr));">
  <div class="bg-white rounded-xl border border-gray-100 shadow-sm px-4 py-3">
    <p class="text-xs text-gray-400 mb-1">Total NET SALES</p>
    <p class="text-lg font-bold text-blue-700"><?php echo number_format($sum_net, 2); ?></p>
    <p class="text-xs text-gray-400"><?php echo count($rows); ?> days</p>
  </div>
  <div class="bg-white rounded-xl border border-gray-100 shadow-sm px-4 py-3">
    <p class="text-xs text-gray-400 mb-1">일평균 매출</p>
    <p class="text-lg font-bold text-blue-700"><?php echo count($rows) > 0 ? number_format($sum_net / count($rows), 2) : '0.00'; ?></p>
    <p class="text-xs text-gray-400">avg / day</p>
  </div>
  <div class="bg-white rounded-xl border border-gray-100 shadow-sm px-4 py-3">
    <p class="text-xs text-gray-400 mb-1">Gross Profit</p>
    <p class="text-lg font-bold text-green-600"><?php echo number_format($sum_gp, 2); ?></p>
    <p class="text-xs text-gray-400"><?php echo $sum_net > 0 ? number_format($sum_gp/$sum_net*100,1).'%' : '-'; ?> margin</p>
  </div>
  <div class="bg-white rounded-xl border border-gray-100 shadow-sm px-4 py-3">
    <p class="text-xs text-gray-400 mb-1">Transactions</p>
    <p class="text-lg font-bold text-gray-700"><?php echo number_format($sum_tx); ?></p>
    <p class="text-xs text-gray-400">avg <?php echo count($rows) > 0 ? number_format($sum_tx/count($rows),1) : 0; ?>/day</p>
  </div>
  <div class="bg-white rounded-xl border border-gray-100 shadow-sm px-4 py-3">
    <p class="text-xs text-gray-400 mb-1">Total PCS</p>
    <p class="text-lg font-bold text-gray-700"><?php echo number_format($sum_pcs); ?></p>
    <p class="text-xs text-gray-400">avg <?php echo $sum_tx > 0 ? number_format($sum_pcs/$sum_tx,1) : 0; ?>/tx</p>
  </div>
</div>

<!-- 차트 -->
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-5">
  <p class="text-sm font-semibold text-gray-600 mb-3">Daily NET SALES</p>
  <canvas id="salesChart" style="max-height:240px;"></canvas>
</div>

<!-- 일별 테이블 + 부서별 테이블 -->
<div class="flex gap-4 flex-wrap">

  <!-- 일별 상세 -->
  <div class="flex-1 min-w-0 bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
    <table class="min-w-full text-xs divide-y divide-gray-100">
      <thead class="bg-gray-50">
        <tr>
          <th class="px-3 py-2 text-left text-gray-500 font-semibold">DATE</th>
          <th class="px-3 py-2 text-right text-gray-500 font-semibold">TX</th>
          <th class="px-3 py-2 text-right text-gray-500 font-semibold">PCS</th>
          <th class="px-3 py-2 text-right text-gray-500 font-semibold">DISCOUNT</th>
          <th class="px-3 py-2 text-right text-gray-500 font-semibold">NET SALES</th>
          <th class="px-3 py-2 text-right text-gray-500 font-semibold">GROSS PROFIT</th>
          <th class="px-3 py-2 text-right text-gray-500 font-semibold">MARGIN</th>
          <th class="px-3 py-2 text-right text-gray-500 font-semibold">vs Avg</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-50">
        <?php
        $avg_net = count($rows) > 0 ? $sum_net / count($rows) : 0;
        foreach ($rows as $r):
            $net  = (float)$r['net_sales'];
            $gp   = (float)$r['gross_profit'];
            $margin = $net > 0 ? $gp/$net*100 : 0;
            $diff   = $avg_net > 0 ? ($net - $avg_net) / $avg_net * 100 : 0;
            $diff_cls = $diff >= 0 ? 'text-green-600' : 'text-red-500';
            $day_of_week = date('D', strtotime($r['sale_date']));
            $is_sun = $day_of_week === 'Sun';
        ?>
        <tr class="hover:bg-gray-50 <?php echo $is_sun ? 'bg-red-50' : ''; ?>">
          <td class="px-3 py-2 font-medium text-gray-700 whitespace-nowrap">
            <?php echo htmlspecialchars($r['sale_date']); ?>
            <span class="text-gray-400 ml-1"><?php echo $day_of_week; ?></span>
          </td>
          <td class="px-3 py-2 text-right font-mono text-gray-600"><?php echo number_format($r['tx_count']); ?></td>
          <td class="px-3 py-2 text-right font-mono text-gray-600"><?php echo number_format($r['total_pcs']); ?></td>
          <td class="px-3 py-2 text-right font-mono text-gray-600"><?php echo number_format((float)$r['discount'], 2); ?></td>
          <td class="px-3 py-2 text-right font-bold font-mono text-blue-700"><?php echo number_format($net, 2); ?></td>
          <td class="px-3 py-2 text-right font-mono text-green-600"><?php echo number_format($gp, 2); ?></td>
          <td class="px-3 py-2 text-right font-mono text-gray-500"><?php echo number_format($margin, 1); ?>%</td>
          <td class="px-3 py-2 text-right font-mono <?php echo $diff_cls; ?>">
            <?php echo ($diff >= 0 ? '+' : '') . number_format($diff, 1); ?>%
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot class="bg-gray-50 border-t-2 border-gray-200">
        <tr>
          <td class="px-3 py-2 font-bold text-gray-700 text-xs">TOTAL (<?php echo count($rows); ?>일)</td>
          <td class="px-3 py-2 text-right font-bold font-mono text-gray-700"><?php echo number_format($sum_tx); ?></td>
          <td class="px-3 py-2 text-right font-bold font-mono text-gray-700"><?php echo number_format($sum_pcs); ?></td>
          <td class="px-3 py-2 text-right font-bold font-mono text-gray-700"><?php echo number_format($sum_disc, 2); ?></td>
          <td class="px-3 py-2 text-right font-bold font-mono text-blue-700"><?php echo number_format($sum_net, 2); ?></td>
          <td class="px-3 py-2 text-right font-bold font-mono text-green-600"><?php echo number_format($sum_gp, 2); ?></td>
          <td class="px-3 py-2 text-right font-bold font-mono text-gray-500">
            <?php echo $sum_net > 0 ? number_format($sum_gp/$sum_net*100,1).'%' : '-'; ?>
          </td>
          <td class="px-3 py-2"></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- 부서별 합계 -->
  <?php if (!empty($dept_rows)): ?>
  <div class="w-96 flex-shrink-0 bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden self-start">
    <div class="px-4 py-3 border-b border-gray-100 bg-gray-50">
      <p class="text-xs font-semibold text-gray-600">Department Breakdown</p>
      <p class="text-xs text-gray-400"><?php echo htmlspecialchars($date_from); ?> ~ <?php echo htmlspecialchars($date_to); ?></p>
    </div>
    <table class="min-w-full text-xs divide-y divide-gray-100">
      <thead class="bg-gray-50">
        <tr>
          <th class="px-3 py-2 text-left text-gray-500 font-semibold">DEPT</th>
          <th class="px-3 py-2 text-right text-gray-500 font-semibold">NET SALES</th>
          <th class="px-3 py-2 text-right text-gray-500 font-semibold">%</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-50">
        <?php
        $dept_total = array_sum(array_column($dept_rows, 'net_sales'));
        foreach ($dept_rows as $dr):
            $pct = $dept_total > 0 ? (float)$dr['net_sales'] / $dept_total * 100 : 0;
        ?>
        <tr class="hover:bg-gray-50">
          <td class="px-3 py-1.5 text-gray-700 truncate max-w-28" title="<?php echo htmlspecialchars($dr['department']); ?>">
            <a href="?from=<?php echo urlencode($date_from); ?>&to=<?php echo urlencode($date_to); ?>&dept=<?php echo urlencode($dr['department']); ?>"
               class="hover:text-blue-600"><?php echo htmlspecialchars($dr['department']); ?></a>
          </td>
          <td class="px-3 py-1.5 text-right font-mono text-blue-700"><?php echo number_format((float)$dr['net_sales'], 2); ?></td>
          <td class="px-3 py-1.5 text-right font-mono text-gray-400"><?php echo number_format($pct, 1); ?>%</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot class="bg-gray-50 border-t-2 border-gray-200">
        <tr>
          <td class="px-3 py-2 font-bold text-gray-700 text-xs">TOTAL</td>
          <td class="px-3 py-2 text-right font-bold font-mono text-blue-700"><?php echo number_format($dept_total, 2); ?></td>
          <td class="px-3 py-2 text-right font-mono text-gray-500">100%</td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>

</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
<script>
(function() {
    const labels  = <?php echo $chart_labels; ?>;
    const netData = <?php echo $chart_net; ?>;
    const gpData  = <?php echo $chart_gp; ?>;

    Chart.register(ChartDataLabels);

    new Chart(document.getElementById('salesChart'), {
        type: 'bar',
        data: {
            labels,
            datasets: [
                {
                    label: 'NET SALES',
                    data: netData,
                    backgroundColor: 'rgba(59,130,246,0.7)',
                    borderColor: 'rgba(59,130,246,1)',
                    borderWidth: 1,
                    order: 2,
                    datalabels: {
                        anchor: 'end',
                        align: 'end',
                        offset: 2,
                        color: '#1d4ed8',
                        font: { size: 9, weight: '600' },
                        formatter: v => v >= 1000
                            ? (v / 1000).toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + 'K'
                            : v.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 })
                    }
                },
                {
                    label: 'Gross Profit',
                    data: gpData,
                    type: 'line',
                    borderColor: 'rgba(34,197,94,0.9)',
                    backgroundColor: 'rgba(34,197,94,0.15)',
                    borderWidth: 2,
                    pointRadius: 3,
                    fill: false,
                    tension: 0.3,
                    order: 1,
                    yAxisID: 'y',
                    datalabels: { display: false }
                }
            ]
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            layout: { padding: { top: 20 } },
            plugins: {
                legend: { position: 'top', labels: { font: { size: 11 } } },
                tooltip: {
                    callbacks: {
                        label: ctx => ctx.dataset.label + ': ' +
                            ctx.parsed.y.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                    }
                }
            },
            scales: {
                x: { ticks: { font: { size: 10 }, maxRotation: 45 } },
                y: { ticks: { font: { size: 10 }, callback: v => v.toLocaleString() } }
            }
        }
    });
})();
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
