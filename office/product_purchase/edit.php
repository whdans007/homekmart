<?php
require_once __DIR__ . '/../lib/office_helper.php';

$id       = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$store_id = get_office_store_id();

// 레코드 로드
$conn = get_db_connection();
$stmt = $conn->prepare("SELECT * FROM office_product_purchases WHERE id=? AND store_id=?");
$stmt->bind_param('ii', $id, $store_id);
$stmt->execute();
$rec = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if (!$rec) {
    header('Location: list.php');
    exit;
}

$errors = [];

// POST 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_office_permission();

    $payment_type     = in_array($_POST['payment_type'] ?? '', ['cash','check']) ? $_POST['payment_type'] : 'cash';
    $supplier_name    = post_str('supplier_name');
    $delivery_content = post_str('delivery_content');
    $amount           = post_float('amount');
    $payment_date     = post_date('payment_date');
    $check_issued     = ($payment_type === 'check') ? post_date('check_issued_date') : null;

    if ($supplier_name === '')    $errors[] = 'Supplier name is required.';
    if ($delivery_content === '') $errors[] = 'Description is required.';
    if ($amount <= 0)             $errors[] = 'Please enter a valid amount.';
    if (!$payment_date)           $errors[] = 'Payment date is required.';
    if ($payment_type === 'check' && !$check_issued) $errors[] = 'Check 발행일을 입력하세요.';

    if (empty($errors)) {
        $conn = get_db_connection();
        $stmt = $conn->prepare(
            "UPDATE office_product_purchases
             SET payment_type=?, supplier_name=?, delivery_content=?, amount=?,
                 payment_date=?, check_issued_date=?
             WHERE id=? AND store_id=?"
        );
        $stmt->bind_param('sssdssii', $payment_type, $supplier_name, $delivery_content, $amount, $payment_date, $check_issued, $id, $store_id);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        header('Location: list.php?year=' . date('Y', strtotime($payment_date)) . '&month=' . date('n', strtotime($payment_date)));
        exit;
    }
    // 오류 시 폼 데이터 유지
    $rec = array_merge($rec, [
        'payment_type'     => $payment_type,
        'supplier_name'    => $supplier_name,
        'delivery_content' => $delivery_content,
        'amount'           => $amount,
        'payment_date'     => $payment_date,
        'check_issued_date'=> $check_issued,
    ]);
}

$page_title      = 'Edit Product Purchase';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';
?>

<div class="max-w-xl mx-auto">
  <div class="flex items-center gap-3 mb-6">
    <a href="list.php" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-arrow-left"></i></a>
    <h2 class="text-xl font-bold text-gray-800">Edit Product Purchase</h2>
  </div>

  <?php if (!empty($errors)): ?>
  <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-4 mb-4 text-sm">
    <?php foreach ($errors as $e): ?><div><?php echo htmlspecialchars($e); ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <form method="POST" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-5">
    <input type="hidden" name="id" value="<?php echo $id; ?>">

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-2">Payment Method</label>
      <div class="flex gap-6">
        <label class="flex items-center gap-2 cursor-pointer">
          <input type="radio" name="payment_type" value="cash"
                 <?php echo $rec['payment_type']==='cash' ? 'checked':'' ?> onchange="toggleType()">
          <span class="text-sm">Cash</span>
        </label>
        <label class="flex items-center gap-2 cursor-pointer">
          <input type="radio" name="payment_type" value="check"
                 <?php echo $rec['payment_type']==='check' ? 'checked':'' ?> onchange="toggleType()">
          <span class="text-sm">Check</span>
        </label>
      </div>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Supplier <span class="text-red-500">*</span></label>
      <input type="text" name="supplier_name" value="<?php echo htmlspecialchars($rec['supplier_name']); ?>"
             class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" required>
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Description <span class="text-red-500">*</span></label>
      <textarea name="delivery_content" rows="3"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"><?php echo htmlspecialchars($rec['delivery_content']); ?></textarea>
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Amount <span class="text-red-500">*</span></label>
      <input type="number" name="amount" step="0.01" min="0" value="<?php echo htmlspecialchars($rec['amount']); ?>"
             class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" required>
    </div>

    <div id="section_cash">
      <label class="block text-sm font-medium text-gray-700 mb-1">Payment Date <span class="text-red-500">*</span></label>
      <input type="date" name="payment_date" value="<?php echo htmlspecialchars($rec['payment_date']); ?>"
             class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
    </div>

    <div id="section_check" class="space-y-4 hidden">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Check 결제예정일 <span class="text-red-500">*</span></label>
        <input type="date" name="payment_date" value="<?php echo htmlspecialchars($rec['payment_date']); ?>"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Check 발행일 <span class="text-red-500">*</span></label>
        <input type="date" name="check_issued_date" value="<?php echo htmlspecialchars($rec['check_issued_date'] ?? ''); ?>"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
      </div>
    </div>

    <div class="flex gap-3 pt-2">
      <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg text-sm font-medium">Save</button>
      <a href="list.php" class="flex-1 text-center bg-gray-100 hover:bg-gray-200 text-gray-700 py-2 rounded-lg text-sm font-medium">Cancel</a>
    </div>
  </form>
</div>

<script>
function toggleType() {
    const isCash = document.querySelector('input[name="payment_type"][value="cash"]').checked;
    document.getElementById('section_cash').classList.toggle('hidden', !isCash);
    document.getElementById('section_check').classList.toggle('hidden', isCash);
}
toggleType();
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
