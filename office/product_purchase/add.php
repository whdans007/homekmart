<?php
// POST 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../lib/office_helper.php';
    require_office_permission();

    $store_id        = get_office_store_id();
    $payment_type    = in_array($_POST['payment_type'] ?? '', ['cash','check']) ? $_POST['payment_type'] : 'cash';
    $supplier_name   = post_str('supplier_name');
    $delivery_content = post_str('delivery_content');
    $amount          = post_float('amount');
    $payment_date    = post_date('payment_date');
    $check_issued    = ($payment_type === 'check') ? post_date('check_issued_date') : null;
    $created_by      = (int)($_SESSION['user_id'] ?? 0) ?: null;

    $errors = [];
    if ($supplier_name === '')    $errors[] = 'Supplier name is required.';
    if ($delivery_content === '') $errors[] = 'Description is required.';
    if ($amount <= 0)             $errors[] = 'Please enter a valid amount.';
    if (!$payment_date)           $errors[] = 'Payment date is required.';
    if ($payment_type === 'check' && !$check_issued) $errors[] = 'Check issue date is required.';

    if (empty($errors)) {
        $conn = get_db_connection();
        $stmt = $conn->prepare(
            "INSERT INTO office_product_purchases
             (store_id, payment_type, supplier_name, delivery_content, amount, payment_date, check_issued_date, created_by)
             VALUES (?,?,?,?,?,?,?,?)"
        );
        $stmt->bind_param('isssdssi', $store_id, $payment_type, $supplier_name, $delivery_content, $amount, $payment_date, $check_issued, $created_by);
        $stmt->execute();
        $purchase_id = (int)$conn->insert_id;
        $stmt->close();
        $conn->close();

        // Plan SC9 — 영수증 연결
        $receipt_id = (int)($_POST['receipt_id'] ?? 0);
        if ($receipt_id > 0) link_receipt($receipt_id, 'product', $purchase_id);

        header('Location: list.php?view=day&date=' . $payment_date);
        exit;
    }
}

$page_title      = 'Add Product Purchase';
$css_base        = '../../admin/';
$office_nav_base = '../';
$_default_date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
require_once __DIR__ . '/../partials/header.php';

$sel_type = $_POST['payment_type'] ?? 'cash';
?>

<div class="max-w-xl mx-auto">
  <div class="flex items-center gap-3 mb-5">
    <a href="list.php" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-arrow-left"></i></a>
    <h2 class="text-xl font-bold text-gray-800">Add Product Purchase</h2>
  </div>

  <!-- Date selector -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-3 mb-4 flex items-center gap-3">
    <label class="text-sm font-medium text-gray-700 whitespace-nowrap">
      <i class="fa-solid fa-calendar-day mr-1 text-blue-500"></i>Payment Date:
    </label>
    <input type="date" id="top_date" max="<?php echo date('Y-m-d'); ?>"
           value="<?php echo $_default_date; ?>"
           onchange="syncDate(this.value)"
           class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-blue-500">
    <?php if ($_default_date === date('Y-m-d')): ?>
    <span class="text-xs text-blue-600 font-medium">Today</span>
    <?php endif; ?>
  </div>

  <?php if (!empty($errors)): ?>
  <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-4 mb-4 text-sm">
    <?php foreach ($errors as $e): ?><div><?php echo htmlspecialchars($e); ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <form method="POST" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-5">

    <!-- Payment Method 라디오 -->
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-2">Payment Method</label>
      <div class="flex gap-6">
        <label class="flex items-center gap-2 cursor-pointer">
          <input type="radio" name="payment_type" value="cash"  id="type_cash"
                 <?php echo $sel_type==='cash' ? 'checked':'' ?> onchange="toggleType()">
          <span class="text-sm text-gray-700">Cash</span>
        </label>
        <label class="flex items-center gap-2 cursor-pointer">
          <input type="radio" name="payment_type" value="check" id="type_check"
                 <?php echo $sel_type==='check'? 'checked':'' ?> onchange="toggleType()">
          <span class="text-sm text-gray-700">Check</span>
        </label>
      </div>
    </div>

    <!-- Load from Receipt — Plan SC4, SC7 -->
    <input type="hidden" name="receipt_id" id="receipt_id" value="">
    <div id="receipt_badge" class="hidden bg-indigo-50 border border-indigo-200 rounded-lg px-3 py-2 text-sm text-indigo-700">
      <div class="flex items-center justify-between">
        <span><i class="fa-solid fa-receipt mr-1"></i>Receipt Linked: <span id="receipt_label" class="font-medium"></span></span>
        <button type="button" onclick="clearReceipt()" class="text-indigo-400 hover:text-red-500"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div id="receipt_date_info" class="text-xs text-indigo-500 mt-0.5"></div>
    </div>

    <!-- 공통 필드 -->
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Supplier <span class="text-red-500">*</span></label>
      <div class="flex gap-2">
        <input type="text" name="supplier_name" id="supplier_name" value="<?php echo htmlspecialchars($_POST['supplier_name'] ?? ''); ?>"
               class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
        <button type="button" onclick="openReceiptModal()"
                class="px-3 py-2 bg-indigo-50 border border-indigo-200 text-indigo-700 rounded-lg text-xs font-medium hover:bg-indigo-100 whitespace-nowrap">
          <i class="fa-solid fa-receipt mr-1"></i>Load from Receipt
        </button>
      </div>
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Description <span class="text-red-500">*</span></label>
      <textarea name="delivery_content" rows="3"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required><?php echo htmlspecialchars($_POST['delivery_content'] ?? ''); ?></textarea>
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Amount <span class="text-red-500">*</span></label>
      <input type="number" name="amount" step="0.01" min="0" value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>"
             class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
    </div>

    <!-- Cash 섹션 -->
    <div id="section_cash">
      <label class="block text-sm font-medium text-gray-700 mb-1">Payment Date <span class="text-red-500">*</span></label>
      <input type="date" name="payment_date" value="<?php echo htmlspecialchars($_POST['payment_date'] ?? (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d'))); ?>"
             class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
    </div>

    <!-- Check 섹션 -->
    <div id="section_check" class="space-y-4 hidden">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Check Due Date <span class="text-red-500">*</span></label>
        <input type="date" name="payment_date" id="check_payment_date"
               value="<?php echo htmlspecialchars($_POST['payment_date'] ?? (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d'))); ?>"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Check Issue Date <span class="text-red-500">*</span></label>
        <input type="date" name="check_issued_date" value="<?php echo htmlspecialchars($_POST['check_issued_date'] ?? (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d'))); ?>"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
      </div>
    </div>

    <div class="flex gap-3 pt-2">
      <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg text-sm font-medium">Save</button>
      <a href="list.php" class="flex-1 text-center bg-gray-100 hover:bg-gray-200 text-gray-700 py-2 rounded-lg text-sm font-medium">Cancel</a>
    </div>
  </form>
</div>

<!-- Load Receipt Modal — Design Ref: §5.3 -->
<div class="modal fade" id="receiptModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header border-b border-gray-100 px-4 py-3">
        <h5 class="modal-title text-base font-semibold text-gray-800">
          <i class="fa-solid fa-receipt mr-2 text-indigo-600"></i>Load from Receipt
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 py-3">
        <div class="flex gap-2 mb-3">
          <input type="text" id="modal_supplier" placeholder="Search supplier"
                 class="flex-1 border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-1 focus:ring-indigo-500">
          <input type="date" id="modal_from" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
          <input type="date" id="modal_to" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
          <button type="button" onclick="searchReceipts()"
                  class="px-3 py-1.5 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">Search</button>
        </div>
        <div id="modal_result" class="text-sm text-gray-500 text-center py-4">Click Search to find receipts.</div>
        <p class="text-xs text-gray-400 mt-2">* Only unlinked receipts are shown.</p>
      </div>
    </div>
  </div>
</div>

<script>
function toggleType() {
    const isCash = document.getElementById('type_cash').checked;
    document.getElementById('section_cash').classList.toggle('hidden', !isCash);
    document.getElementById('section_check').classList.toggle('hidden', isCash);
}
toggleType();

function syncDate(val) {
    // Sync top date picker → visible payment date field
    const cashDate = document.querySelector('#section_cash input[name="payment_date"]');
    const issuedDate = document.querySelector('input[name="check_issued_date"]');
    if (cashDate) cashDate.value = val;
    if (issuedDate) issuedDate.value = val;
}

function openReceiptModal() {
    new bootstrap.Modal(document.getElementById('receiptModal')).show();
    searchReceipts();
}

function searchReceipts() {
    const s = document.getElementById('modal_supplier').value;
    const f = document.getElementById('modal_from').value;
    const t = document.getElementById('modal_to').value;
    const url = `../ajax_search_receipts.php?supplier_name=${encodeURIComponent(s)}&date_from=${f}&date_to=${t}`;
    const res = document.getElementById('modal_result');
    res.innerHTML = '<div class="text-center py-4 text-gray-400"><i class="fa-solid fa-spinner fa-spin mr-1"></i>Loading...</div>';
    fetch(url)
    .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
    .then(txt => JSON.parse(txt.replace(/^﻿/, '')))
    .then(data => {
        if (!data.success || !data.receipts.length) {
            res.innerHTML = '<div class="text-center py-6 text-gray-400">No unlinked receipts found.</div>';
            return;
        }
        let html = '<table class="min-w-full divide-y divide-gray-100 text-sm">';
        html += '<thead class="bg-gray-50"><tr><th class="px-3 py-2 text-left text-xs text-gray-500">Date</th><th class="px-3 py-2 text-left text-xs text-gray-500">Supplier</th><th class="px-3 py-2 text-left text-xs text-gray-500">Description</th><th class="px-3 py-2 text-right text-xs text-gray-500">Amount</th><th class="px-3 py-2"></th></tr></thead>';
        html += '<tbody class="divide-y divide-gray-50">';
        data.receipts.forEach(r => {
            const amt = '₱ ' + r.amount.toLocaleString('en', {minimumFractionDigits:2});
            const clip = r.has_file ? ' <i class="fa-solid fa-paperclip text-gray-400 text-xs"></i>' : '';
            const desc = escHtml(r.description || '');
            html += `<tr class="receipt-row hover:bg-indigo-50 cursor-pointer"
                         data-id="${r.id}"
                         data-supplier="${escHtml(r.supplier_name)}"
                         data-amount="${r.amount}"
                         data-date="${escHtml(r.receipt_date)}"
                         data-desc="${desc}">`;
            html += `<td class="px-3 py-2 text-gray-500 whitespace-nowrap">${escHtml(r.receipt_date)}</td>`;
            html += `<td class="px-3 py-2 font-medium text-gray-800">${escHtml(r.supplier_name)}${clip}</td>`;
            html += `<td class="px-3 py-2 text-gray-500 max-w-xs truncate">${desc}</td>`;
            html += `<td class="px-3 py-2 text-right font-medium text-gray-800 whitespace-nowrap">${amt}</td>`;
            html += `<td class="px-3 py-2 text-right"><button type="button" class="select-receipt-btn px-2 py-1 bg-indigo-600 text-white rounded text-xs hover:bg-indigo-700">Select</button></td>`;
            html += '</tr>';
        });
        html += '</tbody></table>';
        res.innerHTML = html;
        // Event delegation — 특수문자 안전 처리
        res.querySelectorAll('.receipt-row, .select-receipt-btn').forEach(el => {
            el.addEventListener('click', function(e) {
                e.stopPropagation();
                const row = this.closest('.receipt-row');
                if (!row) return;
                selectReceipt(
                    row.dataset.id,
                    row.dataset.supplier,
                    parseFloat(row.dataset.amount),
                    row.dataset.date,
                    row.dataset.desc
                );
            });
        });
    })
    .catch(err => {
        res.innerHTML = '<div class="text-center py-6 text-red-400"><i class="fa-solid fa-circle-exclamation mr-1"></i>Error loading receipts: ' + err.message + '</div>';
    });
}

function decodeHtmlEntities(str) {
    const txt = document.createElement('textarea');
    txt.innerHTML = str;
    return txt.value;
}

function selectReceipt(id, supplier, amount, date, description) {
    document.getElementById('receipt_id').value = id;
    document.getElementById('supplier_name').value = decodeHtmlEntities(supplier);
    document.getElementById('receipt_label').textContent = `${decodeHtmlEntities(supplier)} / ₱${amount.toLocaleString('en',{minimumFractionDigits:2})}`;
    // 영수증 날짜 참고 표시
    document.getElementById('receipt_date_info').textContent = `Receipt Date: ${date}`;
    document.getElementById('receipt_badge').classList.remove('hidden');
    // Description 자동 채움
    const contentEl = document.querySelector('textarea[name="delivery_content"]');
    if (contentEl && description) contentEl.value = decodeHtmlEntities(description);
    // Amount 자동 채움
    const amtEl = document.querySelector('input[name="amount"]');
    if (amtEl) amtEl.value = amount;
    bootstrap.Modal.getInstance(document.getElementById('receiptModal')).hide();
}

function clearReceipt() {
    document.getElementById('receipt_id').value = '';
    document.getElementById('receipt_badge').classList.add('hidden');
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
