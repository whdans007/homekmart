<?php
$page_title      = 'Deferred Payment Tracker';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id      = get_office_store_id();
$today         = date('Y-m-d');
// 반품(entry_type=return)은 음수 금액으로 저장되므로, 부호 있는 페소 표기를 한 곳에서 통일한다.
function dtr_money($n) { $n=(float)$n; return ($n<0?'-':'').'₱'.number_format(abs($n),2); }
$year          = (int)($_GET['year']  ?? date('Y'));
$month         = (int)($_GET['month'] ?? date('n'));
$days_in_month = (int)date('t', mktime(0,0,0,$month,1,$year));
$month_label   = date('F Y', mktime(0,0,0,$month,1,$year));
$days_en       = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

$prev_ts = mktime(0,0,0,$month-1,1,$year);
$next_ts = mktime(0,0,0,$month+1,1,$year);
$prev_y  = (int)date('Y',$prev_ts); $prev_m = (int)date('n',$prev_ts);
$next_y  = (int)date('Y',$next_ts); $next_m = (int)date('n',$next_ts);
$is_future = ($next_y>(int)date('Y'))||($next_y==(int)date('Y')&&$next_m>(int)date('n'));

$conn = get_db_connection();

// 테이블 자동 생성
$conn->query("CREATE TABLE IF NOT EXISTS deferred_entries (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id   INT UNSIGNED NOT NULL,
  entry_date DATE NOT NULL,
  supplier   VARCHAR(200) NOT NULL,
  amount     DECIMAL(15,2) NOT NULL DEFAULT 0,
  notes      TEXT,
  file_path  VARCHAR(500) DEFAULT NULL,
  file_mime  VARCHAR(100) DEFAULT NULL,
  status     ENUM('pending','paid') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_store_date (store_id, entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// 컬럼 자동 추가 (기존 테이블)
foreach (['file_path VARCHAR(500) DEFAULT NULL','file_mime VARCHAR(100) DEFAULT NULL',
          'paid_date DATE DEFAULT NULL','receipt_id INT UNSIGNED DEFAULT NULL',
          "entry_type ENUM('purchase','return') NOT NULL DEFAULT 'purchase'"] as $cd) {
    $col = explode(' ',$cd)[0];
    $chk = $conn->query("SHOW COLUMNS FROM deferred_entries LIKE '{$col}'");
    if ($chk && $chk->num_rows===0) $conn->query("ALTER TABLE deferred_entries ADD COLUMN {$cd}");
}

// 해당 월 데이터
$stmt = $conn->prepare(
    "SELECT id, entry_date, supplier, amount, notes, file_path, file_mime, status, entry_type
     FROM deferred_entries
     WHERE store_id=? AND YEAR(entry_date)=? AND MONTH(entry_date)=?
     ORDER BY supplier, entry_date, id"
);
$stmt->bind_param('iii', $store_id, $year, $month);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// suppliers 테이블 전체 업체명 (자동완성용)
$res_sup = $conn->query("SELECT name FROM suppliers ORDER BY name");
$db_suppliers = $res_sup ? array_column($res_sup->fetch_all(MYSQLI_ASSOC), 'name') : [];

// 전체 기간 업체별 미결 합계
$res_all = $conn->query(
    "SELECT supplier, SUM(amount) AS pending_total
     FROM deferred_entries
     WHERE store_id={$store_id} AND status='pending'
     GROUP BY supplier"
);
$all_pending = [];
if ($res_all) {
    while ($row = $res_all->fetch_assoc()) {
        $all_pending[$row['supplier']] = (float)$row['pending_total'];
    }
}

// 이전 달 미결 항목 (carry-over)
$stmt_co = $conn->prepare(
    "SELECT id, entry_date, supplier, amount, notes, file_path, file_mime, entry_type
     FROM deferred_entries
     WHERE store_id=? AND status='pending'
       AND (YEAR(entry_date) < ? OR (YEAR(entry_date)=? AND MONTH(entry_date)<?))
     ORDER BY supplier, entry_date, id"
);
$stmt_co->bind_param('iiii', $store_id, $year, $year, $month);
$stmt_co->execute();
$carryover_rows = $stmt_co->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_co->close();

$carryover_total = array_sum(array_column($carryover_rows, 'amount'));

// 전체 미결 합계 (전 기간)
$total_pending_all = array_sum($all_pending);

// 결제 이력이 있는 업체 (전체 기간, entry_date/paid_date 달과 무관)
// 이연결제 특성상 항목 등록월과 실제 결제월이 다른 경우가 흔해서, History는
// 특정 달에 종속시키지 않고 결제 이력이 있는 업체면 어느 달을 보고 있든 조회 가능하게 한다.
$res_paid_ever = $conn->query(
    "SELECT DISTINCT supplier FROM deferred_entries
     WHERE store_id={$store_id} AND status='paid'"
);
$paid_ever_suppliers = $res_paid_ever ? array_column($res_paid_ever->fetch_all(MYSQLI_ASSOC), 'supplier') : [];

$conn->close();

// 업체명 목록 (이번 달 데이터가 있거나, 결제 이력이 있는 업체를 컬럼으로)
$suppliers = array_values(array_unique(array_merge(
    array_column($rows, 'supplier'),
    $paid_ever_suppliers
)));
sort($suppliers);

// History 버튼 노출 여부 (결제 이력이 있으면 어느 달이든 노출)
$has_paid_history = array_fill_keys($paid_ever_suppliers, true);

// 자동완성: suppliers 테이블에 등록된 업체만 (수기 입력 항목 제외)
$autocomplete_suppliers = array_values(array_unique($db_suppliers));
sort($autocomplete_suppliers);

// 격자 집계 $grid[$day][$supplier] = [{...},...]
$grid = [];
foreach ($rows as $r) {
    $d = (int)date('j', strtotime($r['entry_date']));
    $grid[$d][$r['supplier']][] = $r;
}

// 업체별 합계
$sup_totals   = array_fill_keys($suppliers, 0);
$sup_pending  = array_fill_keys($suppliers, 0);
$sup_paid     = array_fill_keys($suppliers, 0);
$grand_total  = 0;
$grand_pending= 0;
foreach ($rows as $r) {
    $sup_totals[$r['supplier']]  += (float)$r['amount'];
    $grand_total                 += (float)$r['amount'];
    if ($r['status']==='pending') {
        $sup_pending[$r['supplier']] += (float)$r['amount'];
        $grand_pending               += (float)$r['amount'];
    } else {
        $sup_paid[$r['supplier']] += (float)$r['amount'];
    }
}

// 월 선택
$month_options = [];
for ($i=0;$i<12;$i++) {
    $ts = mktime(0,0,0,date('n')-$i,1,date('Y'));
    $month_options[] = ['y'=>(int)date('Y',$ts),'m'=>(int)date('n',$ts),'label'=>date('M Y',$ts)];
}
?>

<style>
.tg { border-collapse:collapse; font-size:11px; width:100%; }
.tg th, .tg td { border:1px solid #e5e7eb; padding:3px 6px; white-space:nowrap; }
.tg thead th { background:#f9fafb; font-weight:600; text-align:center; color:#374151;
               position:sticky; top:0; z-index:2; }
.tg .sup-hd   { background:#eef2ff; color:#4338ca; font-size:10px; min-width:90px;
                white-space:normal; word-break:break-word; }
.tg .day-cell { background:#f9fafb; font-weight:600; text-align:center; min-width:52px; }
.tg .day-cell.today { background:#fef9c3; color:#92400e; }
.tg .total-row td { background:#f0fdf4; font-weight:700; }
.tg .row-total { text-align:right; font-weight:700; color:#059669; background:#f0fdf4; }
.tg .col-total { text-align:right; font-weight:700; }
.cell-val { cursor:pointer; border-radius:3px; padding:1px 4px;
            display:inline-block; font-size:11px; transition:background .12s; }
.cell-pending { color:#b45309; } .cell-pending:hover { background:#fef3c7; }
.cell-paid    { color:#15803d; } .cell-paid:hover    { background:#dcfce7; }
</style>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header border-b border-gray-100 px-4 py-3">
        <h5 class="modal-title text-sm font-semibold text-gray-800">
          <i class="fa-solid fa-plus mr-1 text-indigo-500"></i>Add Deferred Entry
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 py-4 space-y-3">
        <div id="add_error" class="hidden bg-red-50 border border-red-200 text-red-700 rounded-lg p-3 text-sm"></div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Supplier <span class="text-red-500">*</span></label>
          <div class="flex gap-2">
            <input type="text" id="a_supplier" placeholder="Enter or select supplier"
                   list="supplier_list"
                   class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm">
            <button type="button" onclick="openNewSupplierDtr()"
                    class="px-3 py-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-lg text-sm font-medium whitespace-nowrap border border-indigo-200">
              <i class="fa-solid fa-plus mr-1"></i>New
            </button>
          </div>
          <datalist id="supplier_list">
            <?php foreach ($autocomplete_suppliers as $s): ?>
            <option value="<?php echo htmlspecialchars($s); ?>">
            <?php endforeach; ?>
          </datalist>
          <p class="text-xs text-gray-400 mt-1">If your supplier is not listed, click <strong>New</strong>.</p>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Date <span class="text-red-500">*</span></label>
          <input type="date" id="a_date" value="<?php echo $today; ?>"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1"><i class="fa-solid fa-cart-plus mr-1 text-indigo-500"></i>Purchase Amount</label>
            <input type="number" id="a_amount_purchase" step="0.01" min="0" placeholder="0.00"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm text-right">
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1"><i class="fa-solid fa-rotate-left mr-1 text-purple-500"></i>Return Amount</label>
            <input type="number" id="a_amount_return" step="0.01" min="0" placeholder="0.00"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm text-right">
          </div>
        </div>
        <p class="text-xs text-gray-400 -mt-2">Fill in one, or both to record a purchase and its return for the same supplier at once. The return sign is applied automatically.</p>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Notes</label>
          <input type="text" id="a_notes" placeholder="Optional"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">
            Proof Document <span class="text-gray-400 font-normal">(JPG, PNG, PDF · max 10MB)</span>
          </label>
          <div id="file_drop_zone"
               class="border-2 border-dashed border-gray-300 rounded-lg p-4 text-center cursor-pointer hover:border-indigo-400 transition-colors"
               onclick="document.getElementById('a_file').click()"
               ondragover="event.preventDefault();this.classList.add('border-indigo-500')"
               ondragleave="this.classList.remove('border-indigo-500')"
               ondrop="handleDrop(event)">
            <i class="fa-solid fa-cloud-arrow-up text-2xl text-gray-300 mb-1 block"></i>
            <p class="text-xs text-gray-400">Click or drag &amp; drop</p>
            <p id="a_file_name" class="text-xs text-indigo-600 font-medium mt-1 hidden"></p>
          </div>
          <input type="file" id="a_file" accept=".jpg,.jpeg,.png,.pdf" class="hidden"
                 onchange="showFileName(this,'a_file_name')">
        </div>
      </div>
      <div class="modal-footer px-4 py-3 flex gap-2 justify-end">
        <button class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm" data-bs-dismiss="modal">Cancel</button>
        <button onclick="saveEntry()"
                class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium">
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
          <i class="fa-solid fa-pen-to-square mr-1 text-indigo-500"></i>Edit Entry
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 py-4 space-y-3">
        <input type="hidden" id="e_id">
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Type</label>
          <div class="flex gap-2">
            <label class="flex-1 flex items-center justify-center gap-1.5 border border-gray-300 rounded-lg px-3 py-2 text-sm cursor-pointer has-[:checked]:bg-indigo-50 has-[:checked]:border-indigo-400 has-[:checked]:text-indigo-700">
              <input type="radio" name="e_entry_type" id="e_type_purchase" value="purchase" class="accent-indigo-600">
              <i class="fa-solid fa-cart-plus"></i>Purchase
            </label>
            <label class="flex-1 flex items-center justify-center gap-1.5 border border-gray-300 rounded-lg px-3 py-2 text-sm cursor-pointer has-[:checked]:bg-purple-50 has-[:checked]:border-purple-400 has-[:checked]:text-purple-700">
              <input type="radio" name="e_entry_type" id="e_type_return" value="return" class="accent-purple-600">
              <i class="fa-solid fa-rotate-left"></i>Return
            </label>
          </div>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Date</label>
            <input type="date" id="e_date" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Amount</label>
            <input type="number" id="e_amount" step="0.01" min="0.01"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm text-right">
          </div>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Supplier</label>
          <input type="text" id="e_supplier" list="supplier_list"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Notes</label>
          <input type="text" id="e_notes" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">
            Proof Document <span class="text-gray-400 font-normal">(JPG, PNG, PDF · max 10MB)</span>
          </label>
          <div id="e_file_current" class="hidden items-center gap-2 px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg mb-2 text-xs">
            <i id="e_file_icon" class="fa-solid fa-image text-indigo-500"></i>
            <span id="e_file_label" class="flex-1 text-gray-600 truncate"></span>
            <button type="button" onclick="removeEditFile()"
                    class="text-red-400 hover:text-red-600 text-xs px-2 py-0.5 border border-red-200 rounded ml-auto">
              <i class="fa-solid fa-trash-can mr-1"></i>Remove
            </button>
          </div>
          <div id="e_file_drop_zone"
               class="border-2 border-dashed border-gray-300 rounded-lg p-3 text-center cursor-pointer hover:border-indigo-400 transition-colors"
               onclick="document.getElementById('e_file').click()"
               ondragover="event.preventDefault();this.classList.add('border-indigo-500')"
               ondragleave="this.classList.remove('border-indigo-500')"
               ondrop="handleEditDrop(event)">
            <i class="fa-solid fa-cloud-arrow-up text-xl text-gray-300 mb-1 block"></i>
            <p class="text-xs text-gray-400">Click or drag &amp; drop to replace</p>
            <p id="e_file_name" class="text-xs text-indigo-600 font-medium mt-1 hidden"></p>
          </div>
          <input type="file" id="e_file" accept=".jpg,.jpeg,.png,.pdf" class="hidden"
                 onchange="showFileName(this,'e_file_name')">
          <input type="hidden" id="e_remove_file" value="0">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Status</label>
          <select id="e_status" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            <option value="pending">Pending</option>
            <option value="paid">Paid</option>
          </select>
        </div>
        <div id="edit_error" class="hidden text-xs text-red-600"></div>
      </div>
      <div class="modal-footer px-4 py-3 flex gap-2 justify-end">
        <button class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm" data-bs-dismiss="modal">Cancel</button>
        <button onclick="submitEdit()"
                class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium">
          <i class="fa-solid fa-floppy-disk mr-1"></i>Save
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

<!-- New Supplier Modal (Deferred Tracker) -->
<div class="modal fade" id="newSupplierDtrModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header border-b border-gray-100 px-4 py-3">
        <h5 class="modal-title text-sm font-semibold text-gray-800">
          <i class="fa-solid fa-truck mr-1 text-indigo-600"></i>New Supplier
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 py-4 space-y-3">
        <div id="nsd_error" class="hidden bg-red-50 border border-red-200 text-red-700 rounded-lg p-2 text-xs"></div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Name <span class="text-red-500">*</span></label>
          <input type="text" id="nsd_name" placeholder="Company / supplier name"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-400">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Contact Person</label>
          <input type="text" id="nsd_contact" placeholder="Optional"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-400">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Phone</label>
          <input type="text" id="nsd_phone" placeholder="Optional"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-400">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Email</label>
          <input type="email" id="nsd_email" placeholder="Optional"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-400">
        </div>
      </div>
      <div class="modal-footer px-4 py-3 flex gap-2 justify-end">
        <button class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm"
                data-bs-dismiss="modal">Cancel</button>
        <button onclick="saveNewSupplierDtr()"
                class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium">
          <i class="fa-solid fa-floppy-disk mr-1"></i>Save &amp; Select
        </button>
      </div>
    </div>
  </div>
</div>

<!-- 결제진행 모달 -->
<div class="modal fade" id="payModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header border-b border-gray-100 px-4 py-3">
        <h5 class="modal-title text-sm font-semibold text-gray-800">
          <i class="fa-solid fa-credit-card mr-1 text-red-500"></i>Payment
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 py-4 space-y-3">
        <div id="pay_error" class="hidden bg-red-50 border border-red-200 text-red-700 rounded-lg p-3 text-sm"></div>
        <div class="flex items-center gap-3 px-3 py-2 bg-indigo-50 rounded-lg">
          <i class="fa-solid fa-building text-indigo-500"></i>
          <span id="pay_supplier_label" class="text-sm font-semibold text-indigo-800"></span>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Payment Date <span class="text-red-500">*</span></label>
          <input type="date" id="pay_date"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"
                 onchange="loadPayPreview()">
          <p class="text-xs text-gray-400 mt-1">All pending items up to this date will be marked as paid.</p>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-2">Pending Items</label>
          <div id="pay_items" class="border border-gray-200 rounded-lg px-3 py-2 max-h-48 overflow-y-auto text-xs bg-gray-50">
            <div class="text-gray-400 text-center py-2">Select a date</div>
          </div>
        </div>
        <div>
          <label for="pay_discount" class="block text-xs font-medium text-gray-700 mb-1">Discount Rate (%)</label>
          <input type="number" id="pay_discount" min="0" max="100" step="0.01" value="0" oninput="updatePayTotal()"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm text-right">
        </div>
        <div class="flex justify-between items-center px-3 py-2 bg-gray-100 rounded-lg">
          <span class="text-sm font-semibold text-gray-700">Total Amount</span>
          <span id="pay_total" class="text-base font-bold text-red-600">-</span>
        </div>
      </div>
      <div class="modal-footer px-4 py-3 flex gap-2 justify-end">
        <button class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm" data-bs-dismiss="modal">Cancel</button>
        <button id="pay_confirm_btn" onclick="confirmPayment()"
                class="px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg text-sm font-medium">
          <i class="fa-solid fa-credit-card mr-1"></i>Confirm Payment
        </button>
      </div>
    </div>
  </div>
</div>

<!-- 결제 취소 모달 -->
<div class="modal fade" id="cancelModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header border-b border-gray-100 px-4 py-3">
        <h5 class="modal-title text-sm font-semibold text-gray-800">
          <i class="fa-solid fa-clock-rotate-left mr-1 text-indigo-500"></i>History
          <span id="cancel_supplier_label" class="ml-1 text-indigo-700"></span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 py-4">
        <div id="cancel_error" class="hidden bg-red-50 border border-red-200 text-red-700 rounded-lg p-3 text-sm mb-3"></div>
        <div class="px-3 py-2 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-700 mb-3">
          <i class="fa-solid fa-triangle-exclamation mr-1"></i>
          Select the payment batch to cancel. Items will return to <strong>Pending</strong> status and linked receipts will be deleted.
        </div>
        <div id="cancel_batches">
          <div class="text-gray-400 text-center py-6">Loading...</div>
        </div>
      </div>
      <div class="modal-footer px-4 py-3">
        <button class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- 헤더 -->
<div class="flex items-center justify-between mb-4">
  <h2 class="text-lg font-bold text-gray-800">
    <i class="fa-solid fa-truck-ramp-box mr-2 text-indigo-600"></i>Deferred — <?php echo $month_label; ?>
  </h2>
  <button onclick="bootstrap.Modal.getOrCreateInstance(document.getElementById('addModal')).show()"
          class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
    <i class="fa-solid fa-plus mr-1"></i>Add Entry
  </button>
</div>

<!-- 월 선택 -->
<form method="GET" class="flex gap-3 mb-4 bg-white p-3 rounded-xl shadow-sm border border-gray-100 items-center flex-wrap">
  <a href="index.php?year=<?php echo $prev_y; ?>&month=<?php echo $prev_m; ?>"
     class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-gray-500 hover:bg-gray-50">
    <i class="fa-solid fa-chevron-left"></i>
  </a>
  <select name="month" id="month_sel" class="border border-gray-300 rounded-lg px-3 py-2 text-sm" onchange="syncYear()">
    <?php foreach ($month_options as $opt): ?>
    <option value="<?php echo $opt['m']; ?>" data-year="<?php echo $opt['y']; ?>"
      <?php echo ($opt['y']===$year && $opt['m']===$month) ? 'selected' : ''; ?>>
      <?php echo $opt['label']; ?>
    </option>
    <?php endforeach; ?>
  </select>
  <input type="hidden" name="year" id="year_hidden" value="<?php echo $year; ?>">
  <a href="index.php?year=<?php echo $next_y; ?>&month=<?php echo $next_m; ?>"
     class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-gray-500 hover:bg-gray-50 <?php echo $is_future?'opacity-30 pointer-events-none':''; ?>">
    <i class="fa-solid fa-chevron-right"></i>
  </a>
  <button type="submit" class="px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm">
    <i class="fa-solid fa-magnifying-glass mr-1"></i>Search
  </button>
  <div class="ml-auto flex gap-4 text-sm">
    <span class="text-amber-600 font-semibold">This Month Pending: <?php echo dtr_money($grand_pending); ?></span>
    <?php if ($carryover_total != 0): ?>
    <span class="text-red-600 font-semibold">Carry-over: <?php echo dtr_money($carryover_total); ?></span>
    <?php endif; ?>
    <span class="text-red-700 font-bold">Total Pending: <?php echo dtr_money($total_pending_all); ?></span>
  </div>
</form>

<!-- 격자 -->
<?php if (empty($suppliers)): ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 text-center py-16 text-gray-400">
  <i class="fa-solid fa-truck-ramp-box text-4xl mb-3 block text-gray-200"></i>
  <p>No deferred entries for this period.</p>
  <button onclick="bootstrap.Modal.getOrCreateInstance(document.getElementById('addModal')).show()"
          class="mt-4 px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm">Add Entry</button>
</div>
<?php else: ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
<table class="tg">
  <thead>
    <tr>
      <th class="day-cell">Date</th>
      <?php foreach ($suppliers as $sup): ?>
      <th class="sup-hd" title="<?php echo htmlspecialchars($sup); ?>">
        <div style="display:flex;flex-direction:column;align-items:center;gap:4px">
          <span><?php echo htmlspecialchars($sup); ?></span>
          <div style="display:flex;flex-direction:row;gap:4px;flex-wrap:wrap;justify-content:center">
          <?php if (($all_pending[$sup] ?? 0) > 0): ?>
          <button onclick="openPayModal(<?php echo htmlspecialchars(json_encode($sup),ENT_QUOTES); ?>)"
                  style="font-size:11px;padding:3px 10px;background:#ef4444;color:#fff;border:none;border-radius:4px;cursor:pointer;white-space:nowrap;font-weight:600"
                  onmouseover="this.style.background='#dc2626'" onmouseout="this.style.background='#ef4444'">
            Pay
          </button>
          <?php endif; ?>
          <?php if (($sup_paid[$sup] ?? 0) > 0 || !empty($has_paid_history[$sup])): ?>
          <button onclick="openCancelModal(<?php echo htmlspecialchars(json_encode($sup),ENT_QUOTES); ?>)"
                  style="font-size:11px;padding:3px 10px;background:#6b7280;color:#fff;border:none;border-radius:4px;cursor:pointer;white-space:nowrap;font-weight:600"
                  onmouseover="this.style.background='#4b5563'" onmouseout="this.style.background='#6b7280'">
            History
          </button>
          <?php endif; ?>
          </div>
        </div>
      </th>
      <?php endforeach; ?>
      <th class="row-total" style="background:#f0fdf4;min-width:80px">Total</th>
    </tr>
  </thead>
  <tbody>
  <?php for ($d=1; $d<=$days_in_month; $d++):
    $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
    $dow      = $days_en[date('w', strtotime($date_str))];
    $is_today = ($date_str === $today);
    $is_sun   = ($dow === 'Sun');

    $row_total = 0;
    foreach ($suppliers as $sup)
        foreach ($grid[$d][$sup] ?? [] as $e) $row_total += (float)$e['amount'];
  ?>
  <tr<?php echo $is_sun?' style="background:#fff7f7"':''; ?>>
    <td class="day-cell <?php echo $is_today?'today':''; ?>">
      <?php echo $d; ?> <span style="color:#9ca3af;font-size:9px"><?php echo $dow; ?></span>
    </td>
    <?php foreach ($suppliers as $sup):
      $entries  = $grid[$d][$sup] ?? [];
      $sum      = array_sum(array_column($entries,'amount'));
      $allPaid  = count($entries)>0 && count(array_filter($entries,fn($e)=>$e['status']==='pending'))===0;
      $hasReturn= count(array_filter($entries,fn($e)=>$e['entry_type']==='return'))>0;
    ?>
    <td style="text-align:right;padding:2px 5px">
      <?php if (!empty($entries) && $sum != 0): ?>
      <span class="cell-val <?php echo $allPaid?'cell-paid':'cell-pending'; ?>"
            onclick="showDetail(<?php echo $d; ?>,<?php echo htmlspecialchars(json_encode($sup),ENT_QUOTES); ?>)">
        <?php echo dtr_money($sum); ?>
        <?php if (count($entries)>1): ?><span style="font-size:9px;color:#94a3b8">(<?php echo count($entries); ?>)</span><?php endif; ?>
        <?php if ($hasReturn): ?><i class="fa-solid fa-rotate-left" style="font-size:9px;color:#7c3aed" title="Includes return"></i><?php endif; ?>
        <?php if ($allPaid): ?><i class="fa-solid fa-check" style="font-size:9px;color:#15803d"></i><?php endif; ?>
      </span>
      <?php endif; ?>
    </td>
    <?php endforeach; ?>
    <td class="row-total"><?php echo $row_total!=0?dtr_money($row_total):''; ?></td>
  </tr>
  <?php endfor; ?>

  <!-- 합계 행 -->
  <tr class="total-row">
    <td class="day-cell" style="background:#f0fdf4">Total</td>
    <?php foreach ($suppliers as $sup): ?>
    <td class="col-total" style="color:<?php echo $sup_pending[$sup]>0?'#b45309':'#15803d'; ?>">
      <?php echo $sup_totals[$sup]!=0?dtr_money($sup_totals[$sup]):''; ?>
      <?php if ($sup_pending[$sup]>0): ?>
      <div style="font-size:9px;color:#b45309">Pending: <?php echo dtr_money($sup_pending[$sup]); ?></div>
      <?php endif; ?>
    </td>
    <?php endforeach; ?>
    <td class="row-total"><?php echo dtr_money($grand_total); ?></td>
  </tr>
  </tbody>
</table>
</div>

<!-- 이전 달 미결 (Carry-over) -->
<?php if (!empty($carryover_rows)):
    $co_by_supplier = [];
    foreach ($carryover_rows as $r) {
        $co_by_supplier[$r['supplier']][] = $r;
    }
?>
<div class="mt-4 bg-amber-50 rounded-xl border border-amber-200 overflow-hidden">
  <div class="px-4 py-3 border-b border-amber-200 flex items-center justify-between">
    <h3 class="text-sm font-semibold text-amber-800">
      <i class="fa-solid fa-triangle-exclamation mr-2 text-amber-500"></i>
      Carry-over Pending (Previous Months)
    </h3>
    <span class="text-sm font-bold text-red-600"><?php echo dtr_money($carryover_total); ?></span>
  </div>
  <div class="overflow-x-auto">
  <table class="min-w-full text-xs">
    <thead class="bg-amber-100">
      <tr>
        <th class="px-3 py-2 text-left text-gray-600 font-semibold">Date</th>
        <th class="px-3 py-2 text-left text-gray-600 font-semibold">Supplier</th>
        <th class="px-3 py-2 text-left text-gray-600 font-semibold">Notes</th>
        <th class="px-3 py-2 text-right text-gray-600 font-semibold">Amount</th>
        <th class="px-3 py-2 text-center text-gray-600 font-semibold w-20">File</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-amber-100">
    <?php foreach ($co_by_supplier as $sup => $entries):
        $sup_total = array_sum(array_column($entries, 'amount'));
    ?>
      <?php foreach ($entries as $i => $e): ?>
      <tr class="hover:bg-amber-100 transition-colors">
        <td class="px-3 py-2 text-gray-600 whitespace-nowrap"><?php echo date('M j, Y', strtotime($e['entry_date'])); ?></td>
        <td class="px-3 py-2 font-medium text-gray-800"><?php echo $i === 0 ? htmlspecialchars($sup) : ''; ?></td>
        <td class="px-3 py-2 text-gray-500">
          <?php echo htmlspecialchars($e['notes'] ?? ''); ?>
          <?php if (($e['entry_type'] ?? 'purchase') === 'return'): ?><span class="text-purple-600 font-semibold text-[10px]">(Return)</span><?php endif; ?>
        </td>
        <td class="px-3 py-2 text-right font-mono font-semibold text-amber-800"><?php echo dtr_money((float)$e['amount']); ?></td>
        <td class="px-3 py-2 text-center">
          <?php if ($e['file_path']): ?>
          <button onclick="openDtrPreview(<?php echo $e['id']; ?>,'<?php echo addslashes($e['file_mime']??''); ?>','<?php echo addslashes(htmlspecialchars($sup,ENT_QUOTES)); ?>','<?php echo $e['entry_date']; ?>')"
                  class="text-indigo-500 hover:text-indigo-700" title="Preview">
            <i class="fa-solid <?php echo $e['file_mime']==='application/pdf'?'fa-file-pdf':'fa-image'; ?>"></i>
          </button>
          <?php else: echo '—'; endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <!-- supplier subtotal -->
      <tr class="bg-amber-100">
        <td colspan="3" class="px-3 py-1.5 text-right text-xs text-amber-700 font-semibold">
          <?php echo htmlspecialchars($sup); ?> subtotal
        </td>
        <td class="px-3 py-1.5 text-right font-mono font-bold text-amber-800"><?php echo dtr_money($sup_total); ?></td>
        <td class="px-3 py-1.5 text-center">
          <button onclick="openPayModal(<?php echo htmlspecialchars(json_encode($sup),ENT_QUOTES); ?>)"
                  class="text-xs px-2 py-1 bg-red-500 hover:bg-red-600 text-white rounded font-semibold">
            <i class="fa-solid fa-credit-card mr-1"></i>Pay
          </button>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<!-- 업체별 합계 카드 -->
<div class="mt-4 bg-white rounded-xl shadow-sm border border-gray-100 p-4">
  <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Supplier Summary</h3>
  <div class="flex flex-wrap gap-3">
    <?php foreach ($suppliers as $sup): ?>
    <div class="flex items-center gap-3 px-4 py-3 rounded-xl border bg-indigo-50 border-indigo-200 min-w-44">
      <i class="fa-solid fa-building text-indigo-500 text-base"></i>
      <div class="flex-1">
        <p class="text-xs text-gray-600 font-medium"><?php echo htmlspecialchars($sup); ?></p>
        <p class="text-sm font-bold text-indigo-700"><?php echo dtr_money($sup_totals[$sup]); ?></p>
        <?php if (($all_pending[$sup] ?? 0) != 0): ?>
        <p class="text-xs text-amber-600 mb-1">Pending: <?php echo dtr_money($all_pending[$sup]); ?></p>
        <button onclick="openPayModal(<?php echo htmlspecialchars(json_encode($sup),ENT_QUOTES); ?>)"
                class="text-xs px-3 py-1 bg-red-500 hover:bg-red-600 text-white rounded-lg font-medium transition-colors">
          <i class="fa-solid fa-credit-card mr-1"></i>Pay Now
        </button>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <div class="flex items-center gap-3 px-4 py-3 rounded-xl border bg-gray-50 border-gray-200 min-w-44 ml-auto">
      <i class="fa-solid fa-calculator text-gray-500 text-base"></i>
      <div>
        <p class="text-xs text-gray-500">This Month Total</p>
        <p class="text-sm font-bold text-gray-800"><?php echo dtr_money($grand_total); ?></p>
        <?php if ($grand_pending!=0): ?>
        <p class="text-xs text-amber-600">Month Pending: <?php echo dtr_money($grand_pending); ?></p>
        <?php endif; ?>
        <?php if ($total_pending_all != 0): ?>
        <p class="text-xs text-red-600 font-semibold">All Pending: <?php echo dtr_money($total_pending_all); ?></p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
const YEAR  = <?php echo $year; ?>;
const MONTH = <?php echo $month; ?>;
const ROWS  = <?php echo json_encode($rows, JSON_UNESCAPED_UNICODE); ?>;
// suppliers 테이블에 등록된 업체 목록 (수기 입력 차단용)
const VALID_SUPPLIERS = <?php echo json_encode($autocomplete_suppliers, JSON_UNESCAPED_UNICODE); ?>;

function syncYear(){
    const s=document.getElementById('month_sel');
    document.getElementById('year_hidden').value=s.options[s.selectedIndex].dataset.year;
    s.form.submit();
}
function fmt(n){ const v=parseFloat(n||0); return (v<0?'-':'')+'₱'+Math.abs(v).toLocaleString('en',{minimumFractionDigits:2}); }
// 업체명에 아포스트로피(')가 들어있으면(예: "JEN'S FRUIT") 일부 호스팅 WAF가 SQL 인젝션으로
// 오탐지해 요청을 403으로 차단하는 사례가 있어, supplier/notes는 base64로 감싸서 전송한다.
// (서버는 office_b64_decode()로 복원)
function b64u(str){ return btoa(unescape(encodeURIComponent(str||''))); }
// 첨부파일의 원본 파일명(예: "jen's receipt.pdf")은 브라우저가 멀티파트 요청에 그대로 실어
// 보내서 b64u()로 감쌀 수 없다 — 아포스트로피 등이 들어있으면 supplier와 같은 이유로 WAF가
// 차단하므로, 업로드 직전에 확장자만 남기고 영文/숫자로 치환한 안전한 이름으로 바꿔 보낸다.
function safeFileName(name){
    const parts = String(name||'file').split('.');
    const ext   = parts.length > 1 ? '.' + parts.pop().replace(/[^A-Za-z0-9]/g,'') : '';
    const base  = parts.join('.').replace(/[^A-Za-z0-9._-]/g,'_') || 'file';
    return base + ext;
}

// ── 셀 상세 팝업 ──────────────────────────────────────────────
function showDetail(day, supplier) {
    const dateStr = YEAR+'-'+String(MONTH).padStart(2,'0')+'-'+String(day).padStart(2,'0');
    const entries = ROWS.filter(r => r.entry_date===dateStr && r.supplier===supplier);
    document.getElementById('detail_title').textContent = dateStr+' · '+supplier;

    let html='<table class="w-full text-xs border-collapse">'
            +'<thead><tr class="bg-gray-50">'
            +'<th class="border border-gray-200 px-3 py-2 text-center w-20">Type</th>'
            +'<th class="border border-gray-200 px-3 py-2 text-right w-28">Amount</th>'
            +'<th class="border border-gray-200 px-3 py-2 text-left">Notes</th>'
            +'<th class="border border-gray-200 px-3 py-2 text-center w-20">File</th>'
            +'<th class="border border-gray-200 px-3 py-2 text-center w-20">Status</th>'
            +'<th class="border border-gray-200 px-2 py-2 w-12"></th>'
            +'</tr></thead><tbody>';

    const esc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    let total=0;
    entries.forEach(r=>{
        total += parseFloat(r.amount||0);
        const isPaid = r.status==='paid';
        const badge  = isPaid
            ? '<span class="px-1.5 py-0.5 bg-green-100 text-green-700 rounded text-xs">Paid</span>'
            : '<span class="px-1.5 py-0.5 bg-amber-100 text-amber-700 rounded text-xs">Pending</span>';
        const isReturn = r.entry_type === 'return';
        const typeBadge = isReturn
            ? '<span class="px-1.5 py-0.5 bg-purple-100 text-purple-700 rounded text-xs"><i class="fa-solid fa-rotate-left mr-0.5"></i>Return</span>'
            : '<span class="px-1.5 py-0.5 bg-indigo-50 text-indigo-600 rounded text-xs">Purchase</span>';
        const entryData = esc(JSON.stringify({id:r.id,date:r.entry_date,amount:r.amount,supplier:r.supplier,notes:r.notes||'',status:r.status,file_path:r.file_path||'',file_mime:r.file_mime||'',entry_type:r.entry_type||'purchase'}));
        const proof  = r.file_path
            ? `<button data-id="${r.id}" data-mime="${esc(r.file_mime)}" data-supplier="${esc(r.supplier)}" data-date="${esc(r.entry_date)}"
                       onclick="openDtrPreview(this.dataset.id,this.dataset.mime,this.dataset.supplier,this.dataset.date)"
                       style="border:none;background:none;cursor:pointer;padding:0;color:#6366f1" title="Preview">
                 <i class="fa-solid ${r.file_mime==='application/pdf'?'fa-file-pdf':'fa-image'}"></i></button>`
            : '—';
        html+=`<tr class="border-b border-gray-100 hover:bg-gray-50">
            <td class="px-3 py-2 text-center">${typeBadge}</td>
            <td class="px-3 py-2 font-mono font-semibold text-right">${fmt(r.amount)}</td>
            <td class="px-3 py-2 text-gray-500">${esc(r.notes||'—')}</td>
            <td class="px-3 py-2 text-center">${proof}</td>
            <td class="px-3 py-2 text-center">${badge}</td>
            <td class="px-2 py-2 text-center" style="white-space:nowrap">
              <button data-entry="${entryData}" onclick="openEditFromEntry(this)"
                      class="text-indigo-400 hover:text-indigo-600 mr-1">
                <i class="fa-solid fa-pen-to-square text-xs"></i>
              </button>
              <button onclick="deleteEntry(${r.id})" class="text-gray-300 hover:text-red-500">
                <i class="fa-solid fa-trash-can text-xs"></i>
              </button>
            </td></tr>`;
    });
    html+=`</tbody><tfoot><tr class="bg-indigo-50">
        <td></td>
        <td class="px-3 py-2 font-bold text-right text-indigo-700">${fmt(total)}</td>
        <td class="px-3 py-2 text-xs text-gray-400" colspan="4">${entries.length} items</td>
    </tr></tfoot></table>`;

    document.getElementById('detail_body').innerHTML=html;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('detailModal')).show();
}

// ── Add ───────────────────────────────────────────────────────
function showFileName(input, nameId) {
    const el=document.getElementById(nameId);
    if(input.files.length){ el.textContent='📎 '+input.files[0].name; el.classList.remove('hidden'); }
}
function handleDrop(e){
    e.preventDefault(); e.currentTarget.classList.remove('border-indigo-500');
    if(e.dataTransfer.files.length){
        document.getElementById('a_file').files=e.dataTransfer.files;
        showFileName(document.getElementById('a_file'),'a_file_name');
    }
}
async function saveEntry(){
    const err=document.getElementById('add_error');
    err.classList.add('hidden');
    const supplier = document.getElementById('a_supplier').value.trim();
    // 등록된 업체만 허용 (수기 입력 차단)
    if(!VALID_SUPPLIERS.includes(supplier)){
        err.textContent='Please select a registered supplier. If it is not listed, click "New" to add it first.';
        err.classList.remove('hidden');
        return;
    }
    const purchaseAmt = parseFloat(document.getElementById('a_amount_purchase').value) || 0;
    const returnAmt   = parseFloat(document.getElementById('a_amount_return').value) || 0;
    if (purchaseAmt <= 0 && returnAmt <= 0) {
        err.textContent = 'Enter a purchase amount, a return amount, or both.';
        err.classList.remove('hidden');
        return;
    }
    // 매입/반품을 같은 날짜·업체로 한 번에 저장 — 둘 다 입력되면 두 건의 개별 항목으로 저장된다.
    const entries = [];
    if (purchaseAmt > 0) entries.push({ type: 'purchase', amount: purchaseAmt });
    if (returnAmt   > 0) entries.push({ type: 'return',   amount: returnAmt });

    const date  = document.getElementById('a_date').value;
    const notes = document.getElementById('a_notes').value;
    const fileEl = document.getElementById('a_file');

    try {
        for (const entry of entries) {
            const fd = new FormData();
            fd.append('entry_date', date);
            fd.append('supplier',   b64u(supplier));
            fd.append('amount',     entry.amount);
            fd.append('entry_type', entry.type);
            fd.append('notes',      b64u(notes));
            if(fileEl.files.length) fd.append('dtr_file', fileEl.files[0], safeFileName(fileEl.files[0].name));

            const res  = await fetch('ajax_save_dtr.php',{method:'POST',body:fd});
            const text = await res.text();
            let data;
            try { data = JSON.parse(text); }
            catch(parseErr) {
                console.error('Non-JSON response from ajax_save_dtr.php:', text);
                err.textContent = 'Save failed: unexpected server response (HTTP '+res.status+'). Check console for details.';
                err.classList.remove('hidden');
                return;
            }
            if(!data.success){ err.textContent=data.error||'Save failed.'; err.classList.remove('hidden'); return; }
        }
        location.href='index.php?year='+YEAR+'&month='+MONTH;
    } catch(e) {
        console.error('saveEntry() network error:', e);
        err.textContent = 'Save failed: network error. Please try again.';
        err.classList.remove('hidden');
    }
}

// ── Edit ─────────────────────────────────────────────────────
function openEditFromEntry(btn) {
    const r = JSON.parse(btn.dataset.entry);
    openEdit(r.id, r.date, r.amount, r.supplier, r.notes, r.status, r.file_path||'', r.file_mime||'', r.entry_type||'purchase');
}
function openEdit(id,date,amount,supplier,notes,status,filePath,fileMime,entryType){
    document.getElementById('e_id').value       = id;
    document.getElementById('e_date').value     = date;
    document.getElementById('e_amount').value   = Math.abs(parseFloat(amount)).toFixed(2);
    document.getElementById('e_supplier').value = supplier;
    document.getElementById('e_notes').value    = notes;
    document.getElementById('e_status').value   = status;
    document.getElementById(entryType === 'return' ? 'e_type_return' : 'e_type_purchase').checked = true;
    document.getElementById('edit_error').classList.add('hidden');
    document.getElementById('e_remove_file').value = '0';
    document.getElementById('e_file').value = '';
    document.getElementById('e_file_name').textContent = '';
    document.getElementById('e_file_name').classList.add('hidden');
    const curDiv = document.getElementById('e_file_current');
    if (filePath) {
        const icon = document.getElementById('e_file_icon');
        icon.className = 'fa-solid ' + (fileMime === 'application/pdf' ? 'fa-file-pdf' : 'fa-image') + ' text-indigo-500';
        document.getElementById('e_file_label').textContent = filePath.split('/').pop();
        curDiv.style.display = 'flex';
        curDiv.classList.remove('hidden');
    } else {
        curDiv.style.display = '';
        curDiv.classList.add('hidden');
    }

    const detailEl   = document.getElementById('detailModal');
    const detailInst = bootstrap.Modal.getInstance(detailEl);
    if (detailInst) {
        detailEl.addEventListener('hidden.bs.modal', function() {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal')).show();
        }, {once: true});
        detailInst.hide();
    } else {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal')).show();
    }
}
function removeEditFile() {
    document.getElementById('e_remove_file').value = '1';
    document.getElementById('e_file_current').style.display = '';
    document.getElementById('e_file_current').classList.add('hidden');
}
function handleEditDrop(e) {
    e.preventDefault(); e.currentTarget.classList.remove('border-indigo-500');
    if (e.dataTransfer.files.length) {
        document.getElementById('e_file').files = e.dataTransfer.files;
        showFileName(document.getElementById('e_file'), 'e_file_name');
    }
}
async function submitEdit(){
    const err=document.getElementById('edit_error');
    err.classList.add('hidden');
    const fd=new FormData();
    fd.append('id',          document.getElementById('e_id').value);
    fd.append('entry_date',  document.getElementById('e_date').value);
    fd.append('supplier',    b64u(document.getElementById('e_supplier').value.trim()));
    fd.append('amount',      document.getElementById('e_amount').value);
    fd.append('entry_type',  document.querySelector('input[name="e_entry_type"]:checked').value);
    fd.append('notes',       b64u(document.getElementById('e_notes').value));
    fd.append('status',      document.getElementById('e_status').value);
    fd.append('remove_file', document.getElementById('e_remove_file').value);
    const fileEl = document.getElementById('e_file');
    if (fileEl.files.length) fd.append('dtr_file', fileEl.files[0], safeFileName(fileEl.files[0].name));

    const res=await fetch('ajax_edit_dtr.php',{method:'POST',body:fd});
    const data=await res.json();
    if(data.success){ location.href='index.php?year='+YEAR+'&month='+MONTH; }
    else{ err.textContent=data.error||'Save failed.'; err.classList.remove('hidden'); }
}

// ── New Supplier ─────────────────────────────────────────────
function openNewSupplierDtr() {
    const typed = document.getElementById('a_supplier').value.trim();
    document.getElementById('nsd_name').value    = typed;
    document.getElementById('nsd_contact').value = '';
    document.getElementById('nsd_phone').value   = '';
    document.getElementById('nsd_email').value   = '';
    document.getElementById('nsd_error').classList.add('hidden');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('newSupplierDtrModal')).show();
    setTimeout(() => document.getElementById('nsd_name').focus(), 300);
}

async function saveNewSupplierDtr() {
    const name    = document.getElementById('nsd_name').value.trim();
    const contact = document.getElementById('nsd_contact').value.trim();
    const phone   = document.getElementById('nsd_phone').value.trim();
    const email   = document.getElementById('nsd_email').value.trim();
    const errDiv  = document.getElementById('nsd_error');

    if (!name) {
        errDiv.textContent = 'Supplier name is required.';
        errDiv.classList.remove('hidden');
        return;
    }
    errDiv.classList.add('hidden');

    const fd = new FormData();
    fd.append('name', name);
    fd.append('contact_person', contact);
    fd.append('phone', phone);
    fd.append('email', email);

    const res  = await fetch('../receipts/ajax_add_supplier.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
        document.getElementById('a_supplier').value = data.name;
        const opt = document.createElement('option');
        opt.value = data.name;
        document.getElementById('supplier_list').appendChild(opt);
        // 새로 등록된 업체를 허용 목록에 추가
        if(!VALID_SUPPLIERS.includes(data.name)) VALID_SUPPLIERS.push(data.name);
        bootstrap.Modal.getInstance(document.getElementById('newSupplierDtrModal'))?.hide();
    } else {
        errDiv.textContent = data.error || 'Error saving supplier.';
        errDiv.classList.remove('hidden');
    }
}

// ── Delete ────────────────────────────────────────────────────
async function deleteEntry(id){
    if(!confirm('Delete this entry?')) return;
    const fd=new FormData(); fd.append('id',id);
    const res=await fetch('ajax_delete_dtr.php',{method:'POST',body:fd});
    const data=await res.json();
    if(data.success){ location.href='index.php?year='+YEAR+'&month='+MONTH; }
    else alert('Delete failed');
}

// ── 결제진행 ─────────────────────────────────────────────────
let _paySupplier = '';
let _paySubtotal = 0;

function updatePayTotal() {
    const input = document.getElementById('pay_discount');
    const rate = Number(input.value);
    const valid = input.value !== '' && Number.isFinite(rate) && rate >= 0 && rate <= 100;
    input.setCustomValidity(valid ? '' : 'Discount rate must be between 0% and 100%.');
    const discount = Math.round(_paySubtotal * rate) / 100;
    document.getElementById('pay_total').textContent = valid ? fmt(_paySubtotal - discount) : '-';
    document.getElementById('pay_confirm_btn').disabled = !valid || _paySubtotal <= 0;
}

function openPayModal(supplier) {
    _paySupplier = supplier;
    document.getElementById('pay_supplier_label').textContent = supplier;
    document.getElementById('pay_date').value = '<?php echo $today; ?>';
    document.getElementById('pay_discount').value = '0';
    _paySubtotal = 0;
    document.getElementById('pay_error').classList.add('hidden');
    document.getElementById('pay_confirm_btn').disabled = false;
    loadPayPreview();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('payModal')).show();
}

function loadPayPreview() {
    const date = document.getElementById('pay_date').value;
    if (!date) return;
    document.getElementById('pay_items').innerHTML = '<div class="text-gray-400 text-center py-2">Loading...</div>';
    document.getElementById('pay_total').textContent = '-';
    _paySubtotal = 0;
    document.getElementById('pay_confirm_btn').disabled = true;

    const fd = new FormData();
    fd.append('action', 'preview');
    fd.append('supplier', b64u(_paySupplier));
    fd.append('pay_date', date);
    fetch('ajax_pay_dtr.php', {method:'POST', body:fd})
    .then(r => r.json())
    .then(d => {
        if (!d.success) { document.getElementById('pay_items').innerHTML = '<div class="text-red-400 text-center py-2">Error</div>'; return; }
        if (!d.items.length) {
            document.getElementById('pay_items').innerHTML = '<div class="text-gray-400 text-center py-2">No pending items up to this date.</div>';
            document.getElementById('pay_total').textContent = '₱0.00';
            updatePayTotal();
            return;
        }
        let html = '';
        d.items.forEach(item => {
            html += `<div class="flex justify-between py-1 border-b border-gray-200 last:border-0">
                <span class="text-gray-600">${item.label}${item.notes ? ' <span class="text-gray-400">('+item.notes+')</span>' : ''}</span>
                <span class="font-mono font-medium text-gray-800">${fmt(item.amount)}</span>
            </div>`;
        });
        document.getElementById('pay_items').innerHTML = html;
        _paySubtotal = Number(d.total);
        updatePayTotal();
    });
}

async function confirmPayment() {
    const date   = document.getElementById('pay_date').value;
    const errDiv = document.getElementById('pay_error');
    const btn    = document.getElementById('pay_confirm_btn');
    updatePayTotal();
    if (!document.getElementById('pay_discount').checkValidity() || btn.disabled) {
        document.getElementById('pay_discount').reportValidity();
        return;
    }
    errDiv.classList.add('hidden');
    btn.disabled = true;
    btn.textContent = 'Processing...';

    const fd = new FormData();
    fd.append('action', 'pay');
    fd.append('supplier', b64u(_paySupplier));
    fd.append('pay_date', date);
    fd.append('discount_rate', document.getElementById('pay_discount').value);

    const res  = await fetch('ajax_pay_dtr.php', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) {
        location.href = 'index.php?year='+YEAR+'&month='+MONTH;
    } else {
        errDiv.textContent = data.error || 'Payment failed';
        errDiv.classList.remove('hidden');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-credit-card mr-1"></i>Confirm Payment';
    }
}

// ── 결제 취소 (배치별) ────────────────────────────────────────
let _cancelSupplier = '';

function openCancelModal(supplier) {
    _cancelSupplier = supplier;
    document.getElementById('cancel_supplier_label').textContent = '— ' + supplier;
    document.getElementById('cancel_error').classList.add('hidden');
    document.getElementById('cancel_batches').innerHTML =
        '<div class="text-gray-400 text-center py-6">Loading...</div>';

    const fd = new FormData();
    fd.append('action', 'preview');
    fd.append('supplier', b64u(supplier));
    fd.append('year', YEAR);
    fd.append('month', MONTH);
    fetch('ajax_cancel_dtr.php', {method:'POST', body:fd})
    .then(r => r.json())
    .then(d => {
        if (!d.success) {
            document.getElementById('cancel_batches').innerHTML =
                '<div class="text-red-400 text-center py-6">An error occurred.</div>';
            return;
        }
        if (!d.batches || !d.batches.length) {
            document.getElementById('cancel_batches').innerHTML =
                '<div class="text-gray-400 text-center py-6">No completed payments found.</div>';
            return;
        }
        renderCancelBatches(d.batches);
    })
    .catch(() => {
        document.getElementById('cancel_batches').innerHTML =
            '<div class="text-red-400 text-center py-6">Request failed</div>';
    });

    bootstrap.Modal.getOrCreateInstance(document.getElementById('cancelModal')).show();
}

function buildDetailTable(batch, supplier) {
    const paidLabel = batch.paid_date || 'Unknown date';
    const totalFmt  = fmt(batch.total);
    const subtotal = batch.items.reduce((sum, item) => sum + Number(item.amount), 0);
    const discountRate = batch.discount_rate != null
        ? Number(batch.discount_rate)
        : (subtotal > 0 ? Number(batch.discount) / subtotal * 100 : null);
    const discountLabel = discountRate == null ? 'Discount'
        : `Discount (${Number(discountRate.toFixed(2))}%)`;
    let rows = '';
    batch.items.forEach(item => {
        rows += `<tr>
            <td>${item.label}</td>
            <td>${item.notes || '—'}</td>
            <td class="amount">${fmt(item.amount)}</td>
        </tr>`;
    });
    return `<h2>${supplier}</h2>
    <div class="meta">
        Supplier: <strong>${supplier}</strong> &nbsp;|&nbsp;
        Payment Date: <strong>${paidLabel}</strong> &nbsp;|&nbsp;
        Items: <strong>${batch.items.length}</strong>
    </div>
    <table>
        <thead><tr><th>Date</th><th>Notes</th><th style="text-align:right">Amount</th></tr></thead>
        <tbody>${rows}</tbody>
        <tfoot>
            ${batch.discount > 0 ? `<tr class="total-spacer"><td colspan="3"></td></tr>
            <tr><td colspan="2">${discountLabel}</td><td class="amount">-${fmt(batch.discount)}</td></tr>
            <tr class="total-spacer"><td colspan="3"></td></tr>` : ''}
            <tr class="total-row"><td colspan="2">TOTAL</td><td class="amount">${totalFmt}</td></tr>
        </tfoot>
    </table>`;
}

const PRINT_CSS = `
    body { font-family: Arial, sans-serif; font-size: 12px; margin: 24px; color: #111; }
    h2   { font-size: 15px; margin-bottom: 4px; }
    .meta { font-size: 11px; color: #555; margin-bottom: 16px; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th, td { border: 1px solid #ccc; padding: 6px 10px; text-align: left; }
    th { background: #f3f4f6; font-weight: 600; }
    td.amount { text-align: right; font-family: monospace; }
    .total-row td { font-weight: bold; background: #f0fdf4; }
    .total-spacer td { height: 12px; padding: 0; border-left: 0; border-right: 0; }
    .receipt-section { margin-top: 24px; }
    .receipt-item { page-break-before: always; text-align: center; }
    .receipt-item img { max-width: 100%; max-height: 90vh; border: 1px solid #ccc; }
    .receipt-item embed { width: 100%; height: 90vh; border: 1px solid #ccc; }
    .receipt-label { font-size: 11px; color: #555; margin-bottom: 8px; }
    @media print { .no-print { display:none; } }
`;

// 상세내역만 인쇄
function printBatch(batch, supplier) {
    const win = window.open('', '_blank');
    win.document.write(`<!DOCTYPE html><html><head><meta charset="UTF-8">
    <title>Payment Detail — ${supplier}</title>
    <style>${PRINT_CSS}</style></head><body>
    ${buildDetailTable(batch, supplier)}
    <script>window.onload=()=>window.print();<\/script>
    </body></html>`);
    win.document.close();
}

// 상세내역 + 영수증 인쇄
async function printBatchWithReceipts(batch, supplier, btn) {
    const hasFiles = batch.items.some(i => i.file_path);
    if (!hasFiles) {
        alert('No attached receipts found for this payment batch.');
        printBatch(batch, supplier);
        return;
    }

    // 버튼 로딩 상태
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i>Loading...';

    async function toDataUrl(id) {
        try {
            const resp = await fetch(`view_dtr_file.php?id=${id}`);
            if (!resp.ok) return null;
            const blob = await resp.blob();
            return { type: blob.type, url: await new Promise(r => {
                const fr = new FileReader();
                fr.onload = () => r(fr.result);
                fr.readAsDataURL(blob);
            })};
        } catch { return null; }
    }

    try {
        let receiptHtml = '';
        for (const item of batch.items) {
            if (!item.file_path) continue;
            const f = await toDataUrl(item.id);
            if (!f) continue;
            const label = `${item.label}${item.notes ? ' — ' + item.notes : ''} · ${fmt(item.amount)}`;
            if (f.type.startsWith('image/')) {
                receiptHtml += `<div class="receipt-item">
                    <div class="receipt-label">${label}</div>
                    <img src="${f.url}" alt="receipt" onload="this.dataset.loaded='1'">
                </div>`;
            } else {
                receiptHtml += `<div class="receipt-item">
                    <div class="receipt-label">${label}</div>
                    <iframe src="${f.url}" class="pdf-frame"></iframe>
                </div>`;
            }
        }

        const win = window.open('', '_blank');
        win.document.write(`<!DOCTYPE html><html><head><meta charset="UTF-8">
        <title>Payment + Receipts — ${supplier}</title>
        <style>
            ${PRINT_CSS}
            .pdf-frame { width:100%; height:90vh; border:1px solid #ccc; display:block; }
        </style></head><body>
        ${buildDetailTable(batch, supplier)}
        ${receiptHtml ? '<div class="receipt-section">' + receiptHtml + '</div>' : ''}
        <script>
        window.addEventListener('load', function() {
            const imgs = document.querySelectorAll('img');
            if (imgs.length === 0) { setTimeout(function(){ window.print(); }, 600); return; }
            let loaded = 0;
            function tryPrint() {
                loaded++;
                if (loaded >= imgs.length) setTimeout(function(){ window.print(); }, 400);
            }
            imgs.forEach(function(img) {
                if (img.complete) tryPrint();
                else { img.addEventListener('load', tryPrint); img.addEventListener('error', tryPrint); }
            });
        });
        <\/script>
        </body></html>`);
        win.document.close();
    } finally {
        btn.disabled = false;
        btn.innerHTML = origHtml;
    }
}

function renderCancelBatches(batches) {
    let html = '<div class="space-y-2">';
    batches.forEach((batch, bi) => {
        const ids      = batch.items.map(i => i.id);
        const paidLabel = batch.paid_date ? batch.paid_date + ' Paid' : 'Unknown date';
        const receiptBadge = batch.receipt_id
            ? `<span class="ml-2 text-xs px-1.5 py-0.5 bg-blue-100 text-blue-600 rounded">Receipt #${batch.receipt_id}</span>`
            : '';
        const totalFmt = fmt(batch.total);
        const batchJson = JSON.stringify(batch).replace(/"/g,'&quot;');

        let itemsHtml = '';
        batch.items.forEach(item => {
            itemsHtml += `<div class="flex justify-between py-1 border-b border-gray-100 last:border-0 text-xs">
                <span class="text-gray-500">${item.label}${item.notes ? ' <span class="text-gray-400">('+item.notes+')</span>' : ''}</span>
                <span class="font-mono text-gray-700">${fmt(item.amount)}</span>
            </div>`;
        });
        if (batch.discount > 0) itemsHtml += `<div class="flex justify-between py-1 text-xs text-green-700"><span>Discount${batch.discount_rate !== null ? ' ('+batch.discount_rate+'%)' : ''}</span><span class="font-mono">-${fmt(batch.discount)}</span></div>`;

        html += `
        <div class="border border-gray-200 rounded-xl overflow-hidden">
          <div class="flex items-center gap-3 px-4 py-3 bg-gray-50">
            <div class="flex-1">
              <span class="text-sm font-semibold text-gray-800" id="paid_label_${bi}">${paidLabel}</span>
              <button type="button"
                      onclick="startEditBatchDate(${bi}, '${batch.paid_date||''}', ${JSON.stringify(ids).replace(/"/g,'&quot;')})"
                      class="text-gray-400 hover:text-indigo-600" title="Edit payment date">
                <i class="fa-solid fa-pen text-xs"></i>
              </button>
              ${receiptBadge}
              <span class="ml-2 text-xs text-gray-400">${batch.items.length} items</span>
            </div>
            <span class="text-sm font-bold text-gray-700 mr-3">${totalFmt}</span>
            <button onclick="printBatch(JSON.parse(this.dataset.batch), '${_cancelSupplier.replace(/'/g,"\\'")}') "
                    data-batch="${batchJson}"
                    class="text-xs px-3 py-1.5 bg-blue-500 hover:bg-blue-600 text-white rounded-lg font-medium whitespace-nowrap mr-1"
                    title="Print details only">
              <i class="fa-solid fa-print mr-1"></i>Print
            </button>
            <button onclick="printBatchWithReceipts(JSON.parse(this.dataset.batch), '${_cancelSupplier.replace(/'/g,"\\'")}', this)"
                    data-batch="${batchJson}"
                    class="text-xs px-3 py-1.5 bg-indigo-500 hover:bg-indigo-600 text-white rounded-lg font-medium whitespace-nowrap mr-1"
                    title="Print details + receipts">
              <i class="fa-solid fa-receipt mr-1"></i>+Receipt
            </button>
            <button onclick="toggleCancelBatch(${bi})"
                    class="text-xs px-2 py-1 border border-gray-300 rounded text-gray-500 hover:bg-gray-100 mr-1">
              <i id="cancel_arrow_${bi}" class="fa-solid fa-chevron-down text-xs"></i>
            </button>
            <button onclick="confirmCancelBatch(${JSON.stringify(ids).replace(/"/g,'&quot;')}, this)"
                    class="text-xs px-3 py-1.5 bg-red-500 hover:bg-red-600 text-white rounded-lg font-medium whitespace-nowrap">
              <i class="fa-solid fa-rotate-left mr-1"></i>Cancel Payment
            </button>
          </div>
          <div id="cancel_batch_${bi}" class="hidden px-4 py-2 bg-white border-t border-gray-100">
            ${itemsHtml}
          </div>
        </div>`;
    });
    html += '</div>';
    document.getElementById('cancel_batches').innerHTML = html;
}

// ── 결제일 수기 변경 ─────────────────────────────────────────
function startEditBatchDate(bi, currentDate, ids) {
    const el = document.getElementById('paid_label_' + bi);
    if (!el) return;
    el.dataset.orig = el.innerHTML;
    el.innerHTML = `<input type="date" id="edit_date_input_${bi}" value="${currentDate||''}"
        class="border border-gray-300 rounded px-1 py-0.5 text-xs align-middle">
        <button type="button" onclick="saveBatchDate(${bi}, ${JSON.stringify(ids).replace(/"/g,'&quot;')})"
                class="ml-1 text-green-600 hover:text-green-700 align-middle"><i class="fa-solid fa-check text-xs"></i></button>
        <button type="button" onclick="cancelEditBatchDate(${bi})"
                class="ml-1 text-gray-400 hover:text-red-500 align-middle"><i class="fa-solid fa-xmark text-xs"></i></button>`;
}
function cancelEditBatchDate(bi) {
    const el = document.getElementById('paid_label_' + bi);
    if (el && el.dataset.orig) el.innerHTML = el.dataset.orig;
}
async function saveBatchDate(bi, ids) {
    const input = document.getElementById('edit_date_input_' + bi);
    const newDate = input ? input.value : '';
    if (!newDate) { alert('날짜를 선택하세요.'); return; }

    const fd = new FormData();
    fd.append('action', 'update_paid_date');
    fd.append('supplier', b64u(_cancelSupplier));
    fd.append('ids', JSON.stringify(ids));
    fd.append('new_date', newDate);

    const res  = await fetch('ajax_cancel_dtr.php', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) {
        location.href = 'index.php?year='+YEAR+'&month='+MONTH;
    } else {
        alert(data.error || '결제일 변경 실패');
    }
}

function toggleCancelBatch(bi) {
    const el    = document.getElementById('cancel_batch_' + bi);
    const arrow = document.getElementById('cancel_arrow_' + bi);
    const open  = !el.classList.contains('hidden');
    el.classList.toggle('hidden', open);
    arrow.classList.toggle('fa-chevron-down', open);
    arrow.classList.toggle('fa-chevron-up', !open);
}

async function confirmCancelBatch(ids, btn) {
    if (!confirm('Cancel this payment batch?\nLinked receipts will also be deleted.')) return;

    const errDiv = document.getElementById('cancel_error');
    errDiv.classList.add('hidden');
    btn.disabled = true;
    btn.textContent = 'Processing...';

    const fd = new FormData();
    fd.append('action', 'cancel');
    fd.append('supplier', b64u(_cancelSupplier));
    fd.append('year', YEAR);
    fd.append('month', MONTH);
    fd.append('ids', JSON.stringify(ids));

    const res  = await fetch('ajax_cancel_dtr.php', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) {
        location.href = 'index.php?year='+YEAR+'&month='+MONTH;
    } else {
        errDiv.textContent = data.error || 'Cancellation failed';
        errDiv.classList.remove('hidden');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-rotate-left mr-1"></i>Cancel Payment';
        btn.className = 'text-xs px-3 py-1.5 bg-red-500 hover:bg-red-600 text-white rounded-lg font-medium whitespace-nowrap';
    }
}

// ── 첨부파일 미리보기 ─────────────────────────────────────────
let dtrPreviewBlobUrl = null;
async function openDtrPreview(entryId, mime, supplier, date) {
    const modal   = document.getElementById('dtrPreviewModal');
    const body    = document.getElementById('dtrPreviewBody');
    const dlLink  = document.getElementById('dtrPreviewDownload');
    const viewUrl = 'view_dtr_file.php?id=' + entryId;
    document.getElementById('dtrPreviewTitle').textContent = (supplier||'Attachment') + ' · ' + (date||'');
    dlLink.href = viewUrl + '&dl=1';
    body.innerHTML = '<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:13px">Loading...</div>';
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';

    if (dtrPreviewBlobUrl) { URL.revokeObjectURL(dtrPreviewBlobUrl); dtrPreviewBlobUrl = null; }

    // 파일을 blob으로 받아 blob URL로 표시 → X-Frame-Options 프레이밍 차단을 우회
    try {
        const res = await fetch(viewUrl, { credentials: 'same-origin' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const blob = await res.blob();
        dtrPreviewBlobUrl = URL.createObjectURL(blob);
        const realMime = (mime && mime !== '') ? mime : (blob.type || '');

        if (realMime.startsWith('image/')) {
            const wrap = document.createElement('div');
            wrap.style.cssText = 'position:absolute;inset:0;overflow:auto;display:flex;align-items:center;justify-content:center;padding:16px;background:#e5e7eb';
            const img = document.createElement('img');
            img.src = dtrPreviewBlobUrl;
            img.style.cssText = 'max-width:100%;max-height:100%;object-fit:contain;border-radius:6px;box-shadow:0 4px 20px rgba(0,0,0,.2)';
            body.innerHTML = '';
            wrap.appendChild(img);
            body.appendChild(wrap);
        } else {
            const iframe = document.createElement('iframe');
            iframe.src = dtrPreviewBlobUrl;
            iframe.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;border:none;display:block';
            body.innerHTML = '';
            body.appendChild(iframe);
        }
    } catch (e) {
        body.innerHTML = '<div style="position:absolute;inset:0;display:flex;flex-direction:column;gap:8px;align-items:center;justify-content:center;color:#ef4444;font-size:13px;text-align:center;padding:20px">'
            + '<i class="fa-solid fa-triangle-exclamation" style="font-size:24px"></i>'
            + '<div>File could not be loaded. (' + (e.message || 'error') + ')</div>'
            + '<a href="' + viewUrl + '&dl=1" style="color:#16a34a;text-decoration:underline">Open as download</a>'
            + '</div>';
    }
}
function closeDtrPreview() {
    document.getElementById('dtrPreviewModal').style.display = 'none';
    document.getElementById('dtrPreviewBody').innerHTML = '';
    document.body.style.overflow = '';
    if (dtrPreviewBlobUrl) { URL.revokeObjectURL(dtrPreviewBlobUrl); dtrPreviewBlobUrl = null; }
}
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeDtrPreview(); });
</script>

<!-- 첨부파일 미리보기 모달 (Deferred) -->
<div id="dtrPreviewModal" style="display:none;position:fixed;inset:0;z-index:9999">
  <div style="position:absolute;inset:0;background:rgba(0,0,0,.5)" onclick="closeDtrPreview()"></div>
  <div style="position:absolute;left:0;top:0;bottom:0;width:min(720px,65vw);background:#fff;box-shadow:4px 0 30px rgba(0,0,0,.3);display:flex;flex-direction:column;overflow:hidden">
    <div style="display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid #e5e7eb;background:#f9fafb;flex-shrink:0">
      <i class="fa-solid fa-paperclip" style="color:#6366f1"></i>
      <span id="dtrPreviewTitle" style="flex:1;font-size:13px;font-weight:600;color:#1f2937;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></span>
      <a id="dtrPreviewDownload" href="#" download
         style="display:flex;align-items:center;gap:6px;padding:6px 12px;background:#16a34a;color:#fff;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none">
        <i class="fa-solid fa-download"></i>Download
      </a>
      <button onclick="closeDtrPreview()"
              style="width:32px;height:32px;border:none;background:none;cursor:pointer;border-radius:6px;font-size:16px;color:#6b7280;display:flex;align-items:center;justify-content:center"
              onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='none'">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>
    <div id="dtrPreviewBody" style="flex:1;position:relative;overflow:hidden;background:#e5e7eb"></div>
  </div>
</div>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
