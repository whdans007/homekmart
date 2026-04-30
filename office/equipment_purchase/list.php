<?php
$page_title      = '비품구매지출';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id = get_office_store_id();
$year     = (int)($_GET['year']  ?? date('Y'));
$month    = (int)($_GET['month'] ?? date('n'));

$conn = get_db_connection();
$stmt = $conn->prepare(
    "SELECT * FROM office_equipment_purchases
     WHERE store_id=? AND YEAR(payment_date)=? AND MONTH(payment_date)=?
     ORDER BY payment_date DESC, id DESC"
);
$stmt->bind_param('iii', $store_id, $year, $month);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

$total = array_sum(array_column($rows, 'amount'));

$month_options = [];
for ($i = 0; $i < 12; $i++) {
    $ts = mktime(0, 0, 0, date('n') - $i, 1, date('Y'));
    $month_options[] = ['y' => date('Y', $ts), 'm' => (int)date('n', $ts), 'label' => date('Y년 n월', $ts)];
}
?>

<div class="flex items-center justify-between mb-6">
  <h2 class="text-xl font-bold text-gray-800"><i class="fa-solid fa-box mr-2 text-orange-600"></i>비품구매지출</h2>
  <a href="add.php" class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
    <i class="fa-solid fa-plus mr-1"></i>등록
  </a>
</div>

<!-- 월 필터 -->
<form method="GET" class="flex gap-3 mb-6 bg-white p-4 rounded-xl shadow-sm border border-gray-100">
  <select name="year" class="border border-gray-300 rounded-lg px-3 py-2 text-sm" onchange="this.form.submit()">
    <?php foreach ($month_options as $opt): ?>
    <option value="<?php echo $opt['y']; ?>" data-m="<?php echo $opt['m']; ?>"
      <?php echo ($opt['y']==$year && $opt['m']==$month) ? 'selected' : ''; ?>>
      <?php echo htmlspecialchars($opt['label']); ?>
    </option>
    <?php endforeach; ?>
  </select>
  <input type="hidden" name="month" id="month_hidden" value="<?php echo $month; ?>">
</form>

<!-- 합계 카드 -->
<div class="grid grid-cols-1 gap-4 mb-6 max-w-xs">
  <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-100">
    <div class="text-xs text-gray-500 mb-1">당월 합계</div>
    <div class="text-lg font-bold text-orange-700"><?php echo format_amount($total); ?></div>
  </div>
</div>

<!-- 목록 -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
  <table class="w-full text-sm">
    <thead class="bg-gray-50 border-b border-gray-200">
      <tr>
        <th class="px-4 py-3 text-left text-gray-600">No</th>
        <th class="px-4 py-3 text-left text-gray-600">거래처명</th>
        <th class="px-4 py-3 text-left text-gray-600">비품 내용</th>
        <th class="px-4 py-3 text-right text-gray-600">금액</th>
        <th class="px-4 py-3 text-center text-gray-600">결제일</th>
        <th class="px-4 py-3 text-center text-gray-600">작업</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (empty($rows)): ?>
      <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">등록된 내역이 없습니다.</td></tr>
      <?php else: ?>
      <?php foreach ($rows as $i => $r): ?>
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-3 text-gray-500"><?php echo $i + 1; ?></td>
        <td class="px-4 py-3 font-medium text-gray-800"><?php echo htmlspecialchars($r['supplier_name']); ?></td>
        <td class="px-4 py-3 text-gray-600 max-w-xs truncate"><?php echo htmlspecialchars($r['delivery_content']); ?></td>
        <td class="px-4 py-3 text-right font-mono text-gray-800"><?php echo format_amount((float)$r['amount']); ?></td>
        <td class="px-4 py-3 text-center text-gray-600"><?php echo htmlspecialchars($r['payment_date']); ?></td>
        <td class="px-4 py-3 text-center flex gap-2 justify-center">
          <a href="edit.php?id=<?php echo $r['id']; ?>" class="text-blue-600 hover:underline text-xs">수정</a>
          <form method="POST" action="delete.php" onsubmit="return confirm('삭제하시겠습니까?')">
            <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
            <input type="hidden" name="year" value="<?php echo $year; ?>">
            <input type="hidden" name="month" value="<?php echo $month; ?>">
            <button type="submit" class="text-red-500 hover:underline text-xs">삭제</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
document.querySelector('select[name="year"]').addEventListener('change', function() {
    document.getElementById('month_hidden').value = this.options[this.selectedIndex].dataset.m;
});
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
