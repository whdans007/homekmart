<?php
$page_title      = 'Fixed Expenses Report';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';
require_once __DIR__ . '/../lib/daily_report_helper.php';

$store_id = get_office_store_id();
$conn     = get_db_connection();

// ── 월 선택 ──────────────────────────────────────────────────
$today     = date('Y-m-d');
$sel_year  = (int)($_GET['year']  ?? date('Y'));
$sel_month = (int)($_GET['month'] ?? date('n'));
$sel_year  = max(2020, min((int)date('Y'), $sel_year));
$sel_month = max(1, min(12, $sel_month));

$month_start = sprintf('%04d-%02d-01', $sel_year, $sel_month);
$month_end   = date('Y-m-t', strtotime($month_start));
$prev_m      = $sel_month === 1  ? ['year'=>$sel_year-1,'month'=>12] : ['year'=>$sel_year,'month'=>$sel_month-1];
$next_m      = $sel_month === 12 ? ['year'=>$sel_year+1,'month'=>1]  : ['year'=>$sel_year,'month'=>$sel_month+1];
$is_future_m = ($sel_year > (int)date('Y')) || ($sel_year === (int)date('Y') && $sel_month > (int)date('n'));

// ── Expense Report → other_exp_check / other_exp_cash 조회 ──
$conn->query("CREATE TABLE IF NOT EXISTS er_saved_state (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id INT UNSIGNED NOT NULL,
  save_date DATE NOT NULL,
  state_json MEDIUMTEXT NOT NULL,
  saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_er (store_id, save_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$st2 = $conn->prepare(
    "SELECT save_date, state_json FROM er_saved_state
     WHERE store_id = ? AND save_date BETWEEN ? AND ?
     ORDER BY save_date ASC"
);
$st2->bind_param('iss', $store_id, $month_start, $month_end);
$st2->execute();
$er_rows = $st2->get_result()->fetch_all(MYSQLI_ASSOC);
$st2->close();
$cd_rows = [];

// ── 카테고리 자동 분류 ────────────────────────────────────────
// Design Ref: office/lib/daily_report_helper.php의 get_fixed_expense_categories()/
// classify_fixed_expense_details() — Sales Report/Monthly Closing과 분류 기준을 공유하는 SSOT.
$CATEGORIES = get_fixed_expense_categories();

// ── 항목 수집 ─────────────────────────────────────────────────
// source: 'CD' | 'ER-Check' | 'ER-Cash'
$all_items  = [];
$cat_totals = [];
$src_totals = ['ER-Check' => 0.0, 'ER-Cash' => 0.0];
$grand_total = 0.0;

function collect_items(mysqli $conn, array $raw_rows, string $source_label, array $section_keys,
                        array &$all_items, array &$cat_totals, array &$src_totals, float &$grand_total): void {
    foreach ($raw_rows as $row) {
        $state = json_decode($row['state_json'], true);
        foreach ($section_keys as $sec_key => $src) {
            // 스냅샷에 굳어있는 공급처명을 원본 테이블 기준 최신 이름으로 갱신
            $items = office_refresh_supplier_names($conn, $state['sections'][$sec_key] ?? []);
            foreach ($items as $item) {
                $amt = (float)($item['amount'] ?? 0);
                $classify_text = trim(($item['supplier'] ?? '') . ' ' . ($item['details'] ?? ''));
                $cat = classify_fixed_expense_details($classify_text);
                $all_items[] = [
                    'date'     => $row['save_date'],
                    'source'   => $src,
                    'or_si_no' => $item['or_si_no'] ?? ($item['cv_no'] ?? ''),
                    'supplier' => $item['supplier']  ?? '',
                    'details'  => $item['details']   ?? '',
                    'amount'   => $amt,
                    'category' => $cat,
                ];
                $cat_totals[$cat]  = ($cat_totals[$cat]  ?? 0.0) + $amt;
                $src_totals[$src]  = ($src_totals[$src]  ?? 0.0) + $amt;
                $grand_total      += $amt;
            }
        }
    }
}

collect_items($conn, $er_rows, 'ER',
    ['other_exp_check' => 'ER-Check', 'other_exp_cash' => 'ER-Cash'],
    $all_items, $cat_totals, $src_totals, $grand_total);
$conn->close();

// 날짜 → 소스 순 정렬
usort($all_items, fn($a, $b) => strcmp($a['date'].$a['source'], $b['date'].$b['source']));

function fe($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function ff($n) { return number_format((float)$n, 2); }
$month_label = date('F Y', strtotime($month_start));
?>

<div class="flex items-center justify-between mb-4">
  <h2 class="text-xl font-bold text-gray-800">
    <i class="fa-solid fa-file-invoice-dollar mr-2 text-amber-600"></i>Fixed Expenses Report
  </h2>
  <div class="flex gap-2">
    <?php if (!empty($all_items)): ?>
    <button onclick="printReport()"
            class="px-3 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg text-sm">
      <i class="fa-solid fa-print mr-1"></i>Print
    </button>
    <?php endif; ?>
    <a href="index.php" class="px-3 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-sm">
      <i class="fa-solid fa-money-bill-wave mr-1"></i>Cash Disbursement
    </a>
  </div>
</div>

<!-- 월 선택 -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-3 mb-4 flex items-center gap-2">
  <label class="text-sm font-medium text-gray-700">
    <i class="fa-solid fa-calendar-alt mr-1 text-amber-500"></i>Month:
  </label>
  <a href="?year=<?php echo $prev_m['year']; ?>&month=<?php echo $prev_m['month']; ?>"
     class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-gray-500 hover:bg-gray-50">
    <i class="fa-solid fa-chevron-left"></i>
  </a>
  <form method="GET" class="flex gap-1">
    <select name="year" onchange="this.form.submit()"
            class="border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:ring-2 focus:ring-amber-400">
      <?php for ($y = (int)date('Y'); $y >= 2020; $y--): ?>
      <option value="<?php echo $y; ?>" <?php echo $y === $sel_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
      <?php endfor; ?>
    </select>
    <select name="month" onchange="this.form.submit()"
            class="border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:ring-2 focus:ring-amber-400">
      <?php for ($m = 1; $m <= 12; $m++): ?>
      <option value="<?php echo $m; ?>" <?php echo $m === $sel_month ? 'selected' : ''; ?>>
        <?php echo date('F', mktime(0,0,0,$m,1,2000)); ?>
      </option>
      <?php endfor; ?>
    </select>
  </form>
  <a href="?year=<?php echo $next_m['year']; ?>&month=<?php echo $next_m['month']; ?>"
     class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-gray-500 hover:bg-gray-50 <?php echo $is_future_m ? 'opacity-30 pointer-events-none' : ''; ?>">
    <i class="fa-solid fa-chevron-right"></i>
  </a>
  <span class="text-sm font-semibold text-amber-700 ml-1"><?php echo $month_label; ?></span>
</div>

<?php if (empty($all_items)): ?>
<div class="text-center py-16 text-gray-400">
  <i class="fa-solid fa-file-invoice-dollar text-4xl mb-3"></i>
  <p><?php echo $month_label; ?>에 저장된 Other Expenses 데이터가 없습니다.</p>
  <p class="text-xs mt-1">Expense Report의 OTHER EXPENSES(수표/현금)에서 SAVE 후 표시됩니다.</p>
</div>
<?php else: ?>

<!-- 출처별 요약 바 -->
<div class="grid grid-cols-2 gap-3 mb-3">
  <div class="bg-purple-50 border border-purple-200 rounded-xl p-3 flex items-center gap-3">
    <i class="fa-solid fa-money-check text-purple-500 text-lg"></i>
    <div>
      <div class="text-xs text-purple-600 font-semibold">ER — Other Exp (수표)</div>
      <div class="text-sm font-bold font-mono text-purple-700">₱<?php echo ff($src_totals['ER-Check']); ?></div>
      <div class="text-xs text-gray-400"><?php echo count(array_filter($all_items, fn($i) => $i['source']==='ER-Check')); ?>건</div>
    </div>
  </div>
  <div class="bg-pink-50 border border-pink-200 rounded-xl p-3 flex items-center gap-3">
    <i class="fa-solid fa-coins text-pink-500 text-lg"></i>
    <div>
      <div class="text-xs text-pink-600 font-semibold">ER — Other Exp (현금)</div>
      <div class="text-sm font-bold font-mono text-pink-700">₱<?php echo ff($src_totals['ER-Cash']); ?></div>
      <div class="text-xs text-gray-400"><?php echo count(array_filter($all_items, fn($i) => $i['source']==='ER-Cash')); ?>건</div>
    </div>
  </div>
</div>

<!-- 카테고리별 요약 카드 -->
<div class="grid grid-cols-2 gap-3 mb-4 sm:grid-cols-3 lg:grid-cols-8">
  <?php
  $cat_colors = [
    'Electricity' => ['bg'=>'bg-yellow-50','border'=>'border-yellow-200','text'=>'text-yellow-700','icon'=>'fa-bolt'],
    'Salary'      => ['bg'=>'bg-blue-50',  'border'=>'border-blue-200',  'text'=>'text-blue-700',  'icon'=>'fa-users'],
    'Internet'    => ['bg'=>'bg-indigo-50','border'=>'border-indigo-200','text'=>'text-indigo-700','icon'=>'fa-wifi'],
    'Rent'        => ['bg'=>'bg-green-50', 'border'=>'border-green-200', 'text'=>'text-green-700', 'icon'=>'fa-building'],
    'Water'       => ['bg'=>'bg-cyan-50',  'border'=>'border-cyan-200',  'text'=>'text-cyan-700',  'icon'=>'fa-droplet'],
    'Tax'         => ['bg'=>'bg-red-50',   'border'=>'border-red-200',   'text'=>'text-red-700',   'icon'=>'fa-file-invoice'],
    'Others'      => ['bg'=>'bg-gray-50',  'border'=>'border-gray-200',  'text'=>'text-gray-700',  'icon'=>'fa-ellipsis'],
  ];
  foreach ($CATEGORIES as $cat => $kws):
      if (!isset($cat_totals[$cat])) continue;
      $cc = $cat_colors[$cat] ?? $cat_colors['Others'];
  ?>
  <div class="<?php echo $cc['bg']; ?> border <?php echo $cc['border']; ?> rounded-xl p-3">
    <div class="flex items-center gap-1.5 mb-1">
      <i class="fa-solid <?php echo $cc['icon']; ?> <?php echo $cc['text']; ?> text-xs"></i>
      <span class="text-xs font-semibold <?php echo $cc['text']; ?>"><?php echo $cat; ?></span>
    </div>
    <div class="text-sm font-bold font-mono <?php echo $cc['text']; ?>">₱<?php echo ff($cat_totals[$cat]); ?></div>
    <div class="text-xs text-gray-400 mt-0.5"><?php echo count(array_filter($all_items, fn($i) => $i['category'] === $cat)); ?>건</div>
  </div>
  <?php endforeach; ?>

  <!-- Grand Total -->
  <div class="bg-amber-50 border border-amber-300 rounded-xl p-3">
    <div class="flex items-center gap-1.5 mb-1">
      <i class="fa-solid fa-sigma text-amber-600 text-xs"></i>
      <span class="text-xs font-semibold text-amber-700">Grand Total</span>
    </div>
    <div class="text-sm font-bold font-mono text-amber-700">₱<?php echo ff($grand_total); ?></div>
    <div class="text-xs text-gray-400 mt-0.5"><?php echo count($all_items); ?>건</div>
  </div>
</div>

<!-- 검색/필터 -->
<div class="flex items-center gap-2 mb-3">
  <div class="relative flex-1 max-w-xs">
    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
    <input type="text" id="fe_search" oninput="filterTable()" placeholder="내용 검색..."
           class="w-full border border-gray-300 rounded-lg pl-8 pr-3 py-1.5 text-sm focus:ring-2 focus:ring-amber-400">
  </div>
  <select id="fe_src_filter" onchange="filterTable()"
          class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-amber-400">
    <option value="">전체 출처</option>
    <option value="ER-Check">ER — Check (수표)</option>
    <option value="ER-Cash">ER — Cash (현금)</option>
  </select>
  <select id="fe_cat_filter" onchange="filterTable()"
          class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-amber-400">
    <option value="">전체 카테고리</option>
    <?php foreach (array_keys($CATEGORIES) as $cat): ?>
    <?php if (isset($cat_totals[$cat])): ?>
    <option value="<?php echo fe($cat); ?>"><?php echo fe($cat); ?></option>
    <?php endif; ?>
    <?php endforeach; ?>
  </select>
  <span id="fe_count" class="text-xs text-gray-400"></span>
</div>

<!-- 상세 테이블 -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mb-4">
  <div class="overflow-x-auto" style="max-height:65vh;overflow-y:auto">
    <table class="min-w-full text-xs divide-y divide-gray-100">
      <thead class="bg-gray-50 sticky top-0 z-10">
        <tr>
          <th class="px-3 py-2.5 text-left text-gray-500 font-semibold">날짜</th>
          <th class="px-3 py-2.5 text-left text-gray-500 font-semibold">출처</th>
          <th class="px-3 py-2.5 text-left text-gray-500 font-semibold">카테고리</th>
          <th class="px-3 py-2.5 text-left text-gray-500 font-semibold">OR/SI NO.</th>
          <th class="px-3 py-2.5 text-left text-gray-500 font-semibold">Supplier</th>
          <th class="px-3 py-2.5 text-left text-gray-500 font-semibold">Details</th>
          <th class="px-3 py-2.5 text-right text-gray-500 font-semibold">Amount</th>
        </tr>
      </thead>
      <tbody id="fe_tbody" class="divide-y divide-gray-50">
        <?php
        $cat_badge = [
            'Electricity' => 'bg-yellow-100 text-yellow-700',
            'Salary'      => 'bg-blue-100 text-blue-700',
            'Internet'    => 'bg-indigo-100 text-indigo-700',
            'Rent'        => 'bg-green-100 text-green-700',
            'Water'       => 'bg-cyan-100 text-cyan-700',
            'Tax'         => 'bg-red-100 text-red-700',
            'Others'      => 'bg-gray-100 text-gray-600',
        ];
        $src_badge = [
            'CD'       => 'bg-orange-100 text-orange-700',
            'ER-Check' => 'bg-purple-100 text-purple-700',
            'ER-Cash'  => 'bg-pink-100 text-pink-700',
        ];
        foreach ($all_items as $item):
            $badge  = $cat_badge[$item['category']] ?? 'bg-gray-100 text-gray-600';
            $sbadge = $src_badge[$item['source']]   ?? 'bg-gray-100 text-gray-600';
        ?>
        <tr class="hover:bg-amber-50 fe-row"
            data-cat="<?php echo fe($item['category']); ?>"
            data-src="<?php echo fe($item['source']); ?>">
          <td class="px-3 py-2 text-gray-500 whitespace-nowrap"><?php echo fe($item['date']); ?></td>
          <td class="px-3 py-2 whitespace-nowrap">
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?php echo $sbadge; ?>">
              <?php echo fe($item['source']); ?>
            </span>
          </td>
          <td class="px-3 py-2 whitespace-nowrap">
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?php echo $badge; ?>">
              <?php echo fe($item['category']); ?>
            </span>
          </td>
          <td class="px-3 py-2 font-mono text-gray-500 whitespace-nowrap"><?php echo fe($item['or_si_no']); ?></td>
          <td class="px-3 py-2 text-gray-700 font-medium whitespace-nowrap"><?php echo fe($item['supplier']); ?></td>
          <td class="px-3 py-2 text-gray-600"><?php echo fe($item['details']); ?></td>
          <td class="px-3 py-2 text-right font-mono font-semibold text-gray-800 whitespace-nowrap">₱<?php echo ff($item['amount']); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot class="bg-gray-50 border-t-2 border-gray-200">
        <tr>
          <td colspan="6" class="px-3 py-2.5 text-right text-sm font-bold text-gray-700">Grand Total</td>
          <td class="px-3 py-2.5 text-right font-mono font-bold text-amber-700 text-sm" id="fe_total_display">
            ₱<?php echo ff($grand_total); ?>
          </td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<?php endif; ?>

<script>
function filterTable() {
    const kw  = document.getElementById('fe_search').value.trim().toLowerCase();
    const cat = document.getElementById('fe_cat_filter').value;
    const src = document.getElementById('fe_src_filter').value;
    const rows = document.querySelectorAll('#fe_tbody .fe-row');
    let visible = 0, total = 0;
    rows.forEach(tr => {
        const matchKw  = !kw  || tr.innerText.toLowerCase().includes(kw);
        const matchCat = !cat || tr.dataset.cat === cat;
        const matchSrc = !src || tr.dataset.src === src;
        const show = matchKw && matchCat && matchSrc;
        tr.style.display = show ? '' : 'none';
        if (show) {
            visible++;
            const amtCell = tr.querySelector('td:last-child');
            if (amtCell) total += parseFloat(amtCell.textContent.replace(/[₱,]/g,'')) || 0;
        }
    });
    const cnt = document.getElementById('fe_count');
    if (cnt) cnt.textContent = (kw || cat || src) ? visible + '건 표시' : '';
    const td = document.getElementById('fe_total_display');
    if (td) td.textContent = '₱' + total.toLocaleString('en',{minimumFractionDigits:2});
}

function printReport() {
    const rows = document.querySelectorAll('#fe_tbody .fe-row');
    const monthLabel = <?php echo json_encode($month_label); ?>;
    let tableRows = '', visTotal = 0;
    rows.forEach(tr => {
        if (tr.style.display === 'none') return;
        const cells = tr.querySelectorAll('td');
        const amt = parseFloat((cells[6]?.textContent||'').replace(/[₱,]/g,'')) || 0;
        visTotal += amt;
        tableRows += '<tr>'
            + '<td>' + (cells[0]?.textContent.trim()||'') + '</td>'
            + '<td>' + (cells[1]?.textContent.trim()||'') + '</td>'
            + '<td>' + (cells[2]?.textContent.trim()||'') + '</td>'
            + '<td>' + (cells[3]?.textContent.trim()||'') + '</td>'
            + '<td>' + (cells[4]?.textContent.trim()||'') + '</td>'
            + '<td>' + (cells[5]?.textContent.trim()||'') + '</td>'
            + '<td style="text-align:right;font-family:monospace">₱' + amt.toLocaleString('en',{minimumFractionDigits:2}) + '</td>'
            + '</tr>';
    });

    <?php
    $cat_data_js = [];
    foreach ($CATEGORIES as $cat => $kws) {
        if (isset($cat_totals[$cat])) {
            $cat_data_js[] = [
                'cat'   => $cat,
                'total' => $cat_totals[$cat],
                'count' => count(array_filter($all_items, fn($i) => $i['category'] === $cat)),
            ];
        }
    }
    $src_data_js = [
        ['src'=>'ER — Other Exp (수표)',  'total'=>$src_totals['ER-Check'], 'count'=>count(array_filter($all_items, fn($i)=>$i['source']==='ER-Check'))],
        ['src'=>'ER — Other Exp (현금)',  'total'=>$src_totals['ER-Cash'],  'count'=>count(array_filter($all_items, fn($i)=>$i['source']==='ER-Cash'))],
    ];
    echo 'const catData = ' . json_encode($cat_data_js) . ';';
    echo 'const srcData = ' . json_encode($src_data_js) . ';';
    ?>
    const grandTotal = <?php echo json_encode($grand_total); ?>;

    const srcRows = srcData.map(s =>
        `<tr><td>${s.src}</td><td>${s.count}건</td><td style="text-align:right;font-family:monospace">₱${parseFloat(s.total).toLocaleString('en',{minimumFractionDigits:2})}</td></tr>`
    ).join('');
    const summaryRows = catData.map(c =>
        `<tr><td>${c.cat}</td><td>${c.count}건</td><td style="text-align:right;font-family:monospace">₱${parseFloat(c.total).toLocaleString('en',{minimumFractionDigits:2})}</td></tr>`
    ).join('');

    const win = window.open('', '_blank', 'width=1000,height=750');
    win.document.write(`<!DOCTYPE html><html><head>
<meta charset="utf-8">
<title>Fixed Expenses — ${monthLabel}</title>
<style>
  * { box-sizing:border-box; margin:0; padding:0; }
  body { font-family:Arial,sans-serif; font-size:10px; padding:16px; }
  h2 { font-size:14px; margin-bottom:4px; }
  .meta { font-size:10px; color:#555; margin-bottom:10px; }
  h3 { font-size:11px; margin:12px 0 4px; border-bottom:1px solid #e5e7eb; padding-bottom:2px; }
  .summary-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:12px; }
  table { width:100%; border-collapse:collapse; margin-bottom:10px; }
  th { background:#f3f4f6; border:1px solid #d1d5db; padding:4px 8px; font-size:10px; text-align:left; }
  td { border:1px solid #e5e7eb; padding:3px 8px; }
  tr:nth-child(even) td { background:#fafafa; }
  tfoot td { font-weight:bold; background:#fef3c7; }
  @media print { @page { margin:10mm; size:A4 landscape; } button { display:none; } }
</style>
</head><body>
<h2>Fixed Expenses Report — ${monthLabel}</h2>
<div class="meta">출력일: <?php echo date('Y-m-d H:i'); ?> &nbsp;|&nbsp; Store: <?php echo fe($_office_store_name ?? ''); ?></div>
<div class="summary-grid">
  <div>
    <h3>출처별 합계</h3>
    <table>
      <thead><tr><th>출처</th><th>건수</th><th>합계</th></tr></thead>
      <tbody>${srcRows}</tbody>
      <tfoot><tr><td colspan="2" style="text-align:right">Grand Total</td><td style="text-align:right;font-family:monospace">₱${grandTotal.toLocaleString('en',{minimumFractionDigits:2})}</td></tr></tfoot>
    </table>
  </div>
  <div>
    <h3>카테고리별 합계</h3>
    <table>
      <thead><tr><th>Category</th><th>건수</th><th>합계</th></tr></thead>
      <tbody>${summaryRows}</tbody>
      <tfoot><tr><td colspan="2" style="text-align:right">Grand Total</td><td style="text-align:right;font-family:monospace">₱${grandTotal.toLocaleString('en',{minimumFractionDigits:2})}</td></tr></tfoot>
    </table>
  </div>
</div>
<h3>상세 내역</h3>
<table>
  <thead><tr><th>날짜</th><th>출처</th><th>Category</th><th>OR/SI NO.</th><th>Supplier</th><th>Details</th><th>Amount</th></tr></thead>
  <tbody>${tableRows}</tbody>
  <tfoot><tr><td colspan="6" style="text-align:right">Grand Total</td><td style="text-align:right;font-family:monospace">₱${visTotal.toLocaleString('en',{minimumFractionDigits:2})}</td></tr></tfoot>
</table>
<button onclick="window.print()" style="padding:6px 16px;background:#d97706;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:11px;">인쇄</button>
</body></html>`);
    win.document.close();
    win.focus();
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
