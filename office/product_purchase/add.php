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
    if ($supplier_name === '')    $errors[] = '거래처명을 입력하세요.';
    if ($delivery_content === '') $errors[] = '배달상품 내용을 입력하세요.';
    if ($amount <= 0)             $errors[] = '금액을 올바르게 입력하세요.';
    if (!$payment_date)           $errors[] = '결제일을 입력하세요.';
    if ($payment_type === 'check' && !$check_issued) $errors[] = '수표 발행일을 입력하세요.';

    if (empty($errors)) {
        $conn = get_db_connection();
        $stmt = $conn->prepare(
            "INSERT INTO office_product_purchases
             (store_id, payment_type, supplier_name, delivery_content, amount, payment_date, check_issued_date, created_by)
             VALUES (?,?,?,?,?,?,?,?)"
        );
        $stmt->bind_param('isssdssi', $store_id, $payment_type, $supplier_name, $delivery_content, $amount, $payment_date, $check_issued, $created_by);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        header('Location: list.php?year=' . date('Y', strtotime($payment_date)) . '&month=' . date('n', strtotime($payment_date)));
        exit;
    }
}

$page_title      = '상품구매지출 등록';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$sel_type = $_POST['payment_type'] ?? 'cash';
?>

<div class="max-w-xl mx-auto">
  <div class="flex items-center gap-3 mb-6">
    <a href="list.php" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-arrow-left"></i></a>
    <h2 class="text-xl font-bold text-gray-800">상품구매지출 등록</h2>
  </div>

  <?php if (!empty($errors)): ?>
  <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-4 mb-4 text-sm">
    <?php foreach ($errors as $e): ?><div><?php echo htmlspecialchars($e); ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <form method="POST" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-5">

    <!-- 결제방식 라디오 -->
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-2">결제방식</label>
      <div class="flex gap-6">
        <label class="flex items-center gap-2 cursor-pointer">
          <input type="radio" name="payment_type" value="cash"  id="type_cash"
                 <?php echo $sel_type==='cash' ? 'checked':'' ?> onchange="toggleType()">
          <span class="text-sm text-gray-700">현금</span>
        </label>
        <label class="flex items-center gap-2 cursor-pointer">
          <input type="radio" name="payment_type" value="check" id="type_check"
                 <?php echo $sel_type==='check'? 'checked':'' ?> onchange="toggleType()">
          <span class="text-sm text-gray-700">수표</span>
        </label>
      </div>
    </div>

    <!-- 공통 필드 -->
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">거래처명 <span class="text-red-500">*</span></label>
      <input type="text" name="supplier_name" value="<?php echo htmlspecialchars($_POST['supplier_name'] ?? ''); ?>"
             class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">배달상품 내용 <span class="text-red-500">*</span></label>
      <textarea name="delivery_content" rows="3"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required><?php echo htmlspecialchars($_POST['delivery_content'] ?? ''); ?></textarea>
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">금액 <span class="text-red-500">*</span></label>
      <input type="number" name="amount" step="0.01" min="0" value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>"
             class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" required>
    </div>

    <!-- 현금 섹션 -->
    <div id="section_cash">
      <label class="block text-sm font-medium text-gray-700 mb-1">결제일 <span class="text-red-500">*</span></label>
      <input type="date" name="payment_date" value="<?php echo htmlspecialchars($_POST['payment_date'] ?? date('Y-m-d')); ?>"
             class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
    </div>

    <!-- 수표 섹션 -->
    <div id="section_check" class="space-y-4 hidden">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">수표 결제예정일 <span class="text-red-500">*</span></label>
        <input type="date" name="payment_date" id="check_payment_date"
               value="<?php echo htmlspecialchars($_POST['payment_date'] ?? date('Y-m-d')); ?>"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">수표 발행일 <span class="text-red-500">*</span></label>
        <input type="date" name="check_issued_date" value="<?php echo htmlspecialchars($_POST['check_issued_date'] ?? date('Y-m-d')); ?>"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
      </div>
    </div>

    <div class="flex gap-3 pt-2">
      <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg text-sm font-medium">저장</button>
      <a href="list.php" class="flex-1 text-center bg-gray-100 hover:bg-gray-200 text-gray-700 py-2 rounded-lg text-sm font-medium">취소</a>
    </div>
  </form>
</div>

<script>
function toggleType() {
    const isCash = document.getElementById('type_cash').checked;
    document.getElementById('section_cash').classList.toggle('hidden', !isCash);
    document.getElementById('section_check').classList.toggle('hidden', isCash);
}
toggleType();
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
