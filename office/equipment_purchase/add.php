<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../lib/office_helper.php';
    require_office_permission();

    $store_id         = get_office_store_id();
    $supplier_name    = post_str('supplier_name');
    $delivery_content = post_str('delivery_content');
    $amount           = post_float('amount');
    $payment_date     = post_date('payment_date');
    $created_by       = (int)($_SESSION['user_id'] ?? 0) ?: null;
    $expense_type     = in_array($_POST['expense_type'] ?? '', ['consumable','other_expense'])
                        ? $_POST['expense_type'] : 'consumable';
    $expense_category = post_str('expense_category');

    $errors = [];
    if ($delivery_content === '') $errors[] = 'Description is required.';
    if ($amount <= 0)             $errors[] = 'Please enter a valid amount.';
    if (!$payment_date)           $errors[] = 'Payment date is required.';
    if ($expense_type === 'consumable' && $supplier_name === '') $errors[] = 'Supplier name is required.';

    if (empty($errors)) {
        $conn = get_db_connection();

        // Check if expense_type column exists (migration may not have run yet)
        $chk = $conn->query("SHOW COLUMNS FROM office_equipment_purchases LIKE 'expense_type'");
        $has_expense_type = ($chk && $chk->num_rows > 0);

        if ($has_expense_type) {
            $stmt = $conn->prepare(
                "INSERT INTO office_equipment_purchases
                 (store_id, supplier_name, delivery_content, expense_type, expense_category, amount, payment_date, created_by)
                 VALUES (?,?,?,?,?,?,?,?)"
            );
            $stmt->bind_param('issssdsi',
                $store_id, $supplier_name, $delivery_content,
                $expense_type, $expense_category, $amount, $payment_date, $created_by
            );
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO office_equipment_purchases
                 (store_id, supplier_name, delivery_content, amount, payment_date, created_by)
                 VALUES (?,?,?,?,?,?)"
            );
            $stmt->bind_param('issdsi',
                $store_id, $supplier_name, $delivery_content, $amount, $payment_date, $created_by
            );
        }

        $stmt->execute();
        $purchase_id = (int)$conn->insert_id;
        $stmt->close();
        $conn->close();

        // Plan SC9 — link receipt
        $receipt_id = (int)($_POST['receipt_id'] ?? 0);
        if ($receipt_id > 0) link_receipt($receipt_id, 'equipment', $purchase_id);

        header('Location: list.php?view=day&date=' . $payment_date);
        exit;
    }
}

$page_title      = 'Add Equipment Purchase';
$css_base        = '../../admin/';
$office_nav_base = '../';
$_default_date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
require_once __DIR__ . '/../partials/header.php';
?>

<div class="max-w-xl mx-auto">
  <div class="flex items-center gap-3 mb-5">
    <a href="list.php" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-arrow-left"></i></a>
    <h2 class="text-xl font-bold text-gray-800">Add Equipment Purchase</h2>
  </div>

  <!-- Date selector -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-3 mb-4 flex items-center gap-3">
    <label class="text-sm font-medium text-gray-700 whitespace-nowrap">
      <i class="fa-solid fa-calendar-day mr-1 text-orange-500"></i>Payment Date:
    </label>
    <input type="date" id="top_date" max="<?php echo date('Y-m-d'); ?>"
           value="<?php echo $_default_date; ?>"
           onchange="document.getElementById('payment_date_hidden').value=this.value"
           class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-orange-500">
    <?php if ($_default_date === date('Y-m-d')): ?>
    <span class="text-xs text-orange-600 font-medium">Today</span>
    <?php endif; ?>
  </div>

  <?php if (!empty($errors)): ?>
  <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-4 mb-4 text-sm">
    <?php foreach ($errors as $e): ?><div><?php echo htmlspecialchars($e); ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <form method="POST" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-5">
    <!-- Expense Type -->
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-2">Expense Type <span class="text-red-500">*</span></label>
      <div class="flex gap-2">
        <label class="flex-1 cursor-pointer">
          <input type="radio" name="expense_type" value="consumable" id="type_consumable"
                 <?php echo (($_POST['expense_type'] ?? 'consumable') === 'consumable') ? 'checked' : ''; ?>
                 onchange="toggleExpenseType()" class="sr-only">
          <div class="type-btn py-2.5 text-center rounded-lg border-2 text-sm font-medium transition-all
                      border-orange-400 bg-orange-50 text-orange-700" id="btn_consumable">
            <i class="fa-solid fa-box-open mr-1"></i>Consumable Purchase<br>
            <span class="text-xs font-normal text-orange-500">소모품 구입</span>
          </div>
        </label>
        <label class="flex-1 cursor-pointer">
          <input type="radio" name="expense_type" value="other_expense" id="type_other"
                 <?php echo (($_POST['expense_type'] ?? '') === 'other_expense') ? 'checked' : ''; ?>
                 onchange="toggleExpenseType()" class="sr-only">
          <div class="type-btn py-2.5 text-center rounded-lg border-2 text-sm font-medium transition-all
                      border-gray-200 bg-white text-gray-500" id="btn_other">
            <i class="fa-solid fa-file-invoice-dollar mr-1"></i>Other Expense<br>
            <span class="text-xs font-normal text-gray-400">기타비용처리</span>
          </div>
        </label>
      </div>
    </div>

    <!-- Load from Receipt — Plan SC5 -->
    <input type="hidden" name="receipt_id" id="receipt_id" value="">
    <div id="receipt_badge" class="hidden bg-indigo-50 border border-indigo-200 rounded-lg px-3 py-2 text-sm text-indigo-700">
      <div class="flex items-center justify-between">
        <span><i class="fa-solid fa-receipt mr-1"></i>Receipt Linked: <span id="receipt_label" class="font-medium"></span></span>
        <button type="button" onclick="clearReceipt()" class="text-indigo-400 hover:text-red-500"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div id="receipt_date_info" class="text-xs text-indigo-500 mt-0.5"></div>
    </div>

    <div id="supplier_section">
      <label class="block text-sm font-medium text-gray-700 mb-1">
        Supplier <span class="text-red-500">*</span>
      </label>
      <div class="flex gap-2">
        <input type="text" name="supplier_name" id="supplier_name" value="<?php echo htmlspecialchars($_POST['supplier_name'] ?? ''); ?>"
               class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
        <button type="button" onclick="openReceiptModal()"
                class="px-3 py-2 bg-indigo-50 border border-indigo-200 text-indigo-700 rounded-lg text-xs font-medium hover:bg-indigo-100 whitespace-nowrap">
          <i class="fa-solid fa-receipt mr-1"></i>Load from Receipt
        </button>
      </div>
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Description <span class="text-red-500">*</span></label>
      <textarea name="delivery_content" rows="3"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-orange-500 focus:border-orange-500" required><?php echo htmlspecialchars($_POST['delivery_content'] ?? ''); ?></textarea>
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Amount <span class="text-red-500">*</span></label>
      <input type="number" name="amount" step="0.01" min="0" value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>"
             class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-orange-500 focus:border-orange-500" required>
    </div>
    <input type="hidden" id="payment_date_hidden" name="payment_date"
           value="<?php echo htmlspecialchars($_POST['payment_date'] ?? $_default_date); ?>">

    <div class="flex gap-3 pt-2">
      <button type="submit" class="flex-1 bg-orange-600 hover:bg-orange-700 text-white py-2 rounded-lg text-sm font-medium">Save</button>
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
function openReceiptModal() {
    new bootstrap.Modal(document.getElementById('receiptModal')).show();
    searchReceipts();
}

function searchReceipts() {
    const s   = document.getElementById('modal_supplier').value;
    const f   = document.getElementById('modal_from').value;
    const t   = document.getElementById('modal_to').value;
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
            const amt  = '₱ ' + r.amount.toLocaleString('en', {minimumFractionDigits:2});
            const clip = r.has_file ? ' <i class="fa-solid fa-paperclip text-gray-400 text-xs"></i>' : '';
            const desc = escHtml(r.description || '');
            const args = `${r.id},'${escHtml(r.supplier_name)}',${r.amount},'${escHtml(r.receipt_date)}','${desc}'`;
            html += `<tr class="hover:bg-indigo-50 cursor-pointer" onclick="selectReceipt(${args})">`;
            html += `<td class="px-3 py-2 text-gray-500 whitespace-nowrap">${escHtml(r.receipt_date)}</td>`;
            html += `<td class="px-3 py-2 font-medium text-gray-800">${escHtml(r.supplier_name)}${clip}</td>`;
            html += `<td class="px-3 py-2 text-gray-500 max-w-xs truncate">${desc}</td>`;
            html += `<td class="px-3 py-2 text-right font-medium text-gray-800 whitespace-nowrap">${amt}</td>`;
            html += `<td class="px-3 py-2 text-right"><button type="button" class="px-2 py-1 bg-indigo-600 text-white rounded text-xs hover:bg-indigo-700" onclick="event.stopPropagation();selectReceipt(${args})">Select</button></td>`;
            html += '</tr>';
        });
        html += '</tbody></table>';
        res.innerHTML = html;
    })
    .catch(err => {
        res.innerHTML = '<div class="text-center py-6 text-red-400"><i class="fa-solid fa-circle-exclamation mr-1"></i>Error: ' + err.message + '</div>';
    });
}

function selectReceipt(id, supplier, amount, date, description) {
    document.getElementById('receipt_id').value = id;
    document.getElementById('supplier_name').value = supplier;
    document.getElementById('receipt_label').textContent = `${supplier} / ₱${amount.toLocaleString('en',{minimumFractionDigits:2})}`;
    document.getElementById('receipt_date_info').textContent = `Receipt Date: ${date}`;
    document.getElementById('receipt_badge').classList.remove('hidden');
    const contentEl = document.querySelector('textarea[name="delivery_content"]');
    if (contentEl && description) contentEl.value = description;
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

function toggleExpenseType() {
    const isOther = document.getElementById('type_other').checked;
    const btnC = document.getElementById('btn_consumable');
    const btnO = document.getElementById('btn_other');
    if (isOther) {
        btnC.className = 'type-btn py-2.5 text-center rounded-lg border-2 text-sm font-medium transition-all border-gray-200 bg-white text-gray-500';
        btnO.className = 'type-btn py-2.5 text-center rounded-lg border-2 text-sm font-medium transition-all border-purple-400 bg-purple-50 text-purple-700';
    } else {
        btnC.className = 'type-btn py-2.5 text-center rounded-lg border-2 text-sm font-medium transition-all border-orange-400 bg-orange-50 text-orange-700';
        btnO.className = 'type-btn py-2.5 text-center rounded-lg border-2 text-sm font-medium transition-all border-gray-200 bg-white text-gray-500';
    }
}
toggleExpenseType();
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
