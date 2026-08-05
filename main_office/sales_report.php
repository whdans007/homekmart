<?php
// Design Ref: sales-report-main-office — store-selectable SALES REPORT viewer (read-only)
// Aggregation logic is shared with office/sales/monthly_report.php and office/lib/sales_report_helper.php.
$page_title = 'Sales Report';
require_once __DIR__ . '/partials/header.php';

$mo_sr_error = null;
try {
    require_once __DIR__ . '/../office/lib/sales_report_helper.php';

    $conn = get_db_connection();
    // Logistics center/warehouse entries aren't real stores, so exclude them from the list
    $stores = $conn->query(
        "SELECT id, name FROM stores
         WHERE name NOT IN ('CENTER (물류센터)', 'KIMS MALL WHEREHOUSE (킴스몰 창고)')
         ORDER BY name"
    )->fetch_all(MYSQLI_ASSOC);
    $conn->close();

    $store_id = (int)($_GET['store_id'] ?? 0);
    if ($store_id <= 0 || !in_array($store_id, array_map('intval', array_column($stores, 'id')), true)) {
        $store_id = (int)($stores[0]['id'] ?? 0);
    }
    $store_name = '';
    foreach ($stores as $s) {
        if ((int)$s['id'] === $store_id) { $store_name = $s['name']; break; }
    }

    $year  = (int)($_GET['year']  ?? date('Y'));
    $month = (int)($_GET['month'] ?? date('n'));

    $prev_ts = mktime(0, 0, 0, $month - 1, 1, $year);
    $next_ts = mktime(0, 0, 0, $month + 1, 1, $year);
    $prev_y  = (int)date('Y', $prev_ts); $prev_m = (int)date('n', $prev_ts);
    $next_y  = (int)date('Y', $next_ts); $next_m = (int)date('n', $next_ts);
    $today   = date('Y-m-d');
    $month_label = date('F Y', mktime(0, 0, 0, $month, 1, $year));
    $is_next_disabled = ($next_y > (int)date('Y')) || ($next_y == (int)date('Y') && $next_m > (int)date('n'));

    $report = $store_id > 0 ? get_monthly_sales_report($store_id, $year, $month) : null;
} catch (Throwable $e) {
    $mo_sr_error = $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    error_log('main_office/sales_report.php error: ' . $mo_sr_error);
}

function mosr_qs(int $sid, int $y, int $m): string {
    return 'store_id=' . $sid . '&year=' . $y . '&month=' . $m;
}
?>

<style>
.mo-content { max-width: 100% !important; }
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
.report-table td.zero { color: #d1d5db; }
.sr-store-menu { width: 190px; flex-shrink: 0; }
.sr-store-menu .list-group-item { border-left: none; border-right: none; font-size: 0.85rem; }
.sr-store-menu .list-group-item.active { background: #4f46e5; border-color: #4f46e5; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h2 class="h5 fw-bold mb-0"><i class="fa-solid fa-chart-bar me-2 text-success"></i>SALES REPORT</h2>
  <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i>Back to List</a>
</div>

<?php if ($mo_sr_error !== null): ?>
<div class="alert alert-danger">
  <strong>An error occurred.</strong><br>
  <code><?php echo htmlspecialchars($mo_sr_error); ?></code>
</div>
<?php else: ?>

<div class="d-flex gap-3 align-items-start">

  <div class="sr-store-menu bg-white rounded-3 shadow-sm border overflow-hidden">
    <div class="px-3 py-2 border-bottom fw-semibold small text-muted bg-light"><i class="fa-solid fa-shop me-1"></i>Select Store</div>
    <div class="list-group list-group-flush">
      <?php foreach ($stores as $s): $sid = (int)$s['id']; ?>
      <a href="?<?php echo mosr_qs($sid, $year, $month); ?>"
         class="list-group-item list-group-item-action <?php echo $sid === $store_id ? 'active' : ''; ?>">
        <?php echo htmlspecialchars($s['name']); ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="flex-fill" style="min-width:0;">

  <div class="bg-white rounded-3 shadow-sm border p-3 mb-3 d-flex align-items-center gap-2 flex-wrap">
    <a href="?<?php echo mosr_qs($store_id, $prev_y, $prev_m); ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-chevron-left"></i></a>
    <span class="badge text-bg-light border"><?php echo htmlspecialchars($month_label); ?></span>
    <a href="?<?php echo mosr_qs($store_id, $next_y, $next_m); ?>"
       class="btn btn-sm btn-outline-secondary <?php echo $is_next_disabled ? 'disabled' : ''; ?>"><i class="fa-solid fa-chevron-right"></i></a>
    <?php if ($report): ?>
    <a href="export_sales_report.php?<?php echo mosr_qs($store_id, $year, $month); ?>"
       class="btn btn-sm btn-success ms-auto"><i class="fa-solid fa-file-excel me-1"></i>Download Excel</a>
    <?php endif; ?>
  </div>

  <?php if (!$report): ?>
  <p class="text-muted text-center py-5">No stores registered.</p>
  <?php else: ?>

  <div class="bg-white rounded-3 shadow-sm border p-3 mb-4 fw-bold">
    HOME K MART <?php echo htmlspecialchars(strtoupper($store_name)); ?> SALES REPORT
  </div>

  <div class="table-responsive bg-white rounded-3 shadow-sm border">
<table class="report-table">
  <thead>
    <tr>
      <th class="col-date" rowspan="2">DATE</th>
      <th colspan="2">GY<br><span style="font-weight:normal;font-size:9px;">12:00AM-8:00AM</span></th>
      <th colspan="2">MORNING<br><span style="font-weight:normal;font-size:9px;">8:00AM-5:00PM</span></th>
      <th colspan="2">MID<br><span style="font-weight:normal;font-size:9px;">5:00PM-12:00AM</span></th>
      <th>DELIVERY K</th>
      <th>POS<br><span style="font-weight:normal;font-size:9px;">Credit</span></th>
      <th>Credit Invoice</th>
      <th>WHOLE SALE</th>
      <th class="col-total">SALES TOTAL</th>
      <th>PURCHASE</th>
      <th>STORE EXP</th>
      <th>TRANSFER</th>
      <th>NET</th>
    </tr>
    <tr>
      <th>POS 1</th><th>POS 2</th>
      <th>POS 1</th><th>POS 2</th>
      <th>POS 1</th><th>POS 2</th>
      <th colspan="9"></th>
    </tr>
  </thead>
  <tbody>
  <?php $days_en = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
  for ($d = 1; $d <= $report['days']; $d++):
    $r  = $report['rows'][$d];
    $dt = date('Y-m-d', mktime(0, 0, 0, $month, $d, $year));
    $dow = $days_en[date('w', strtotime($dt))];
    $is_today = ($dt === $today);
  ?>
  <tr class="<?php echo $is_today ? 'table-primary' : ''; ?>">
    <td class="col-date text-start"><?php echo date('M j', strtotime($dt)); ?> <?php echo $dow; ?></td>
    <td class="<?php echo !$r['gy1'] ? 'zero' : ''; ?>"><?php echo sr_fmtC($r['gy1']); ?></td>
    <td class="<?php echo !$r['gy2'] ? 'zero' : ''; ?>"><?php echo sr_fmtC($r['gy2']); ?></td>
    <td class="<?php echo !$r['mo1'] ? 'zero' : ''; ?>"><?php echo sr_fmtC($r['mo1']); ?></td>
    <td class="<?php echo !$r['mo2'] ? 'zero' : ''; ?>"><?php echo sr_fmtC($r['mo2']); ?></td>
    <td class="<?php echo !$r['mi1'] ? 'zero' : ''; ?>"><?php echo sr_fmtC($r['mi1']); ?></td>
    <td class="<?php echo !$r['mi2'] ? 'zero' : ''; ?>"><?php echo sr_fmtC($r['mi2']); ?></td>
    <td class="<?php echo !$r['dk'] ? 'zero' : ''; ?>"><?php echo sr_fmtC($r['dk']); ?></td>
    <td class="<?php echo !$r['pc'] ? 'zero' : ''; ?>"><?php echo sr_fmtC($r['pc']); ?></td>
    <td class="<?php echo !$r['cd'] ? 'zero' : ''; ?>"><?php echo sr_fmtC($r['cd']); ?></td>
    <td class="<?php echo !$r['ws'] ? 'zero' : ''; ?>"><?php echo sr_fmtC($r['ws']); ?></td>
    <td class="col-total <?php echo !$r['st'] ? 'zero' : ''; ?>"><?php echo sr_fmtC($r['st']); ?></td>
    <td class="<?php echo !$r['pu'] ? 'zero' : ''; ?>"><?php echo sr_fmtC($r['pu']); ?></td>
    <td class="<?php echo !$r['eq'] ? 'zero' : ''; ?>"><?php echo sr_fmtC($r['eq']); ?></td>
    <td class="<?php echo !$r['tr'] ? 'zero' : ''; ?>"><?php echo sr_fmtC($r['tr']); ?></td>
    <td class="<?php echo $r['net'] < 0 ? 'text-danger fw-semibold' : (!$r['net'] ? 'zero' : ''); ?>"><?php echo sr_fmtC($r['net']); ?></td>
  </tr>
  <?php endfor; ?>
  <tr class="row-sum">
    <td class="col-date text-start">TOTAL</td>
    <td><?php echo sr_fmtT($report['col_totals']['gy_pos1']); ?></td>
    <td><?php echo sr_fmtT($report['col_totals']['gy_pos2']); ?></td>
    <td><?php echo sr_fmtT($report['col_totals']['morning_pos1']); ?></td>
    <td><?php echo sr_fmtT($report['col_totals']['morning_pos2']); ?></td>
    <td><?php echo sr_fmtT($report['col_totals']['mid_pos1']); ?></td>
    <td><?php echo sr_fmtT($report['col_totals']['mid_pos2']); ?></td>
    <td><?php echo sr_fmtT($report['col_totals']['delivery_k']); ?></td>
    <td><?php echo sr_fmtT($report['col_totals']['pos_credit']); ?></td>
    <td><?php echo sr_fmtT($report['col_totals']['credit_doc']); ?></td>
    <td><?php echo sr_fmtT($report['col_totals']['whole_sale']); ?></td>
    <td class="col-total"><?php echo sr_fmtT($report['col_totals']['sales_total']); ?></td>
    <td><?php echo sr_fmtT($report['col_totals']['purchase']); ?></td>
    <td><?php echo sr_fmtT($report['col_totals']['equip']); ?></td>
    <td><?php echo sr_fmtT($report['col_totals']['transfer']); ?></td>
    <td><?php echo sr_fmtT($report['col_totals']['net']); ?></td>
  </tr>
  <?php $div = $report['days_with_sales'] > 0 ? $report['days_with_sales'] : 1; ?>
  <tr class="row-avg">
    <td class="col-date text-start">AVG / DAY<br><span style="font-size:9px;"><?php echo $report['days_with_sales']; ?> business days</span></td>
    <td><?php echo sr_fmtT($report['col_totals']['gy_pos1']/$div); ?></td>
    <td><?php echo sr_fmtT($report['col_totals']['gy_pos2']/$div); ?></td>
    <td><?php echo sr_fmtT($report['col_totals']['morning_pos1']/$div); ?></td>
    <td><?php echo sr_fmtT($report['col_totals']['morning_pos2']/$div); ?></td>
    <td><?php echo sr_fmtT($report['col_totals']['mid_pos1']/$div); ?></td>
    <td><?php echo sr_fmtT($report['col_totals']['mid_pos2']/$div); ?></td>
    <td><?php echo sr_fmtT($report['col_totals']['delivery_k']/$div); ?></td>
    <td><?php echo sr_fmtT($report['col_totals']['pos_credit']/$div); ?></td>
    <td><?php echo sr_fmtT($report['col_totals']['credit_doc']/$div); ?></td>
    <td><?php echo sr_fmtT($report['col_totals']['whole_sale']/$div); ?></td>
    <td class="col-total"><?php echo sr_fmtT($report['col_totals']['sales_total']/$div); ?></td>
    <td><?php echo sr_fmtT($report['col_totals']['purchase']/$div); ?></td>
    <td><?php echo sr_fmtT($report['col_totals']['equip']/$div); ?></td>
    <td><?php echo sr_fmtT($report['col_totals']['transfer']/$div); ?></td>
    <td><?php echo sr_fmtT($report['col_totals']['net']/$div); ?></td>
  </tr>
  </tbody>
</table>
</div>

<div class="row g-3 mt-1">
  <div class="col-12 col-lg-6">
    <div class="bg-white rounded-3 shadow-sm border p-3">
      <div class="fw-semibold small text-muted mb-2"><i class="fa-solid fa-chart-pie me-1"></i>Sales Breakdown</div>
      <table class="table table-sm mb-0">
        <tr><td>Retail Sales (POS)</td><td class="text-end"><?php echo sr_fmtT($report['retail_total']); ?></td><td class="text-end text-muted"><?php echo sr_fmtPct($report['retail_pct']); ?></td></tr>
        <tr><td>Wholesale Sales</td><td class="text-end"><?php echo sr_fmtT($report['wholesale_total']); ?></td><td class="text-end text-muted"><?php echo sr_fmtPct($report['wholesale_pct']); ?></td></tr>
        <tr class="fw-bold border-top"><td>TOTAL SALES</td><td class="text-end"><?php echo sr_fmtT($report['sales_total_s']); ?></td><td class="text-end">100%</td></tr>
      </table>
    </div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="bg-white rounded-3 shadow-sm border p-3">
      <div class="fw-semibold small text-muted mb-2"><i class="fa-solid fa-percent me-1"></i>Key Ratios</div>
      <table class="table table-sm mb-0">
        <tr><td>Purchase Ratio</td><td class="text-end"><?php echo sr_fmtPct($report['purchase_ratio']); ?></td></tr>
        <tr><td>Expense Ratio</td><td class="text-end"><?php echo sr_fmtPct($report['expense_ratio']); ?></td></tr>
        <tr><td>Net Margin</td><td class="text-end <?php echo $report['net_margin'] < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo sr_fmtPct($report['net_margin']); ?></td></tr>
      </table>
    </div>
  </div>
</div>

<?php endif; ?>

  </div>
</div>

<?php endif; // $mo_sr_error ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
