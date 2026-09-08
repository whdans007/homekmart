<?php
// Design Ref: homekmart-store-config §5.2
require_once __DIR__ . '/../../lib/store_config_helper.php';
// Design Ref: §6.2 — Monthly report matching image format
$page_title      = 'Sales Report';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id = get_office_store_id();
$year     = (int)($_GET['year']  ?? date('Y'));
$month    = (int)($_GET['month'] ?? date('n'));

// Month navigation
$prev_ts  = mktime(0,0,0,$month-1,1,$year);
$next_ts  = mktime(0,0,0,$month+1,1,$year);
$prev_y   = (int)date('Y',$prev_ts); $prev_m = (int)date('n',$prev_ts);
$next_y   = (int)date('Y',$next_ts); $next_m = (int)date('n',$next_ts);
$days     = (int)date('t', mktime(0,0,0,$month,1,$year));
$today    = date('Y-m-d');
$month_label = date('F Y', mktime(0,0,0,$month,1,$year));

// 리포트 제목에 표시할 현재 지점명 (header.php에서 설정된 전역 변수 사용)
// 예) "SUNSET (선셋점)" → 브랜딩 형식에 맞춰 앞의 영문 부분만 대문자로 표시
$store_display = $_office_store_name ?? '';
if (($_p = strpos($store_display, ' (')) !== false) {
    $store_display = substr($store_display, 0, $_p);
}
$store_display = strtoupper(trim($store_display));

// Design Ref: sales-report-main-office — 집계 로직은 office/lib/sales_report_helper.php로 이전.
// office(점포 세션)와 main_office(전 점포 열람)가 동일 함수를 공유해 계산식 drift를 방지한다.
require_once __DIR__ . '/../lib/sales_report_helper.php';
$report = get_monthly_sales_report($store_id, $year, $month);
$days            = $report['days'];
$rows            = $report['rows'];
$col_totals      = $report['col_totals'];
$retail_total    = $report['retail_total'];
$wholesale_total = $report['wholesale_total'];
$sales_total_s   = $report['sales_total_s'];
$days_with_sales = $report['days_with_sales'];
$avg_daily       = $report['avg_daily'];
$avg_retail      = $report['avg_retail'];
$avg_wholesale   = $report['avg_wholesale'];
$purchase_ratio  = $report['purchase_ratio'];
$expense_ratio   = $report['expense_ratio'];
$net_margin      = $report['net_margin'];
$retail_pct      = $report['retail_pct'];
$wholesale_pct   = $report['wholesale_pct'];
$delivery_k_pct  = $report['delivery_k_pct'];
$credit_doc_pct  = $report['credit_doc_pct'];
$whole_sale_pct  = $report['whole_sale_pct'];

function fmtC($n) { return sr_fmtC($n); }
function fmtT($n) { return sr_fmtT($n); }
function fmtPct($n) { return sr_fmtPct($n); }

$days_en = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
?>

<style>
.report-table { font-size: 11px; border-collapse: collapse; width: 100%; }
.report-table th, .report-table td {
    border: 1px solid #ccc; padding: 3px 6px; white-space: nowrap;
    text-align: right; vertical-align: middle;
}
.report-table .col-date { text-align: left; min-width: 110px; }
.report-table thead th { background: #f3f4f6; font-weight: 600; text-align: center; font-size: 10px; }
.report-table .col-total { background: #fee2e2; font-weight: bold; color: #991b1b; }
.report-table .row-sum td { background: #fee2e2; font-weight: bold; }
.report-table .row-avg td { background: #eff6ff; color: #1e40af; font-size: 10px; }
.report-table .row-avg .col-date { font-style: italic; }
.report-table .row-data:hover td { background: #f9fafb; }
.report-table td.zero { color: #d1d5db; }
</style>

<div class="mb-4 flex items-center justify-between flex-wrap gap-2">
  <h2 class="text-xl font-bold text-gray-800">
    <i class="fa-solid fa-chart-bar mr-2 text-green-600"></i>HOME K MART <?php echo htmlspecialchars($store_display); ?> SALES REPORT
  </h2>
  <div class="flex items-center gap-2">
    <a href="monthly_report.php?year=<?php echo $prev_y; ?>&month=<?php echo $prev_m; ?>"
       class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-gray-600 hover:bg-gray-50">
      <i class="fa-solid fa-chevron-left"></i>
    </a>
    <span class="px-4 py-1.5 rounded-lg bg-gray-50 border border-gray-200 text-sm font-medium text-gray-800 min-w-32 text-center">
      <?php echo $month_label; ?>
    </span>
    <a href="monthly_report.php?year=<?php echo $next_y; ?>&month=<?php echo $next_m; ?>"
       class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-gray-600 hover:bg-gray-50 <?php echo ($next_y>date('Y')||($next_y==date('Y')&&$next_m>date('n')))?'opacity-40 pointer-events-none':''; ?>">
      <i class="fa-solid fa-chevron-right"></i>
    </a>
    <a href="export_monthly.php?year=<?php echo $year; ?>&month=<?php echo $month; ?>"
       target="_blank" class="px-3 py-1.5 bg-gray-600 hover:bg-gray-700 text-white rounded-lg text-sm font-medium">
      <i class="fa-solid fa-file-excel mr-1"></i>Excel
    </a>
  </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
<table class="report-table">
  <thead>
    <tr>
      <th class="col-date" rowspan="2">DATE</th>
      <?php $report_shifts = get_store_shifts($store_id);
      foreach (STORE_SHIFT_KEYS as $shift_key):
        $shift_config = $report_shifts[$shift_key];
        $shift_columns = array_filter($report['pos_keys'], fn($key) => strpos($key, $shift_key . '_pos') === 0);
      ?>
      <th colspan="<?php echo count($shift_columns); ?>"><?php echo htmlspecialchars($shift_config['label']); ?><br><span style="font-weight:normal;font-size:9px;"><?php echo htmlspecialchars(format_shift_time($shift_config['start_time'], $shift_config['end_time'], 'dash')); ?></span></th>
      <?php endforeach; ?>
      <th>DELIVERY K</th>
      <th>POS<br><span style="font-weight:normal;font-size:9px;">외상</span></th>
      <th>거래명세서<br><span style="font-weight:normal;font-size:9px;">Credit Invoice</span></th>
      <th>WHOLE SALE</th>
      <th class="col-total">SALES TOTAL</th>
      <th>PURCHASE<br>(매입)</th>
      <th>STORE EXP<br>(점지출)</th>
      <th>TRANSFER<br>(재고이동)</th>
      <th>NET<br>(매출-지출)</th>
    </tr>
    <tr>
      <?php foreach ($report['pos_keys'] as $pos_key): ?>
      <th>POS <?php echo (int)substr($pos_key, strrpos($pos_key, 'pos') + 3); ?></th>
      <?php endforeach; ?>
      <th colspan="9"></th>
    </tr>
  </thead>
  <tbody>
  <?php for ($d = 1; $d <= $days; $d++):
    $r  = $rows[$d];
    $dt = date('Y-m-d', mktime(0,0,0,$month,$d,$year));
    $dow= $days_en[date('w', strtotime($dt))];
    $is_today = ($dt === $today);
    $has_data = $r['st'] > 0;
  ?>
  <tr class="row-data <?php echo $is_today ? 'bg-blue-50' : ''; ?>">
    <td class="col-date text-left <?php echo $is_today ? 'font-bold text-blue-700' : 'text-gray-600'; ?>">
      <?php echo date('M j', strtotime($dt)); ?> <?php echo $dow; ?>
      <?php if ($is_today) echo ' <span class="text-xs text-blue-500">●</span>'; ?>
    </td>
    <?php foreach ($report['pos_keys'] as $pos_key): ?>
    <td class="<?php echo !$r[$pos_key] ? 'zero' : ''; ?>"><?php echo fmtC($r[$pos_key]); ?></td>
    <?php endforeach; ?>
    <td class="<?php echo !$r['dk'] ? 'zero':'' ?>"><?php echo fmtC($r['dk']); ?></td>
    <td class="<?php echo !$r['pc'] ? 'zero':'' ?>"><?php echo fmtC($r['pc']); ?></td>
    <td class="<?php echo !$r['cd'] ? 'zero':'' ?>"><?php echo fmtC($r['cd']); ?></td>
    <td class="<?php echo !$r['ws'] ? 'zero':'' ?>"><?php echo fmtC($r['ws']); ?></td>
    <td class="col-total <?php echo !$r['st'] ? 'zero':'' ?>"><?php echo fmtC($r['st']); ?></td>
    <td class="<?php echo !$r['pu'] ? 'zero':'' ?>"><?php echo fmtC($r['pu']); ?></td>
    <td class="<?php echo !$r['eq'] ? 'zero':'' ?>"><?php echo fmtC($r['eq']); ?></td>
    <td class="<?php echo !$r['tr'] ? 'zero':'' ?>"><?php echo fmtC($r['tr']); ?></td>
    <td class="<?php echo $r['net']<0 ? 'text-red-600 font-medium' : (!$r['net'] ? 'zero' : '') ?>"><?php echo fmtC($r['net']); ?></td>
  </tr>
  <?php endfor; ?>
  <!-- Total Row -->
  <tr class="row-sum">
    <td class="col-date text-left">TOTAL</td>
    <?php foreach ($report['pos_keys'] as $pos_key): ?>
    <td><?php echo fmtT($col_totals[$pos_key]); ?></td>
    <?php endforeach; ?>
    <td><?php echo fmtT($col_totals['delivery_k']); ?></td>
    <td><?php echo fmtT($col_totals['pos_credit']); ?></td>
    <td><?php echo fmtT($col_totals['credit_doc']); ?></td>
    <td><?php echo fmtT($col_totals['whole_sale']); ?></td>
    <td class="col-total"><?php echo fmtT($col_totals['sales_total']); ?></td>
    <td><?php echo fmtT($col_totals['purchase']); ?></td>
    <td><?php echo fmtT($col_totals['equip']); ?></td>
    <td><?php echo fmtT($col_totals['transfer']); ?></td>
    <td><?php echo fmtT($col_totals['net']); ?></td>
  </tr>
  <!-- Daily Average Row -->
  <?php $div = $days_with_sales > 0 ? $days_with_sales : 1; ?>
  <tr class="row-avg">
    <td class="col-date text-left">AVG / DAY<br><span style="color:#93c5fd;font-size:9px;"><?php echo $days_with_sales; ?>영업일</span></td>
    <?php foreach ($report['pos_keys'] as $pos_key): ?>
    <td><?php echo fmtT($col_totals[$pos_key]/$div); ?></td>
    <?php endforeach; ?>
    <td><?php echo fmtT($col_totals['delivery_k']/$div); ?></td>
    <td><?php echo fmtT($col_totals['pos_credit']/$div); ?></td>
    <td><?php echo fmtT($col_totals['credit_doc']/$div); ?></td>
    <td><?php echo fmtT($col_totals['whole_sale']/$div); ?></td>
    <td class="col-total" style="background:#dbeafe;color:#1e3a8a;"><?php echo fmtT($col_totals['sales_total']/$div); ?></td>
    <td><?php echo fmtT($col_totals['purchase']/$div); ?></td>
    <td><?php echo fmtT($col_totals['equip']/$div); ?></td>
    <td><?php echo fmtT($col_totals['transfer']/$div); ?></td>
    <td><?php echo fmtT($col_totals['net']/$div); ?></td>
  </tr>
  </tbody>
</table>
</div>

<!-- Summary Section -->
<div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-4">

  <!-- Sales Breakdown Table -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="px-4 py-3 bg-gray-50 border-b border-gray-100 font-semibold text-sm text-gray-700">
      <i class="fa-solid fa-chart-pie mr-2 text-blue-500"></i>매출 구성 (Sales Breakdown)
      <span class="ml-2 text-xs text-gray-400 font-normal"><?php echo $month_label; ?></span>
    </div>
    <table class="w-full text-sm">
      <thead>
        <tr class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
          <th class="px-4 py-2 text-left font-semibold">구분</th>
          <th class="px-4 py-2 text-right font-semibold">금액</th>
          <th class="px-4 py-2 text-right font-semibold">비율</th>
          <th class="px-4 py-2 text-right font-semibold">일 평균</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-50">
        <!-- Retail (POS only) -->
        <tr class="hover:bg-gray-50">
          <td class="px-4 py-2.5 text-gray-700 font-medium">
            <span class="inline-block w-2 h-2 rounded-full bg-blue-400 mr-2"></span>소매 매출 (POS)
          </td>
          <td class="px-4 py-2.5 text-right font-mono text-gray-800"><?php echo fmtT($retail_total); ?></td>
          <td class="px-4 py-2.5 text-right">
            <span class="inline-block bg-blue-50 text-blue-700 px-2 py-0.5 rounded text-xs font-medium"><?php echo fmtPct($retail_pct); ?></span>
          </td>
          <td class="px-4 py-2.5 text-right font-mono text-gray-600 text-xs"><?php echo fmtT($avg_retail); ?></td>
        </tr>
        <!-- Wholesale (Delivery K + Whole Sale) -->
        <tr class="hover:bg-gray-50">
          <td class="px-4 py-2.5 text-gray-700 font-medium">
            <span class="inline-block w-2 h-2 rounded-full bg-green-500 mr-2"></span>도매 매출 (Wholesale)
          </td>
          <td class="px-4 py-2.5 text-right font-mono text-gray-800"><?php echo fmtT($wholesale_total); ?></td>
          <td class="px-4 py-2.5 text-right">
            <span class="inline-block bg-green-50 text-green-700 px-2 py-0.5 rounded text-xs font-medium"><?php echo fmtPct($wholesale_pct); ?></span>
          </td>
          <td class="px-4 py-2.5 text-right font-mono text-gray-600 text-xs"><?php echo fmtT($avg_wholesale); ?></td>
        </tr>
        <tr class="bg-green-50/40">
          <td class="px-4 py-2 text-gray-500 text-xs pl-8">└ Delivery K</td>
          <td class="px-4 py-2 text-right font-mono text-gray-500 text-xs"><?php echo fmtT($col_totals['delivery_k']); ?></td>
          <td class="px-4 py-2 text-right text-xs text-gray-400"><?php echo fmtPct($delivery_k_pct); ?></td>
          <td class="px-4 py-2 text-right font-mono text-gray-400 text-xs"><?php echo $days_with_sales>0 ? fmtT($col_totals['delivery_k']/$days_with_sales) : '0.00'; ?></td>
        </tr>
        <tr class="bg-green-50/40">
          <td class="px-4 py-2 text-gray-500 text-xs pl-8">└ 거래명세서 (Credit Invoice)</td>
          <td class="px-4 py-2 text-right font-mono text-gray-500 text-xs"><?php echo fmtT($col_totals['credit_doc']); ?></td>
          <td class="px-4 py-2 text-right text-xs text-gray-400"><?php echo fmtPct($credit_doc_pct); ?></td>
          <td class="px-4 py-2 text-right font-mono text-gray-400 text-xs"><?php echo $days_with_sales>0 ? fmtT($col_totals['credit_doc']/$days_with_sales) : '0.00'; ?></td>
        </tr>
        <tr class="bg-green-50/40">
          <td class="px-4 py-2 text-gray-500 text-xs pl-8">└ Whole Sale</td>
          <td class="px-4 py-2 text-right font-mono text-gray-500 text-xs"><?php echo fmtT($col_totals['whole_sale']); ?></td>
          <td class="px-4 py-2 text-right text-xs text-gray-400"><?php echo fmtPct($whole_sale_pct); ?></td>
          <td class="px-4 py-2 text-right font-mono text-gray-400 text-xs"><?php echo $days_with_sales>0 ? fmtT($col_totals['whole_sale']/$days_with_sales) : '0.00'; ?></td>
        </tr>
        <!-- Total -->
        <tr class="bg-red-50 font-bold border-t-2 border-red-200">
          <td class="px-4 py-3 text-red-800">
            <i class="fa-solid fa-sigma mr-1.5 text-red-500"></i>TOTAL SALES
          </td>
          <td class="px-4 py-3 text-right font-mono text-red-800"><?php echo fmtT($sales_total_s); ?></td>
          <td class="px-4 py-3 text-right text-red-600 text-sm">100%</td>
          <td class="px-4 py-3 text-right font-mono text-red-700 text-sm"><?php echo fmtT($avg_daily); ?></td>
        </tr>
      </tbody>
    </table>
    <div class="px-4 py-2 bg-gray-50 border-t border-gray-100 text-xs text-gray-400">
      영업일 <strong class="text-gray-600"><?php echo $days_with_sales; ?>일</strong> 기준 일 평균
    </div>
  </div>

  <!-- Key Ratios -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="px-4 py-3 bg-gray-50 border-b border-gray-100 font-semibold text-sm text-gray-700">
      <i class="fa-solid fa-percent mr-2 text-violet-500"></i>주요 비율 (Key Ratios)
    </div>
    <div class="p-4 grid grid-cols-2 gap-3">
      <!-- Purchase Ratio -->
      <div class="bg-orange-50 border border-orange-100 rounded-lg p-3">
        <div class="text-xs text-orange-600 font-medium mb-1">구매율 (Purchase Ratio)</div>
        <div class="text-2xl font-bold text-orange-700"><?php echo fmtPct($purchase_ratio); ?></div>
        <div class="text-xs text-orange-500 mt-1">₱<?php echo fmtT($col_totals['purchase'] + $col_totals['transfer']); ?> / ₱<?php echo fmtT($sales_total_s); ?></div>
      </div>
      <!-- Expense Ratio -->
      <div class="bg-yellow-50 border border-yellow-100 rounded-lg p-3">
        <div class="text-xs text-yellow-700 font-medium mb-1">운영비 비율 (Expense Ratio)</div>
        <div class="text-2xl font-bold text-yellow-700"><?php echo fmtPct($expense_ratio); ?></div>
        <div class="text-xs text-yellow-600 mt-1">₱<?php echo fmtT($col_totals['equip']); ?> / ₱<?php echo fmtT($sales_total_s); ?></div>
      </div>
      <!-- Net Margin -->
      <div class="<?php echo $net_margin>=0 ? 'bg-green-50 border-green-100' : 'bg-red-50 border-red-100'; ?> border rounded-lg p-3">
        <div class="text-xs <?php echo $net_margin>=0 ? 'text-green-700' : 'text-red-700'; ?> font-medium mb-1">순이익률 (Net Margin)</div>
        <div class="text-2xl font-bold <?php echo $net_margin>=0 ? 'text-green-700' : 'text-red-700'; ?>"><?php echo fmtPct($net_margin); ?></div>
        <div class="text-xs <?php echo $net_margin>=0 ? 'text-green-600' : 'text-red-600'; ?> mt-1">₱<?php echo fmtT($col_totals['net']); ?></div>
      </div>
      <!-- Wholesale Ratio -->
      <div class="bg-blue-50 border border-blue-100 rounded-lg p-3">
        <div class="text-xs text-blue-700 font-medium mb-1">도매 비율 (Wholesale Mix)</div>
        <div class="text-2xl font-bold text-blue-700"><?php echo fmtPct($wholesale_pct); ?></div>
        <div class="text-xs text-blue-600 mt-1">DK ₱<?php echo fmtT($col_totals['delivery_k']); ?> + WS ₱<?php echo fmtT($col_totals['whole_sale']); ?></div>
      </div>
    </div>

  </div>

</div>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
