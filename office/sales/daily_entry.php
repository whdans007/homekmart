<?php
// Design Ref: §6 / module-4 — POS 셀 버튼 + Shift Entry 모달 (POS Shift Entry v10 디자인 충실 재현)
$page_title      = 'POS Entry';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';
require_once __DIR__ . '/lib/pos_recon_helper.php';

$store_id = get_office_store_id();
$today    = date('Y-m-d');
$date     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : $today;

$prev_date = date('Y-m-d', strtotime($date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($date . ' +1 day'));
$is_future = $next_date > $today;

$conn = get_db_connection();
$stmt = $conn->prepare("SELECT * FROM sales_daily WHERE store_id=? AND sale_date=?");
$stmt->bind_param('is', $store_id, $date);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc() ?? [];
$stmt->close();
$preload = pos_preload_date($conn, $store_id, $date);
$conn->close();

$saved    = !empty($existing);
$days_en  = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
$dow      = $days_en[date('w', strtotime($date))];
$sales_tab = 'pos';

$shifts = [
    'gy'      => ['label'=>'GY',      'time'=>'12:00AM–8:00AM', 'icon'=>'fa-moon',     'color'=>'#a78bfa'],
    'morning' => ['label'=>'Morning', 'time'=>'8:00AM–5:00PM',  'icon'=>'fa-sun',      'color'=>'#fbbf24'],
    'mid'     => ['label'=>'Mid',     'time'=>'5:00PM–12:00AM', 'icon'=>'fa-cloud-sun','color'=>'#fb923c'],
];
?>
<?php require __DIR__ . '/partials/sales_nav.php'; ?>

<style>
.s-card { background:#fff; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,.06); border:1px solid #f0f0f0; }
.s-table { border-collapse:collapse; width:100%; }
.s-table th, .s-table td { border:1px solid #e5e7eb; padding:10px 16px; vertical-align:middle; }
.s-table thead th { background:#f9fafb; font-size:11px; font-weight:600; text-align:center; color:#6b7280; white-space:nowrap; }
.s-table .shift-col { text-align:left; background:#f9fafb; white-space:nowrap; }
.cell-btn { width:100%; min-width:150px; border:1px dashed #cbd5e1; border-radius:8px; padding:10px 12px; background:#fff; cursor:pointer; transition:all .15s; text-align:center; }
.cell-btn:hover { border-color:#16a34a; background:#f0fdf4; }
.cell-btn.filled { border-style:solid; border-color:#bbf7d0; background:#f0fdf4; }
.cell-total { font-size:15px; font-weight:700; color:#166534; }
.cell-empty { font-size:12px; color:#94a3b8; }
.badge-os { display:inline-block; font-size:10px; padding:1px 6px; border-radius:10px; margin-top:3px; }
.badge-pos { background:#eff6ff; color:#2563eb; } .badge-neg { background:#fef2f2; color:#dc2626; } .badge-zero { background:#f0fdf4; color:#15803d; }

/* ===== modal (v10) ===== */
#pos-modal { position:fixed; inset:0; background:rgba(15,23,42,.5); z-index:60; display:none; align-items:flex-start; justify-content:center; overflow-y:auto; padding:20px 10px; }
#pos-modal.open { display:flex; }
.pm-card { background:#fff; border-radius:14px; width:100%; max-width:1040px; box-shadow:0 20px 60px rgba(0,0,0,.22); overflow:hidden; font-size:14px; }
.num { font-variant-numeric:tabular-nums; }
.sec-label { font-size:11px; font-weight:600; letter-spacing:.04em; text-transform:uppercase; color:#9ca3af; }
.pm-head { padding:18px 22px; display:flex; align-items:flex-start; justify-content:space-between; background:linear-gradient(180deg,#f0fdf4,#fff); border-bottom:1px solid #dcfce7; }
.pm-ico { display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:11px; color:#fff; background:#16a34a; }
.pm-grid { display:grid; grid-template-columns:1fr 1fr; }
.pm-left { padding:18px 22px; border-right:1px solid #f0f0f0; }
.pm-right { padding:18px 22px; }
.ic-card { background:#fff; border-radius:12px; border:1px solid #f0f0f0; box-shadow:0 1px 3px rgba(0,0,0,.06); overflow:hidden; }
.dn-tbl { width:100%; border-collapse:collapse; font-size:14px; }
.dn-tbl thead th { background:#fafafa; font-size:11px; font-weight:600; color:#6b7280; padding:8px 12px; text-align:left; }
.dn-tbl td { padding:5px 12px; border-bottom:1px solid #f3f4f6; }
.dn-tbl tfoot td { background:#f0fdf4; border-top:2px solid #bbf7d0; padding:10px 12px; }
.hl100 { background:#fff7ed; }
.sec-label .sub { text-transform:none; font-weight:400; color:#cbd5e1; }
/* POS 마감 금액 (좌측 상단으로 이동) */
.closing-box { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border-radius:9px; background:#f0f9ff; border:1px solid #bae6fd; margin-bottom:16px; }
/* Expenses(사용내역) 고정 카테고리 테이블 */
.exp-tbl { width:100%; border-collapse:collapse; font-size:14px; }
.exp-tbl thead th { background:#fff5f5; font-size:11px; font-weight:600; color:#6b7280; padding:8px 12px; text-align:left; }
.exp-tbl thead th.r { text-align:right; }
.exp-tbl td { padding:5px 12px; border-bottom:1px solid #f3f4f6; vertical-align:middle; }
.exp-tbl tfoot td { background:#fff1f2; border-top:2px solid #fecdd3; padding:10px 12px; }
.exp-sub { font-size:10px; color:#9ca3af; line-height:1.2; }
.qty-inp { width:64px; border:1px solid #d1d5db; border-radius:7px; padding:6px 8px; font-size:14px; text-align:center; outline:none; }
.qty-inp:focus { border-color:#16a34a; box-shadow:0 0 0 2px #bbf7d0; }
.amt-inp { width:120px; border:1px solid #d1d5db; border-radius:8px; padding:7px 10px; font-size:14px; text-align:right; outline:none; font-variant-numeric:tabular-nums; }
.amt-inp:focus { border-color:#16a34a; box-shadow:0 0 0 2px #bbf7d0; }
.pay-ico { display:inline-flex; align-items:center; justify-content:center; border-radius:9px; width:34px; height:34px; flex-shrink:0; }
.pm-method { margin-bottom:8px; }
.pm-method-head { display:flex; align-items:center; gap:12px; padding:9px 11px; border:1px solid #eef0f2; border-radius:10px; background:#fff; }
.mini { font-size:12px; padding:3px 8px; border-radius:6px; border:1px solid #d1d5db; background:#f8fafc; cursor:pointer; }
.sel { border:1px solid #d1d5db; border-radius:8px; padding:8px 10px; font-size:13px; outline:none; background:#fff; }
.ws-row { display:flex; align-items:center; gap:8px; font-size:13px; padding:5px 8px; border-radius:7px; background:#fff; border:1px solid #eef0f2; }
.chip { font-size:10px; font-weight:700; padding:1px 7px; border-radius:999px; }
.bd-tbl { width:100%; font-size:13px; border-collapse:collapse; }
.bd-tbl td { padding:4px 8px; border-bottom:1px dashed #eee; }
.bd-tbl td.r { text-align:right; }
.pm-foot { padding:14px 22px; display:flex; align-items:center; justify-content:flex-end; gap:12px; background:#fafafa; border-top:1px solid #f0f0f0; }
@media (max-width:820px){ .pm-grid{ grid-template-columns:1fr; } .pm-left{ border-right:none; border-bottom:1px solid #f0f0f0; } }
</style>

<!-- Date navigator -->
<div class="s-card px-4 py-3 mb-4 flex items-center gap-3 flex-wrap">
  <a href="daily_entry.php?date=<?php echo $prev_date; ?>" class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-gray-500 hover:bg-gray-50"><i class="fa-solid fa-chevron-left"></i></a>
  <input type="date" id="entry_date" max="<?php echo $today; ?>" value="<?php echo $date; ?>"
         onchange="location.href='daily_entry.php?date='+this.value"
         class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-green-500">
  <span class="text-sm font-medium text-gray-700"><?php echo $dow; ?></span>
  <?php if ($date === $today): ?>
    <span class="text-xs px-2 py-0.5 bg-green-100 text-green-700 rounded-full font-medium">Today</span>
  <?php else: ?>
    <a href="daily_entry.php?date=<?php echo $today; ?>" class="text-xs px-2 py-1 bg-gray-100 text-gray-600 rounded hover:bg-gray-200">Today</a>
  <?php endif; ?>
  <a href="daily_entry.php?date=<?php echo $next_date; ?>" class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-gray-500 hover:bg-gray-50 <?php echo $is_future ? 'opacity-30 pointer-events-none' : ''; ?>"><i class="fa-solid fa-chevron-right"></i></a>
  <span class="ml-auto text-sm text-gray-400">Click a cell to enter payment methods &amp; cash reconciliation</span>
</div>

<!-- POS Grid -->
<div class="s-card overflow-x-auto mb-4">
  <table class="s-table">
    <thead><tr><th class="shift-col" style="width:200px">SHIFT</th><th>POS 1</th><th>POS 2</th><th style="width:130px">SUBTOTAL</th></tr></thead>
    <tbody>
      <?php foreach ($shifts as $sk => $sm): ?>
      <tr>
        <td class="shift-col">
          <span class="flex items-center gap-2">
            <i class="fa-solid <?php echo $sm['icon']; ?>" style="color:<?php echo $sm['color']; ?>"></i>
            <span><div class="font-semibold text-gray-700 text-sm"><?php echo $sm['label']; ?> Shift</div>
            <div class="text-xs text-gray-400"><?php echo $sm['time']; ?></div></span>
          </span>
        </td>
        <?php for ($pos = 1; $pos <= 2; $pos++): $key = "{$sk}_pos{$pos}"; ?>
        <td class="text-center" id="cell_<?php echo $key; ?>"></td>
        <?php endfor; ?>
        <td class="text-right font-bold" style="color:#16a34a;background:#f0fdf4;white-space:nowrap" id="sub_<?php echo $sk; ?>">0.00</td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="s-card px-5 py-4 flex items-center justify-between" style="background:#16a34a">
  <div><div class="text-xs" style="color:#bbf7d0">DAY TOTAL <span style="color:#86efac">(deposit + other + expenses + POS credit)</span></div>
    <div id="day_total" class="text-2xl font-bold text-white num">₱ 0.00</div></div>
  <div class="text-xs" style="color:#bbf7d0">6 cells</div>
</div>

<!-- ===== MODAL (v10) ===== -->
<div id="pos-modal"><div class="pm-card">
  <div class="pm-head">
    <div class="flex items-center gap-2">
      <span class="pm-ico"><i class="fa-solid fa-cash-register"></i></span>
      <div>
        <div id="m-title" class="text-base font-bold text-gray-800" style="line-height:1.2">POS</div>
        <div class="text-xs text-gray-400">Cashier closing &amp; cash reconciliation</div>
      </div>
    </div>
    <div class="text-right">
      <div class="text-xs text-gray-400">Sale date</div>
      <div class="text-sm font-semibold text-gray-700"><?php echo $date . ' ' . $dow; ?></div>
      <button class="mini mt-1" onclick="closeCell()"><i class="fa-solid fa-xmark"></i> Close</button>
    </div>
  </div>

  <div class="pm-grid">
    <!-- LEFT: closing amount + cash count + starting/deposit + closing check -->
    <div class="pm-left">
      <!-- POS 마감 금액 (상단 이동) -->
      <div class="closing-box">
        <span class="text-xs" style="color:#0369a1;font-weight:500"><i class="fa-solid fa-receipt" style="margin-right:4px"></i>POS Closing Amount <span style="font-weight:400;opacity:.7">/ 마감 금액</span></span>
        <input type="number" step="0.01" min="0" class="amt-inp" id="m-closing" oninput="recalc()" onkeydown="closingKey(event)">
      </div>

      <div class="sec-label"><i class="fa-solid fa-coins" style="color:#fbbf24;margin-right:4px"></i> 1. Cash count</div>
      <div class="ic-card">
        <table class="dn-tbl">
          <thead><tr><th>Denom</th><th style="text-align:center">Qty</th><th style="text-align:right">Subtotal</th></tr></thead>
          <tbody id="m-cash-body"></tbody>
          <tfoot><tr>
            <td class="font-semibold" style="color:#166534">Cash total</td>
            <td class="text-center text-xs num" style="color:#9ca3af" id="m-qty-total">0</td>
            <td class="text-right font-bold num" style="color:#166534" id="m-cash-total">₱ 0.00</td>
          </tr></tfoot>
        </table>
      </div>

      <!-- 준비금 -->
      <div class="ic-card" style="background:#fffdf5;border-color:#fde68a;padding:14px;margin-top:12px">
        <div class="flex items-center justify-between" style="margin-bottom:2px">
          <span class="sec-label" style="color:#b45309;margin-bottom:0"><i class="fa-solid fa-piggy-bank" style="margin-right:4px"></i> Starting Money <span style="font-weight:400;opacity:.7">/ 준비금</span></span>
          <span class="text-xl font-bold num" style="color:#b45309" id="m-start-total">₱ 0.00</span>
        </div>
        <div style="font-size:11px;color:#d97706;margin-bottom:8px">Keep ₱100 first · target ₱ 10,000</div>
        <table class="bd-tbl"><tbody id="m-start-break"></tbody></table>
        <div id="m-start-warn" style="display:none;margin-top:8px;font-size:12px;font-weight:500;color:#b91c1c"></div>
      </div>

      <!-- 입금 -->
      <div class="ic-card" style="background:#f0fdf4;border-color:#bbf7d0;padding:14px;margin-top:12px">
        <div class="flex items-center justify-between" style="margin-bottom:2px">
          <span class="sec-label" style="color:#15803d;margin-bottom:0"><i class="fa-solid fa-money-bill-transfer" style="margin-right:4px"></i> Deposit <span style="font-weight:400;opacity:.7">/ 입금할 현금</span></span>
          <span class="text-xl font-bold num" style="color:#15803d" id="m-deposit-total">₱ 0.00</span>
        </div>
        <div style="font-size:11px;color:#16a34a;margin-bottom:8px">Cash total − starting ₱10,000 · large bills first</div>
        <table class="bd-tbl"><tbody id="m-deposit-break"></tbody></table>
      </div>

      <!-- 6. Closing check -->
      <div style="margin-top:14px">
        <div class="sec-label"><i class="fa-solid fa-scale-balanced" style="color:#9ca3af;margin-right:4px"></i> 6. Closing check <span class="sub">(vs POS closing +/−)</span></div>
        <div class="ic-card" style="padding:14px">
          <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-radius:9px;background:#f9fafb;margin-bottom:10px">
            <span class="text-xs text-gray-500">Cash Total</span>
            <span class="text-lg font-semibold text-gray-800 num" id="m-chk-total">₱ 0.00</span>
          </div>
          <!-- 참고: 포인트 사용 + POS 등록 외상 (시제에는 미포함, 마감 확인용 표시) -->
          <div id="m-chk-detail"></div>
          <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-radius:9px;background:#f9fafb" id="m-diff-box">
            <span class="text-xs font-medium" style="color:#6b7280">Over / Short (+/−)</span>
            <span class="text-2xl font-bold num" id="m-diff">—</span>
          </div>
        </div>
        <div class="flex items-center justify-between" style="margin-top:10px;padding:10px 14px;border-radius:9px;background:#eff6ff;border:1px solid #bfdbfe">
          <span class="text-xs font-medium" style="color:#1d4ed8">Sales Total <span style="color:#93c5fd;font-weight:400">(Cash Total + Expenses + POS credit)</span></span>
          <span class="text-lg font-bold num" style="color:#1d4ed8" id="m-sales-total">₱ 0.00</span>
        </div>
      </div>

      <!-- 7. Manual DR (4번 거래명세서 + 5번 Whole Sale 합산) -->
      <div class="sec-label" style="margin-top:16px"><i class="fa-solid fa-file-invoice-dollar" style="color:#0ea5e9;margin-right:4px"></i> 7. Manual DR</div>
      <div class="ic-card" style="background:#f0f9ff;border-color:#bae6fd;padding:12px">
        <div id="m-mdr-list" style="display:flex;flex-direction:column;gap:6px"></div>
        <div class="flex items-center justify-between" style="margin-top:8px;padding-top:8px;border-top:1px dashed #7dd3fc">
          <span class="text-xs font-semibold" style="color:#0369a1">Subtotal</span>
          <span class="text-sm font-bold num" style="color:#0369a1" id="m-mdr-total">₱ 0.00</span>
        </div>
      </div>

      <!-- Total (deposit + other + Manual DR) -->
      <div class="flex items-center justify-between" style="margin-top:16px;padding:14px 18px;border-radius:12px;background:#16a34a">
        <div class="text-xs font-medium" style="color:#dcfce7">Total Amount <span style="color:#bbf7d0">(POS closing + manual DR)</span></div>
        <div id="m-grand" class="text-2xl font-bold text-white num">₱ 0.00</div>
      </div>
    </div>

    <!-- RIGHT: expenses + other payments + SCC + whole sale -->
    <div class="pm-right">
      <!-- 2. Expenses (사용내역) -->
      <div class="sec-label"><i class="fa-solid fa-receipt" style="color:#fb7185;margin-right:4px"></i> 2. Expenses <span class="sub">(Usage)</span></div>
      <div class="ic-card">
        <table class="exp-tbl">
          <thead><tr><th>Detail</th><th class="r">Amount</th></tr></thead>
          <tbody id="m-exp-body"></tbody>
          <tfoot><tr>
            <td class="font-semibold" style="color:#be123c">Total</td>
            <td class="text-right font-bold num" style="color:#be123c" id="m-exp-total">₱ 0.00</td>
          </tr></tfoot>
        </table>
      </div>
      <div style="font-size:11px;color:#fb7185;margin:8px 0 16px;padding:0 2px"><i class="fa-solid fa-circle-info" style="margin-right:4px"></i> Total (incl. Points Used) is applied in 6. Closing check</div>

      <!-- 3. Other payments -->
      <div class="sec-label"><i class="fa-regular fa-credit-card" style="color:#60a5fa;margin-right:4px"></i> 3. Other payments</div>
      <div id="m-methods"></div>
      <div class="flex items-center justify-between" style="margin-top:8px;padding-top:8px;border-top:1px dashed #bfdbfe">
        <span class="text-xs font-semibold" style="color:#2563eb">Subtotal</span>
        <span class="text-sm font-bold num" style="color:#2563eb" id="m-methods-total">₱ 0.00</span>
      </div>

      <!-- 4. Subsidiary Company Credits (Delivery K 선택 + POS 등록 외상 수기) -->
      <div class="sec-label" style="margin-top:16px"><i class="fa-solid fa-building" style="color:#a78bfa;margin-right:4px"></i> 4. Subsidiary Company Credits</div>
      <div class="ic-card" style="background:#fdf4ff;border-color:#e9d5ff;padding:12px">
        <div class="flex items-center gap-2">
          <select id="m-scc-select" class="sel" style="flex:1" onchange="addSCC()"><option value="">— Select credit invoice / 거래명세서 —</option></select>
          <button type="button" onclick="addCredit()" class="mini" style="padding:8px 11px;white-space:nowrap" title="POS 외상 등록 (거래처 선택)"><i class="fa-solid fa-store"></i> POS</button>
        </div>
        <div style="font-size:11px;color:#a78bfa;margin-top:6px"><i class="fa-solid fa-circle-info" style="margin-right:3px"></i> Credit invoices (거래명세서) registered for this date are shown in the dropdown with preset amounts · Use [POS] to register a POS credit by picking a company</div>
        <div id="m-scc-list" style="display:flex;flex-direction:column;gap:6px;margin-top:8px"></div>
        <div class="flex items-center justify-between" style="margin-top:8px;padding-top:8px;border-top:1px dashed #d8b4fe">
          <span class="text-xs font-semibold" style="color:#7c3aed">Subtotal</span>
          <span class="text-sm font-bold num" style="color:#7c3aed" id="m-scc-total">₱ 0.00</span>
        </div>
      </div>

      <!-- 5. Whole Sale -->
      <div class="sec-label" style="margin-top:16px"><i class="fa-solid fa-boxes-stacked" style="color:#818cf8;margin-right:4px"></i> 5. Whole Sale</div>
      <div class="ic-card" style="background:#f8f8ff;border-color:#e0e7ff;padding:12px">
        <div class="flex items-center gap-2">
          <select id="m-ws-select" class="sel" style="flex:1" onchange="addWS()"><option value="">— Select —</option></select>
        </div>
        <div id="m-ws-list" style="display:flex;flex-direction:column;gap:6px;margin-top:8px"></div>
        <div class="flex items-center justify-between" style="margin-top:8px;padding-top:8px;border-top:1px dashed #c7d2fe">
          <span class="text-xs font-semibold" style="color:#4338ca">Subtotal</span>
          <span class="text-sm font-bold num" style="color:#4338ca" id="m-ws-total">₱ 0.00</span>
        </div>
      </div>
    </div>
  </div>

  <div class="pm-foot">
    <div id="m-msg" style="display:none;margin-right:auto;font-size:13px;font-weight:500"></div>
    <button class="mini" onclick="printCell()"><i class="fa-solid fa-print"></i> Daybook</button>
    <button class="mini" style="padding:9px 16px;border-radius:11px" onclick="closeCell()">Cancel</button>
    <button class="px-6 py-2.5 rounded-xl text-sm font-medium text-white" style="background:#16a34a;border:none;cursor:pointer" onclick="saveCell()"><i class="fa-solid fa-floppy-disk" style="margin-right:4px"></i> Save</button>
  </div>
</div></div>

<script>
const SALE_DATE = <?php echo json_encode($date); ?>;
const DENOMS = [1000,500,200,100,50,20,10,5,1];
// 준비금 retain 우선순위: 소액권(₱100 우선)부터 ₱10,000 까지 보존. (서버 POS_RETAIN_PRIORITY 와 동일)
const RETAIN_PRIORITY = [100,50,20,10,5,1,200,500,1000];
const START_TARGET = 10000;
const METHODS = [
  {key:'card',        label:'Credit/Debit Card', icon:'fa-solid fa-credit-card',     bg:'#eff6ff', fg:'#2563eb'},
  {key:'gcash',       label:'Gcash',       icon:'fa-solid fa-mobile-screen-button',  bg:'#eef2ff', fg:'#4f46e5'},
  {key:'paymaya',     label:'PayMaya',     icon:'fa-solid fa-wallet',                bg:'#f0fdfa', fg:'#0d9488'},
  {key:'phqr',        label:'PhQR',        icon:'fa-solid fa-qrcode',                bg:'#f5f3ff', fg:'#7c3aed'}
];
// 과거 저장분 하위호환: credit_card/debit_card 로 저장된 기존 데이터를 합쳐진 'card' 항목으로 매핑
const METHOD_KEY_ALIAS = {credit_card:'card', debit_card:'card'};
// Expenses 고정 카테고리. key = DB 저장 detail(변경 금지·기존 데이터 호환), label = 화면/인쇄 영문 표기.
const EXPENSE_CATS = [
  {key:'반품', label:'Returns'},
  {key:'인건비', label:'Labor', sub:'SSS · PAG-IBIG · PhilHealth'},
  {key:'전기세 · 관리비 · CDC · BIR', label:'Electricity · Maintenance · CDC · BIR'},
  {key:'PLDT · LPG · 방역', label:'PLDT · LPG · Pest Control'},
  {key:'사무실 (비품)', label:'Office (Supplies)'},
  {key:'농산 · 축산 · 수산 · 키친', label:'Produce · Meat · Seafood · Kitchen'},
  {key:'차량 유지비', label:'Vehicle Maintenance'},
  {key:'일반할인 5%', label:'General Discount 5%'},
  {key:'한인회 5%', label:'Korean Association 5%'},
  {key:'생수', label:'Drinking Water'},
  {key:'기타', label:'Others', sub:'Bottle Deposit · Plastic Container · Medical'},
  {key:'포인트 사용', label:'Points Used', isPoint:true}
];
const SHIFT_LABEL = {gy:'GY', morning:'Morning', mid:'Mid'};
const SHIFT_TIME  = {gy:'12AM–8AM', morning:'8AM–5PM', mid:'5PM–12AM'};
let PRELOAD = <?php echo json_encode($preload, JSON_UNESCAPED_UNICODE); ?>;
let CUR = null;
let WS_CAND = null;          // 후보 캐시 (거래명세서 credit_doc + wholesale 혼합)
let CREDIT_COMPANIES = [];   // [POS] 버튼 외상 등록용 거래처 목록 (credit_customers)
let entries = {};            // method -> [{desc,amt}]
let sccPicked = [];          // 섹션4: Delivery K 선택 + POS 등록 외상(credit, 수기) [{source_type,source_id,client,remark,amount,manual}]
let wsPicked = [];           // Whole Sale 선택 [{...}]

function fmt(n){ return (Math.round((n||0)*100)/100).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function peso(n){ return '₱ ' + fmt(n); }
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function allocate(qty){
  let cash=0; DENOMS.forEach(d=>cash+=d*(qty[d]||0));
  const shortage=cash < START_TARGET;
  const shortfall=Math.max(0, START_TARGET-cash);
  // 입금은 큰 권종 우선으로 (현금 − 10,000) 만큼 구성 → 소액권은 최대한 준비금(거스름)으로 남김.
  //   정확히 10,000 맞추기 위한 소액만 입금으로 빠지고, 소액권을 무조건 전부 준비금으로 잡지 않음.
  let dq={}; DENOMS.forEach(d=>dq[d]=0);
  if(cash > START_TARGET){
    let dep=Math.round(cash - START_TARGET);
    for(const d of DENOMS){ if(dep<=0)break; const t=Math.min(qty[d]||0, Math.floor(dep/d)); dq[d]=t; dep-=t*d; }
  }
  let sq={}; DENOMS.forEach(d=>sq[d]=(qty[d]||0)-dq[d]);
  let starting=0; DENOMS.forEach(d=>starting+=d*sq[d]);
  const deposit=Math.round((cash-starting)*100)/100;
  return {cash, starting, deposit, shortage, shortfall, sq, dq};
}

// ── grid ──
// 셀 표시 매출(그 POS의 총 매출) = r.total_amount (서버 산출: 입금분 + 기타결제 + 지출 합계 + POS 등록 외상)
// 셀 Over/Short = 마감금액(expected_cash) − 그 POS의 총 매출(total_amount)
// (모달 6. Closing check 와 동일 산식)
function cellOverShort(d){
  const r=d.recon||{};
  if(r.expected_cash===null || r.expected_cash===undefined || r.expected_cash==='') return null;
  const pos=parseFloat(r.expected_cash); if(isNaN(pos)) return null;
  const total=parseFloat(r.total_amount)||0;
  return Math.round((pos-total)*100)/100;
}
function renderGrid(){
  let day=0;
  for(const sk of ['gy','morning','mid']){
    let sub=0;
    for(let p=1;p<=2;p++){
      const key=sk+'_pos'+p, d=(PRELOAD[key]||{}), r=d.recon;
      const td=document.getElementById('cell_'+key);
      if(r && parseFloat(r.total_amount)>0){
        const tot=Math.round(parseFloat(r.total_amount)*100)/100; sub+=tot; day+=tot;
        let badge='';
        const os=cellOverShort(d);
        if(os!==null){
          const cls=Math.abs(os)<0.005?'badge-zero':(os>0?'badge-pos':'badge-neg');
          const sign=os>0?'+':(os<0?'−':'');
          badge=`<div class="badge-os ${cls}">${sign}${fmt(Math.abs(os))}</div>`;
        }
        td.innerHTML=`<button class="cell-btn filled" onclick="openCell('${sk}',${p})"><div class="cell-total">${peso(tot)}</div>${badge}<div style="font-size:10px;color:#94a3b8;margin-top:2px">Edit · <i class="fa-solid fa-print"></i></div></button>`;
      } else {
        td.innerHTML=`<button class="cell-btn" onclick="openCell('${sk}',${p})"><div class="cell-empty"><i class="fa-solid fa-plus"></i> Enter</div></button>`;
      }
    }
    document.getElementById('sub_'+sk).textContent=fmt(sub);
  }
  document.getElementById('day_total').textContent=peso(day);
}

// ── open ──
function openCell(shift,pos){
  CUR={shift,pos};
  const key=shift+'_pos'+pos, d=PRELOAD[key]||{};
  document.getElementById('m-title').innerHTML='POS '+pos+' &middot; '+SHIFT_LABEL[shift]+' <span class="text-xs font-normal text-gray-400">('+SHIFT_TIME[shift]+')</span>';

  // cash rows (₱100 강조)
  const cashMap=d.cash||{};
  let body='';
  DENOMS.forEach(dn=>{
    const q=parseInt(cashMap[dn]||0)||0, is100=dn===100;
    body+=`<tr${is100?' class="hl100"':''}>
      <td class="text-gray-700"><span style="color:#9ca3af">₱</span> ${dn.toLocaleString()}</td>
      <td class="text-center"><input type="number" min="0" class="qty-inp" data-d="${dn}" value="${q||''}" oninput="recalc()" onkeydown="cashKey(event)"></td>
      <td class="text-right num text-gray-700" id="m-sub-${dn}">0.00</td></tr>`;
  });
  document.getElementById('m-cash-body').innerHTML=body;

  // payments → entries (과거 credit_card/debit_card 저장분은 병합된 'card' 항목으로 합산)
  entries={}; METHODS.forEach(m=>entries[m.key]=[]);
  (d.payments||[]).forEach(p=>{
    const key=METHOD_KEY_ALIAS[p.method]||p.method;
    if(entries[key]) entries[key].push({desc:p.description||'', amt:parseFloat(p.amount)||0});
  });
  buildMethods();

  // expenses → 고정 카테고리 채움 (detail 라벨 매칭)
  buildExpenses(d.expenses||[]);

  // closing
  const r=d.recon||{};
  document.getElementById('m-closing').value=(r.expected_cash!==null && r.expected_cash!==undefined && r.expected_cash!=='')? r.expected_cash : '';

  // wholesale 저장분 → 섹션4(Delivery K + 외상 credit) / 섹션5(Whole Sale) 분리
  sccPicked=[]; wsPicked=[];
  (d.wholesale||[]).forEach(w=>{
    const isDoc=(w.source_type==='credit_doc');       // admin 거래명세서 참조 (읽기 전용·금액 preset)
    const isCredit=(w.source_type==='credit');         // POS 외상 (거래처 선택 + 금액)
    const sid=String(w.source_id);
    const obj={source_type:w.source_type, source_id:sid, client:w.client||'', remark:w.remark||'', amount:parseFloat(w.amount)||0,
               isDoc:isDoc, isPos:isCredit};
    if(w.source_type==='wholesale') wsPicked.push(obj); else sccPicked.push(obj); // credit/credit_doc/delivery_k → 섹션4
  });
  loadWSOptions();

  const msg=document.getElementById('m-msg'); msg.style.display='none';
  document.getElementById('pos-modal').classList.add('open');
  recalc();
}
function closeCell(){ document.getElementById('pos-modal').classList.remove('open'); CUR=null; }

// POS Closing Amount(마감 금액)에서 엔터 → ₱1000 현금 입력 칸으로 포커스 이동
function closingKey(e){
  if(e.key!=='Enter') return;
  e.preventDefault();
  const inp=document.querySelector('#m-cash-body .qty-inp[data-d="1000"]');
  if(inp){ inp.focus(); inp.select(); }
}

// Cash count: 엔터 시 다음 권종(1000→500→…→1) 칸으로 자동 포커스 이동. 마지막(₱1)에서는 유지.
function cashKey(e){
  if(e.key!=='Enter') return;
  e.preventDefault();
  const inps=Array.from(document.querySelectorAll('#m-cash-body .qty-inp'));
  const i=inps.indexOf(e.target);
  if(i>-1 && i<inps.length-1){ const n=inps[i+1]; n.focus(); n.select(); }
  else if(i===inps.length-1){ e.target.blur(); }
}

// ── methods (결제수단별 금액 1칸 직접 입력) ──
function methodTotal(k){ return (entries[k]||[]).reduce((s,e)=>s+(e.amt||0),0); }
function buildMethods(){
  const wrap=document.getElementById('m-methods'); wrap.innerHTML='';
  METHODS.forEach(m=>{
    // 기존에 여러 줄로 저장된 값이 있으면 합산해서 단일 금액으로 표시 (라인 추가 기능 제거)
    const sum=(entries[m.key]||[]).reduce((s,e)=>s+(parseFloat(e.amt)||0),0);
    entries[m.key]=[{desc:'',amt:sum}];
    const blk=document.createElement('div'); blk.className='pm-method'; blk.dataset.k=m.key;
    blk.innerHTML=`<div class="pm-method-head">
        <span class="pay-ico" style="background:${m.bg};color:${m.fg}"><i class="${m.icon}"></i></span>
        <span style="flex:1;font-weight:500;color:#374151">${m.label}</span>
        <input type="number" step="0.01" min="0" placeholder="0.00" class="amt-inp method-inp" style="width:120px" value="${sum||''}" oninput="setMethodAmt('${m.key}',this.value)" onkeydown="methodKey(event)"></div>`;
    wrap.appendChild(blk);
  });
}
function setMethodAmt(k,v){ entries[k][0].amt=parseFloat(v)||0; recalc(); }
// Other payments: 엔터 시 다음 결제수단 칸으로 자동 포커스 이동. 마지막 칸에서는 유지.
function methodKey(e){
  if(e.key!=='Enter') return;
  e.preventDefault();
  const inps=Array.from(document.querySelectorAll('#m-methods .method-inp'));
  const i=inps.indexOf(e.target);
  if(i>-1 && i<inps.length-1){ const n=inps[i+1]; n.focus(); n.select(); }
  else if(i===inps.length-1){ e.target.blur(); }
}

// ── expenses (고정 카테고리) ──
function buildExpenses(saved){
  const map={}; (saved||[]).forEach(e=>{ map[e.detail]=parseFloat(e.amount)||0; });
  const b=document.getElementById('m-exp-body'); b.innerHTML='';
  EXPENSE_CATS.forEach((c,i)=>{
    const val = (map[c.key]!==undefined) ? map[c.key] : '';
    const tr=document.createElement('tr'); if(c.isPoint) tr.className='hl100';
    const sub=c.sub?`<div class="exp-sub">${esc(c.sub)}</div>`:'';
    const ico=c.isPoint?'<i class="fa-solid fa-coins" style="color:#fbbf24;font-size:10px;margin-right:4px"></i>':'';
    tr.innerHTML=`<td class="text-gray-700">${ico}${esc(c.label)}${sub}</td>
      <td class="text-right"><input type="number" min="0" step="0.01" class="amt-inp exp-a" data-exp="${i}" value="${val}" style="width:108px" oninput="recalc()"></td>`;
    b.appendChild(tr);
  });
}
function expTotal(){ // 2. Expenses 전체 합계 (포인트 사용 포함) — 6. Closing check 에서 사용
  let s=0;
  document.querySelectorAll('.exp-a').forEach(inp=>{ s+=parseFloat(inp.value)||0; });
  return Math.round(s*100)/100;
}

// ── wholesale / delivery K (admin 입력분 선택) ──
function buildSelects(){
  const items=WS_CAND||[];
  const sccSel=document.getElementById('m-scc-select'); sccSel.innerHTML='<option value="">— Select —</option>';
  const wsSel =document.getElementById('m-ws-select');  wsSel.innerHTML ='<option value="">— Select —</option>';
  items.forEach((it,idx)=>{
    const k=it.source_type+':'+it.source_id;
    if(it.source_type==='wholesale'){
      if(wsPicked.some(w=>w.source_type+':'+w.source_id===k)) return;
      const o=document.createElement('option'); o.value=idx; o.textContent=(it.client||it.remark||'Whole Sale')+' — '+peso(it.amount); wsSel.appendChild(o);
    } else if(it.source_type==='credit_doc'){ // admin 거래명세서 → 섹션4 (금액 preset)
      if(sccPicked.some(w=>w.source_type+':'+w.source_id===k)) return;
      const o=document.createElement('option'); o.value=idx; o.textContent=(it.client||it.remark||'Credit')+' — '+peso(it.amount); sccSel.appendChild(o);
    }
  });
}
function loadWSOptions(){
  // 다른 셀(shift×pos)에서 이미 사용된 Whole Sale/거래명세서 항목은 서버가 제외해서 내려줌 → 중복 입력 방지.
  // 셀을 열 때마다 최신 사용 현황을 반영해야 하므로 캐시하지 않고 매번 새로 조회한다.
  const q='ajax_wholesale_picklist.php?date='+SALE_DATE+(CUR?('&shift='+CUR.shift+'&pos_no='+CUR.pos):'');
  fetch(q).then(r=>r.json()).then(d=>{ WS_CAND=d.items||[]; CREDIT_COMPANIES=d.companies||[]; buildSelects(); renderSCC(); renderWS(); })
    .catch(()=>{ WS_CAND=[]; CREDIT_COMPANIES=[]; buildSelects(); renderSCC(); renderWS(); });
}
function newCreditId(){   // 셀 내 음수·유니크 (INT 범위 안전 · 양수 admin id와 충돌 없음)
  let min=0; sccPicked.forEach(w=>{ const n=parseInt(w.source_id); if(!isNaN(n)&&n<min) min=n; });
  return String(min-1);
}
function addSCC(){
  const sel=document.getElementById('m-scc-select'); const idx=sel.value;
  if(idx===''){ return; }                                    // 빈 선택은 무시 (선택 시 자동 추가)
  const it=(WS_CAND||[])[parseInt(idx)]; if(!it)return;
  // admin 거래명세서(credit_doc) 선택 → 이름·금액 고정(읽기 전용). 금액은 거래명세서 최종금액.
  sccPicked.push({source_type:it.source_type, source_id:String(it.source_id), client:it.client||'', remark:it.remark||it.client||'', amount:parseFloat(it.amount)||0, isDoc:true});
  buildSelects(); renderSCC(); recalc();
}
function addCredit(){                                          // [POS] 외상 등록 행 추가 (거래처 선택 + 금액)
  sccPicked.push({source_type:'credit', source_id:newCreditId(), client:'', remark:'', amount:0, isPos:true});
  renderSCC(); recalc();
  const sels=document.querySelectorAll('#m-scc-list .pos-cust'); const sel=sels[sels.length-1]; if(sel) sel.focus();
}
function setCredit(k,f,v){ const c=sccPicked.find(w=>w.source_type+':'+w.source_id===k); if(!c)return; if(f==='amount'){ c.amount=parseFloat(v)||0; recalc(); } else c.remark=v; }
// [POS] 외상 행에서 거래처 선택 → source_id=거래처 id(양수)로 갱신, 이름 세팅. (AR 거래처 잔액 귀속)
function setPOSCust(k, cid){
  const c=sccPicked.find(w=>w.source_type+':'+w.source_id===k); if(!c)return;
  if(cid===''){ return; }
  if(sccPicked.some(w=>w!==c && w.source_type==='credit' && String(w.source_id)===String(cid))){
    alert('이미 선택된 거래처입니다.'); renderSCC(); return;
  }
  const co=CREDIT_COMPANIES.find(x=>String(x.id)===String(cid));
  c.source_id=String(cid); c.client=co?co.name:''; c.remark=co?co.name:'';
  renderSCC(); recalc();
}
function removeSCC(k){ sccPicked=sccPicked.filter(w=>w.source_type+':'+w.source_id!==k); buildSelects(); renderSCC(); recalc(); }
// 섹션4 소계(참고용) — 거래명세서·수기 외상 모두 표시. 단, 시제(셀 총액)에는 포함하지 않는다(recalc 참고).
function sccTotal(){ return sccPicked.reduce((s,w)=>s+(w.amount||0),0); }
function renderSCC(){
  const list=document.getElementById('m-scc-list'); list.innerHTML='';
  if(!sccPicked.length){ list.innerHTML='<div style="font-size:12px;color:#cbd5e1;font-style:italic;padding:2px">No items selected</div>'; }
  sccPicked.forEach(w=>{
    const k=w.source_type+':'+w.source_id;
    const row=document.createElement('div'); row.className='ws-row';
    if(w.isDoc){        // admin 거래명세서(credit_transactions) 참조: 이름·금액 고정(읽기 전용)
      row.innerHTML=`<span class="chip" style="background:#dcfce7;color:#166534">거래명세서</span>
        <span style="flex:1;color:#374151">${esc(w.client||w.remark||'—')}</span>
        <span class="num font-medium text-gray-800">${peso(w.amount)}</span>
        <button class="mini" onclick="removeSCC('${k}')"><i class="fa-solid fa-xmark"></i></button>`;
    } else if(w.isPos){   // POS 외상: 등록 거래처 선택 + 금액 입력
      let opts='<option value="">— 거래처 선택 —</option>';
      CREDIT_COMPANIES.forEach(co=>{ opts+=`<option value="${co.id}"${String(co.id)===String(w.source_id)?' selected':''}>${esc(co.name)}</option>`; });
      row.innerHTML=`<span class="chip" style="background:#dbeafe;color:#1d4ed8">POS</span>
        <select class="pos-cust" style="flex:1;border:1px solid #e5e7eb;border-radius:7px;padding:4px 8px;font-size:13px;background:#fff" onchange="setPOSCust('${k}',this.value)">${opts}</select>
        <input type="number" step="0.01" style="width:96px;border:1px solid #e5e7eb;border-radius:7px;padding:4px 8px;font-size:13px;text-align:right" placeholder="0.00" value="${w.amount||''}" oninput="setCredit('${k}','amount',this.value)">
        <button class="mini" onclick="removeSCC('${k}')"><i class="fa-solid fa-xmark"></i></button>`;
    } else {        // 레거시(예: Delivery K) 저장분 — 읽기 전용
      row.innerHTML=`<span class="chip" style="background:#f3e8ff;color:#7c3aed">Delivery K</span>
        <span style="flex:1;color:#374151">${esc(w.remark||w.client||'—')}</span>
        <span class="num font-medium text-gray-800">${peso(w.amount)}</span>
        <button class="mini" onclick="removeSCC('${k}')"><i class="fa-solid fa-xmark"></i></button>`;
    }
    list.appendChild(row);
  });
  document.getElementById('m-scc-total').textContent=peso(sccTotal());
}
function addWS(){
  const sel=document.getElementById('m-ws-select'); const idx=sel.value; if(idx==='')return;
  const it=(WS_CAND||[])[parseInt(idx)]; if(!it)return;
  wsPicked.push({source_type:it.source_type, source_id:String(it.source_id), client:it.client||'', remark:it.remark||'', amount:parseFloat(it.amount)||0});
  buildSelects(); renderWS(); recalc();
}
function removeWS(k){ wsPicked=wsPicked.filter(w=>w.source_type+':'+w.source_id!==k); buildSelects(); renderWS(); recalc(); }
function wsTotal(){ return wsPicked.reduce((s,w)=>s+(w.amount||0),0); }
function renderWS(){
  const list=document.getElementById('m-ws-list'); list.innerHTML='';
  if(!wsPicked.length){ list.innerHTML='<div style="font-size:12px;color:#cbd5e1;font-style:italic;padding:2px">No items selected</div>'; }
  wsPicked.forEach(w=>{
    const row=document.createElement('div'); row.className='ws-row';
    row.innerHTML=`<span class="chip" style="background:#e0e7ff;color:#4338ca">Whole Sale</span>
      <span style="flex:1;color:#374151">${esc(w.client||w.remark||'—')}</span>
      <span class="num font-medium text-gray-800">${peso(w.amount)}</span>
      <button class="mini" onclick="removeWS('${w.source_type}:${w.source_id}')"><i class="fa-solid fa-xmark"></i></button>`;
    list.appendChild(row);
  });
  document.getElementById('m-ws-total').textContent=peso(wsTotal());
}

// ── breakdown render ──
function renderBreak(elId, counts, color){
  const el=document.getElementById(elId); el.innerHTML=''; let any=false;
  DENOMS.forEach(d=>{ const q=counts[d]||0; if(q<=0)return; any=true;
    const tr=document.createElement('tr');
    tr.innerHTML=`<td>₱ ${d.toLocaleString()}</td><td class="r" style="font-weight:700;color:${color}">× ${q}</td><td class="r num text-gray-700">${peso(q*d)}</td>`;
    el.appendChild(tr);
  });
  if(!any) el.innerHTML='<tr><td colspan="3" style="font-size:12px;color:#cbd5e1;font-style:italic">—</td></tr>';
}

// ── recalc ──
function recalc(){
  const qty={}; let cash=0, qt=0;
  document.querySelectorAll('.qty-inp').forEach(i=>{ const q=parseInt(i.value)||0; qty[i.dataset.d]=q; qt+=q; cash+=q*i.dataset.d; });
  DENOMS.forEach(d=>{ const el=document.getElementById('m-sub-'+d); if(el) el.textContent=fmt(d*(qty[d]||0)); });
  const a=allocate(qty);
  document.getElementById('m-cash-total').textContent=peso(a.cash);
  document.getElementById('m-qty-total').textContent=qt;
  document.getElementById('m-start-total').textContent=peso(a.starting);
  document.getElementById('m-deposit-total').textContent=peso(a.deposit);
  renderBreak('m-start-break', a.sq, '#b45309');
  renderBreak('m-deposit-break', a.dq, '#15803d');
  const warn=document.getElementById('m-start-warn');
  if(a.shortage && a.cash>0){ warn.style.display='block'; warn.textContent='⚠ Cash short — starting ₱10,000 not met ('+peso(a.shortfall)+' short)'; }
  else warn.style.display='none';

  // expenses (포인트 제외)
  document.getElementById('m-exp-total').textContent=peso(expTotal());
  // methods
  let other=0; METHODS.forEach(m=>{ other+=methodTotal(m.key); });
  document.getElementById('m-methods-total').textContent=peso(other);
  // credits + wholesale
  const scc=sccTotal(), ws=wsTotal();
  document.getElementById('m-scc-total').textContent=peso(scc);
  document.getElementById('m-ws-total').textContent=peso(ws);
  // total(시제/매출) = 입금액(현금 − 준비금) + 기타결제(카드·Gcash 등)만.
  // 섹션4 신용거래(credits)·섹션5 도매(WS)는 매출에서 제외(참고·기록용).
  const salesCash = a.deposit;
  const total=Math.round((salesCash+other)*100)/100;

  // 7. Manual DR = 4번 거래명세서(credit_doc) + 5번 Whole Sale (참고·기록용, 시제 매출과 별도 합산)
  const mdrItems=sccPicked.filter(w=>w.isDoc)
      .map(w=>({tag:'거래명세서',bg:'#dcfce7',fg:'#166534',name:w.client||w.remark||'—',amount:w.amount||0}))
    .concat(wsPicked.map(w=>({tag:'Whole Sale',bg:'#e0e7ff',fg:'#4338ca',name:w.client||w.remark||'—',amount:w.amount||0})));
  let mdrHtml='';
  if(!mdrItems.length){ mdrHtml='<div style="font-size:12px;color:#cbd5e1;font-style:italic;padding:2px">No items</div>'; }
  else mdrItems.forEach(it=>{ mdrHtml+=`<div class="ws-row"><span class="chip" style="background:${it.bg};color:${it.fg}">${it.tag}</span><span style="flex:1;color:#374151">${esc(it.name)}</span><span class="num font-medium text-gray-800">${peso(it.amount)}</span></div>`; });
  document.getElementById('m-mdr-list').innerHTML=mdrHtml;
  const mdrTotal=Math.round(mdrItems.reduce((s,it)=>s+it.amount,0)*100)/100;
  document.getElementById('m-mdr-total').textContent=peso(mdrTotal);

  // Total Amount = POS Closing Amount(마감 금액) + Manual DR 합계
  const posClosing=parseFloat(document.getElementById('m-closing').value)||0;
  document.getElementById('m-grand').textContent=peso(posClosing+mdrTotal);
  document.getElementById('m-chk-total').textContent=peso(total);

  // 6. Closing check 상세: 2. Expenses 합계(포인트 사용 포함) + POS 등록 외상 (참고 표시 · 시제 미포함)
  const expAmt=expTotal();
  const posCredits=sccPicked.filter(w=>w.isPos || w.source_type==='credit');
  let detail='';
  if(expAmt>0){
    detail+=`<div style="display:flex;align-items:center;justify-content:space-between;padding:5px 14px">
        <span class="text-xs" style="color:#be123c"><i class="fa-solid fa-receipt" style="margin-right:5px;color:#fb7185"></i>Expenses Total</span>
        <span class="text-sm num" style="color:#be123c">${fmt(expAmt)}</span></div>`;
  }
  posCredits.forEach(w=>{
    const nm=w.client||w.remark||'—';
    detail+=`<div style="display:flex;align-items:center;justify-content:space-between;padding:5px 14px">
        <span class="text-xs" style="color:#1d4ed8;display:flex;align-items:center;gap:6px"><span class="chip" style="background:#dbeafe;color:#1d4ed8">POS</span>${esc(nm)}</span>
        <span class="text-sm num" style="color:#1d4ed8">${fmt(w.amount)}</span></div>`;
  });
  const detailBox=document.getElementById('m-chk-detail');
  detailBox.innerHTML=detail;
  detailBox.style.marginBottom = detail ? '10px' : '0';

  // closing diff = 마감 금액(POS closing) − Total − Expenses 합계(2번) − POS 등록 외상 합계
  const posCreditSum=posCredits.reduce((s,w)=>s+(w.amount||0),0);

  // Sales Total(그 POS의 총 매출) = Cash Total(입금분+기타결제) + Expenses 합계 + POS 등록 외상 (서버 total_amount 와 동일 산식)
  const salesTotal=Math.round((total+expAmt+posCreditSum)*100)/100;
  document.getElementById('m-sales-total').textContent=peso(salesTotal);

  const pos=parseFloat(document.getElementById('m-closing').value);
  const dv=document.getElementById('m-diff'), box=document.getElementById('m-diff-box');
  if(isNaN(pos)){ dv.textContent='—'; dv.style.color='#9ca3af'; box.style.background='#f9fafb'; }
  else { const diff=Math.round((pos-total-expAmt-posCreditSum)*100)/100;
    if(Math.abs(diff)<0.005){ dv.textContent='₱ 0.00 ✓'; dv.style.color='#15803d'; box.style.background='#f0fdf4'; }
    else if(diff>0){ dv.textContent='+ '+peso(diff); dv.style.color='#2563eb'; box.style.background='#eff6ff'; }
    else { dv.textContent='− '+peso(Math.abs(diff)); dv.style.color='#dc2626'; box.style.background='#fef2f2'; } }
}

// ── save ──
function saveCell(){
  if(!CUR)return;
  const fd=new FormData();
  fd.append('sale_date',SALE_DATE); fd.append('shift',CUR.shift); fd.append('pos_no',CUR.pos);
  document.querySelectorAll('.qty-inp').forEach(i=>{ const q=parseInt(i.value)||0; if(q>0) fd.append('cash['+i.dataset.d+']',q); });
  let pi=0; METHODS.forEach(m=>{ (entries[m.key]||[]).forEach(e=>{ if((e.amt||0)===0)return; fd.append(`pay[${pi}][method]`,m.key); fd.append(`pay[${pi}][description]`,e.desc||''); fd.append(`pay[${pi}][amount]`,e.amt); pi++; }); });
  // expenses: 고정 카테고리 라벨을 detail 로 저장 (0 이 아닌 항목만). 포인트 사용도 값 보존 위해 저장.
  let ei=0; document.querySelectorAll('.exp-a').forEach(inp=>{ const i=parseInt(inp.dataset.exp); const amt=parseFloat(inp.value)||0; if(amt===0)return; fd.append(`exp[${ei}][detail]`,EXPENSE_CATS[i].key); fd.append(`exp[${ei}][amount]`,amt); ei++; });
  // 섹션4(delivery_k + 외상 credit) + 섹션5(wholesale) 통합 저장 (백엔드가 source_type 으로 구분)
  // 빈 외상 수기 행(금액 0·내용 공백)은 제외.
  let wi=0; sccPicked.concat(wsPicked).forEach(w=>{
    // 금액 미입력 외상(등록/수기)은 저장 제외
    if(w.source_type==='credit' && (w.amount||0)===0) return;
    fd.append(`ws[${wi}][source_type]`,w.source_type); fd.append(`ws[${wi}][source_id]`,w.source_id); fd.append(`ws[${wi}][amount]`,w.amount); fd.append(`ws[${wi}][client]`,w.client||''); fd.append(`ws[${wi}][remark]`,w.remark||''); wi++;
  });
  const cv=document.getElementById('m-closing').value; if(cv!=='') fd.append('expected_cash',cv);

  const msg=document.getElementById('m-msg');
  fetch('ajax_save_pos_cell.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    msg.style.display='block';
    if(d.success){
      const key=CUR.shift+'_pos'+CUR.pos;
      // 저장된 셀 상세를 클라이언트 캐시(PRELOAD)에 반영 — 같은 세션에서 재열람 시 입력값(특히 Expenses)이 유지되도록.
      const cashMap={};
      document.querySelectorAll('.qty-inp').forEach(i=>{ const q=parseInt(i.value)||0; if(q>0) cashMap[i.dataset.d]=q; });
      const payArr=[];
      METHODS.forEach(m=>{ (entries[m.key]||[]).forEach(e=>{ if((e.amt||0)!==0) payArr.push({method:m.key, description:e.desc||'', amount:e.amt}); }); });
      const expArr=[];
      document.querySelectorAll('.exp-a').forEach(inp=>{ const idx=parseInt(inp.dataset.exp); const amt=parseFloat(inp.value)||0; if(amt===0)return; expArr.push({detail:EXPENSE_CATS[idx].key, amount:amt}); });
      const wsArr=sccPicked.concat(wsPicked)
        .filter(w=>!(w.source_type==='credit' && (w.amount||0)===0))
        .map(w=>({source_type:w.source_type, source_id:w.source_id, client:w.client||'', remark:w.remark||'', amount:w.amount}));
      PRELOAD[key]={cash:cashMap, payments:payArr, expenses:expArr, wholesale:wsArr,
        recon:{total_amount:d.total_amount, over_short:d.over_short, expected_cash:d.expected_cash}};
      renderGrid();
      msg.style.color='#15803d'; msg.textContent='✅ Saved — '+peso(d.total_amount)+(d.shortage?' (cash short)':'');
      setTimeout(()=>closeCell(),900);
    } else { msg.style.color='#dc2626'; msg.textContent='❌ '+(d.error||'Save failed'); }
  });
}
function printCell(){ if(!CUR)return; window.open('print_daybook.php?date='+SALE_DATE+'&shift='+CUR.shift+'&pos_no='+CUR.pos,'_blank','width=900,height=950'); }

// 모달 바깥(오버레이) 클릭으로는 닫지 않음 — Save / Cancel 버튼으로만 닫히도록 함.
renderGrid();
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
