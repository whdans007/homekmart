<?php
require_once __DIR__ . '/../lib/office_helper.php';

$id       = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$store_id = get_office_store_id();

$conn = get_db_connection();
$stmt = $conn->prepare("SELECT * FROM office_equipment_purchases WHERE id=? AND store_id=?");
$stmt->bind_param('ii', $id, $store_id);
$stmt->execute();
$rec = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if (!$rec) { header('Location: list.php'); exit; }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_office_permission();

    $supplier_name    = post_str('supplier_name');
    $delivery_content = post_str('delivery_content');
    $amount           = post_float('amount');
    $payment_date     = post_date('payment_date');

    if ($supplier_name === '')    $errors[] = '거래처명을 입력하세요.';
    if ($delivery_content === '') $errors[] = '비품 내용을 입력하세요.';
    if ($amount <= 0)             $errors[] = '금액을 올바르게 입력하세요.';
    if (!$payment_date)           $errors[] = '결제일을 입력하세요.';

    if (empty($errors)) {
        $conn = get_db_connection();
        $stmt = $conn->prepare(
            "UPDATE office_equipment_purchases
             SET supplier_name=?, delivery_content=?, amount=?, payment_date=?
             WHERE id=? AND store_id=?"
        );
        $stmt->bind_param('ssdsii', $supplier_name, $delivery_content, $amount, $payment_date, $id, $store_id);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        header('Location: list.php?year=' . date('Y', strtotime($payment_date)) . '&month=' . date('n', strtotime($payment_date)));
        exit;
    }
    $rec = array_merge($rec, compact('supplier_name','delivery_content','amount','payment_date'));
}

$page_title      = '비품구매지출 수정';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';
?>

<div class="max-w-xl mx-auto">
  <div class="flex items-center gap-3 mb-6">
    <a href="list.php" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-arrow-left"></i></a>
    <h2 class="text-xl font-bold text-gray-800">비품구매지출 수정</h2>
  </div>

  <?php if (!empty($errors)): ?>
  <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-4 mb-4 text-sm">
    <?php foreach ($errors as $e): ?><div><?php echo htmlspecialchars($e); ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <form method="POST" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-5">
    <input type="hidden" name="id" value="<?php echo $id; ?>">
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">거래처명 <span class="text-red-500">*</span></label>
      <input type="text" name="supplier_name" value="<?php echo htmlspecialchars($rec['supplier_name']); ?>"
             class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" required>
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">비품 내용 <span class="text-red-500">*</span></label>
      <textarea name="delivery_content" rows="3"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"><?php echo htmlspecialchars($rec['delivery_content']); ?></textarea>
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">금액 <span class="text-red-500">*</span></label>
      <input type="number" name="amount" step="0.01" min="0" value="<?php echo htmlspecialchars($rec['amount']); ?>"
             class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" required>
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">결제일 <span class="text-red-500">*</span></label>
      <input type="date" name="payment_date" value="<?php echo htmlspecialchars($rec['payment_date']); ?>"
             class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" required>
    </div>

    <div class="flex gap-3 pt-2">
      <button type="submit" class="flex-1 bg-orange-600 hover:bg-orange-700 text-white py-2 rounded-lg text-sm font-medium">저장</button>
      <a href="list.php" class="flex-1 text-center bg-gray-100 hover:bg-gray-200 text-gray-700 py-2 rounded-lg text-sm font-medium">취소</a>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
