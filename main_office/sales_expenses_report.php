<?php
// Design Ref: sales-expenses-report.design.md §5/§11 — 점포선택+월이동+일자별 그리드 (sales_report.php 뼈대 재사용)
$page_title = 'Sales & Expenses Report';
require_once __DIR__ . '/partials/header.php';

$mo_ser_error = null;
try {
    require_once __DIR__ . '/../office/lib/sales_expenses_report_helper.php';

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

    $report = $store_id > 0 ? get_monthly_sales_expenses_report($store_id, $year, $month) : null;
} catch (Throwable $e) {
    $mo_ser_error = $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    error_log('main_office/sales_expenses_report.php error: ' . $mo_ser_error);
}

function moser_qs(int $sid, int $y, int $m): string {
    return 'store_id=' . $sid . '&year=' . $y . '&month=' . $m;
}
?>

<style>
.mo-content { max-width: 100% !important; }
.ser-table { font-size: 12px; border-collapse: collapse; width: 100%; }
.ser-table th, .ser-table td {
    border: 1px solid #ccc; padding: 4px 8px; white-space: nowrap;
    text-align: right; vertical-align: middle;
}
.ser-table .col-date { text-align: left; min-width: 110px; }
.ser-table thead th { background: #f3f4f6; font-weight: 600; text-align: center; font-size: 11px; }
.ser-table .col-total { background: #fee2e2; font-weight: bold; color: #991b1b; }
.ser-table .row-sum td { background: #fee2e2; font-weight: bold; }
.ser-table td.zero { color: #d1d5db; }
.ser-table .col-particular { text-align: left; white-space: normal; min-width: 180px; }
.ser-particular-input {
    width: 100%; border: 1px solid transparent; background: transparent;
    font-size: 12px; padding: 2px 4px; border-radius: 4px;
}
.ser-particular-input:hover { border-color: #e5e7eb; }
.ser-particular-input:focus { outline: none; border-color: #4f46e5; background: #fff; }
.ser-particular-input.ser-saved { background: #f0fdf4; }
.sr-store-menu { width: 190px; flex-shrink: 0; }
.sr-store-menu .list-group-item { border-left: none; border-right: none; font-size: 0.85rem; }
.sr-store-menu .list-group-item.active { background: #4f46e5; border-color: #4f46e5; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h2 class="h5 fw-bold mb-0"><i class="fa-solid fa-scale-balanced me-2 text-success"></i>SALES &amp; EXPENSES REPORT</h2>
  <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i>Back to List</a>
</div>

<?php if ($mo_ser_error !== null): ?>
<div class="alert alert-danger">
  <strong>An error occurred.</strong><br>
  <code><?php echo htmlspecialchars($mo_ser_error); ?></code>
</div>
<?php else: ?>

<div class="d-flex gap-3 align-items-start">

  <div class="sr-store-menu bg-white rounded-3 shadow-sm border overflow-hidden">
    <div class="px-3 py-2 border-bottom fw-semibold small text-muted bg-light"><i class="fa-solid fa-shop me-1"></i>Select Store</div>
    <div class="list-group list-group-flush">
      <?php foreach ($stores as $s): $sid = (int)$s['id']; ?>
      <a href="?<?php echo moser_qs($sid, $year, $month); ?>"
         class="list-group-item list-group-item-action <?php echo $sid === $store_id ? 'active' : ''; ?>">
        <?php echo htmlspecialchars($s['name']); ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="flex-fill" style="min-width:0;">

  <div class="bg-white rounded-3 shadow-sm border p-3 mb-3 d-flex align-items-center gap-2 flex-wrap">
    <a href="?<?php echo moser_qs($store_id, $prev_y, $prev_m); ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-chevron-left"></i></a>
    <span class="badge text-bg-light border"><?php echo htmlspecialchars($month_label); ?></span>
    <a href="?<?php echo moser_qs($store_id, $next_y, $next_m); ?>"
       class="btn btn-sm btn-outline-secondary <?php echo $is_next_disabled ? 'disabled' : ''; ?>"><i class="fa-solid fa-chevron-right"></i></a>
    <?php if ($report): ?>
    <a href="export_sales_expenses_report.php?<?php echo moser_qs($store_id, $year, $month); ?>"
       class="btn btn-sm btn-success ms-auto"><i class="fa-solid fa-file-excel me-1"></i>Download Excel</a>
    <?php endif; ?>
  </div>

  <?php if (!$report): ?>
  <p class="text-muted text-center py-5">No stores registered.</p>
  <?php else: ?>

  <div class="bg-white rounded-3 shadow-sm border p-3 mb-4 fw-bold">
    <?php echo htmlspecialchars(strtoupper($store_name)); ?> — SALES AND EXPENSES REPORT (<?php echo strtoupper($month_label); ?>)
  </div>

  <div class="table-responsive bg-white rounded-3 shadow-sm border">
  <table class="ser-table">
    <thead>
      <tr>
        <th class="col-date" rowspan="2">DATE</th>
        <th colspan="2">SALES</th>
        <th colspan="2">EXPENSES</th>
        <th rowspan="2">PARTICULAR</th>
      </tr>
      <tr>
        <th>CASH</th><th>CREDITS</th>
        <th>CASH</th><th>CHEQUE</th>
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
      <td class="<?php echo !$r['cash_sales'] ? 'zero' : ''; ?>"><?php echo ser_fmtC($r['cash_sales']); ?></td>
      <td class="<?php echo !$r['credit_sales'] ? 'zero' : ''; ?>"><?php echo ser_fmtC($r['credit_sales']); ?></td>
      <td class="<?php echo !$r['cash_expense'] ? 'zero' : ''; ?>"><?php echo ser_fmtC($r['cash_expense']); ?></td>
      <td class="<?php echo !$r['cheque_expense'] ? 'zero' : ''; ?>"><?php echo ser_fmtC($r['cheque_expense']); ?></td>
      <td class="col-particular">
        <input type="text" class="ser-particular-input" data-store="<?php echo $store_id; ?>" data-date="<?php echo $dt; ?>"
               value="<?php echo htmlspecialchars($r['particular']); ?>">
      </td>
    </tr>
    <?php endfor; ?>
    <tr class="row-sum">
      <td class="col-date text-start">TOTAL</td>
      <td><?php echo ser_fmtT($report['col_totals']['cash_sales']); ?></td>
      <td><?php echo ser_fmtT($report['col_totals']['credit_sales']); ?></td>
      <td><?php echo ser_fmtT($report['col_totals']['cash_expense']); ?></td>
      <td><?php echo ser_fmtT($report['col_totals']['cheque_expense']); ?></td>
      <td></td>
    </tr>
    </tbody>
  </table>
  </div>

  <div class="bg-white rounded-3 shadow-sm border p-3 mt-3 d-flex align-items-center justify-content-end gap-2">
    <span class="fw-semibold text-muted">PROFIT / LOSS:</span>
    <span class="fs-5 fw-bold <?php echo $report['profit_loss'] < 0 ? 'text-danger' : 'text-success'; ?>">
      <?php echo ser_fmtT($report['profit_loss']); ?>
    </span>
  </div>

  <script>
  document.querySelectorAll('.ser-particular-input').forEach(function (input) {
      var original = input.value;
      input.addEventListener('blur', function () {
          if (input.value === original) return;
          fetch('ajax_save_ser_particular.php', {
              method: 'POST',
              headers: {'Content-Type': 'application/x-www-form-urlencoded'},
              body: new URLSearchParams({
                  store_id: input.dataset.store,
                  date: input.dataset.date,
                  particular: input.value
              })
          })
          .then(function (r) { return r.json(); })
          .then(function (res) {
              if (res.success) {
                  original = input.value;
                  input.classList.add('ser-saved');
                  setTimeout(function () { input.classList.remove('ser-saved'); }, 800);
              }
          });
      });
  });
  </script>

  <?php endif; ?>

  </div>
</div>

<?php endif; // $mo_ser_error ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
