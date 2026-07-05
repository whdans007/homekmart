<?php
// Design Ref: §6.2 — Add Receipt + 파일 업로드
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../lib/office_helper.php';
    require_office_permission();

    $store_id      = get_office_store_id();
    $supplier_name = post_str('supplier_name');
    $description   = post_str('description');
    $amount        = post_float('amount');
    $receipt_date  = post_date('receipt_date');
    $notes         = post_str('notes');
    $cv_no         = post_str('cv_no');
    $created_by    = (int)($_SESSION['user_id'] ?? 0) ?: null;

    $errors = [];
    if ($supplier_name === '') $errors[] = 'Supplier name is required.';
    if ($amount <= 0)          $errors[] = 'Please enter a valid amount.';
    if (!$receipt_date)        $errors[] = 'Date is required.';

    // 파일 업로드 처리 — Plan SC2, SC6
    $file_path = $file_original = $file_mime = null;
    if (!empty($_FILES['receipt_file']['name'])) {
        $allowed_mime = ['image/jpeg', 'image/png', 'application/pdf'];
        $tmp  = $_FILES['receipt_file']['tmp_name'];
        $orig = $_FILES['receipt_file']['name'];
        $size = $_FILES['receipt_file']['size'];

        // finfo로 실제 MIME 검증
        $finfo     = finfo_open(FILEINFO_MIME_TYPE);
        $real_mime = strtok(finfo_file($finfo, $tmp) ?: '', ';');
        finfo_close($finfo);
        if ($real_mime === 'application/x-pdf') $real_mime = 'application/pdf';
        if ($real_mime !== 'application/pdf') {
            $fh = fopen($tmp, 'rb');
            if ($fh) {
                $h = fread($fh, 16); fclose($fh);
                while (substr($h, 0, 3) === "\xEF\xBB\xBF") { $h = substr($h, 3); }
                if (substr($h, 0, 4) === '%PDF') $real_mime = 'application/pdf';
            }
        }

        if (!in_array($real_mime, $allowed_mime)) {
            $errors[] = 'Invalid file type. Only JPG, PNG, PDF are allowed.';
        } elseif ($size > 10 * 1024 * 1024) {
            $errors[] = 'File size must not exceed 10MB.';
        } else {
            $ext_map  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
            $ext      = $ext_map[$real_mime];
            $dir      = __DIR__ . '/../../uploads/receipts/' . date('Y/m') . '/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname    = uniqid('r_', true) . '.' . $ext;
            $content  = file_get_contents($tmp);
            if ($real_mime === 'application/pdf') {
                while (substr($content, 0, 3) === "\xEF\xBB\xBF") { $content = substr($content, 3); }
            }
            if (file_put_contents($dir . $fname, $content) !== false) {
                $file_path     = 'uploads/receipts/' . date('Y/m') . '/' . $fname;
                $file_original = htmlspecialchars($orig, ENT_QUOTES, 'UTF-8');
                $file_mime     = $real_mime;
            } else {
                $errors[] = 'Failed to save file.';
            }
        }
    }

    // Design Ref: §8.1 — payment_type 저장
    $payment_type = in_array($_POST['payment_type'] ?? '', ['cash','check']) ? $_POST['payment_type'] : 'cash';

    if (empty($errors)) {
        $conn = get_db_connection();
        // payment_type 컬럼 존재 여부 확인
        $has_pt = (bool)$conn->query("SHOW COLUMNS FROM office_receipts LIKE 'payment_type'")->num_rows;
        if ($has_pt) {
            $stmt = $conn->prepare(
                "INSERT INTO office_receipts
                 (store_id, supplier_name, description, amount, receipt_date,
                  file_path, file_original_name, file_mime, notes, cv_no, payment_type, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            if (!$stmt) {
                $errors[] = 'DB Error: ' . $conn->error;
            } else {
                $stmt->bind_param('issdsssssssi',
                    $store_id, $supplier_name, $description, $amount, $receipt_date,
                    $file_path, $file_original, $file_mime, $notes, $cv_no, $payment_type, $created_by
                );
                $stmt->execute();
                $stmt->close();
                $conn->close();
                header('Location: list.php');
                exit;
            }
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO office_receipts
                 (store_id, supplier_name, description, amount, receipt_date,
                  file_path, file_original_name, file_mime, notes, cv_no, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)"
            );
            if (!$stmt) {
                $errors[] = 'DB Error: ' . $conn->error . ' (Please run run_receipt_status_migration.php)';
            } else {
                $stmt->bind_param('issdssssssi',
                    $store_id, $supplier_name, $description, $amount, $receipt_date,
                    $file_path, $file_original, $file_mime, $notes, $cv_no, $created_by
                );
                $stmt->execute();
                $stmt->close();
                $conn->close();
                header('Location: list.php');
                exit;
            }
        }
    }
}

$page_title      = 'Add Receipt';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

// Plan SC3 — suppliers 자동완성
$suppliers = [];
try {
    $conn      = get_db_connection();
    $res       = $conn->query("SELECT name FROM suppliers ORDER BY name");
    $suppliers = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $conn->close();
} catch (Exception $e) { /* 자동완성 실패는 무시 */ }
?>

<div class="max-w-xl mx-auto">
  <div class="flex items-center gap-3 mb-6">
    <a href="list.php" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-arrow-left"></i></a>
    <h2 class="text-xl font-bold text-gray-800"><i class="fa-solid fa-receipt mr-2 text-indigo-600"></i>Add Receipt</h2>
  </div>

  <?php if (!empty($errors)): ?>
  <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-4 mb-4 text-sm">
    <?php foreach ($errors as $e): ?><div><?php echo htmlspecialchars($e); ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <form method="POST" enctype="multipart/form-data" id="receiptForm" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-5">

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Supplier <span class="text-red-500">*</span></label>
      <div class="flex gap-2">
        <div class="relative flex-1">
          <input type="text" id="supplier_search" autocomplete="off"
                 value="<?php echo htmlspecialchars($_POST['supplier_name'] ?? ''); ?>"
                 placeholder="Search supplier name"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" required>
          <input type="hidden" name="supplier_name" id="supplier_name"
                 value="<?php echo htmlspecialchars($_POST['supplier_name'] ?? ''); ?>">
          <div id="supplier_dropdown"
               class="hidden absolute z-10 mt-1 w-full bg-white border border-gray-200 rounded-lg shadow-lg max-h-48 overflow-y-auto"></div>
        </div>
        <button type="button" onclick="openNewSupplier()"
                class="px-3 py-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-lg text-sm font-medium whitespace-nowrap border border-indigo-200">
          <i class="fa-solid fa-plus mr-1"></i>New
        </button>
      </div>
      <p class="text-xs text-gray-400 mt-1">Select an existing supplier from the list. Can't find it? Click <strong>New</strong> to register.</p>
      <p id="supplier_error" class="hidden text-xs text-red-500 mt-1">
        <i class="fa-solid fa-circle-exclamation mr-1"></i>Please select a supplier from the list, or click <strong>New</strong> to register one.
      </p>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
      <textarea name="description" rows="2"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
    </div>

    <!-- Design Ref: §8.1 — payment_type 선택 -->
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-2">Payment Type <span class="text-red-500">*</span></label>
      <div class="flex gap-5">
        <label class="flex items-center gap-2 cursor-pointer">
          <input type="radio" name="payment_type" value="cash"
                 <?php echo (($_POST['payment_type'] ?? 'cash') === 'cash') ? 'checked' : ''; ?>
                 class="text-indigo-600 focus:ring-indigo-500">
          <span class="text-sm text-gray-700">Cash (현금)</span>
        </label>
        <label class="flex items-center gap-2 cursor-pointer">
          <input type="radio" name="payment_type" value="check"
                 <?php echo (($_POST['payment_type'] ?? '') === 'check') ? 'checked' : ''; ?>
                 class="text-indigo-600 focus:ring-indigo-500">
          <span class="text-sm text-gray-700">Cheque (수표)</span>
        </label>
      </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Amount <span class="text-red-500">*</span></label>
        <input type="number" name="amount" step="0.01" min="0"
               value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" required>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Date <span class="text-red-500">*</span></label>
        <input type="date" name="receipt_date"
               value="<?php echo htmlspecialchars($_POST['receipt_date'] ?? date('Y-m-d')); ?>"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" required>
      </div>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">CV No. <span class="text-xs text-gray-400">(reference number, optional)</span></label>
      <input type="text" name="cv_no" value="<?php echo htmlspecialchars($_POST['cv_no'] ?? ''); ?>"
             placeholder="e.g. INV-2026-001, DR-12345"
             class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
      <input type="text" name="notes" value="<?php echo htmlspecialchars($_POST['notes'] ?? ''); ?>"
             class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Attach Receipt <span class="text-xs text-gray-400">(JPG/PNG/PDF, max 10MB)</span></label>
      <input type="file" name="receipt_file" accept="image/jpeg,image/png,application/pdf"
             class="w-full text-sm text-gray-600 file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-sm file:font-medium file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
    </div>

    <div class="flex gap-3 pt-2">
      <button type="submit" class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white py-2 rounded-lg text-sm font-medium">Save</button>
      <a href="list.php" class="flex-1 text-center bg-gray-100 hover:bg-gray-200 text-gray-700 py-2 rounded-lg text-sm font-medium">Cancel</a>
    </div>
  </form>
</div>

<!-- New Supplier Modal -->
<div class="modal fade" id="newSupplierModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header border-b border-gray-100 px-4 py-3">
        <h5 class="modal-title text-sm font-semibold text-gray-800">
          <i class="fa-solid fa-truck mr-1 text-indigo-600"></i>New Supplier
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 py-4 space-y-3">
        <div id="ns_error" class="hidden bg-red-50 border border-red-200 text-red-700 rounded-lg p-2 text-xs"></div>

        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Name <span class="text-red-500">*</span></label>
          <input type="text" id="ns_name" placeholder="Company / supplier name"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-400">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Contact Person</label>
          <input type="text" id="ns_contact" placeholder="Optional"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-400">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Phone</label>
          <input type="text" id="ns_phone" placeholder="Optional"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-400">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Email</label>
          <input type="email" id="ns_email" placeholder="Optional"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-400">
        </div>
      </div>
      <div class="modal-footer px-4 py-3 flex gap-2 justify-end">
        <button class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm"
                data-bs-dismiss="modal">Cancel</button>
        <button onclick="saveNewSupplier()"
                class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium">
          <i class="fa-solid fa-floppy-disk mr-1"></i>Save &amp; Select
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// Plan SC3 — 등록된 거래처만 선택 가능한 검색형 드롭다운 (오타로 인한 잘못된 업체명 방지)
const SUPPLIERS = <?php echo json_encode(array_column($suppliers, 'name'), JSON_UNESCAPED_UNICODE); ?>;

const supplierSearch   = document.getElementById('supplier_search');
const supplierValue    = document.getElementById('supplier_name');
const supplierDropdown = document.getElementById('supplier_dropdown');
const supplierError    = document.getElementById('supplier_error');

function escapeHtml(str) {
    return str.replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function renderSupplierDropdown(filter) {
    const q = filter.trim().toLowerCase();
    const matches = SUPPLIERS.filter(s => s.toLowerCase().includes(q));
    if (!matches.length) {
        supplierDropdown.classList.add('hidden');
        supplierDropdown.innerHTML = '';
        return;
    }
    supplierDropdown.innerHTML = matches.map(s =>
        `<div class="px-3 py-2 text-sm hover:bg-indigo-50 cursor-pointer" data-name="${escapeHtml(s)}">${escapeHtml(s)}</div>`
    ).join('');
    supplierDropdown.classList.remove('hidden');
}

function selectSupplier(name) {
    supplierSearch.value = name;
    supplierValue.value  = name;
    supplierDropdown.classList.add('hidden');
    supplierError.classList.add('hidden');
}

supplierSearch.addEventListener('focus', () => renderSupplierDropdown(supplierSearch.value));
supplierSearch.addEventListener('input', () => {
    supplierValue.value = '';
    supplierError.classList.add('hidden');
    renderSupplierDropdown(supplierSearch.value);
});
supplierDropdown.addEventListener('click', (e) => {
    const opt = e.target.closest('[data-name]');
    if (opt) selectSupplier(opt.dataset.name);
});
document.addEventListener('click', (e) => {
    if (!e.target.closest('#supplier_search') && !e.target.closest('#supplier_dropdown')) {
        supplierDropdown.classList.add('hidden');
    }
});

document.getElementById('receiptForm').addEventListener('submit', (e) => {
    const typed = supplierSearch.value.trim();
    const exact = SUPPLIERS.find(s => s.toLowerCase() === typed.toLowerCase());
    if (exact) {
        supplierValue.value = exact;
    } else {
        e.preventDefault();
        supplierError.classList.remove('hidden');
        renderSupplierDropdown(typed);
        supplierSearch.focus();
    }
});

function openNewSupplier() {
    // Pre-fill name from what the user typed
    const typed = supplierSearch.value.trim();
    document.getElementById('ns_name').value    = typed;
    document.getElementById('ns_contact').value = '';
    document.getElementById('ns_phone').value   = '';
    document.getElementById('ns_email').value   = '';
    document.getElementById('ns_error').classList.add('hidden');
    new bootstrap.Modal(document.getElementById('newSupplierModal')).show();
    setTimeout(() => document.getElementById('ns_name').focus(), 300);
}

function saveNewSupplier() {
    const name    = document.getElementById('ns_name').value.trim();
    const contact = document.getElementById('ns_contact').value.trim();
    const phone   = document.getElementById('ns_phone').value.trim();
    const email   = document.getElementById('ns_email').value.trim();
    const errDiv  = document.getElementById('ns_error');

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

    fetch('ajax_add_supplier.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            // Add to selectable list and select it
            SUPPLIERS.push(d.name);
            selectSupplier(d.name);
            // Close modal
            bootstrap.Modal.getInstance(document.getElementById('newSupplierModal')).hide();
        } else {
            errDiv.textContent = d.error || 'Error saving supplier.';
            errDiv.classList.remove('hidden');
        }
    });
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
