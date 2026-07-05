<?php
$page_title      = '업체별 월간 입고 보고서';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id = get_office_store_id();

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

$prev_ts = mktime(0, 0, 0, $month - 1, 1, $year);
$next_ts = mktime(0, 0, 0, $month + 1, 1, $year);
$prev_y  = (int)date('Y', $prev_ts); $prev_m = (int)date('n', $prev_ts);
$next_y  = (int)date('Y', $next_ts); $next_m = (int)date('n', $next_ts);

$conn = get_db_connection();

$all_items = []; // ['date', 'supplier', 'details', 'amount', 'type']

// ── 1) Cash product purchases (Sales Report 방식과 동일) ──────
$stmt = $conn->prepare(
    "SELECT supplier_name, delivery_content AS details, amount, payment_date AS ref_date
     FROM office_product_purchases
     WHERE store_id=? AND payment_type='cash'
       AND YEAR(payment_date)=? AND MONTH(payment_date)=?
     ORDER BY payment_date, id"
);
$stmt->bind_param('iii', $store_id, $year, $month);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $all_items[] = [
        'date'     => $r['ref_date'],
        'supplier' => trim($r['supplier_name']),
        'details'  => $r['details'],
        'amount'   => (float)$r['amount'],
        'type'     => 'cash',
    ];
}
$stmt->close();

// ── 2) Check product purchases ────────────────────────────────
$stmt = $conn->prepare(
    "SELECT supplier_name, delivery_content AS details, amount, check_issued_date AS ref_date
     FROM office_product_purchases
     WHERE store_id=? AND payment_type='check'
       AND YEAR(check_issued_date)=? AND MONTH(check_issued_date)=?
     ORDER BY check_issued_date, id"
);
$stmt->bind_param('iii', $store_id, $year, $month);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $all_items[] = [
        'date'     => $r['ref_date'],
        'supplier' => trim($r['supplier_name']),
        'details'  => $r['details'],
        'amount'   => (float)$r['amount'],
        'type'     => 'check',
    ];
}
$stmt->close();

// ── 3) ER 저장 상태의 r_ 영수증 아이템 (selling/check_sup 섹션만) ──
// Sales Report와 동일: p_/pc_/e_ 는 위 DB 쿼리에서 처리, r_ 만 추가
$er_tbl = $conn->query("SHOW TABLES LIKE 'er_saved_state'");
if ($er_tbl && $er_tbl->num_rows > 0) {
    $first    = sprintf('%04d-%02d-01', $year, $month);
    $last_day = date('Y-m-t', strtotime($first));
    $er_q = $conn->prepare(
        "SELECT save_date, state_json FROM er_saved_state
         WHERE store_id=? AND save_date BETWEEN ? AND ?"
    );
    if ($er_q) {
        $er_q->bind_param('iss', $store_id, $first, $last_day);
        $er_q->execute();
        $er_purchase_secs = ['selling', 'check_sup'];
        $receipt_ids_by_date = []; // date => [id => type(cash|check)]

        foreach ($er_q->get_result()->fetch_all(MYSQLI_ASSOC) as $er_row) {
            $save_date = $er_row['save_date'];
            $state     = json_decode($er_row['state_json'], true);
            if (!$state || !isset($state['sections'])) continue;

            foreach ($state['sections'] as $sec_name => $rows) {
                if (!in_array($sec_name, $er_purchase_secs)) continue;
                $rtype = ($sec_name === 'check_sup') ? 'check' : 'cash';
                foreach ($rows as $row) {
                    $bid = (string)($row['item_id'] ?? '');
                    if (strncmp($bid, 'r_', 2) !== 0) continue;
                    $amt = (float)($row['amount'] ?? 0);
                    if ($amt <= 0) continue;
                    // Sales Report와 동일: JSON에 저장된 amount/supplier/details 직접 사용
                    $all_items[] = [
                        'date'     => $save_date,
                        'supplier' => trim($row['supplier'] ?? ''),
                        'details'  => $row['details'] ?? '',
                        'amount'   => $amt,
                        'type'     => $rtype,
                    ];
                }
            }
        }
        $er_q->close();
    }
}
$conn->close();

// 날짜 오름차순 정렬 후 supplier 그룹핑
usort($all_items, fn($a, $b) => strcmp($a['date'], $b['date']));

$groups      = [];
$grand_total = 0.0;
foreach ($all_items as $item) {
    if ($item['amount'] <= 0) continue;
    $key = mb_strtolower($item['supplier']);
    if (!isset($groups[$key])) {
        $groups[$key] = ['label' => $item['supplier'], 'items' => []];
    }
    $groups[$key]['items'][] = $item;
    $grand_total += $item['amount'];
}
ksort($groups);

$export_url = 'monthly_report_export.php?' . http_build_query(['year' => $year, 'month' => $month]);
?>

<div class="flex items-center justify-between mb-4">
  <h2 class="text-xl font-bold text-gray-800">
    <i class="fa-solid fa-file-invoice-dollar mr-2 text-blue-600"></i>업체별 월간 입고 보고서
  </h2>
  <?php if (!empty($groups)): ?>
  <a href="<?php echo htmlspecialchars($export_url); ?>"
     class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
    <i class="fa-solid fa-file-excel mr-1"></i>Excel 다운로드
  </a>
  <?php endif; ?>
</div>

<!-- 월 네비게이션 -->
<div class="flex items-center justify-center gap-4 mb-6">
  <a href="?year=<?php echo $prev_y; ?>&month=<?php echo $prev_m; ?>"
     class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 text-gray-600">
    <i class="fa-solid fa-chevron-left mr-1"></i><?php echo "{$prev_y}년 {$prev_m}월"; ?>
  </a>
  <span class="text-lg font-bold text-gray-800"><?php echo "{$year}년 {$month}월"; ?></span>
  <a href="?year=<?php echo $next_y; ?>&month=<?php echo $next_m; ?>"
     class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm hover:bg-gray-50 text-gray-600">
    <?php echo "{$next_y}년 {$next_m}월"; ?><i class="fa-solid fa-chevron-right ml-1"></i>
  </a>
</div>

<!-- ER 섹션 안내 + 전체 합계 -->
<div class="mb-4 flex items-center justify-between">
  <div class="flex gap-2 text-xs">
    <span class="px-2.5 py-1 bg-blue-50 border border-blue-200 rounded text-blue-700 font-medium">
      <i class="fa-solid fa-circle mr-1"></i>CASH SELLING – SUPPLIERS
    </span>
    <span class="px-2.5 py-1 bg-amber-50 border border-amber-200 rounded text-amber-700 font-medium">
      <i class="fa-solid fa-circle mr-1"></i>PAY THRU CHECK
    </span>
  </div>
  <?php if (!empty($groups)): ?>
  <div class="text-right">
    <div class="text-xs text-gray-400 mb-0.5"><?php echo "{$year}년 {$month}월"; ?> 전체 합계</div>
    <div class="text-2xl font-bold font-mono text-gray-900"><?php echo number_format($grand_total, 2); ?></div>
  </div>
  <?php endif; ?>
</div>

<?php if (empty($groups)): ?>
<div class="text-center py-16 text-gray-400">
  <i class="fa-solid fa-box-open text-4xl mb-3"></i>
  <p><?php echo "{$year}년 {$month}월"; ?> 입고 데이터가 없습니다.</p>
</div>
<?php else: ?>

<div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
<table class="min-w-full text-xs border-collapse">
  <thead>
    <tr class="bg-gray-100 border-b border-gray-300">
      <th class="px-3 py-2 text-left font-semibold text-gray-600 border-r border-gray-200 w-48">업체명</th>
      <th class="px-3 py-2 text-left font-semibold text-gray-600 border-r border-gray-200 w-28">날짜</th>
      <th class="px-3 py-2 text-left font-semibold text-gray-600 border-r border-gray-200">내용</th>
      <th class="px-3 py-2 text-right font-semibold text-gray-600 border-r border-gray-200 w-36">금액</th>
      <th class="px-3 py-2 text-right font-semibold text-gray-600 w-36">합계금액</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($groups as $group):
    $supplier = $group['label'];
    $items    = $group['items'];
    $subtotal = array_sum(array_column($items, 'amount'));
    $count    = count($items);
  ?>
    <?php foreach ($items as $idx => $item): ?>
    <tr class="border-b border-gray-100 hover:bg-gray-50">
      <?php if ($idx === 0): ?>
      <td class="px-3 py-1.5 border-r border-gray-200 font-medium text-gray-800 align-top"
          rowspan="<?php echo $count; ?>">
        <?php echo htmlspecialchars($supplier); ?>
      </td>
      <?php endif; ?>
      <td class="px-3 py-1.5 border-r border-gray-200 text-gray-500 whitespace-nowrap">
        <?php echo date('m/d (D)', strtotime($item['date'])); ?>
      </td>
      <td class="px-3 py-1.5 border-r border-gray-200 text-gray-700">
        <?php echo htmlspecialchars($item['details']); ?>
      </td>
      <td class="px-3 py-1.5 border-r border-gray-200 text-right font-mono text-gray-700">
        <?php echo number_format($item['amount'], 2); ?>
      </td>
      <?php if ($idx === 0): ?>
      <td class="px-3 py-1.5 text-right font-mono font-bold text-blue-800 align-top"
          rowspan="<?php echo $count; ?>">
        <?php echo number_format($subtotal, 2); ?>
      </td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
  <?php endforeach; ?>
  <!-- 총 합계 -->
  <tr class="bg-gray-800">
    <td class="px-3 py-2 text-white font-bold text-right" colspan="3">
      총 합계 (<?php echo count($all_items); ?>건)
    </td>
    <td class="px-3 py-2"></td>
    <td class="px-3 py-2 text-right font-mono font-bold text-white">
      <?php echo number_format($grand_total, 2); ?>
    </td>
  </tr>
  </tbody>
</table>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
