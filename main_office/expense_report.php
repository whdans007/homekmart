<?php
// Design Ref: main-office-reports — Expense Report read-only viewer + left store menu.
// Reuses office/expense_report/print_er.php via the mo_store_id parameter so the format
// (A4 print layout) is identical to the original (embedded via iframe).
$page_title = 'Expense Report';
require_once __DIR__ . '/partials/header.php';

$mo_error = null;
try {
    require_once __DIR__ . '/../office/lib/office_helper.php';

    $conn = get_db_connection();
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

    $today     = date('Y-m-d');
    $date      = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : $today;
    $prev_date = date('Y-m-d', strtotime($date . ' -1 day'));
    $next_date = date('Y-m-d', strtotime($date . ' +1 day'));
    $is_future = $next_date > $today;

    $state = $store_id > 0 ? get_saved_report_state('er_saved_state', $store_id, $date) : null;

    $print_url  = '../office/expense_report/print_er.php?mo_store_id=' . $store_id . '&date=' . $date;
    $export_url = '../office/expense_report/export_er.php?mo_store_id=' . $store_id . '&date=' . $date;
} catch (Throwable $e) {
    $mo_error = $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    error_log('main_office/expense_report.php error: ' . $mo_error);
}

function mo_er_qs(int $sid, string $d): string {
    return 'store_id=' . $sid . '&date=' . $d;
}
?>

<?php if ($mo_error !== null): ?>
<div class="alert alert-danger">
  <strong>An error occurred.</strong><br>
  <code><?php echo htmlspecialchars($mo_error); ?></code>
</div>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
<?php exit; ?>
<?php endif; ?>

<style>
.mo-content { max-width: 100% !important; }
.sr-store-menu { width: 190px; flex-shrink: 0; }
.sr-store-menu .list-group-item { border-left: none; border-right: none; font-size: 0.85rem; }
.sr-store-menu .list-group-item.active { background: #4f46e5; border-color: #4f46e5; }
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h2 class="h5 fw-bold mb-0"><i class="fa-solid fa-receipt me-2 text-primary"></i>Expense Report</h2>
  <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i>Back to List</a>
</div>

<div class="d-flex gap-3 align-items-start">

  <div class="sr-store-menu bg-white rounded-3 shadow-sm border overflow-hidden">
    <div class="px-3 py-2 border-bottom fw-semibold small text-muted bg-light"><i class="fa-solid fa-shop me-1"></i>Select Store</div>
    <div class="list-group list-group-flush">
      <?php foreach ($stores as $s): $sid = (int)$s['id']; ?>
      <a href="?<?php echo mo_er_qs($sid, $date); ?>"
         class="list-group-item list-group-item-action <?php echo $sid === $store_id ? 'active' : ''; ?>">
        <?php echo htmlspecialchars($s['name']); ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="flex-fill" style="min-width:0;">

    <div class="bg-white rounded-3 shadow-sm border p-3 mb-3 d-flex align-items-center gap-2 flex-wrap">
      <span class="fw-semibold small text-muted me-1"><?php echo htmlspecialchars($store_name); ?></span>
      <a href="?<?php echo mo_er_qs($store_id, $prev_date); ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-chevron-left"></i></a>
      <input type="date" max="<?php echo $today; ?>" value="<?php echo $date; ?>" class="form-control form-control-sm" style="width:auto"
             onchange="if(this.value) window.location.href='?store_id=<?php echo $store_id; ?>&date='+this.value">
      <a href="?<?php echo mo_er_qs($store_id, $next_date); ?>"
         class="btn btn-sm btn-outline-secondary <?php echo $is_future ? 'disabled' : ''; ?>"><i class="fa-solid fa-chevron-right"></i></a>
      <?php if ($date === $today): ?><span class="badge text-bg-primary">Today</span><?php endif; ?>
      <?php if ($state && !empty($state['saved_at'])): ?>
      <span class="text-muted small">Saved: <?php echo htmlspecialchars($state['saved_at']); ?></span>
      <?php else: ?>
      <span class="text-muted small">No saved data</span>
      <?php endif; ?>
      <a href="<?php echo htmlspecialchars($export_url); ?>" class="btn btn-sm btn-success ms-auto">
        <i class="fa-solid fa-file-excel me-1"></i>Download Excel
      </a>
      <a href="<?php echo htmlspecialchars($print_url); ?>" target="_blank" class="btn btn-sm btn-outline-dark">
        <i class="fa-solid fa-up-right-from-square me-1"></i>Open in New Tab
      </a>
    </div>

    <div class="bg-white rounded-3 shadow-sm border overflow-hidden">
      <iframe src="<?php echo htmlspecialchars($print_url); ?>" style="width:100%;height:1400px;border:none;display:block;"></iframe>
    </div>

  </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
