<?php
$page_title      = '월마감 REPORT';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';
require_once __DIR__ . '/../lib/monthly_closing_helper.php';

$store_id = get_office_store_id();

$default_ts = strtotime('first day of last month');
$year  = (int)($_GET['year']  ?? date('Y', $default_ts));
$month = (int)($_GET['month'] ?? date('n', $default_ts));

$prev_ts = mktime(0, 0, 0, $month - 1, 1, $year);
$next_ts = mktime(0, 0, 0, $month + 1, 1, $year);
$prev_y  = (int)date('Y', $prev_ts); $prev_m = (int)date('n', $prev_ts);
$next_y  = (int)date('Y', $next_ts); $next_m = (int)date('n', $next_ts);

$report = get_monthly_closing_report($store_id, $year, $month);

$korean_salary            = $report['korean_salary'];
$monthly_rent             = $report['monthly_rent'];
$items                    = $report['items'];
$total_sales              = $report['total_sales'];
$total_expense            = $report['total_expense'];
$total_purchase_with_transfer = $report['total_purchase_with_transfer'];
$total_wholesale_sales    = $report['total_wholesale_sales'];
$total_retail_sales       = $report['total_retail_sales'];
$commission_tbl_ready     = $report['commission_tbl_ready'];
$commission_rows          = $report['commission_rows'];
$commission_sales_total   = $report['commission_sales_total'];
$commission_fee_total     = $report['commission_fee_total'];
$commission_tax_total     = $report['commission_tax_total'];
$commission_payout_total  = $report['commission_payout_total'];
?>

<div class="flex items-center justify-between mb-4">
  <h2 class="text-xl font-bold text-gray-800">
    <i class="fa-solid fa-flag-checkered mr-2 text-gray-700"></i>월마감 REPORT
  </h2>
  <a href="export_monthly_closing.php?year=<?php echo $year; ?>&month=<?php echo $month; ?>"
     class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium">
    <i class="fa-solid fa-file-excel mr-1"></i>엑셀 다운로드
  </a>
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

<!-- 항목 리스트 (총 매출 / 지출 항목들 / 총 지출) -->
<div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden max-w-2xl mx-auto">
  <!-- 총 매출 -->
  <div class="flex items-center justify-between px-6 py-4 bg-blue-50 border-b border-gray-200">
    <span class="text-base font-bold text-blue-800">총 매출</span>
    <span class="font-mono font-bold text-blue-800 text-lg"><?php echo number_format($total_sales, 2); ?></span>
  </div>

  <?php foreach ($items as $item): ?>
  <div class="flex items-center justify-between gap-4 px-6 py-3 border-b border-gray-100 bg-orange-50">
    <span class="text-sm font-medium text-gray-700"><?php echo htmlspecialchars($item['label']); ?></span>
    <?php if ($item['input']): ?>
    <input type="number" step="0.01" min="0"
           id="inp_<?php echo $item['input']; ?>"
           value="<?php echo $item['amount'] > 0 ? $item['amount'] : ''; ?>"
           placeholder="0.00"
           class="w-36 text-right border border-gray-300 rounded-lg px-3 py-1.5 text-sm font-mono focus:ring-2 focus:ring-blue-400"
           onchange="markChanged()">
    <?php else: ?>
    <span class="font-mono font-bold text-gray-800 text-base">
      <?php echo $item['amount'] > 0 ? number_format($item['amount'], 2) : '<span class="text-gray-300 font-normal text-sm">—</span>'; ?>
    </span>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  <!-- 저장 버튼 -->
  <div class="flex items-center justify-end gap-3 px-6 py-3 bg-gray-50 border-b border-gray-200">
    <span id="save_status" class="text-xs text-gray-400"></span>
    <button id="btn_save" onclick="saveFixed()" disabled
            class="px-4 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium disabled:opacity-40 disabled:cursor-not-allowed">
      <i class="fa-solid fa-floppy-disk mr-1"></i>저장
    </button>
  </div>

  <!-- 총 지출 -->
  <div class="flex items-center justify-between px-6 py-5 bg-yellow-300">
    <span class="text-base font-bold text-gray-800">총 지출</span>
    <span id="grand_total_display" class="font-mono font-bold text-gray-900 text-xl"><?php echo number_format($total_expense, 2); ?></span>
  </div>
</div>

<!-- 수수료 매장 -->
<div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden max-w-3xl mx-auto mt-8">
  <div class="px-6 py-3 bg-gray-50 border-b border-gray-200">
    <h3 class="text-sm font-bold text-gray-700"><i class="fa-solid fa-store mr-2 text-gray-500"></i>수수료 매장</h3>
  </div>
  <?php if (!$commission_tbl_ready): ?>
  <div class="px-6 py-4 text-xs text-amber-800 bg-amber-50">
    <i class="fa-solid fa-triangle-exclamation mr-1"></i>수수료 코너 테이블이 아직 없습니다. Daily Report 화면에서 먼저 마이그레이션을 실행하세요.
  </div>
  <?php elseif (empty($commission_rows)): ?>
  <div class="px-6 py-6 text-sm text-gray-400 text-center">
    등록된 수수료 매장이 없습니다. <a href="../daily_report/index.php" class="text-blue-600 hover:underline">Daily Report</a> 화면에서 업체를 등록하세요.
  </div>
  <?php else: ?>
  <div class="overflow-x-auto">
  <table class="w-full text-sm">
    <thead>
      <tr class="bg-gray-50 text-gray-500 text-xs">
        <th class="px-4 py-2 text-left">상호명</th>
        <th class="px-3 py-2 text-right w-20">%</th>
        <th class="px-4 py-2 text-right">총매출</th>
        <th class="px-4 py-2 text-right">수수료</th>
        <th class="px-3 py-2 text-right w-28">세금(12%)</th>
        <th class="px-4 py-2 text-right">지급액</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($commission_rows as $crow): ?>
      <tr class="commission-row border-t border-gray-100" data-id="<?php echo $crow['id']; ?>" data-amount="<?php echo $crow['amount']; ?>">
        <td class="px-4 py-2 text-gray-700"><?php echo htmlspecialchars($crow['supplier_name']); ?></td>
        <td class="px-3 py-2 text-right">
          <input type="number" step="0.1" min="0" max="100" id="crate_<?php echo $crow['id']; ?>"
                 value="<?php echo $crow['rate'] > 0 ? $crow['rate'] : ''; ?>" placeholder="0.0"
                 class="w-16 text-right border border-gray-300 rounded px-1.5 py-1 text-xs font-mono"
                 onchange="saveCommissionRate(<?php echo $crow['id']; ?>, <?php echo $crow['amount']; ?>)">
        </td>
        <td class="px-4 py-2 text-right font-mono text-gray-800"><?php echo number_format($crow['amount'], 2); ?></td>
        <td class="px-4 py-2 text-right font-mono text-gray-600" id="ccommission_<?php echo $crow['id']; ?>"><?php echo number_format($crow['commission'], 2); ?></td>
        <td class="px-3 py-2 text-right">
          <input type="number" step="0.01" min="0" id="ctax_<?php echo $crow['id']; ?>"
                 value="<?php echo $crow['tax'] > 0 ? $crow['tax'] : ''; ?>" placeholder="0.00"
                 class="w-20 text-right border border-gray-300 rounded px-1.5 py-1 text-xs font-mono"
                 onchange="saveCommissionTax(<?php echo $crow['id']; ?>, <?php echo $crow['amount']; ?>)">
        </td>
        <td class="px-4 py-2 text-right font-mono font-bold text-gray-800" id="cpayout_<?php echo $crow['id']; ?>"><?php echo number_format($crow['payout'], 2); ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="border-t-2 border-gray-200 bg-orange-50 font-bold text-sm">
        <td class="px-4 py-2" colspan="2">합계</td>
        <td class="px-4 py-2 text-right font-mono" id="commission_sales_total"><?php echo number_format($commission_sales_total, 2); ?></td>
        <td class="px-4 py-2 text-right font-mono" id="commission_fee_total"><?php echo number_format($commission_fee_total, 2); ?></td>
        <td class="px-3 py-2 text-right font-mono text-gray-400" id="commission_tax_total"><?php echo $commission_tax_total > 0 ? number_format($commission_tax_total, 2) : '-'; ?></td>
        <td class="px-4 py-2 text-right font-mono text-red-600" id="commission_payout_total"><?php echo number_format($commission_payout_total, 2); ?></td>
      </tr>
    </tfoot>
  </table>
  </div>
  <p class="px-4 py-2 text-[11px] text-gray-400 border-t border-gray-100">
    %(수수료율)는 업체 등록 시 고정되는 값입니다. 업체 추가/삭제는 <a href="../daily_report/index.php" class="text-blue-500 hover:underline">Daily Report</a> 화면에서 관리하세요.
  </p>
  <?php endif; ?>
</div>

<!-- 요약 -->
<div class="max-w-2xl mx-auto mt-8 border border-gray-300 rounded-xl overflow-hidden">
  <div class="grid grid-cols-2 text-sm">
    <div class="px-4 py-2.5 font-bold text-gray-700 border-b border-r border-gray-300 bg-gray-50">총 매출</div>
    <div class="px-4 py-2.5 text-right font-mono font-bold border-b border-gray-300"><?php echo number_format($total_sales, 2); ?></div>

    <div class="px-4 py-2.5 text-gray-600 border-b border-r border-gray-300 bg-gray-50">수수료매장 매출</div>
    <div class="px-4 py-2.5 text-right font-mono border-b border-gray-300" id="summary_commission_sales"><?php echo number_format($commission_sales_total, 2); ?></div>

    <div class="px-4 py-2.5 text-gray-600 border-b border-r border-gray-300 bg-gray-50">총 도매 + DELIVERY K 매출</div>
    <div class="px-4 py-2.5 text-right font-mono border-b border-gray-300"><?php echo number_format($total_wholesale_sales, 2); ?></div>

    <div class="px-4 py-2.5 text-gray-600 border-b border-r border-gray-300 bg-gray-50">총 소매매출</div>
    <div class="px-4 py-2.5 text-right font-mono border-b border-gray-300"><?php echo number_format($total_retail_sales, 2); ?></div>

    <div class="px-4 py-2.5 text-gray-600 border-r border-gray-300 bg-gray-50">총 구매지출</div>
    <div class="px-4 py-2.5 text-right font-mono"><?php echo number_format($total_purchase_with_transfer, 2); ?></div>
  </div>
</div>

<script>
const YEAR  = <?php echo $year; ?>;
const MONTH = <?php echo $month; ?>;
const AUTO_TOTAL = <?php echo $total_expense - $korean_salary - $monthly_rent; ?>; // 자동 계산 항목 합계

function fmt2(n) {
    return n.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function markChanged() {
    document.getElementById('btn_save').disabled = false;
    document.getElementById('save_status').textContent = '저장되지 않음';
    document.getElementById('save_status').className = 'text-xs text-amber-500';
    updateGrandTotal();
}

function updateGrandTotal() {
    const ks = parseFloat(document.getElementById('inp_korean_salary').value) || 0;
    const mr = parseFloat(document.getElementById('inp_monthly_rent').value)  || 0;
    const total = AUTO_TOTAL + ks + mr;
    document.getElementById('grand_total_display').textContent = fmt2(total);
}

async function saveFixed() {
    const btn = document.getElementById('btn_save');
    btn.disabled = true;
    document.getElementById('save_status').textContent = '저장 중...';

    const fd = new FormData();
    fd.append('year',           YEAR);
    fd.append('month',          MONTH);
    fd.append('korean_salary',  document.getElementById('inp_korean_salary').value || 0);
    fd.append('monthly_rent',   document.getElementById('inp_monthly_rent').value  || 0);

    const res  = await fetch('ajax_save_monthly_fixed.php', {method:'POST', body:fd});
    const data = await res.json();

    if (data.success) {
        document.getElementById('save_status').textContent = '저장 완료 ✓';
        document.getElementById('save_status').className = 'text-xs text-green-600';
    } else {
        document.getElementById('save_status').textContent = '저장 실패';
        document.getElementById('save_status').className = 'text-xs text-red-500';
        btn.disabled = false;
    }
}

// ── 수수료 매장 표 — 실시간 재계산 + 개별 셀 자동 저장 ──────────
function recalcCommissionRow(id, amount) {
    const rate = parseFloat(document.getElementById('crate_' + id).value) || 0;
    const tax  = parseFloat(document.getElementById('ctax_'  + id).value) || 0;
    const commission = amount * rate / 100;
    const payout     = amount - commission - tax;
    document.getElementById('ccommission_' + id).textContent = fmt2(commission);
    document.getElementById('cpayout_'     + id).textContent = fmt2(payout);
    recalcCommissionTotals();
}

function recalcCommissionTotals() {
    let salesT = 0, feeT = 0, taxT = 0, payoutT = 0;
    document.querySelectorAll('.commission-row').forEach(function (row) {
        const id     = row.dataset.id;
        const amount = parseFloat(row.dataset.amount) || 0;
        const rate   = parseFloat(document.getElementById('crate_' + id).value) || 0;
        const tax    = parseFloat(document.getElementById('ctax_'  + id).value) || 0;
        const fee    = amount * rate / 100;
        salesT  += amount;
        feeT    += fee;
        taxT    += tax;
        payoutT += amount - fee - tax;
    });
    document.getElementById('commission_sales_total').textContent  = fmt2(salesT);
    document.getElementById('commission_fee_total').textContent    = fmt2(feeT);
    document.getElementById('commission_tax_total').textContent    = taxT > 0 ? fmt2(taxT) : '-';
    document.getElementById('commission_payout_total').textContent = fmt2(payoutT);
    const summaryEl = document.getElementById('summary_commission_sales');
    if (summaryEl) summaryEl.textContent = fmt2(salesT);
}

async function saveCommissionField(url, payload, id, amount) {
    recalcCommissionRow(id, amount);
    const fd = new FormData();
    for (const k in payload) fd.append(k, payload[k]);
    const res  = await fetch(url, {method: 'POST', body: fd});
    const data = await res.json();
    if (!data.success) alert('저장 실패: ' + (data.error || ''));
}

function saveCommissionRate(id, amount) {
    const rate = parseFloat(document.getElementById('crate_' + id).value) || 0;
    saveCommissionField('ajax_save_commission_rate.php', {id: id, rate: rate}, id, amount);
}

function saveCommissionTax(id, amount) {
    const tax = parseFloat(document.getElementById('ctax_' + id).value) || 0;
    saveCommissionField('ajax_save_commission_tax.php', {year: YEAR, month: MONTH, company_id: id, tax_amount: tax}, id, amount);
}
</script>

<p class="text-center text-xs text-gray-400 mt-3">
  <i class="fa-solid fa-circle-info mr-1"></i>
  인건비·전기세·월세는 Expense Report(other expenses) 키워드 기준으로 자동 분류됩니다.
</p>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
