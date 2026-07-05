<?php
$page_title      = 'Store Transfers';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id  = get_office_store_id();
$today     = date('Y-m-d');
$year      = (int)($_GET['year']  ?? date('Y'));
$month     = (int)($_GET['month'] ?? date('n'));
$sales_tab = 'transfer';
$days_in_month = (int)date('t', mktime(0,0,0,$month,1,$year));
$month_label   = date('F Y', mktime(0,0,0,$month,1,$year));
$days_en       = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
$categories    = ['grocery'=>'Grocery','meat'=>'Meat','seafood'=>'Seafood','fruit'=>'Fruit'];

$conn = get_db_connection();

// category 컬럼 없으면 자동 추가
$chk_cat = $conn->query("SHOW COLUMNS FROM sales_transfers LIKE 'category'");
if ($chk_cat && $chk_cat->num_rows === 0) {
    $conn->query("ALTER TABLE sales_transfers
                  ADD COLUMN category VARCHAR(20) NOT NULL DEFAULT 'grocery' AFTER notes");
}

// 지점 목록 — 해당 월 거래 있는 점포만 (없으면 전체 표시)
$all_branches = $conn->query("SELECT id, name FROM stores ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);

// ── IN: 현재 점포가 받은 이동 (sales_transfers, direction='in') ───────────
$stmt = $conn->prepare(
    "SELECT id, transfer_date, other_store_id, amount, category, notes, file_path, file_mime
     FROM sales_transfers
     WHERE store_id=? AND direction='in'
       AND YEAR(transfer_date)=? AND MONTH(transfer_date)=?
     ORDER BY transfer_date, id"
);
$stmt->bind_param('iii', $store_id, $year, $month);
$stmt->execute();
$in_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── OUT: 현재 점포가 보낸 이동 (store_transfers, auto) ───────────────────
$stmt2 = $conn->prepare(
    "SELECT st.id, st.transfer_date, st.to_store_id AS other_store_id,
            st.final_amount AS amount, st.notes
     FROM store_transfers st
     WHERE st.from_store_id=? AND st.status != 'cancelled'
       AND YEAR(st.transfer_date)=? AND MONTH(st.transfer_date)=?
     ORDER BY st.transfer_date, st.id"
);
$stmt2->bind_param('iii', $store_id, $year, $month);
$stmt2->execute();
$out_rows = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt2->close();
$conn->close();

// 카테고리별 전체 합계 (모든 지점 합산)
$cat_totals = ['grocery'=>0,'meat'=>0,'seafood'=>0,'fruit'=>0];
foreach ($in_rows as $r) {
    $cat = in_array($r['category']??'', array_keys($cat_totals)) ? $r['category'] : 'grocery';
    $cat_totals[$cat] += (float)$r['amount'];
}

// 격자용 집계
// $in_grid[$day][$branch_id][$cat]  = [{id,amount,notes,...},...]
// $out_grid[$day][$branch_id]       = [{id,amount,notes,...},...]
$in_grid  = [];
$out_grid = [];

foreach ($in_rows as $r) {
    $d   = (int)date('j', strtotime($r['transfer_date']));
    $bid = (int)$r['other_store_id'];
    $cat = in_array($r['category'], array_keys($categories)) ? $r['category'] : 'grocery';
    $in_grid[$d][$bid][$cat][] = $r;
}
foreach ($out_rows as $r) {
    $d   = (int)date('j', strtotime($r['transfer_date']));
    $bid = (int)$r['other_store_id'];
    $out_grid[$d][$bid][] = $r;
}

// 거래 있는 지점 ID만 추출 → 해당 지점만 표시
$active_branch_ids = array_unique(array_merge(
    array_column($in_rows,  'other_store_id'),
    array_column($out_rows, 'other_store_id')
));
$active_branch_ids = array_map('intval', $active_branch_ids);
$branches = array_filter($all_branches, fn($b) => in_array((int)$b['id'], $active_branch_ids));
$branches = array_values($branches); // 인덱스 재정렬

// 합계
$in_total  = array_sum(array_column($in_rows,  'amount'));
$out_total = array_sum(array_column($out_rows, 'amount'));

// 월 선택
$month_options = [];
for ($i = 0; $i < 12; $i++) {
    $ts = mktime(0,0,0,date('n')-$i,1,date('Y'));
    $month_options[] = ['y'=>(int)date('Y',$ts),'m'=>(int)date('n',$ts),'label'=>date('M Y',$ts)];
}
?>
<?php require __DIR__ . '/partials/sales_nav.php'; ?>

<style>
.tg { border-collapse:collapse; font-size:11px; width:100%; }
.tg th, .tg td { border:1px solid #e5e7eb; padding:3px 5px; white-space:nowrap; }
.tg thead th { background:#f9fafb; font-weight:600; text-align:center; color:#374151;
               position:sticky; top:0; z-index:2; }
.tg .branch-hd  { background:#eff6ff; color:#1d4ed8; }
.tg .in-hd      { background:#f0fdf4; color:#15803d; font-size:10px; min-width:58px; }
.tg .out-hd     { background:#fff1f2; color:#b91c1c; font-size:10px; min-width:58px; }
.tg .day-cell   { background:#f9fafb; font-weight:600; text-align:center; min-width:52px; }
.tg .day-cell.today { background:#fef9c3; color:#92400e; }
.tg .total-row td { background:#f0fdf4; font-weight:700; }
.tg .row-total  { text-align:right; font-weight:700; color:#059669; background:#f0fdf4; }
.tg .col-total  { text-align:right; font-weight:700; color:#059669; }
.cell-in  { cursor:pointer; color:#15803d; border-radius:3px; padding:1px 3px;
            display:inline-block; transition:background .12s; }
.cell-in:hover  { background:#dcfce7; }
.cell-out { cursor:pointer; color:#b91c1c; border-radius:3px; padding:1px 3px;
            display:inline-block; transition:background .12s; }
.cell-out:hover { background:#fee2e2; }
.tg tbody tr:hover td { background:#fef9c3 !important; }
</style>

<!-- Add Transfer Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header border-b border-gray-100 px-4 py-3">
        <h5 class="modal-title text-sm font-semibold text-gray-800">
          <i class="fa-solid fa-arrow-down mr-1 text-green-600"></i>Add — IN (Received from Store)
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 py-4 space-y-3">
        <div id="add_errors" class="hidden bg-red-50 border border-red-200 text-red-700 rounded-lg p-3 text-sm"></div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Date <span class="text-red-500">*</span></label>
            <input type="date" id="t_date" max="<?php echo $today; ?>" value="<?php echo $today; ?>"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Amount <span class="text-red-500">*</span></label>
            <input type="number" id="t_amount" step="0.01" min="0.01" placeholder="0.00"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm text-right">
          </div>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">From Store <span class="text-red-500">*</span></label>
            <select id="t_store_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
              <option value="">— Select store —</option>
              <?php foreach ($all_branches as $s): ?>
              <option value="<?php echo $s['id']; ?>"
                      data-name="<?php echo htmlspecialchars($s['name']); ?>"
                      <?php echo stripos($s['name'], 'KIMS MALL') !== false ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($s['name']); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Category <span class="text-red-500">*</span></label>
            <select id="t_category" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
              <option value="grocery">Grocery (그로서리)</option>
              <option value="meat">Meat (미트)</option>
              <option value="seafood">Seafood (씨푸드)</option>
              <option value="fruit">Fruit (과일)</option>
            </select>
          </div>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Notes</label>
          <input type="text" id="t_notes" placeholder="Optional"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">
            Proof Document <span class="text-gray-400 font-normal">(JPG, PNG, PDF · max 10MB)</span>
          </label>
          <div id="file_drop_zone"
               class="border-2 border-dashed border-gray-300 rounded-lg p-4 text-center cursor-pointer hover:border-teal-400"
               onclick="document.getElementById('t_file').click()"
               ondragover="event.preventDefault();this.classList.add('border-teal-500')"
               ondragleave="this.classList.remove('border-teal-500')"
               ondrop="handleDrop(event)">
            <i class="fa-solid fa-cloud-arrow-up text-2xl text-gray-300 mb-1 block"></i>
            <p class="text-xs text-gray-400">Click or drag &amp; drop</p>
            <p id="file_name" class="text-xs text-teal-600 font-medium mt-1 hidden"></p>
          </div>
          <input type="file" id="t_file" accept=".jpg,.jpeg,.png,.pdf" class="hidden" onchange="showFileName(this)">
        </div>
      </div>
      <div class="modal-footer px-4 py-3 flex gap-2 justify-end">
        <button class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm" data-bs-dismiss="modal">Cancel</button>
        <button onclick="saveTransfer()"
                class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium">
          <i class="fa-solid fa-floppy-disk mr-1"></i>Save
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header border-b border-gray-100 px-4 py-3">
        <h5 class="modal-title text-sm font-semibold text-gray-800">
          <i class="fa-solid fa-pen-to-square mr-1 text-indigo-500"></i>Edit Transfer
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 py-4 space-y-3">
        <input type="hidden" id="edit_id">
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Date</label>
            <input type="date" id="edit_date"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Amount</label>
            <input type="number" id="edit_amount" step="0.01" min="0.01"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm text-right">
          </div>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Category</label>
          <select id="edit_category" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            <option value="grocery">Grocery (그로서리)</option>
            <option value="meat">Meat (미트)</option>
            <option value="seafood">Seafood (씨푸드)</option>
            <option value="fruit">Fruit (과일)</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Notes</label>
          <input type="text" id="edit_notes" placeholder="Optional"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div id="edit_error" class="hidden text-xs text-red-600"></div>
      </div>
      <div class="modal-footer px-4 py-3 flex gap-2 justify-end">
        <button class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm" data-bs-dismiss="modal">Cancel</button>
        <button onclick="submitEdit()"
                class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium">
          <i class="fa-solid fa-floppy-disk mr-1"></i>Save Changes
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Detail Modal -->
<div class="modal fade" id="detailModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header border-b border-gray-100 px-4 py-3">
        <h5 id="detail_title" class="modal-title text-sm font-semibold text-gray-800"></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 py-3" id="detail_body"></div>
    </div>
  </div>
</div>

<!-- 헤더 -->
<div class="flex items-center justify-between mb-4">
  <h2 class="text-lg font-bold text-gray-800">
    <i class="fa-solid fa-arrow-right-arrow-left mr-2 text-teal-600"></i>Store Transfers — <?php echo $month_label; ?>
  </h2>
  <button onclick="new bootstrap.Modal(document.getElementById('addModal')).show()"
          class="bg-teal-600 hover:bg-teal-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
    <i class="fa-solid fa-plus mr-1"></i>Add IN
  </button>
</div>

<!-- 월 선택 -->
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
  <button type="submit" class="px-3 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm">Search</button>
  <div class="ml-auto flex gap-4 text-sm">
    <span class="text-green-600 font-semibold">IN: ₱<?php echo number_format($in_total,2); ?></span>
    <span class="text-red-600 font-semibold">OUT: ₱<?php echo number_format($out_total,2); ?></span>
    <span class="text-gray-700 font-bold">Net: ₱<?php echo number_format($in_total - $out_total,2); ?></span>
  </div>
</form>

<!-- 격자 -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
<table class="tg">
  <thead>
    <!-- 지점 헤더 -->
    <tr>
      <th rowspan="2" class="day-cell">Date</th>
      <?php foreach ($branches as $b): ?>
      <th colspan="6" class="branch-hd"><?php echo htmlspecialchars($b['name']); ?></th>
      <?php endforeach; ?>
      <th rowspan="2" class="row-total" style="background:#f0fdf4;min-width:70px">Total</th>
    </tr>
    <!-- 카테고리 + OUT 헤더 -->
    <tr>
      <?php foreach ($branches as $b): ?>
      <th class="in-hd">Grocery</th>
      <th class="in-hd">Meat</th>
      <th class="in-hd">Seafood</th>
      <th class="in-hd">Fruit</th>
      <th class="in-hd" style="background:#e0f2fe;color:#0369a1;font-weight:700">Total IN</th>
      <th class="out-hd"><i class="fa-solid fa-arrow-up text-red-400 mr-1"></i>OUT</th>
      <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
  <?php
  $col_in_totals  = array_fill_keys(array_column($branches,'id'), 0);
  $col_out_totals = array_fill_keys(array_column($branches,'id'), 0);

  for ($d=1; $d<=$days_in_month; $d++):
    $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
    $dow      = $days_en[date('w', strtotime($date_str))];
    $is_today = ($date_str === $today);
    $is_sun   = ($dow === 'Sun');

    $row_total = 0;
    foreach ($branches as $b) {
        $bid = $b['id'];
        foreach ($in_grid[$d][$bid] ?? [] as $cat => $entries)
            foreach ($entries as $e) { $row_total += (float)$e['amount']; $col_in_totals[$bid] += (float)$e['amount']; }
        foreach ($out_grid[$d][$bid] ?? [] as $e) { $row_total += (float)$e['amount']; $col_out_totals[$bid] += (float)$e['amount']; }
    }
  ?>
  <tr<?php echo $is_sun ? ' style="background:#fff7f7"' : ''; ?>>
    <td class="day-cell <?php echo $is_today?'today':''; ?>">
      <?php echo $d; ?> <span style="color:#9ca3af;font-size:9px"><?php echo $dow; ?></span>
    </td>

    <?php foreach ($branches as $b):
      $bid = $b['id'];

      // 카테고리별 IN
      foreach ($categories as $catKey => $catLabel):
          $catEntries = $in_grid[$d][$bid][$catKey] ?? [];
          $catSum     = array_sum(array_column($catEntries, 'amount'));
          $catCnt     = count($catEntries);
    ?>
    <td style="text-align:right;padding:2px 4px">
      <?php if ($catSum > 0): ?>
      <span class="cell-in"
            onclick="showInDetailCat(<?php echo $d; ?>,<?php echo $bid; ?>,'<?php echo $catKey; ?>','<?php echo htmlspecialchars($b['name'],ENT_QUOTES); ?>','<?php echo $catLabel; ?>')">
        ₱<?php echo number_format($catSum,2); ?>
        <?php if ($catCnt > 1): ?><span style="font-size:9px;color:#94a3b8">(<?php echo $catCnt; ?>)</span><?php endif; ?>
      </span>
      <?php endif; ?>
    </td>
    <?php endforeach;

      // Total IN for this branch on this day
      $branch_in_sum = 0;
      foreach ($categories as $ck => $_) {
          foreach ($in_grid[$d][$bid][$ck] ?? [] as $e) $branch_in_sum += (float)$e['amount'];
      }
    ?>
    <td style="text-align:right;padding:2px 4px;background:#f0f9ff">
      <?php if ($branch_in_sum > 0): ?>
      <span style="color:#0369a1;font-weight:700">₱<?php echo number_format($branch_in_sum,2); ?></span>
      <?php endif; ?>
    </td>
    <?php
      // OUT
      $out_entries = $out_grid[$d][$bid] ?? [];
      $out_sum     = array_sum(array_column($out_entries, 'amount'));
    ?>
    <td style="text-align:right;padding:2px 4px">
      <?php if ($out_sum > 0): ?>
      <span class="cell-out"
            onclick="showOutDetail(<?php echo $d; ?>,<?php echo $bid; ?>,'<?php echo htmlspecialchars($b['name'],ENT_QUOTES); ?>')">
        ₱<?php echo number_format($out_sum,2); ?>
        <?php if (count($out_entries) > 1): ?><span style="font-size:9px;color:#94a3b8">(<?php echo count($out_entries); ?>)</span><?php endif; ?>
      </span>
      <?php endif; ?>
    </td>
    <?php endforeach; ?>

    <td class="row-total"><?php echo $row_total > 0 ? '₱'.number_format($row_total,2) : ''; ?></td>
  </tr>
  <?php endfor; ?>

  <!-- 합계 행 -->
  <tr class="total-row">
    <td class="day-cell" style="background:#f0fdf4">Total</td>
    <?php foreach ($branches as $b):
      $bid = $b['id'];
      foreach ($categories as $catKey => $catLabel):
          $catTot = 0;
          foreach ($in_rows as $r) {
              if ((int)$r['other_store_id']===(int)$bid && ($r['category']??'grocery')===$catKey)
                  $catTot += (float)$r['amount'];
          }
    ?>
    <td class="col-total" style="color:#15803d">
      <?php echo $catTot > 0 ? '₱'.number_format($catTot,2) : ''; ?>
    </td>
    <?php endforeach;
      $branch_in_total = 0;
      foreach ($in_rows as $r) {
          if ((int)$r['other_store_id'] === (int)$bid) $branch_in_total += (float)$r['amount'];
      }
    ?>
    <td class="col-total" style="background:#e0f2fe;color:#0369a1;font-weight:700">
      <?php echo $branch_in_total > 0 ? '₱'.number_format($branch_in_total,2) : ''; ?>
    </td>
    <td class="col-total" style="color:#b91c1c">
      <?php echo $col_out_totals[$bid] > 0 ? '₱'.number_format($col_out_totals[$bid],2) : ''; ?>
    </td>
    <?php endforeach; ?>
    <td class="row-total">₱<?php echo number_format($in_total+$out_total,2); ?></td>
  </tr>
  </tbody>
</table>
</div>

<!-- 카테고리 합계 -->
<div class="mt-4 bg-white rounded-xl shadow-sm border border-gray-100 p-4">
  <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">
    Category Total (IN) — <?php echo $month_label; ?>
  </h3>
  <div class="flex flex-wrap gap-3">
    <?php
    $cat_colors = [
        'grocery' => ['bg'=>'bg-green-50',  'border'=>'border-green-200',  'text'=>'text-green-700',  'icon'=>'fa-leaf'],
        'meat'    => ['bg'=>'bg-red-50',    'border'=>'border-red-200',    'text'=>'text-red-700',    'icon'=>'fa-drumstick-bite'],
        'seafood' => ['bg'=>'bg-blue-50',   'border'=>'border-blue-200',   'text'=>'text-blue-700',   'icon'=>'fa-fish'],
        'fruit'   => ['bg'=>'bg-orange-50', 'border'=>'border-orange-200', 'text'=>'text-orange-700', 'icon'=>'fa-apple-whole'],
    ];
    foreach ($categories as $catKey => $catLabel):
        $c = $cat_colors[$catKey];
        $v = $cat_totals[$catKey];
    ?>
    <div class="flex items-center gap-3 px-4 py-3 rounded-xl border <?php echo $c['bg'].' '.$c['border']; ?> min-w-40">
      <i class="fa-solid <?php echo $c['icon']; ?> <?php echo $c['text']; ?> text-lg"></i>
      <div>
        <p class="text-xs text-gray-500"><?php echo $catLabel; ?></p>
        <p class="text-sm font-bold <?php echo $c['text']; ?>">
          <?php echo $v > 0 ? '₱'.number_format($v,2) : '₱0.00'; ?>
        </p>
      </div>
    </div>
    <?php endforeach; ?>
    <div class="flex items-center gap-3 px-4 py-3 rounded-xl border bg-red-50 border-red-200 min-w-40">
      <i class="fa-solid fa-arrow-up text-red-500 text-lg"></i>
      <div>
        <p class="text-xs text-gray-500">OUT (Auto)</p>
        <p class="text-sm font-bold text-red-700">₱<?php echo number_format($out_total,2); ?></p>
      </div>
    </div>
    <div class="flex items-center gap-3 px-4 py-3 rounded-xl border bg-teal-50 border-teal-200 min-w-40 ml-auto">
      <i class="fa-solid fa-calculator text-teal-600 text-lg"></i>
      <div>
        <p class="text-xs text-gray-500">Grand Total</p>
        <p class="text-sm font-bold text-teal-700">₱<?php echo number_format($in_total+$out_total,2); ?></p>
      </div>
    </div>
  </div>
</div>

<!-- 브랜치별 월간 합계 -->
<?php
$branch_monthly = [];
foreach ($branches as $b) {
    $bid = (int)$b['id'];
    $row = ['name'=>$b['name'], 'grocery'=>0, 'meat'=>0, 'seafood'=>0, 'fruit'=>0, 'in_total'=>0, 'out_total'=>0];
    foreach ($in_rows as $r) {
        if ((int)$r['other_store_id'] === $bid) {
            $cat = in_array($r['category']??'', array_keys($categories)) ? $r['category'] : 'grocery';
            $row[$cat] += (float)$r['amount'];
            $row['in_total'] += (float)$r['amount'];
        }
    }
    foreach ($out_rows as $r) {
        if ((int)$r['other_store_id'] === $bid) $row['out_total'] += (float)$r['amount'];
    }
    $branch_monthly[] = $row;
}
?>
<div class="mt-4 bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
  <div class="px-4 py-3 border-b border-gray-100">
    <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">
      Monthly Total by Branch — <?php echo $month_label; ?>
    </h3>
  </div>
  <table class="tg">
    <thead>
      <tr>
        <th class="branch-hd" style="min-width:120px">Branch</th>
        <th class="in-hd">Grocery</th>
        <th class="in-hd">Meat</th>
        <th class="in-hd">Seafood</th>
        <th class="in-hd">Fruit</th>
        <th class="in-hd" style="background:#e0f2fe;color:#0369a1;font-weight:700">Total IN</th>
        <th class="out-hd"><i class="fa-solid fa-arrow-up text-red-400 mr-1"></i>OUT</th>
        <th class="row-total" style="background:#f0fdf4;min-width:80px">Net</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($branch_monthly as $brow): ?>
    <tr>
      <td class="branch-hd" style="font-weight:600"><?php echo htmlspecialchars($brow['name']); ?></td>
      <td class="col-total" style="color:#15803d"><?php echo $brow['grocery']>0 ? '₱'.number_format($brow['grocery'],2) : ''; ?></td>
      <td class="col-total" style="color:#15803d"><?php echo $brow['meat']>0    ? '₱'.number_format($brow['meat'],2)    : ''; ?></td>
      <td class="col-total" style="color:#15803d"><?php echo $brow['seafood']>0 ? '₱'.number_format($brow['seafood'],2) : ''; ?></td>
      <td class="col-total" style="color:#15803d"><?php echo $brow['fruit']>0   ? '₱'.number_format($brow['fruit'],2)   : ''; ?></td>
      <td class="col-total" style="background:#e0f2fe;color:#0369a1;font-weight:700"><?php echo $brow['in_total']>0 ? '₱'.number_format($brow['in_total'],2) : ''; ?></td>
      <td class="col-total" style="color:#b91c1c"><?php echo $brow['out_total']>0 ? '₱'.number_format($brow['out_total'],2) : ''; ?></td>
      <td class="row-total"><?php echo ($brow['in_total']+$brow['out_total'])>0 ? '₱'.number_format($brow['in_total']+$brow['out_total'],2) : ''; ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
    <tr class="total-row">
      <td class="day-cell" style="background:#f0fdf4">Total</td>
      <?php foreach (array_keys($categories) as $ck): ?>
      <td class="col-total" style="color:#15803d">₱<?php echo number_format($cat_totals[$ck],2); ?></td>
      <?php endforeach; ?>
      <td class="col-total" style="background:#e0f2fe;color:#0369a1;font-weight:700">₱<?php echo number_format($in_total,2); ?></td>
      <td class="col-total" style="color:#b91c1c">₱<?php echo number_format($out_total,2); ?></td>
      <td class="row-total">₱<?php echo number_format($in_total+$out_total,2); ?></td>
    </tr>
    </tfoot>
  </table>
</div>

<script>
const YEAR    = <?php echo $year; ?>;
const MONTH   = <?php echo $month; ?>;
const IN_ROWS = <?php echo json_encode($in_rows, JSON_UNESCAPED_UNICODE); ?>;
const OUT_ROWS= <?php echo json_encode($out_rows, JSON_UNESCAPED_UNICODE); ?>;
const CAT_LABELS = {grocery:'Grocery',meat:'Meat',seafood:'Seafood',fruit:'Fruit'};
const esc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

function syncYear(){
    const s=document.getElementById('month_sel');
    document.getElementById('year_hidden').value=s.options[s.selectedIndex].dataset.year;
    s.form.submit();
}
function fmt(n){ return '₱'+parseFloat(n||0).toLocaleString('en',{minimumFractionDigits:2}); }

// ── IN 카테고리별 상세 팝업 ───────────────────────────────────
function showInDetailCat(day, branchId, catKey, branchName, catLabel) {
    const dateStr = YEAR+'-'+String(MONTH).padStart(2,'0')+'-'+String(day).padStart(2,'0');
    const entries = IN_ROWS.filter(r =>
        r.other_store_id==branchId &&
        r.transfer_date===dateStr &&
        (r.category||'grocery')===catKey
    );
    document.getElementById('detail_title').innerHTML =
        `<span class="text-green-700"><i class="fa-solid fa-arrow-down mr-1"></i>IN</span> — ${dateStr} · ${branchName} · ${catLabel}`;

    let html = '<table class="w-full text-xs border-collapse">'
             + '<thead><tr class="bg-gray-50">'
             + '<th class="border border-gray-200 px-3 py-2 text-right w-28">Amount</th>'
             + '<th class="border border-gray-200 px-3 py-2 text-left">Notes</th>'
             + '<th class="border border-gray-200 px-3 py-2 text-center w-16">Proof</th>'
             + '<th class="border border-gray-200 px-2 py-2 w-8"></th>'
             + '</tr></thead><tbody>';

    let total = 0;
    entries.forEach(r => {
        total += parseFloat(r.amount||0);
        const proof = r.file_path
            ? `<button type="button"
                  data-id="${r.id}" data-mime="${esc(r.file_mime||'')}" data-title="${esc(r.notes||'Transfer Proof')}"
                  onclick="openPreview(this.dataset.id,this.dataset.mime,this.dataset.title)"
                  class="text-teal-600 hover:text-teal-800 text-xs">
                 <i class="fa-solid fa-${r.file_mime==='application/pdf'?'file-pdf':'image'} mr-1"></i>View
               </button>`
            : '—';
        html += `<tr class="border-b border-gray-100">
            <td class="px-3 py-2 font-mono font-semibold text-right">${fmt(r.amount)}</td>
            <td class="px-3 py-2 text-gray-500">${r.notes||'—'}</td>
            <td class="px-3 py-2 text-center">${proof}</td>
            <td class="px-2 py-2 text-center" style="white-space:nowrap">
              <button onclick="openEdit(${r.id},'${r.transfer_date}',${r.amount},'${r.category||'grocery'}','${(r.notes||'').replace(/'/g,'&apos;')}')"
                      class="text-indigo-400 hover:text-indigo-600 mr-1">
                <i class="fa-solid fa-pen-to-square text-xs"></i>
              </button>
              <form method="POST" action="ajax_delete_transfer.php" onsubmit="return confirm('Delete?')" class="inline">
                <input type="hidden" name="id" value="${r.id}">
                <input type="hidden" name="year" value="${YEAR}">
                <input type="hidden" name="month" value="${MONTH}">
                <button type="submit" class="text-gray-300 hover:text-red-500"><i class="fa-solid fa-trash-can text-xs"></i></button>
              </form>
            </td></tr>`;
    });
    html += `</tbody><tfoot><tr class="bg-green-50">
        <td class="px-3 py-2 font-bold text-right text-green-700">${fmt(total)}</td>
        <td class="px-3 py-2 text-xs text-gray-400" colspan="3">${entries.length}건</td>
    </tr></tfoot></table>`;

    document.getElementById('detail_body').innerHTML = html || '<p class="text-gray-400 text-sm">No data</p>';
    new bootstrap.Modal(document.getElementById('detailModal')).show();
}

// ── OUT 상세 팝업 ─────────────────────────────────────────────
function showOutDetail(day, branchId, branchName) {
    const dateStr = YEAR+'-'+String(MONTH).padStart(2,'0')+'-'+String(day).padStart(2,'0');
    const entries = OUT_ROWS.filter(r => r.other_store_id==branchId && r.transfer_date===dateStr);
    document.getElementById('detail_title').innerHTML =
        `<span class="text-red-600"><i class="fa-solid fa-arrow-up mr-1"></i>OUT</span> — ${dateStr} · ${branchName}`;

    let html = '<table class="w-full text-xs border-collapse">'
             + '<thead><tr class="bg-gray-50"><th class="border border-gray-200 px-3 py-2 text-right">Amount</th>'
             + '<th class="border border-gray-200 px-3 py-2 text-left">Notes</th></tr></thead><tbody>';
    entries.forEach(r => {
        html += `<tr class="border-b border-gray-100">
            <td class="px-3 py-2 font-mono font-semibold text-right">${fmt(r.amount)}</td>
            <td class="px-3 py-2 text-gray-500">${r.notes||'—'}</td></tr>`;
    });
    const total = entries.reduce((s,r)=>s+parseFloat(r.amount||0),0);
    html += `</tbody><tfoot><tr class="bg-red-50">
        <td class="px-3 py-2 font-bold text-right text-red-700">${fmt(total)}</td>
        <td class="px-3 py-2 text-xs text-gray-400">${entries.length}건 (Auto)</td>
    </tr></tfoot></table>`;

    document.getElementById('detail_body').innerHTML = html;
    new bootstrap.Modal(document.getElementById('detailModal')).show();
}

// ── Edit ─────────────────────────────────────────────────────
function openEdit(id, date, amount, category, notes) {
    bootstrap.Modal.getInstance(document.getElementById('detailModal'))?.hide();
    document.getElementById('edit_id').value       = id;
    document.getElementById('edit_date').value     = date;
    document.getElementById('edit_amount').value   = parseFloat(amount).toFixed(2);
    document.getElementById('edit_category').value = category;
    document.getElementById('edit_notes').value    = notes;
    document.getElementById('edit_error').classList.add('hidden');
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
async function submitEdit() {
    const id  = document.getElementById('edit_id').value;
    const amt = document.getElementById('edit_amount').value;
    const err = document.getElementById('edit_error');
    err.classList.add('hidden');

    if (!amt || parseFloat(amt) <= 0) {
        err.textContent = 'Amount must be greater than 0.';
        err.classList.remove('hidden'); return;
    }

    const fd = new FormData();
    fd.append('id',            id);
    fd.append('transfer_date', document.getElementById('edit_date').value);
    fd.append('amount',        amt);
    fd.append('category',      document.getElementById('edit_category').value);
    fd.append('notes',         document.getElementById('edit_notes').value);

    const res  = await fetch('ajax_edit_transfer.php', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) {
        location.href = 'transfer.php?year='+YEAR+'&month='+MONTH;
    } else {
        err.textContent = data.error || 'Save failed.';
        err.classList.remove('hidden');
    }
}

// ── 첨부파일 미리보기 ─────────────────────────────────────────
let previewBlobUrl = null;
async function openPreview(transferId, mime, title) {
    const modal  = document.getElementById('previewModal');
    const body   = document.getElementById('previewBody');
    const dlLink = document.getElementById('previewDownload');
    const viewUrl = 'view_transfer_file.php?id=' + transferId;

    document.getElementById('previewTitle').textContent = title || '첨부파일';
    dlLink.href = viewUrl + '&dl=1';
    body.innerHTML = '<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:13px">로딩 중...</div>';
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';

    if (previewBlobUrl) { URL.revokeObjectURL(previewBlobUrl); previewBlobUrl = null; }

    // 파일을 blob으로 받아 blob URL로 표시 → X-Frame-Options 프레이밍 차단을 우회
    try {
        const res = await fetch(viewUrl, { credentials: 'same-origin' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const blob = await res.blob();
        previewBlobUrl = URL.createObjectURL(blob);
        const realMime = (mime && mime !== '') ? mime : (blob.type || '');

        if (realMime.startsWith('image/')) {
            const wrap = document.createElement('div');
            wrap.style.cssText = 'position:absolute;inset:0;overflow:auto;display:flex;align-items:center;justify-content:center;padding:16px;background:#e5e7eb';
            const img = document.createElement('img');
            img.src = previewBlobUrl;
            img.alt = title;
            img.style.cssText = 'max-width:100%;max-height:100%;object-fit:contain;border-radius:6px;box-shadow:0 4px 20px rgba(0,0,0,.2)';
            body.innerHTML = '';
            wrap.appendChild(img);
            body.appendChild(wrap);
        } else {
            const iframe = document.createElement('iframe');
            iframe.src   = previewBlobUrl;
            iframe.title = title;
            iframe.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;border:none;display:block';
            body.innerHTML = '';
            body.appendChild(iframe);
        }
    } catch (e) {
        body.innerHTML = '<div style="position:absolute;inset:0;display:flex;flex-direction:column;gap:8px;align-items:center;justify-content:center;color:#ef4444;font-size:13px;text-align:center;padding:20px">'
            + '<i class="fa-solid fa-triangle-exclamation" style="font-size:24px"></i>'
            + '<div>파일을 불러올 수 없습니다. (' + (e.message || 'error') + ')</div>'
            + '<a href="' + viewUrl + '&dl=1" style="color:#16a34a;text-decoration:underline">다운로드로 열기</a>'
            + '</div>';
    }
}
function closePreview() {
    document.getElementById('previewModal').style.display = 'none';
    document.getElementById('previewBody').innerHTML = '';
    document.body.style.overflow = '';
    if (previewBlobUrl) { URL.revokeObjectURL(previewBlobUrl); previewBlobUrl = null; }
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('previewModal').style.display !== 'none') closePreview();
});

// ── 저장 ─────────────────────────────────────────────────────
function showFileName(input){
    const el=document.getElementById('file_name');
    if(input.files.length){ el.textContent='📎 '+input.files[0].name; el.classList.remove('hidden'); }
}
function handleDrop(e){
    e.preventDefault(); e.currentTarget.classList.remove('border-teal-500');
    if(e.dataTransfer.files.length){ document.getElementById('t_file').files=e.dataTransfer.files; showFileName(document.getElementById('t_file')); }
}
function saveTransfer(){
    const storeEl=document.getElementById('t_store_id');
    const errDiv=document.getElementById('add_errors');
    errDiv.classList.add('hidden');

    const fd=new FormData();
    fd.append('transfer_date', document.getElementById('t_date').value);
    fd.append('other_store_id', storeEl.value);
    fd.append('other_store_name', storeEl.options[storeEl.selectedIndex]?.dataset.name||'');
    fd.append('amount', document.getElementById('t_amount').value);
    fd.append('notes',  document.getElementById('t_notes').value);
    fd.append('category', document.getElementById('t_category').value);
    const fileEl=document.getElementById('t_file');
    if(fileEl.files.length) fd.append('transfer_file',fileEl.files[0]);

    fetch('ajax_save_transfer.php',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(d=>{
        if(d.success){ location.href='transfer.php?year='+YEAR+'&month='+MONTH; }
        else{ errDiv.innerHTML=(d.errors||['Error']).map(e=>'• '+e).join('<br>'); errDiv.classList.remove('hidden'); }
    });
}
</script>

<!-- 첨부파일 미리보기 사이드 패널 -->
<div id="previewModal" style="display:none;position:fixed;inset:0;z-index:9999">
  <div style="position:absolute;inset:0;background:rgba(0,0,0,.5)" onclick="closePreview()"></div>
  <div style="position:absolute;left:0;top:0;bottom:0;width:min(720px,65vw);background:#fff;box-shadow:4px 0 30px rgba(0,0,0,.3);display:flex;flex-direction:column;overflow:hidden">
    <div style="display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid #e5e7eb;background:#f9fafb;flex-shrink:0">
      <i class="fa-solid fa-paperclip" style="color:#6366f1"></i>
      <span id="previewTitle" style="flex:1;font-size:13px;font-weight:600;color:#1f2937;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></span>
      <a id="previewDownload" href="#"
         style="display:flex;align-items:center;gap:6px;padding:6px 12px;background:#16a34a;color:#fff;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none">
        <i class="fa-solid fa-download"></i>다운로드
      </a>
      <button onclick="closePreview()"
              style="width:32px;height:32px;border:none;background:none;cursor:pointer;border-radius:6px;font-size:16px;color:#6b7280;display:flex;align-items:center;justify-content:center"
              onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='none'">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>
    <div id="previewBody" style="flex:1;position:relative;overflow:hidden;background:#e5e7eb">
      <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:13px">로딩 중...</div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
