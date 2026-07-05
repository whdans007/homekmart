<?php
// Design Ref: §6.3 — Edit Receipt (등록됨 상태는 수정 불가)
require_once __DIR__ . '/../lib/office_helper.php';
require_office_permission();

$store_id = get_office_store_id();
$id       = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if (!$id) { header('Location: list.php'); exit; }

// 기존 데이터 조회
$conn = get_db_connection();
$stmt = $conn->prepare(
    "SELECT * FROM office_receipts WHERE id=? AND store_id=?"
);
$stmt->bind_param('ii', $id, $store_id);
$stmt->execute();
$receipt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$receipt) { $conn->close(); header('Location: list.php'); exit; }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 관리자 강제 연결 해제
    if (isset($_POST['action']) && $_POST['action'] === 'unlink') {
        $role = $_SESSION['role'] ?? '';
        if (in_array($role, ['super_admin', 'admin'])) {
            $conn2 = get_db_connection();
            $su = $conn2->prepare(
                "UPDATE office_receipts
                 SET linked_purchase_type=NULL, linked_purchase_id=NULL
                 WHERE id=? AND store_id=?"
            );
            $su->bind_param('ii', $id, $store_id);
            $su->execute();
            $su->close();
            $conn2->close();
        }
        header('Location: edit.php?id=' . $id);
        exit;
    }

    // 등록됨 상태는 수정 불가
    if ($receipt['linked_purchase_id'] !== null) {
        $errors[] = 'This receipt is linked to an expense. Please delete the expense first.';
    } else {
        $supplier_name = post_str('supplier_name');
        $description   = post_str('description');
        $amount        = post_float('amount');
        $receipt_date  = post_date('receipt_date');
        $notes         = post_str('notes');
        $cv_no         = post_str('cv_no');
        $payment_type  = in_array($_POST['payment_type'] ?? '', ['cash','check']) ? $_POST['payment_type'] : 'cash';

        if ($supplier_name === '') $errors[] = 'Supplier name is required.';
        if ($amount <= 0)          $errors[] = 'Please enter a valid amount.';
        if (!$receipt_date)        $errors[] = 'Date is required.';

        // 새 파일 업로드
        $file_path = $receipt['file_path'];
        $file_original = $receipt['file_original_name'];
        $file_mime = $receipt['file_mime'];

        if (!empty($_FILES['receipt_file']['name'])) {
            $allowed_mime = ['image/jpeg', 'image/png', 'application/pdf'];
            $tmp  = $_FILES['receipt_file']['tmp_name'];
            $orig = $_FILES['receipt_file']['name'];
            $size = $_FILES['receipt_file']['size'];

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
                // 기존 파일 삭제
                if ($receipt['file_path']) {
                    $old = __DIR__ . '/../../' . ltrim($receipt['file_path'], '/');
                    if (is_file($old)) unlink($old);
                }
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
                    $errors[] = 'Failed to save the file.';
                }
            }
        }

        if (empty($errors)) {
            // Design Ref: §8.2 — payment_type 수정 가능
            $has_pt = (bool)$conn->query("SHOW COLUMNS FROM office_receipts LIKE 'payment_type'")->num_rows;
            $pt_clause = $has_pt ? ', payment_type=?' : '';
            $stmt2 = $conn->prepare(
                "UPDATE office_receipts
                 SET supplier_name=?, description=?, amount=?, receipt_date=?,
                     file_path=?, file_original_name=?, file_mime=?, notes=?, cv_no=?{$pt_clause}
                 WHERE id=? AND store_id=?"
            );
            if ($has_pt) {
                $stmt2->bind_param('ssdsssssssii',
                    $supplier_name, $description, $amount, $receipt_date,
                    $file_path, $file_original, $file_mime, $notes, $cv_no, $payment_type,
                    $id, $store_id
                );
            } else {
                $stmt2->bind_param('ssdssssssii',
                    $supplier_name, $description, $amount, $receipt_date,
                    $file_path, $file_original, $file_mime, $notes, $cv_no,
                    $id, $store_id
                );
            }
            $stmt2->execute();
            $stmt2->close();
            $conn->close();
            header('Location: list.php');
            exit;
        }
    }
    // 에러 시 폼 재표시용 갱신
    $receipt['supplier_name'] = $_POST['supplier_name'] ?? $receipt['supplier_name'];
    $receipt['description']   = $_POST['description']   ?? $receipt['description'];
    $receipt['amount']        = $_POST['amount']         ?? $receipt['amount'];
    $receipt['receipt_date']  = $_POST['receipt_date']   ?? $receipt['receipt_date'];
    $receipt['notes']         = $_POST['notes']          ?? $receipt['notes'];
}
$conn->close();

$page_title      = 'Edit Receipt';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$suppliers = [];
try {
    $c    = get_db_connection();
    $res  = $c->query("SELECT name FROM suppliers ORDER BY name");
    $suppliers = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $c->close();
} catch (Exception $e) {}

$is_linked    = $receipt['linked_purchase_id'] !== null;
$payment_type = $receipt['payment_type'] ?? 'cash';
?>

<div class="max-w-xl mx-auto">
  <div class="flex items-center gap-3 mb-6">
    <a href="list.php" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-arrow-left"></i></a>
    <h2 class="text-xl font-bold text-gray-800"><i class="fa-solid fa-receipt mr-2 text-indigo-600"></i>Edit Receipt</h2>
  </div>

  <?php if ($is_linked): ?>
  <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-lg p-4 mb-4 text-sm">
    <div class="flex items-center justify-between gap-3">
      <div>
        <i class="fa-solid fa-triangle-exclamation mr-1"></i>
        This receipt is linked to <?php echo $receipt['linked_purchase_type'] === 'product' ? 'Product Purchase' : 'Equipment Purchase'; ?> and cannot be edited.
      </div>
      <?php if (in_array($_SESSION['role'] ?? '', ['super_admin', 'admin'])): ?>
      <form method="POST" onsubmit="return confirm('Unlinking will change this receipt to unused status. Are you sure?')">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="action" value="unlink">
        <button type="submit" class="whitespace-nowrap bg-yellow-600 hover:bg-yellow-700 text-white text-xs font-medium px-3 py-1.5 rounded-lg">
          <i class="fa-solid fa-link-slash mr-1"></i>Unlink (관리자)
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
  <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-4 mb-4 text-sm">
    <?php foreach ($errors as $e): ?><div><?php echo htmlspecialchars($e); ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <form method="POST" enctype="multipart/form-data" id="receiptForm"
        class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-5 <?php echo $is_linked ? 'opacity-60 pointer-events-none' : ''; ?>">
    <input type="hidden" name="id" value="<?php echo $id; ?>">

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Supplier <span class="text-red-500">*</span></label>
      <div class="relative">
        <input type="text" id="supplier_search" autocomplete="off"
               value="<?php echo htmlspecialchars($receipt['supplier_name']); ?>"
               placeholder="Search supplier name"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500" required>
        <input type="hidden" name="supplier_name" id="supplier_name"
               value="<?php echo htmlspecialchars($receipt['supplier_name']); ?>">
        <div id="supplier_dropdown"
             class="hidden absolute z-10 mt-1 w-full bg-white border border-gray-200 rounded-lg shadow-lg max-h-48 overflow-y-auto"></div>
      </div>
      <p class="text-xs text-gray-400 mt-1">Select an existing supplier from the list.</p>
      <p id="supplier_error" class="hidden text-xs text-red-500 mt-1">
        <i class="fa-solid fa-circle-exclamation mr-1"></i>Please select a supplier from the list.
      </p>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
      <textarea name="description" rows="2"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500"><?php echo htmlspecialchars($receipt['description'] ?? ''); ?></textarea>
    </div>

    <!-- Design Ref: §8.2 — payment_type 수정 -->
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-2">Payment Type</label>
      <div class="flex gap-5">
        <label class="flex items-center gap-2 cursor-pointer">
          <input type="radio" name="payment_type" value="cash"
                 <?php echo ($payment_type === 'cash') ? 'checked' : ''; ?>
                 class="text-indigo-600 focus:ring-indigo-500">
          <span class="text-sm text-gray-700">Cash</span>
        </label>
        <label class="flex items-center gap-2 cursor-pointer">
          <input type="radio" name="payment_type" value="check"
                 <?php echo ($payment_type === 'check') ? 'checked' : ''; ?>
                 class="text-indigo-600 focus:ring-indigo-500">
          <span class="text-sm text-gray-700">Cheque</span>
        </label>
      </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Amount <span class="text-red-500">*</span></label>
        <input type="number" name="amount" step="0.01" min="0"
               value="<?php echo htmlspecialchars($receipt['amount']); ?>"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500" required>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Date <span class="text-red-500">*</span></label>
        <input type="date" name="receipt_date"
               value="<?php echo htmlspecialchars($receipt['receipt_date']); ?>"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500" required>
      </div>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">CV No. <span class="text-xs text-gray-400">(reference number, optional)</span></label>
      <input type="text" name="cv_no" value="<?php echo htmlspecialchars($receipt['cv_no'] ?? ''); ?>"
             placeholder="e.g. INV-2026-001, DR-12345"
             class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500">
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
      <input type="text" name="notes" value="<?php echo htmlspecialchars($receipt['notes'] ?? ''); ?>"
             class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500">
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Replace Attachment
        <span class="text-xs text-gray-400">(Leave blank to keep existing file)</span>
      </label>
      <?php if ($receipt['file_path']): ?>
      <div class="mb-2 text-xs text-gray-500">
        Current file: <a href="view.php?id=<?php echo $id; ?>" target="_blank" class="text-indigo-600 hover:underline">
          <?php echo htmlspecialchars($receipt['file_original_name'] ?? 'Attachment'); ?>
        </a>
      </div>
      <?php endif; ?>
      <input type="file" name="receipt_file" accept="image/jpeg,image/png,application/pdf"
             class="w-full text-sm text-gray-600 file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-sm file:font-medium file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
    </div>

    <?php if (!$is_linked): ?>
    <div class="flex gap-3 pt-2">
      <button type="submit" class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white py-2 rounded-lg text-sm font-medium">Save</button>
      <a href="list.php" class="flex-1 text-center bg-gray-100 hover:bg-gray-200 text-gray-700 py-2 rounded-lg text-sm font-medium">Cancel</a>
    </div>
    <?php else: ?>
    <div class="pt-2">
      <a href="list.php" class="block text-center bg-gray-100 hover:bg-gray-200 text-gray-700 py-2 rounded-lg text-sm font-medium">Back to List</a>
    </div>
    <?php endif; ?>
  </form>
</div>

<script>
// Plan SC3 — 등록된 거래처만 선택 가능한 검색형 드롭다운 (오타로 인한 잘못된 업체명 방지)
const SUPPLIERS = <?php echo json_encode(array_column($suppliers, 'name'), JSON_UNESCAPED_UNICODE); ?>;
// 기존 데이터에 등록되지 않은 거래처명이 있더라도 그대로 유지할 수 있도록 추가
const CURRENT_SUPPLIER = <?php echo json_encode($receipt['supplier_name'], JSON_UNESCAPED_UNICODE); ?>;
if (CURRENT_SUPPLIER && !SUPPLIERS.some(s => s.toLowerCase() === CURRENT_SUPPLIER.toLowerCase())) {
    SUPPLIERS.push(CURRENT_SUPPLIER);
}

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
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
