<?php
$page_title   = '상품구매지출';
$css_base     = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id = get_office_store_id();
$year     = (int)($_GET['year']  ?? date('Y'));
$month    = (int)($_GET['month'] ?? date('n'));
$type_f   = $_GET['type'] ?? 'all';

$conn  = get_db_connection();
$where = "WHERE store_id=? AND YEAR(payment_date)=? AND MONTH(payment_date)=?";
$bind  = [$store_id, $year, $month];
$types = 'iii';
if (in_array($type_f, ['cash','check'])) {
    $where .= " AND payment_type=?";
    $bind[] = $type_f;
    $types .= 's';
}
$stmt = $conn->prepare("SELECT * FROM office_product_purchases $where ORDER BY payment_date DESC, id DESC");
$stmt->bind_param($types, ...$bind);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total_cash = $total_check = 0.0;
foreach ($rows as $r) {
    if ($r['payment_type'] === 'cash') $total_cash += $r['amount'];
    else $total_check += $r['amount'];
}
$conn->close();

// 월 선택 옵션 (최근 12개월)
$month_options = [];
for ($i = 0; $i < 12; $i++) {
    $ts = mktime(0, 0, 0, date('n') - $i, 1, date('Y'));
    $month_options[] = ['y' => date('Y', $ts), 'm' => (int)date('n', $ts), 'label' => date('Y년 n월', $ts)];
}
?>

<div class="flex items-center justify-between mb-6">
  <h2 class="text-xl font-bold text-gray-800"><i class="fa-solid fa-cart-shopping mr-2 text-blue-600"></i>상품구매지출</h2>
  <a href="add.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
    <i class="fa-solid fa-plus mr-1"></i>등록
  </a>
</div>

<!-- 필터 -->
<form method="GET" class="flex flex-wrap gap-3 mb-6 bg-white p-4 rounded-xl shadow-sm border border-gray-100">
  <select name="year" class="border border-gray-300 rounded-lg px-3 py-2 text-sm" onchange="this.form.submit()">
    <?php foreach ($month_options as $opt): ?>
    <option value="<?php echo $opt['y']; ?>" data-m="<?php echo $opt['m']; ?>"
      <?php echo ($opt['y']==$year && $opt['m']==$month) ? 'selected' : ''; ?>>
      <?php echo htmlspecialchars($opt['label']); ?>
    </option>
    <?php endforeach; ?>
  </select>
  <input type="hidden" name="month" id="month_hidden" value="<?php echo $month; ?>">
  <select name="type" class="border border-gray-300 rounded-lg px-3 py-2 text-sm" onchange="this.form.submit()">
    <option value="all"   <?php echo $type_f==='all'   ? 'selected':'' ?>>전체</option>
    <option value="cash"  <?php echo $type_f==='cash'  ? 'selected':'' ?>>현금</option>
    <option value="check" <?php echo $type_f==='check' ? 'selected':'' ?>>수표</option>
  </select>
</form>

<!-- 합계 카드 -->
<div class="grid grid-cols-3 gap-4 mb-6">
  <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-100">
    <div class="text-xs text-gray-500 mb-1">현금 합계</div>
    <div class="text-lg font-bold text-green-700"><?php echo format_amount($total_cash); ?></div>
  </div>
  <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-100">
    <div class="text-xs text-gray-500 mb-1">수표 합계</div>
    <div class="text-lg font-bold text-purple-700"><?php echo format_amount($total_check); ?></div>
  </div>
  <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-100">
    <div class="text-xs text-gray-500 mb-1">총 합계</div>
    <div class="text-lg font-bold text-gray-800"><?php echo format_amount($total_cash + $total_check); ?></div>
  </div>
</div>

<!-- 목록 테이블 -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
  <table class="w-full text-sm">
    <thead class="bg-gray-50 border-b border-gray-200">
      <tr>
        <th class="px-4 py-3 text-left text-gray-600">No</th>
        <th class="px-4 py-3 text-left text-gray-600">거래처명</th>
        <th class="px-4 py-3 text-left text-gray-600">배달상품 내용</th>
        <th class="px-4 py-3 text-right text-gray-600">금액</th>
        <th class="px-4 py-3 text-center text-gray-600">결제방식</th>
        <th class="px-4 py-3 text-center text-gray-600">결제일/예정일</th>
        <th class="px-4 py-3 text-center text-gray-600">수표발행일</th>
        <th class="px-4 py-3 text-center text-gray-600">작업</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (empty($rows)): ?>
      <tr><td colspan="8" class="px-4 py-8 text-center text-gray-400">등록된 내역이 없습니다.</td></tr>
      <?php else: ?>
      <?php foreach ($rows as $i => $r): ?>
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-3 text-gray-500"><?php echo $i + 1; ?></td>
        <td class="px-4 py-3 font-medium text-gray-800"><?php echo htmlspecialchars($r['supplier_name']); ?></td>
        <td class="px-4 py-3 text-gray-600 max-w-xs truncate"><?php echo htmlspecialchars($r['delivery_content']); ?></td>
        <td class="px-4 py-3 text-right font-mono text-gray-800"><?php echo format_amount((float)$r['amount']); ?></td>
        <td class="px-4 py-3 text-center">
          <?php if ($r['payment_type']==='cash'): ?>
          <span class="bg-green-100 text-green-700 px-2 py-1 rounded-full text-xs font-medium">현금</span>
          <?php else: ?>
          <span class="bg-purple-100 text-purple-700 px-2 py-1 rounded-full text-xs font-medium">수표</span>
          <?php endif; ?>
        </td>
        <td class="px-4 py-3 text-center text-gray-600"><?php echo htmlspecialchars($r['payment_date']); ?></td>
        <td class="px-4 py-3 text-center text-gray-600"><?php echo $r['check_issued_date'] ? htmlspecialchars($r['check_issued_date']) : '—'; ?></td>
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
// 연월 선택 연동
document.querySelector('select[name="year"]').addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    document.getElementById('month_hidden').value = opt.dataset.m;
});
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
