<?php
// Design Ref: daily-report.design.md §5.1/§11.2 step 3 — Daily Report 메인 화면 (읽기 전용 자동집계)
$page_title      = 'Daily Report';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';
require_once __DIR__ . '/../lib/daily_report_helper.php';
require_once __DIR__ . '/../../lib/lang_helper.php';

$is_super_admin = ($_SESSION['role'] ?? '') === 'super_admin';
$store_id       = get_office_store_id();
$today          = date('Y-m-d');
// 기본 조회일 = 어제. 오늘 보고서는 전날 마감된(확정된) 매출자료를 다루므로 날짜 미지정 시 어제를 기본으로 연다.
$default_date   = date('Y-m-d', strtotime('-1 day'));
$date           = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : $default_date;
$prev_date      = date('Y-m-d', strtotime($date . ' -1 day'));
$next_date      = date('Y-m-d', strtotime($date . ' +1 day'));
$is_future      = $next_date > $today;

$conn = get_db_connection();

// super_admin만 다른 점포 조회 가능 (Design §7 — store 스코프 강제)
$store_list = [];
if ($is_super_admin) {
    $res = $conn->query(
        "SELECT id, name AS label FROM stores
         WHERE is_active=1 AND name NOT IN ('CENTER (물류센터)', 'KIMS MALL WHEREHOUSE (킴스몰 창고)')
         ORDER BY label"
    );
    $store_list = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $req_store_id = (int)($_GET['store_id'] ?? 0);
    if ($req_store_id > 0 && in_array($req_store_id, array_column($store_list, 'id'), true)) {
        $store_id = $req_store_id;
    }
}

$store_stmt = $conn->prepare("SELECT name AS label FROM stores WHERE id=?");
$store_stmt->bind_param('i', $store_id);
$store_stmt->execute();
$store_row = $store_stmt->get_result()->fetch_assoc();
$store_stmt->close();
$store_label = $store_row['label'] ?? 'SUNSET';

$pos_summary   = get_daily_pos_summary($conn, $store_id, $date);
$credit_detail = get_daily_credit_breakdown($conn, $store_id, $date);
$purchase      = get_daily_purchase_summary($conn, $store_id, $date);
$ar            = get_daily_ar_summary($conn, $store_id, $date);
$wholesale     = get_daily_wholesale_summary($conn, $store_id, $date);
$other_exp     = get_daily_other_expense_categories($conn, $store_id, $date);

// 수수료 코너 — 마이그레이션 전이면 등록 UI 대신 안내만 표시
$commission_tbl = $conn->query("SHOW TABLES LIKE 'daily_report_commission_companies'");
$commission_tbl_ready = $commission_tbl && $commission_tbl->num_rows > 0;
$commission = $commission_tbl_ready ? get_daily_commission_summary($conn, $store_id, $date) : ['rows' => [], 'total' => 0.0];

$supplier_res  = $conn->query(
    "SELECT DISTINCT supplier FROM pos_sales_data
     WHERE upload_id IN (SELECT id FROM pos_sales_uploads WHERE store_id={$store_id})
       AND supplier IS NOT NULL AND supplier != ''
     ORDER BY supplier ASC"
);
$supplier_list = $supplier_res ? array_column($supplier_res->fetch_all(MYSQLI_ASSOC), 'supplier') : [];

$conn->close();

$grand_sales    = $pos_summary['totals']['total'] + $ar['credit_sales_total'];
$grand_purchase = $purchase['totals']['total'];
$grand_expense  = $other_exp['total_placed'];
$grand_profit   = $grand_sales - $grand_purchase - $grand_expense;

function fmt2($n) { return number_format((float)$n, 2); }
function esc($s) { return htmlspecialchars((string)($s ?? '')); }
?>

<?php echo get_js_translation_script([
    'daily_report.register_failed',
    'daily_report.delete_failed',
    'daily_report.confirm_delete_company',
    'daily_report.no_detail',
    'daily_report.unclassified_count',
    'daily_report.expense_load_failed',
]); ?>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<style>
.dr-card {
    background:#fff; border:1px solid #e5e7eb; border-radius:6px;
    padding:5px 8px; margin-bottom:4px; cursor:grab; user-select:none; font-size:11px;
}
.dr-card:active { cursor:grabbing; }
.dr-card-label  { color:#374151; }
.dr-card-amount { color:#c2410c; font-weight:600; }
.dr-ghost { opacity:.3; }
.dr-zone {
    min-height:56px; border:2px dashed #e5e7eb; border-radius:8px;
    padding:4px; background:#fff;
}
.dr-zone.dr-source { background:#f9fafb; min-height:400px; }
</style>

<div class="flex items-center justify-between mb-4">
  <h2 class="text-xl font-bold text-gray-800">
    <i class="fa-solid fa-file-invoice mr-2 text-purple-600"></i><?php echo esc(t('daily_report.title')); ?> — <?php echo esc($store_label); ?>
  </h2>
  <div class="flex gap-2 items-center">
    <div class="flex items-center rounded-lg overflow-hidden border border-gray-200 text-xs mr-1">
      <button type="button" onclick="drSetLanguage('ko')"
              class="px-2.5 py-2 font-medium <?php echo get_language() === 'ko' ? 'bg-purple-600 text-white' : 'bg-white text-gray-500 hover:bg-gray-50'; ?>">KO</button>
      <button type="button" onclick="drSetLanguage('en')"
              class="px-2.5 py-2 font-medium <?php echo get_language() === 'en' ? 'bg-purple-600 text-white' : 'bg-white text-gray-500 hover:bg-gray-50'; ?>">EN</button>
    </div>
    <a href="export_daily_report.php?date=<?php echo $date; ?>&store_id=<?php echo $store_id; ?>"
       class="px-3 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm">
      <i class="fa-solid fa-file-excel mr-1"></i><?php echo esc(t('daily_report.excel')); ?>
    </a>
    <a href="export_daily_report_monthly.php?year=<?php echo date('Y', strtotime($date)); ?>&month=<?php echo date('n', strtotime($date)); ?>&store_id=<?php echo $store_id; ?>"
       class="px-3 py-2 bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg text-sm">
      <i class="fa-solid fa-calendar-days mr-1"></i><?php echo esc(t('daily_report.monthly_excel')); ?>
    </a>
    <a href="print_daily_report.php?date=<?php echo $date; ?>&store_id=<?php echo $store_id; ?>" target="_blank"
       class="px-3 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg text-sm">
      <i class="fa-solid fa-print mr-1"></i><?php echo esc(t('common.print')); ?>
    </a>
  </div>
</div>

<script>
function drSetLanguage(lang) {
    fetch('<?php echo $css_base; ?>ajax_set_language.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'language=' + encodeURIComponent(lang)
    })
    .then(r => r.json())
    .then(res => { if (res.success) location.reload(); })
    .catch(() => {});
}
</script>

<!-- 날짜/점포 선택 -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-3 mb-4 flex items-center gap-2 flex-wrap">
  <label class="text-sm font-medium text-gray-700"><i class="fa-solid fa-calendar-day mr-1 text-purple-500"></i><?php echo esc(t('common.date')); ?>:</label>
  <a href="?date=<?php echo $prev_date; ?><?php echo $is_super_admin ? '&store_id='.$store_id : ''; ?>"
     class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-gray-500 hover:bg-gray-50">
    <i class="fa-solid fa-chevron-left"></i>
  </a>
  <input type="date" max="<?php echo $today; ?>" value="<?php echo $date; ?>"
         onchange="if(this.value) window.location.href='?date='+this.value+'<?php echo $is_super_admin ? '&store_id='.$store_id : ''; ?>'"
         class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-purple-400">
  <a href="?date=<?php echo $next_date; ?><?php echo $is_super_admin ? '&store_id='.$store_id : ''; ?>"
     class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-gray-500 hover:bg-gray-50 <?php echo $is_future ? 'opacity-30 pointer-events-none' : ''; ?>">
    <i class="fa-solid fa-chevron-right"></i>
  </a>
  <?php if ($date === $today): ?><span class="text-xs text-purple-600 font-medium"><?php echo esc(t('common.today')); ?></span><?php endif; ?>

  <?php if ($is_super_admin): ?>
  <div class="ml-auto flex items-center gap-2">
    <label class="text-sm font-medium text-gray-700"><i class="fa-solid fa-store mr-1 text-purple-500"></i><?php echo esc(t('common.store')); ?>:</label>
    <select onchange="window.location.href='?date=<?php echo $date; ?>&store_id='+this.value"
            class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-purple-400">
      <?php foreach ($store_list as $s): ?>
        <option value="<?php echo $s['id']; ?>" <?php echo ((int)$s['id'] === $store_id) ? 'selected' : ''; ?>>
          <?php echo esc($s['label']); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
</div>

<!-- 헤더 요약 -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto mb-4">
  <table class="w-full text-sm">
    <thead>
      <tr class="bg-gray-50 text-gray-500 text-xs">
        <th class="px-3 py-2 text-left"><?php echo esc(t('common.date')); ?></th>
        <th class="px-3 py-2 text-right"><?php echo esc(t('daily_report.col_sales')); ?></th>
        <th class="px-3 py-2 text-right"><?php echo esc(t('daily_report.col_credit_customer')); ?></th>
        <th class="px-3 py-2 text-right"><?php echo esc(t('daily_report.col_purchase_cash')); ?></th>
        <th class="px-3 py-2 text-right"><?php echo esc(t('daily_report.col_purchase_check')); ?></th>
        <th class="px-3 py-2 text-right"><?php echo esc(t('daily_report.col_purchase_transfer')); ?></th>
        <th class="px-3 py-2 text-right"><?php echo esc(t('daily_report.col_other_expense')); ?></th>
        <th class="px-3 py-2 text-right"><?php echo esc(t('daily_report.col_collection_cash')); ?></th>
        <th class="px-3 py-2 text-right font-bold"><?php echo esc(t('daily_report.col_profit')); ?></th>
      </tr>
    </thead>
    <tbody>
      <tr class="font-semibold">
        <td class="px-3 py-2"><?php echo esc($date); ?></td>
        <td class="px-3 py-2 text-right"><?php echo fmt2($pos_summary['totals']['total']); ?></td>
        <td class="px-3 py-2 text-right"><?php echo fmt2($ar['credit_sales_total']); ?></td>
        <td class="px-3 py-2 text-right"><?php echo fmt2($purchase['totals']['cash']); ?></td>
        <td class="px-3 py-2 text-right"><?php echo fmt2($purchase['totals']['check']); ?></td>
        <td class="px-3 py-2 text-right"><?php echo fmt2($purchase['totals']['transfer']); ?></td>
        <td class="px-3 py-2 text-right"><?php echo fmt2($grand_expense); ?></td>
        <td class="px-3 py-2 text-right"><?php echo fmt2($ar['collections_total']); ?></td>
        <td class="px-3 py-2 text-right font-bold text-purple-700"><?php echo fmt2($grand_profit); ?></td>
      </tr>
    </tbody>
  </table>
</div>

<div class="grid gap-4 mb-4" style="grid-template-columns:1.4fr 1fr 1.2fr">

  <!-- 포스매출 -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="px-3 py-2 bg-blue-50 border-b border-blue-100 font-semibold text-sm text-blue-800"><?php echo esc(t('daily_report.pos_sales_title')); ?></div>
    <table class="w-full text-xs">
      <thead>
        <tr class="bg-gray-50 text-gray-500">
          <th class="px-2 py-1 text-left"><?php echo esc(t('daily_report.pos')); ?></th>
          <th class="px-2 py-1 text-right"><?php echo esc(t('daily_report.cash')); ?></th>
          <th class="px-2 py-1 text-right"><?php echo esc(t('daily_report.credit')); ?></th>
          <th class="px-2 py-1 text-right"><?php echo esc(t('daily_report.wholesale_col')); ?></th>
          <th class="px-2 py-1 text-right"><?php echo esc(t('common.total')); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pos_summary['rows'] as $row): ?>
        <tr class="border-t border-gray-100">
          <td class="px-2 py-1"><?php echo esc($row['label']); ?></td>
          <td class="px-2 py-1 text-right"><?php echo fmt2($row['cash']); ?></td>
          <td class="px-2 py-1 text-right"><?php echo fmt2($row['credit']); ?></td>
          <td class="px-2 py-1 text-right"><?php echo $row['delivery_slip'] > 0 ? fmt2($row['delivery_slip']) : '-'; ?></td>
          <td class="px-2 py-1 text-right font-medium"><?php echo fmt2($row['total']); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="border-t-2 border-gray-200 font-bold bg-gray-50">
          <td class="px-2 py-1"><?php echo esc(t('common.total')); ?></td>
          <td class="px-2 py-1 text-right"><?php echo fmt2($pos_summary['totals']['cash']); ?></td>
          <td class="px-2 py-1 text-right"><?php echo fmt2($pos_summary['totals']['credit']); ?></td>
          <td class="px-2 py-1 text-right"><?php echo fmt2($pos_summary['totals']['delivery_slip']); ?></td>
          <td class="px-2 py-1 text-right"><?php echo fmt2($pos_summary['totals']['total']); ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- 수수료 코너 — 등록된 입점업체의 pos_sales_data(SUPPLIER 일치) NET SALES 자동 집계 -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="px-3 py-2 bg-yellow-50 border-b border-yellow-100 font-semibold text-sm text-yellow-800"><?php echo esc(t('daily_report.commission_corner')); ?></div>
    <?php if (!$commission_tbl_ready): ?>
    <div class="px-3 py-4 text-xs text-amber-800 bg-amber-50">
      <i class="fa-solid fa-triangle-exclamation mr-1"></i><?php echo esc(t('daily_report.no_table')); ?>
      <a href="run_commission_migration.php" class="underline font-medium"><?php echo esc(t('daily_report.run_migration')); ?></a><?php echo esc(t('daily_report.try_again_suffix')); ?>
    </div>
    <?php else: ?>
    <table class="w-full text-xs">
      <thead>
        <tr class="bg-gray-50 text-gray-500">
          <th class="px-2 py-1 text-left"><?php echo esc(t('daily_report.company_name')); ?></th>
          <th class="px-2 py-1 text-right"><?php echo esc(t('daily_report.sales')); ?></th>
          <th class="px-2 py-1 w-6"></th>
        </tr>
      </thead>
      <tbody id="dr-commission-tbody">
        <?php if (empty($commission['rows'])): ?>
        <tr><td colspan="3" class="px-2 py-4 text-center text-gray-400"><?php echo esc(t('daily_report.no_companies')); ?></td></tr>
        <?php endif; ?>
        <?php foreach ($commission['rows'] as $row): ?>
        <tr class="border-t border-gray-100">
          <td class="px-2 py-1"><?php echo esc($row['supplier_name']); ?></td>
          <td class="px-2 py-1 text-right"><?php echo $row['amount'] > 0 ? fmt2($row['amount']) : '-'; ?></td>
          <td class="px-2 py-1 text-right">
            <button type="button" class="dr-commission-del text-gray-300 hover:text-red-500" data-id="<?php echo $row['id']; ?>" title="<?php echo esc(t('common.delete')); ?>">
              <i class="fa-solid fa-xmark"></i>
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="border-t-2 border-gray-200 font-bold bg-gray-50">
          <td class="px-2 py-1"><?php echo esc(t('common.total')); ?></td>
          <td class="px-2 py-1 text-right"><?php echo fmt2($commission['total']); ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
    <form id="dr-commission-form" class="px-3 py-2 border-t border-gray-100 flex gap-1.5">
      <input type="text" id="dr-commission-input" list="dr-commission-suppliers" placeholder="<?php echo esc(t('daily_report.company_placeholder')); ?>"
             class="flex-1 border border-gray-300 rounded-lg px-2 py-1.5 text-xs focus:ring-2 focus:ring-yellow-400">
      <datalist id="dr-commission-suppliers">
        <?php foreach ($supplier_list as $s): ?>
        <option value="<?php echo esc($s); ?>">
        <?php endforeach; ?>
      </datalist>
      <button type="submit" class="px-3 py-1.5 bg-yellow-600 hover:bg-yellow-700 text-white rounded-lg text-xs font-medium">
        <i class="fa-solid fa-plus mr-1"></i><?php echo esc(t('daily_report.register')); ?>
      </button>
    </form>
    <?php endif; ?>
  </div>

  <!-- 기타지출 요약 (상세 드래그앤드롭 편집은 하단 "기타지출 분류" 섹션 참고 — Design §5.3) -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="px-3 py-2 bg-orange-50 border-b border-orange-100 font-semibold text-sm text-orange-800 flex justify-between items-center">
      <span><?php echo esc(t('daily_report.col_other_expense')); ?></span>
      <span id="dr-unplaced-badge-top" class="text-xs font-normal bg-red-100 text-red-700 px-2 py-0.5 rounded-full <?php echo $other_exp['unplaced_count'] > 0 ? '' : 'hidden'; ?>"><?php echo esc(t('daily_report.unclassified_count', ['n' => $other_exp['unplaced_count']])); ?></span>
    </div>
    <table class="w-full text-xs">
      <thead>
        <tr class="bg-gray-50 text-gray-500">
          <th class="px-2 py-1 text-left"><?php echo esc(t('daily_report.usage_detail')); ?></th>
          <th class="px-2 py-1 text-right"><?php echo esc(t('daily_report.usage_amount')); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($other_exp['categories'] as $key => $label): ?>
        <tr class="border-t border-gray-100">
          <td class="px-2 py-1"><?php echo esc($label); ?></td>
          <td class="px-2 py-1 text-right"><?php echo $other_exp['by_category'][$key] > 0 ? fmt2($other_exp['by_category'][$key]) : '-'; ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="border-t-2 border-gray-200 font-bold bg-gray-50">
          <td class="px-2 py-1"><?php echo esc(t('common.total')); ?></td>
          <td class="px-2 py-1 text-right"><?php echo fmt2($other_exp['total_placed']); ?></td>
        </tr>
      </tfoot>
    </table>
    <div class="px-3 py-2 text-xs border-t border-gray-100">
      <a href="#dr-editor" class="text-orange-700 hover:underline"><i class="fa-solid fa-hand-pointer mr-1"></i><?php echo esc(t('daily_report.classify_dnd')); ?> ↓</a>
    </div>
  </div>
</div>

<div class="grid gap-4 mb-4" style="grid-template-columns:1.4fr 1fr 1fr">

  <!-- 매입 -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="px-3 py-2 bg-teal-50 border-b border-teal-100 font-semibold text-sm text-teal-800"><?php echo esc(t('daily_report.purchase_title')); ?></div>
    <table class="w-full text-xs">
      <thead>
        <tr class="bg-gray-50 text-gray-500">
          <th class="px-2 py-1 text-left"><?php echo esc(t('daily_report.vendor_name')); ?></th>
          <th class="px-2 py-1 text-right"><?php echo esc(t('daily_report.cash_purchase')); ?></th>
          <th class="px-2 py-1 text-right"><?php echo esc(t('daily_report.check_purchase')); ?></th>
          <th class="px-2 py-1 text-right"><?php echo esc(t('daily_report.transfer_purchase')); ?></th>
          <th class="px-2 py-1 text-right"><?php echo esc(t('common.total')); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($purchase['rows'])): ?>
        <tr><td colspan="5" class="px-2 py-4 text-center text-gray-400"><?php echo esc(t('daily_report.no_data')); ?></td></tr>
        <?php endif; ?>
        <?php foreach ($purchase['rows'] as $row): ?>
        <tr class="border-t border-gray-100">
          <td class="px-2 py-1"><?php echo esc($row['supplier']); ?></td>
          <td class="px-2 py-1 text-right"><?php echo $row['cash'] > 0 ? fmt2($row['cash']) : '-'; ?></td>
          <td class="px-2 py-1 text-right"><?php echo $row['check'] > 0 ? fmt2($row['check']) : '-'; ?></td>
          <td class="px-2 py-1 text-right"><?php echo $row['transfer'] > 0 ? fmt2($row['transfer']) : '-'; ?></td>
          <td class="px-2 py-1 text-right font-medium"><?php echo fmt2($row['total']); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="border-t-2 border-gray-200 font-bold bg-gray-50">
          <td class="px-2 py-1"><?php echo esc(t('common.total')); ?></td>
          <td class="px-2 py-1 text-right"><?php echo fmt2($purchase['totals']['cash']); ?></td>
          <td class="px-2 py-1 text-right"><?php echo fmt2($purchase['totals']['check']); ?></td>
          <td class="px-2 py-1 text-right"><?php echo fmt2($purchase['totals']['transfer']); ?></td>
          <td class="px-2 py-1 text-right"><?php echo fmt2($purchase['totals']['total']); ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- 크레딧(CARD, E-MONEY) -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="px-3 py-2 bg-indigo-50 border-b border-indigo-100 font-semibold text-sm text-indigo-800"><?php echo esc(t('daily_report.credit_card_emoney')); ?></div>
    <table class="w-full text-xs">
      <thead>
        <tr class="bg-gray-50 text-gray-500">
          <th class="px-2 py-1 text-left"><?php echo esc(t('daily_report.vendor_name')); ?></th>
          <th class="px-2 py-1 text-right"><?php echo esc(t('daily_report.amount')); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (['BDO', 'GCASH', 'MAYA', 'QR'] as $bucket): ?>
        <tr class="border-t border-gray-100">
          <td class="px-2 py-1"><?php echo $bucket; ?></td>
          <td class="px-2 py-1 text-right"><?php echo $credit_detail[$bucket] > 0 ? fmt2($credit_detail[$bucket]) : '-'; ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="border-t-2 border-gray-200 font-bold bg-gray-50">
          <td class="px-2 py-1"><?php echo esc(t('common.total')); ?></td>
          <td class="px-2 py-1 text-right"><?php echo fmt2($credit_detail['total']); ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- 외상수금 -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="px-3 py-2 bg-rose-50 border-b border-rose-100 font-semibold text-sm text-rose-800"><?php echo esc(t('daily_report.collection_title')); ?></div>
    <table class="w-full text-xs">
      <thead>
        <tr class="bg-gray-50 text-gray-500">
          <th class="px-2 py-1 text-left"><?php echo esc(t('daily_report.vendor_name')); ?></th>
          <th class="px-2 py-1 text-right"><?php echo esc(t('daily_report.amount')); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($ar['collections'])): ?>
        <tr><td colspan="2" class="px-2 py-4 text-center text-gray-400"><?php echo esc(t('daily_report.no_data')); ?></td></tr>
        <?php endif; ?>
        <?php foreach ($ar['collections'] as $row): ?>
        <tr class="border-t border-gray-100">
          <td class="px-2 py-1"><?php echo esc($row['customer_name']); ?></td>
          <td class="px-2 py-1 text-right"><?php echo fmt2($row['amount']); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="border-t-2 border-gray-200 font-bold bg-gray-50">
          <td class="px-2 py-1"><?php echo esc(t('common.total')); ?></td>
          <td class="px-2 py-1 text-right"><?php echo fmt2($ar['collections_total']); ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<div class="grid gap-4 mb-4" style="grid-template-columns:1fr 1fr">

  <!-- 외상 판매 -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="px-3 py-2 bg-rose-50 border-b border-rose-100 font-semibold text-sm text-rose-800"><?php echo esc(t('daily_report.credit_sales_title')); ?></div>
    <table class="w-full text-xs">
      <thead>
        <tr class="bg-gray-50 text-gray-500">
          <th class="px-2 py-1 text-left"><?php echo esc(t('daily_report.vendor_name')); ?></th>
          <th class="px-2 py-1 text-right"><?php echo esc(t('daily_report.pos_register')); ?></th>
          <th class="px-2 py-1 text-right"><?php echo esc(t('daily_report.credit_doc')); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($ar['credit_sales'])): ?>
        <tr><td colspan="3" class="px-2 py-4 text-center text-gray-400"><?php echo esc(t('daily_report.no_data')); ?></td></tr>
        <?php endif; ?>
        <?php foreach ($ar['credit_sales'] as $row): ?>
        <tr class="border-t border-gray-100">
          <td class="px-2 py-1"><?php echo esc($row['customer_name']); ?></td>
          <td class="px-2 py-1 text-right">-</td>
          <td class="px-2 py-1 text-right"><?php echo fmt2($row['amount']); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="border-t-2 border-gray-200 font-bold bg-gray-50">
          <td class="px-2 py-1"><?php echo esc(t('common.total')); ?></td>
          <td class="px-2 py-1 text-right">-</td>
          <td class="px-2 py-1 text-right"><?php echo fmt2($ar['credit_sales_total']); ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- 도매 판매(거래명세서) -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="px-3 py-2 bg-cyan-50 border-b border-cyan-100 font-semibold text-sm text-cyan-800"><?php echo esc(t('daily_report.wholesale_title')); ?></div>
    <table class="w-full text-xs">
      <thead>
        <tr class="bg-gray-50 text-gray-500">
          <th class="px-2 py-1 text-left"><?php echo esc(t('daily_report.vendor_name')); ?></th>
          <th class="px-2 py-1 text-right"><?php echo esc(t('daily_report.amount')); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php $ws_rows = array_merge($wholesale['delivery_k'], $wholesale['whole_sale']); ?>
        <?php if (empty($ws_rows)): ?>
        <tr><td colspan="2" class="px-2 py-4 text-center text-gray-400"><?php echo esc(t('daily_report.no_data')); ?></td></tr>
        <?php endif; ?>
        <?php foreach ($ws_rows as $row): ?>
        <tr class="border-t border-gray-100">
          <td class="px-2 py-1"><?php echo esc($row['customer']); ?></td>
          <td class="px-2 py-1 text-right"><?php echo fmt2($row['amount']); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="border-t-2 border-gray-200 font-bold bg-gray-50">
          <td class="px-2 py-1"><?php echo esc(t('common.total')); ?></td>
          <td class="px-2 py-1 text-right"><?php echo fmt2($wholesale['total']); ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<!-- 기타지출 분류 (드래그앤드롭) — Design §5.3/§11.2 step 4 -->
<div id="dr-editor" class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
  <div class="flex items-center justify-between mb-3">
    <h3 class="text-sm font-bold text-gray-800">
      <i class="fa-solid fa-hand-pointer mr-1 text-orange-600"></i><?php echo esc(t('daily_report.expense_classification')); ?>
    </h3>
    <div class="flex items-center gap-3">
      <span id="dr-unplaced-badge" class="text-xs font-semibold bg-red-100 text-red-700 px-2 py-0.5 rounded-full hidden"></span>
      <span class="text-xs text-gray-500"><?php echo esc(t('daily_report.placed_total')); ?>: <span id="dr-total-placed" class="font-bold text-orange-700">₱ 0.00</span></span>
    </div>
  </div>

  <div id="dr-board" data-date="<?php echo esc($date); ?>" data-store-id="<?php echo $is_super_admin ? $store_id : ''; ?>"
       class="flex gap-3 items-start" style="min-height:400px">

    <!-- 미배치 소스 카드 -->
    <div class="w-64 flex-shrink-0">
      <div class="text-xs font-semibold text-gray-500 mb-1 px-1"><?php echo esc(t('daily_report.unclassified_items')); ?></div>
      <div class="dr-zone dr-source" data-category="__source__"></div>
    </div>

    <!-- 12개 고정 카테고리 드롭존 -->
    <div class="flex-1 grid gap-2" style="grid-template-columns:repeat(3,1fr)">
      <?php foreach ($other_exp['categories'] as $key => $label): ?>
      <div>
        <div class="text-xs font-semibold text-gray-600 mb-1 px-1 flex justify-between">
          <span><?php echo esc($label); ?></span>
          <span class="dr-zone-total font-mono text-orange-700" data-category="<?php echo esc($key); ?>">₱ 0.00</span>
        </div>
        <div class="dr-zone" data-category="<?php echo esc($key); ?>"></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script src="daily-report.js?v=<?php echo filemtime(__DIR__ . '/daily-report.js'); ?>"></script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
