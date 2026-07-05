<?php
$page_title      = 'Equipment Purchase';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id = get_office_store_id();
$_has_view = isset($_GET['view']);
$_has_ym   = isset($_GET['year']) || isset($_GET['month']);
$view = $_has_view
    ? (in_array($_GET['view'], ['day','month']) ? $_GET['view'] : 'day')
    : ($_has_ym ? 'month' : 'day');
$today    = date('Y-m-d');

if ($view === 'day') {
    $date  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : $today;
    $ts    = strtotime($date);
    $prev  = date('Y-m-d', strtotime('-1 day', $ts));
    $next  = date('Y-m-d', strtotime('+1 day', $ts));
    $label = ($date === $today) ? 'Today · ' . date('M j, Y', $ts) : date('M j, Y', $ts);

    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "SELECT ep.*, r.id AS receipt_id, r.file_path AS r_file_path, r.file_original_name AS r_file_name
         FROM office_equipment_purchases ep
         LEFT JOIN office_receipts r ON r.linked_purchase_type='equipment' AND r.linked_purchase_id=ep.id
         WHERE ep.store_id=? AND ep.payment_date=? ORDER BY ep.id DESC"
    );
    $stmt->bind_param('is', $store_id, $date);
} else {
    $year  = (int)($_GET['year']  ?? date('Y'));
    $month = (int)($_GET['month'] ?? date('n'));
    $prev_ts   = mktime(0,0,0,$month-1,1,$year);
    $next_ts   = mktime(0,0,0,$month+1,1,$year);
    $prev_y    = (int)date('Y',$prev_ts); $prev_m = (int)date('n',$prev_ts);
    $next_y    = (int)date('Y',$next_ts); $next_m = (int)date('n',$next_ts);
    $label     = "{$year}-{$month}";

    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "SELECT ep.*, r.id AS receipt_id, r.file_path AS r_file_path, r.file_original_name AS r_file_name
         FROM office_equipment_purchases ep
         LEFT JOIN office_receipts r ON r.linked_purchase_type='equipment' AND r.linked_purchase_id=ep.id
         WHERE ep.store_id=? AND YEAR(ep.payment_date)=? AND MONTH(ep.payment_date)=?
         ORDER BY ep.payment_date DESC, ep.id DESC"
    );
    $stmt->bind_param('iii', $store_id, $year, $month);
}

$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

$total = array_sum(array_column($rows, 'amount'));

function nav_url_equip(string $view, string $date='', int $y=0, int $m=0): string {
    if ($view === 'day') return "list.php?view=day&date={$date}";
    return "list.php?view=month&year={$y}&month={$m}";
}
?>

<div class="flex items-center justify-between mb-4">
  <h2 class="text-xl font-bold text-gray-800">
    <i class="fa-solid fa-box mr-2 text-orange-600"></i>Equipment Purchase
  </h2>
  <div class="flex gap-2">
    <?php if ($view === 'day'): ?>
    <a href="../exports/export_expenses.php?date=<?php echo $date; ?>"
       target="_blank"
       class="bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-lg text-sm font-medium">
      <i class="fa-solid fa-file-excel mr-1"></i>Save Excel
    </a>
    <a href="../exports/print_expenses.php?date=<?php echo $date; ?>"
       target="_blank"
       class="bg-gray-600 hover:bg-gray-700 text-white px-3 py-2 rounded-lg text-sm font-medium">
      <i class="fa-solid fa-print mr-1"></i>Print Preview
    </a>
    <?php endif; ?>
    <a href="add.php?date=<?php echo $view==='day' ? $date : $today; ?>" class="bg-orange-600 hover:bg-orange-700 text-white px-3 py-2 rounded-lg text-sm font-medium">
      <i class="fa-solid fa-plus mr-1"></i>Add
    </a>
  </div>
</div>

<!-- Date Navigation -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-3 mb-4">
  <div class="flex items-center gap-2 flex-wrap">

    <!-- View Toggle -->
    <div class="flex rounded-lg border border-gray-200 overflow-hidden text-sm">
      <a href="list.php?view=day&date=<?php echo $today; ?>"
         class="px-3 py-1.5 <?php echo $view==='day' ? 'bg-orange-600 text-white font-medium' : 'bg-white text-gray-600 hover:bg-gray-50'; ?>">
        <i class="fa-solid fa-calendar-day mr-1"></i>Daily
      </a>
      <a href="list.php?view=month&year=<?php echo $view==='month' ? $year : date('Y'); ?>&month=<?php echo $view==='month' ? $month : date('n'); ?>"
         class="px-3 py-1.5 <?php echo $view==='month' ? 'bg-orange-600 text-white font-medium' : 'bg-white text-gray-600 hover:bg-gray-50'; ?>">
        <i class="fa-solid fa-calendar-days mr-1"></i>Monthly
      </a>
    </div>

    <!-- Prev/Today/Next -->
    <div class="flex items-center gap-1">
      <?php if ($view === 'day'): ?>
        <a href="<?php echo nav_url_equip('day', $prev); ?>"
           class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-gray-600 hover:bg-gray-50">
          <i class="fa-solid fa-chevron-left"></i>
        </a>
        <span class="px-4 py-1.5 rounded-lg bg-gray-50 border border-gray-200 text-sm font-medium text-gray-800 min-w-40 text-center">
          <?php echo htmlspecialchars($label); ?>
        </span>
        <a href="<?php echo nav_url_equip('day', $next); ?>"
           class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-gray-600 hover:bg-gray-50 <?php echo $next > $today ? 'opacity-40 pointer-events-none' : ''; ?>">
          <i class="fa-solid fa-chevron-right"></i>
        </a>
        <?php if ($date !== $today): ?>
        <a href="<?php echo nav_url_equip('day', $today); ?>"
           class="px-3 py-1.5 rounded-lg bg-orange-50 border border-orange-200 text-sm text-orange-700 hover:bg-orange-100 font-medium">
          Today
        </a>
        <?php endif; ?>
        <input type="date" max="<?php echo $today; ?>" value="<?php echo $date; ?>"
               onchange="if(this.value) location.href='list.php?view=day&date='+this.value"
               class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-gray-600 cursor-pointer">
      <?php else: ?>
        <a href="<?php echo nav_url_equip('month', '', $prev_y, $prev_m); ?>"
           class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-gray-600 hover:bg-gray-50">
          <i class="fa-solid fa-chevron-left"></i>
        </a>
        <span class="px-4 py-1.5 rounded-lg bg-gray-50 border border-gray-200 text-sm font-medium text-gray-800 min-w-28 text-center">
          <?php echo htmlspecialchars($label); ?>
        </span>
        <a href="<?php echo nav_url_equip('month', '', $next_y, $next_m); ?>"
           class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-gray-600 hover:bg-gray-50 <?php echo ($next_y > date('Y') || ($next_y == date('Y') && $next_m > date('n'))) ? 'opacity-40 pointer-events-none' : ''; ?>">
          <i class="fa-solid fa-chevron-right"></i>
        </a>
        <?php if ($year != date('Y') || $month != date('n')): ?>
        <a href="<?php echo nav_url_equip('month', '', (int)date('Y'), (int)date('n')); ?>"
           class="px-3 py-1.5 rounded-lg bg-orange-50 border border-orange-200 text-sm text-orange-700 hover:bg-orange-100 font-medium">
          This Month
        </a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Total -->
<div class="grid grid-cols-1 gap-3 mb-4 max-w-xs">
  <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-100">
    <div class="text-xs text-gray-500 mb-1"><?php echo $view==='day' ? "Today's" : 'Monthly'; ?> Total</div>
    <div class="text-lg font-bold text-orange-700"><?php echo format_amount($total); ?></div>
  </div>
</div>

<!-- List -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
  <table class="w-full text-sm">
    <thead class="bg-gray-50 border-b border-gray-200">
      <tr>
        <th class="px-4 py-3 text-left text-gray-600">No</th>
        <th class="px-4 py-3 text-left text-gray-600">Supplier</th>
        <th class="px-4 py-3 text-left text-gray-600">Description</th>
        <th class="px-4 py-3 text-right text-gray-600">Amount</th>
        <th class="px-4 py-3 text-center text-gray-600">Payment Date</th>
        <th class="px-4 py-3 text-center text-gray-600">Receipt</th>
        <th class="px-4 py-3 text-center text-gray-600">Actions</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (empty($rows)): ?>
      <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">
        <i class="fa-solid fa-inbox text-2xl mb-2 block"></i>
        <?php echo $view==='day' ? 'No records for today.' : 'No records for this period.'; ?>
      </td></tr>
      <?php else: ?>
      <?php foreach ($rows as $i => $r): ?>
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-3 text-gray-500"><?php echo $i + 1; ?></td>
        <td class="px-4 py-3 font-medium text-gray-800"><?php echo htmlspecialchars($r['supplier_name']); ?></td>
        <td class="px-4 py-3 text-gray-600 max-w-xs truncate"><?php echo htmlspecialchars($r['delivery_content']); ?></td>
        <td class="px-4 py-3 text-right font-mono text-gray-800"><?php echo format_amount((float)$r['amount']); ?></td>
        <td class="px-4 py-3 text-center text-gray-600"><?php echo htmlspecialchars($r['payment_date']); ?></td>
        <td class="px-4 py-3 text-center">
          <?php if ($r['r_file_path'] && $r['receipt_id']): ?>
          <a href="../receipts/view.php?id=<?php echo (int)$r['receipt_id']; ?>" target="_blank"
             title="<?php echo htmlspecialchars($r['r_file_name'] ?? 'Attachment'); ?>"
             class="inline-flex items-center gap-1 px-2 py-1 rounded bg-indigo-50 text-indigo-600 hover:bg-indigo-100 text-xs">
            <i class="fa-solid fa-paperclip"></i>View
          </a>
          <?php else: ?>
          <span class="text-gray-300 text-xs">—</span>
          <?php endif; ?>
        </td>
        <td class="px-4 py-3 text-center">
          <div class="flex gap-2 justify-center">
            <a href="edit.php?id=<?php echo $r['id']; ?>" class="text-blue-600 hover:underline text-xs">Edit</a>
            <form method="POST" action="delete.php" onsubmit="return confirm('Delete this record?')">
              <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
              <input type="hidden" name="year" value="<?php echo date('Y', strtotime($r['payment_date'])); ?>">
              <input type="hidden" name="month" value="<?php echo date('n', strtotime($r['payment_date'])); ?>">
              <?php if ($view === 'day'): ?>
              <input type="hidden" name="date" value="<?php echo $date; ?>">
              <?php endif; ?>
              <button type="submit" class="text-red-500 hover:underline text-xs">Delete</button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
