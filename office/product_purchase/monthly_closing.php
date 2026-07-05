<?php
$page_title      = '월마감 REPORT';
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

$first    = sprintf('%04d-%02d-01', $year, $month);
$last_day = date('Y-m-t', strtotime($first));

$conn = get_db_connection();

// ── 저장된 수동 입력값 로드 ───────────────────────────────────
$korean_salary = 0.0;
$monthly_rent  = 0.0;
$conn->query("CREATE TABLE IF NOT EXISTS office_monthly_fixed (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  store_id INT UNSIGNED NOT NULL, year SMALLINT UNSIGNED NOT NULL,
  month TINYINT UNSIGNED NOT NULL, korean_salary DECIMAL(15,2) NOT NULL DEFAULT 0,
  monthly_rent DECIMAL(15,2) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_store_month (store_id, year, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$sf = $conn->prepare("SELECT korean_salary, monthly_rent FROM office_monthly_fixed WHERE store_id=? AND year=? AND month=?");
$sf->bind_param('iii', $store_id, $year, $month); $sf->execute();
$sf_row = $sf->get_result()->fetch_assoc(); $sf->close();
if ($sf_row) { $korean_salary = (float)$sf_row['korean_salary']; $monthly_rent = (float)$sf_row['monthly_rent']; }

// ── 1. 총 물품구매액 ──────────────────────────────────────────
$total_purchase = 0.0;

$s = $conn->prepare(
    "SELECT COALESCE(SUM(amount),0) FROM office_product_purchases
     WHERE store_id=? AND payment_type='cash'
       AND YEAR(payment_date)=? AND MONTH(payment_date)=?"
);
$s->bind_param('iii', $store_id, $year, $month); $s->execute();
$total_purchase += (float)$s->get_result()->fetch_row()[0]; $s->close();

$s = $conn->prepare(
    "SELECT COALESCE(SUM(amount),0) FROM office_product_purchases
     WHERE store_id=? AND payment_type='check'
       AND YEAR(check_issued_date)=? AND MONTH(check_issued_date)=?"
);
$s->bind_param('iii', $store_id, $year, $month); $s->execute();
$total_purchase += (float)$s->get_result()->fetch_row()[0]; $s->close();

// ── 2. 타 지점으로 물품 이동 OUT ─────────────────────────────
$total_tr_out = 0.0;
$s = $conn->prepare(
    "SELECT COALESCE(SUM(final_amount),0) FROM store_transfers
     WHERE from_store_id=? AND status!='cancelled'
       AND YEAR(transfer_date)=? AND MONTH(transfer_date)=?"
);
$s->bind_param('iii', $store_id, $year, $month); $s->execute();
$total_tr_out = (float)$s->get_result()->fetch_row()[0]; $s->close();

// ── 3. 타 지점으로부터 물품 이동 IN ──────────────────────────
$total_tr_in = 0.0;
$s = $conn->prepare(
    "SELECT COALESCE(SUM(amount),0) FROM sales_transfers
     WHERE store_id=? AND direction='in'
       AND YEAR(transfer_date)=? AND MONTH(transfer_date)=?"
);
$s->bind_param('iii', $store_id, $year, $month); $s->execute();
$total_tr_in = (float)$s->get_result()->fetch_row()[0]; $s->close();

// ── 도매 판매 (Delivery K + Whole Sale) ──────────────────────
$total_delivery_k = 0.0;
$s = $conn->prepare(
    "SELECT COALESCE(SUM(delivery_k),0) FROM sales_daily
     WHERE store_id=? AND YEAR(sale_date)=? AND MONTH(sale_date)=?"
);
$s->bind_param('iii', $store_id, $year, $month); $s->execute();
$total_delivery_k = (float)$s->get_result()->fetch_row()[0]; $s->close();

$total_wholesale = 0.0;
$s = $conn->prepare(
    "SELECT COALESCE(SUM(final_amount),0) FROM wholesale_sales
     WHERE store_id=? AND status!='cancelled'
       AND YEAR(sale_date)=? AND MONTH(sale_date)=?"
);
$s->bind_param('iii', $store_id, $year, $month); $s->execute();
$total_wholesale = (float)$s->get_result()->fetch_row()[0]; $s->close();

$total_wholesale_sales = $total_delivery_k + $total_wholesale;

// ── 5. 사무실 경비(부속품 일체) — office_equipment_purchases ──
$total_equip = 0.0;
$s = $conn->prepare(
    "SELECT COALESCE(SUM(amount),0) FROM office_equipment_purchases
     WHERE store_id=? AND YEAR(payment_date)=? AND MONTH(payment_date)=?"
);
$s->bind_param('iii', $store_id, $year, $month); $s->execute();
$total_equip = (float)$s->get_result()->fetch_row()[0]; $s->close();

// ── ER other_exp 섹션 수집 ────────────────────────────────────
$salary_kws   = ['월급','salary','salari','wage','급여','인건비','labor'];
$electric_kws = ['전기','electric','elec','meralco','power'];

$total_salary    = 0.0; // 현지 직원 인건비
$total_electric  = 0.0; // 전기세
$total_other_exp = 0.0; // ER other_exp 전체 합계

$tbl = $conn->query("SHOW TABLES LIKE 'er_saved_state'");
if ($tbl && $tbl->num_rows > 0) {
    $er_q = $conn->prepare(
        "SELECT save_date, state_json FROM er_saved_state
         WHERE store_id=? AND save_date BETWEEN ? AND ?"
    );
    $er_q->bind_param('iss', $store_id, $first, $last_day);
    $er_q->execute();

    foreach ($er_q->get_result()->fetch_all(MYSQLI_ASSOC) as $er_row) {
        $state = json_decode($er_row['state_json'], true);
        if (!$state || !isset($state['sections'])) continue;

        // 물품구매 r_ 보완 (selling + check_sup)
        foreach (['selling', 'check_sup'] as $sec) {
            foreach ($state['sections'][$sec] ?? [] as $row) {
                if (strncmp((string)($row['item_id'] ?? ''), 'r_', 2) !== 0) continue;
                $total_purchase += (float)($row['amount'] ?? 0);
            }
        }

        // 사무실 경비 r_ 보완 (not_selling)
        foreach ($state['sections']['not_selling'] ?? [] as $row) {
            if (strncmp((string)($row['item_id'] ?? ''), 'r_', 2) !== 0) continue;
            $total_equip += (float)($row['amount'] ?? 0);
        }

        // other_exp: r_ 아이템만 처리 (e_ 아이템은 DB 쿼리에서 이미 집계)
        foreach (['other_exp_check', 'other_exp_cash', 'other_exp'] as $sec) {
            foreach ($state['sections'][$sec] ?? [] as $row) {
                if (strncmp((string)($row['item_id'] ?? ''), 'r_', 2) !== 0) continue;
                $amt = (float)($row['amount'] ?? 0);
                if ($amt <= 0) continue;
                $total_other_exp += $amt;
                $d = strtolower($row['details'] ?? '');
                foreach ($salary_kws   as $kw) { if (str_contains($d, $kw)) { $total_salary   += $amt; break; } }
                foreach ($electric_kws as $kw) { if (str_contains($d, $kw)) { $total_electric += $amt; break; } }
            }
        }
    }
    $er_q->close();
}

$conn->close();

// 사무실 경비 = STORE EXP 전체 - 현지 직원 인건비 - 전기세
// STORE EXP = office_equipment_purchases(not_selling) + ER other_exp 전체
$total_store_exp = $total_equip + $total_other_exp;
$total_office    = $total_store_exp - $total_salary - $total_electric;

// ── 항목 정의 ─────────────────────────────────────────────────
$total_purchase_with_transfer = $total_purchase + $total_tr_in - $total_tr_out;

$items = [
    ['label' => '총 물품구매액 (매입 + IN - OUT)',      'amount' => $total_purchase_with_transfer, 'icon' => 'fa-boxes-stacked',           'color' => 'blue',   'input' => false],
    ['label' => '타 지점으로 물품 이동 (OUT)',         'amount' => $total_tr_out,                'icon' => 'fa-arrow-right-from-bracket', 'color' => 'orange', 'input' => false],
    ['label' => '타 지점으로부터 물품 이동 (IN)',      'amount' => $total_tr_in,                 'icon' => 'fa-arrow-right-to-bracket',  'color' => 'green',  'input' => false],
    ['label' => '현지 직원 인건비',                   'amount' => $total_salary,                'icon' => 'fa-users',                   'color' => 'purple', 'input' => false],
    ['label' => '사무실 경비 (부속품 일체)',            'amount' => $total_office,                'icon' => 'fa-screwdriver-wrench',      'color' => 'gray',   'input' => false],
    ['label' => '전기세',                             'amount' => $total_electric,              'icon' => 'fa-bolt',                    'color' => 'yellow', 'input' => false],
    ['label' => '한국 직원 급여',                      'amount' => $korean_salary,              'icon' => 'fa-user-tie',                'color' => 'indigo', 'input' => 'korean_salary'],
    ['label' => '월세',                               'amount' => $monthly_rent,               'icon' => 'fa-building',                'color' => 'red',    'input' => 'monthly_rent'],
    ['label' => '도매 판매 (Delivery K + Whole Sale)', 'amount' => $total_wholesale_sales,      'icon' => 'fa-truck-fast',              'color' => 'teal',   'input' => false],
];
$grand_total = array_sum(array_column($items, 'amount'));

$color_map = [
    'blue'   => ['bg' => 'bg-blue-50',   'text' => 'text-blue-700',   'icon' => 'text-blue-400'],
    'orange' => ['bg' => 'bg-orange-50', 'text' => 'text-orange-700', 'icon' => 'text-orange-400'],
    'green'  => ['bg' => 'bg-green-50',  'text' => 'text-green-700',  'icon' => 'text-green-400'],
    'purple' => ['bg' => 'bg-purple-50', 'text' => 'text-purple-700', 'icon' => 'text-purple-400'],
    'gray'   => ['bg' => 'bg-gray-50',   'text' => 'text-gray-700',   'icon' => 'text-gray-400'],
    'yellow' => ['bg' => 'bg-yellow-50', 'text' => 'text-yellow-700', 'icon' => 'text-yellow-500'],
    'red'    => ['bg' => 'bg-red-50',    'text' => 'text-red-700',    'icon' => 'text-red-400'],
    'indigo' => ['bg' => 'bg-indigo-50', 'text' => 'text-indigo-700', 'icon' => 'text-indigo-400'],
    'teal'   => ['bg' => 'bg-teal-50',   'text' => 'text-teal-700',   'icon' => 'text-teal-400'],
];
?>

<div class="flex items-center justify-between mb-4">
  <h2 class="text-xl font-bold text-gray-800">
    <i class="fa-solid fa-flag-checkered mr-2 text-gray-700"></i>월마감 REPORT
  </h2>
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

<!-- 항목 리스트 -->
<div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden max-w-2xl mx-auto">
  <?php foreach ($items as $i => $item):
    $c = $color_map[$item['color']];
  ?>
  <div class="flex items-center gap-4 px-6 py-3 border-b border-gray-100 <?php echo $c['bg']; ?>">
    <span class="text-sm font-semibold text-gray-400 w-5 text-right"><?php echo $i + 1; ?></span>
    <span class="w-8 text-center <?php echo $c['icon']; ?>">
      <i class="fa-solid <?php echo $item['icon']; ?>"></i>
    </span>
    <span class="flex-1 text-sm font-medium <?php echo $c['text']; ?>"><?php echo htmlspecialchars($item['label']); ?></span>
    <?php if ($item['input']): ?>
    <div class="flex items-center gap-2">
      <input type="number" step="0.01" min="0"
             id="inp_<?php echo $item['input']; ?>"
             value="<?php echo $item['amount'] > 0 ? $item['amount'] : ''; ?>"
             placeholder="0.00"
             class="w-36 text-right border border-gray-300 rounded-lg px-3 py-1.5 text-sm font-mono focus:ring-2 focus:ring-blue-400"
             onchange="markChanged()">
    </div>
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

  <!-- 합계 -->
  <div class="flex items-center gap-4 px-6 py-5 bg-gray-800">
    <span class="w-5"></span>
    <span class="w-8 text-center text-gray-300"><i class="fa-solid fa-sigma"></i></span>
    <span class="flex-1 text-sm font-bold text-white">합계</span>
    <span id="grand_total_display" class="font-mono font-bold text-white text-xl"><?php echo number_format($grand_total, 2); ?></span>
  </div>
</div>

<script>
const YEAR  = <?php echo $year; ?>;
const MONTH = <?php echo $month; ?>;
const AUTO_TOTAL = <?php echo $grand_total - $korean_salary - $monthly_rent; ?>; // 자동 계산 항목 합계

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
    document.getElementById('grand_total_display').textContent =
        total.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
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
</script>

<p class="text-center text-xs text-gray-400 mt-3">
  <i class="fa-solid fa-circle-info mr-1"></i>
  인건비·전기세·월세는 Expense Report(other expenses) 키워드 기준으로 자동 분류됩니다.
</p>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
