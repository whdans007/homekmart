<?php
// Design Ref: main-office-dashboard — read-only per-store Daily Report viewer
// (data layout follows office/daily_report/index.php).
// Drag-and-drop editing / commission registration (write features) are excluded — view only.
$page_title = 'Daily Report';
require_once __DIR__ . '/partials/header.php';

$mo_error = null;
try {
    require_once __DIR__ . '/../office/lib/daily_report_helper.php';

    $conn = get_db_connection();
    // Logistics center/warehouse entries aren't real stores, so exclude them from the list
    $stores = $conn->query(
        "SELECT id, name FROM stores
         WHERE name NOT IN ('CENTER (물류센터)', 'KIMS MALL WHEREHOUSE (킴스몰 창고)')
         ORDER BY name"
    )->fetch_all(MYSQLI_ASSOC);

    $store_id = (int)($_GET['store_id'] ?? 0);
    if ($store_id <= 0 || !in_array($store_id, array_map('intval', array_column($stores, 'id')), true)) {
        $store_id = (int)($stores[0]['id'] ?? 0);
    }
    $store_label = '';
    foreach ($stores as $s) {
        if ((int)$s['id'] === $store_id) { $store_label = $s['name']; break; }
    }

    $today     = date('Y-m-d');
    $date      = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : $today;
    $prev_date = date('Y-m-d', strtotime($date . ' -1 day'));
    $next_date = date('Y-m-d', strtotime($date . ' +1 day'));
    $is_future = $next_date > $today;

    $pos_summary   = get_daily_pos_summary($conn, $store_id, $date);
    $credit_detail = get_daily_credit_breakdown($conn, $store_id, $date);
    $purchase      = get_daily_purchase_summary($conn, $store_id, $date);
    $ar            = get_daily_ar_summary($conn, $store_id, $date);
    $wholesale     = get_daily_wholesale_summary($conn, $store_id, $date);
    $other_exp     = get_daily_other_expense_categories($conn, $store_id, $date);

    $commission_tbl = $conn->query("SHOW TABLES LIKE 'daily_report_commission_companies'");
    $commission_tbl_ready = $commission_tbl && $commission_tbl->num_rows > 0;
    $commission = $commission_tbl_ready ? get_daily_commission_summary($conn, $store_id, $date) : ['rows' => [], 'total' => 0.0];

    $conn->close();

    $grand_sales    = $pos_summary['totals']['total'] + $ar['credit_sales_total'];
    $grand_purchase = $purchase['totals']['total'];
    $grand_expense  = $other_exp['total_placed'];
    $grand_profit   = $grand_sales - $grand_purchase - $grand_expense;
} catch (Throwable $e) {
    $mo_error = $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    error_log('main_office/daily_report.php error: ' . $mo_error);
}

function mo_dr_qs(int $sid, string $d): string {
    return 'store_id=' . $sid . '&date=' . $d;
}
function mo_fmt2($n) { return number_format((float)$n, 2); }
?>

<style>
.mo-content { max-width: 100% !important; }
.sr-store-menu { width: 190px; flex-shrink: 0; }
.sr-store-menu .list-group-item { border-left: none; border-right: none; font-size: 0.85rem; }
.sr-store-menu .list-group-item.active { background: #4f46e5; border-color: #4f46e5; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h2 class="h5 fw-bold mb-0"><i class="fa-solid fa-file-invoice me-2 text-primary"></i>Daily Report</h2>
  <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i>Back to List</a>
</div>

<?php if ($mo_error !== null): ?>
<div class="alert alert-danger">
  <strong>An error occurred.</strong><br>
  <code><?php echo htmlspecialchars($mo_error); ?></code>
</div>
<?php else: ?>

<div class="d-flex gap-3 align-items-start">

  <div class="sr-store-menu bg-white rounded-3 shadow-sm border overflow-hidden">
    <div class="px-3 py-2 border-bottom fw-semibold small text-muted bg-light"><i class="fa-solid fa-shop me-1"></i>Select Store</div>
    <div class="list-group list-group-flush">
      <?php foreach ($stores as $s): $sid = (int)$s['id']; ?>
      <a href="?<?php echo mo_dr_qs($sid, $date); ?>"
         class="list-group-item list-group-item-action <?php echo $sid === $store_id ? 'active' : ''; ?>">
        <?php echo htmlspecialchars($s['name']); ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="flex-fill" style="min-width:0;">

  <div class="bg-white rounded-3 shadow-sm border p-3 mb-3 d-flex align-items-center gap-2 flex-wrap">
    <span class="fw-semibold small text-muted me-1"><?php echo htmlspecialchars($store_label); ?></span>
    <a href="?<?php echo mo_dr_qs($store_id, $prev_date); ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-chevron-left"></i></a>
    <input type="date" max="<?php echo $today; ?>" value="<?php echo $date; ?>" class="form-control form-control-sm" style="width:auto"
           onchange="if(this.value) window.location.href='?store_id=<?php echo $store_id; ?>&date='+this.value">
    <a href="?<?php echo mo_dr_qs($store_id, $next_date); ?>"
       class="btn btn-sm btn-outline-secondary <?php echo $is_future ? 'disabled' : ''; ?>"><i class="fa-solid fa-chevron-right"></i></a>
    <?php if ($date === $today): ?><span class="badge text-bg-primary">Today</span><?php endif; ?>
  </div>

  <!-- Summary -->
  <div class="table-responsive bg-white rounded-3 shadow-sm border p-3 mb-3">
    <table class="table table-bordered text-center align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th rowspan="2">Date</th>
          <th colspan="3">Sales</th>
          <th colspan="2">Purchase</th>
          <th rowspan="2">Other Expense</th>
          <th>AR Collection</th>
          <th rowspan="2">Profit</th>
        </tr>
        <tr>
          <th>POS+Manual</th><th>Credit Customer</th><th>Commission Corner</th>
          <th>Cash</th><th>Check</th>
          <th>Cash</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td class="fw-semibold"><?php echo htmlspecialchars($date); ?></td>
          <td class="text-end"><?php echo mo_fmt2($pos_summary['totals']['total']); ?></td>
          <td class="text-end"><?php echo $ar['credit_sales_total'] > 0 ? mo_fmt2($ar['credit_sales_total']) : '-'; ?></td>
          <td class="text-end"><?php echo $commission['total'] > 0 ? mo_fmt2($commission['total']) : '-'; ?></td>
          <td class="text-end"><?php echo mo_fmt2($purchase['totals']['cash']); ?></td>
          <td class="text-end"><?php echo mo_fmt2($purchase['totals']['check']); ?></td>
          <td class="text-end"><?php echo mo_fmt2($grand_expense); ?></td>
          <td class="text-end"><?php echo mo_fmt2($ar['collections_total']); ?></td>
          <td class="text-end fw-bold table-success"><?php echo mo_fmt2($grand_profit); ?></td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="row g-3 mb-3">
    <!-- POS Sales -->
    <div class="col-12 col-lg-5">
      <div class="bg-white rounded-3 shadow-sm border overflow-hidden h-100">
        <div class="px-3 py-2 bg-primary bg-opacity-10 border-bottom fw-semibold small text-primary">POS Sales</div>
        <table class="table table-sm mb-0">
          <thead class="table-light"><tr><th>POS</th><th class="text-end">Cash</th><th class="text-end">Credit</th><th class="text-end">Wholesale</th><th class="text-end">Total</th></tr></thead>
          <tbody>
          <?php foreach ($pos_summary['rows'] as $row): ?>
            <tr>
              <td><?php echo htmlspecialchars($row['label']); ?></td>
              <td class="text-end"><?php echo mo_fmt2($row['cash']); ?></td>
              <td class="text-end"><?php echo mo_fmt2($row['credit']); ?></td>
              <td class="text-end"><?php echo $row['delivery_slip'] > 0 ? mo_fmt2($row['delivery_slip']) : '-'; ?></td>
              <td class="text-end fw-semibold"><?php echo mo_fmt2($row['total']); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot class="table-light fw-bold">
            <tr>
              <td>Total</td>
              <td class="text-end"><?php echo mo_fmt2($pos_summary['totals']['cash']); ?></td>
              <td class="text-end"><?php echo mo_fmt2($pos_summary['totals']['credit']); ?></td>
              <td class="text-end"><?php echo mo_fmt2($pos_summary['totals']['delivery_slip']); ?></td>
              <td class="text-end"><?php echo mo_fmt2($pos_summary['totals']['total']); ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <!-- Commission Corner -->
    <div class="col-12 col-lg-3">
      <div class="bg-white rounded-3 shadow-sm border overflow-hidden h-100">
        <div class="px-3 py-2 bg-warning bg-opacity-25 border-bottom fw-semibold small">Commission Corner</div>
        <?php if (!$commission_tbl_ready): ?>
        <div class="p-3 small text-warning">The commission corner table isn't set up yet.</div>
        <?php else: ?>
        <table class="table table-sm mb-0">
          <thead class="table-light"><tr><th>Company</th><th class="text-end">Sales</th></tr></thead>
          <tbody>
          <?php if (empty($commission['rows'])): ?>
            <tr><td colspan="2" class="text-center text-muted py-3">No companies registered</td></tr>
          <?php endif; ?>
          <?php foreach ($commission['rows'] as $row): ?>
            <tr>
              <td><?php echo htmlspecialchars($row['supplier_name']); ?></td>
              <td class="text-end"><?php echo $row['amount'] > 0 ? mo_fmt2($row['amount']) : '-'; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot class="table-light fw-bold">
            <tr><td>Total</td><td class="text-end"><?php echo mo_fmt2($commission['total']); ?></td></tr>
          </tfoot>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- Other Expenses -->
    <div class="col-12 col-lg-4">
      <div class="bg-white rounded-3 shadow-sm border overflow-hidden h-100">
        <div class="px-3 py-2 bg-orange-subtle border-bottom fw-semibold small" style="background:#fff3e0;">Other Expenses</div>
        <table class="table table-sm mb-0">
          <thead class="table-light"><tr><th>Detail</th><th class="text-end">Amount</th></tr></thead>
          <tbody>
          <?php foreach ($other_exp['categories'] as $key => $label): ?>
            <tr>
              <td><?php echo htmlspecialchars($label); ?></td>
              <td class="text-end"><?php echo $other_exp['by_category'][$key] > 0 ? mo_fmt2($other_exp['by_category'][$key]) : '-'; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot class="table-light fw-bold">
            <tr><td>Total</td><td class="text-end"><?php echo mo_fmt2($other_exp['total_placed']); ?></td></tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <!-- Purchases -->
    <div class="col-12 col-lg-5">
      <div class="bg-white rounded-3 shadow-sm border overflow-hidden h-100">
        <div class="px-3 py-2 bg-teal bg-opacity-10 border-bottom fw-semibold small" style="background:#e0f2f1;">Purchases</div>
        <table class="table table-sm mb-0">
          <thead class="table-light"><tr><th>Supplier</th><th class="text-end">Cash Purchase</th><th class="text-end">Check Purchase</th><th class="text-end">Total</th></tr></thead>
          <tbody>
          <?php if (empty($purchase['rows'])): ?>
            <tr><td colspan="4" class="text-center text-muted py-3">No data</td></tr>
          <?php endif; ?>
          <?php foreach ($purchase['rows'] as $row): ?>
            <tr>
              <td><?php echo htmlspecialchars($row['supplier']); ?></td>
              <td class="text-end"><?php echo $row['cash'] > 0 ? mo_fmt2($row['cash']) : '-'; ?></td>
              <td class="text-end"><?php echo $row['check'] > 0 ? mo_fmt2($row['check']) : '-'; ?></td>
              <td class="text-end fw-semibold"><?php echo mo_fmt2($row['total']); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot class="table-light fw-bold">
            <tr>
              <td>Total</td>
              <td class="text-end"><?php echo mo_fmt2($purchase['totals']['cash']); ?></td>
              <td class="text-end"><?php echo mo_fmt2($purchase['totals']['check']); ?></td>
              <td class="text-end"><?php echo mo_fmt2($purchase['totals']['total']); ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <!-- Credit (CARD, E-MONEY) -->
    <div class="col-12 col-lg-3">
      <div class="bg-white rounded-3 shadow-sm border overflow-hidden h-100">
        <div class="px-3 py-2 bg-indigo bg-opacity-10 border-bottom fw-semibold small" style="background:#e8eaf6;">Credit (CARD, E-MONEY)</div>
        <table class="table table-sm mb-0">
          <thead class="table-light"><tr><th>Type</th><th class="text-end">Amount</th></tr></thead>
          <tbody>
          <?php foreach (['BDO', 'GCASH', 'MAYA', 'QR'] as $bucket): ?>
            <tr>
              <td><?php echo $bucket; ?></td>
              <td class="text-end"><?php echo $credit_detail[$bucket] > 0 ? mo_fmt2($credit_detail[$bucket]) : '-'; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot class="table-light fw-bold">
            <tr><td>Total</td><td class="text-end"><?php echo mo_fmt2($credit_detail['total']); ?></td></tr>
          </tfoot>
        </table>
      </div>
    </div>

    <!-- AR Collections -->
    <div class="col-12 col-lg-4">
      <div class="bg-white rounded-3 shadow-sm border overflow-hidden h-100">
        <div class="px-3 py-2 bg-rose bg-opacity-10 border-bottom fw-semibold small" style="background:#fce4ec;">AR Collections</div>
        <table class="table table-sm mb-0">
          <thead class="table-light"><tr><th>Customer</th><th class="text-end">Amount</th></tr></thead>
          <tbody>
          <?php if (empty($ar['collections'])): ?>
            <tr><td colspan="2" class="text-center text-muted py-3">No data</td></tr>
          <?php endif; ?>
          <?php foreach ($ar['collections'] as $row): ?>
            <tr>
              <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
              <td class="text-end"><?php echo mo_fmt2($row['amount']); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot class="table-light fw-bold">
            <tr><td>Total</td><td class="text-end"><?php echo mo_fmt2($ar['collections_total']); ?></td></tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <!-- Credit Sales -->
    <div class="col-12 col-lg-6">
      <div class="bg-white rounded-3 shadow-sm border overflow-hidden h-100">
        <div class="px-3 py-2 bg-rose bg-opacity-10 border-bottom fw-semibold small" style="background:#fce4ec;">Credit Sales</div>
        <table class="table table-sm mb-0">
          <thead class="table-light"><tr><th>Customer</th><th class="text-end">POS Entry</th><th class="text-end">Credit Invoice</th></tr></thead>
          <tbody>
          <?php if (empty($ar['credit_sales'])): ?>
            <tr><td colspan="3" class="text-center text-muted py-3">No data</td></tr>
          <?php endif; ?>
          <?php foreach ($ar['credit_sales'] as $row): ?>
            <tr>
              <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
              <td class="text-end">-</td>
              <td class="text-end"><?php echo mo_fmt2($row['amount']); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot class="table-light fw-bold">
            <tr><td>Total</td><td class="text-end">-</td><td class="text-end"><?php echo mo_fmt2($ar['credit_sales_total']); ?></td></tr>
          </tfoot>
        </table>
      </div>
    </div>

    <!-- Wholesale Sales (Credit Invoice) -->
    <div class="col-12 col-lg-6">
      <div class="bg-white rounded-3 shadow-sm border overflow-hidden h-100">
        <div class="px-3 py-2 bg-cyan bg-opacity-10 border-bottom fw-semibold small" style="background:#e0f7fa;">Wholesale Sales (Credit Invoice)</div>
        <table class="table table-sm mb-0">
          <thead class="table-light"><tr><th>Customer</th><th class="text-end">Amount</th></tr></thead>
          <tbody>
          <?php $ws_rows = array_merge($wholesale['delivery_k'], $wholesale['whole_sale']); ?>
          <?php if (empty($ws_rows)): ?>
            <tr><td colspan="2" class="text-center text-muted py-3">No data</td></tr>
          <?php endif; ?>
          <?php foreach ($ws_rows as $row): ?>
            <tr>
              <td><?php echo htmlspecialchars($row['customer']); ?></td>
              <td class="text-end"><?php echo mo_fmt2($row['amount']); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot class="table-light fw-bold">
            <tr><td>Total</td><td class="text-end"><?php echo mo_fmt2($wholesale['total']); ?></td></tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
