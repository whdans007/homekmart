<?php
// Design Ref: main-office-dashboard — main_office_admin+ only, hub for viewing per-store data
$page_title = 'Main Office';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../office/lib/office_helper.php';
require_once __DIR__ . '/../config/db_config.php';

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
$today = date('Y-m-d');

$conn = get_db_connection();
// Logistics center/warehouse entries aren't real stores, so exclude them from the list
// (Ref: lib/permission_helper.php is_logistics_department, kimsmall_wherehouse/lib/auth.php)
$stores = $conn->query(
    "SELECT id, name FROM stores
     WHERE name NOT IN ('CENTER (물류센터)', 'KIMS MALL WHEREHOUSE (킴스몰 창고)')
     ORDER BY name"
)->fetch_all(MYSQLI_ASSOC);
$conn->close();

$month_options = [];
for ($i = 0; $i < 12; $i++) {
    $ts = mktime(0, 0, 0, date('n') - $i, 1, date('Y'));
    $month_options[] = ['y' => (int)date('Y', $ts), 'm' => (int)date('n', $ts), 'label' => date('F Y', $ts)];
}
?>

<form method="GET" class="d-flex align-items-center gap-2 mb-4 flex-wrap">
  <h2 class="h5 fw-bold mb-0 me-2"><i class="fa-solid fa-store me-2 text-primary"></i>Store Data</h2>
  <select name="year_month" id="mo_ym" class="form-select form-select-sm" style="width:auto" onchange="this.form.year.value=this.value.split('-')[0];this.form.month.value=this.value.split('-')[1];this.form.submit();">
    <?php foreach ($month_options as $opt): $val = $opt['y'].'-'.$opt['m']; ?>
    <option value="<?php echo $val; ?>" <?php echo ($opt['y']==$year && $opt['m']==$month) ? 'selected' : ''; ?>>
      <?php echo htmlspecialchars($opt['label']); ?>
    </option>
    <?php endforeach; ?>
  </select>
  <input type="hidden" name="year" value="<?php echo $year; ?>">
  <input type="hidden" name="month" value="<?php echo $month; ?>">
</form>

<div class="row g-3">
<?php foreach ($stores as $store):
    $sid    = (int)$store['id'];
    $totals = get_purchase_monthly_total($sid, $year, $month);
    $pending = get_pending_checks_count($sid);
    $total_sum = $totals['product_cash'] + $totals['product_check'] + $totals['equipment'];
?>
  <div class="col-12 col-md-6 col-lg-4">
    <div class="bg-white rounded-3 shadow-sm border p-3 h-100">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="fw-bold"><i class="fa-solid fa-shop me-1 text-primary"></i><?php echo htmlspecialchars($store['name']); ?></span>
        <?php if ($pending > 0): ?>
        <span class="badge text-bg-danger">Pending Checks <?php echo $pending; ?></span>
        <?php endif; ?>
      </div>
      <table class="table table-sm mb-2">
        <tr><td class="text-muted">Purchases (Cash)</td><td class="text-end"><?php echo format_amount($totals['product_cash']); ?></td></tr>
        <tr><td class="text-muted">Purchases (Check)</td><td class="text-end"><?php echo format_amount($totals['product_check']); ?></td></tr>
        <tr><td class="text-muted">Equipment Purchases</td><td class="text-end"><?php echo format_amount($totals['equipment']); ?></td></tr>
        <tr class="fw-bold border-top"><td>Total</td><td class="text-end"><?php echo format_amount($total_sum); ?></td></tr>
      </table>
      <div class="d-flex flex-wrap gap-2">
        <a href="daily_report.php?store_id=<?php echo $sid; ?>&date=<?php echo $today; ?>"
           class="btn btn-sm btn-outline-primary flex-fill">
          <i class="fa-solid fa-file-invoice me-1"></i>Daily Report
        </a>
        <a href="sales_report.php?store_id=<?php echo $sid; ?>&year=<?php echo $year; ?>&month=<?php echo $month; ?>"
           class="btn btn-sm btn-outline-success flex-fill">
          <i class="fa-solid fa-chart-bar me-1"></i>Sales Report
        </a>
        <a href="expense_report.php?store_id=<?php echo $sid; ?>&date=<?php echo $today; ?>"
           class="btn btn-sm btn-outline-secondary flex-fill">
          <i class="fa-solid fa-receipt me-1"></i>Expense
        </a>
        <a href="cash_disbursement.php?store_id=<?php echo $sid; ?>&date=<?php echo $today; ?>"
           class="btn btn-sm btn-outline-warning flex-fill">
          <i class="fa-solid fa-money-bill-wave me-1"></i>Cash Disb.
        </a>
        <a href="cheque_expense_report.php?store_id=<?php echo $sid; ?>&date=<?php echo $today; ?>"
           class="btn btn-sm btn-outline-danger flex-fill">
          <i class="fa-solid fa-file-invoice-dollar me-1"></i>Cheque Exp.
        </a>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<?php if (empty($stores)): ?>
<p class="text-muted text-center py-5">No stores registered.</p>
<?php endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
