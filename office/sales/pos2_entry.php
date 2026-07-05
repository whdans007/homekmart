<?php
// POS 2 — daily_entry.php 디자인 그대로 보존한 직접 입력형 매출 등록 페이지.
// 셀 클릭(모달) 대신 각 셀에 매출 금액을 직접 입력한다. 저장은 기존 ajax_save_sales.php 사용.
$page_title      = 'POS 2';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

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
$existing = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();
$conn->close();

$days_en   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
$dow       = $days_en[date('w', strtotime($date))];
$sales_tab = 'pos2';

$shifts = [
    'gy'      => ['label'=>'GY',      'time'=>'12:00AM–8:00AM', 'icon'=>'fa-moon',     'color'=>'#a78bfa'],
    'morning' => ['label'=>'Morning', 'time'=>'8:00AM–5:00PM',  'icon'=>'fa-sun',      'color'=>'#fbbf24'],
    'mid'     => ['label'=>'Mid',     'time'=>'5:00PM–12:00AM', 'icon'=>'fa-cloud-sun','color'=>'#fb923c'],
];

// 셀 값 프리로드 (없으면 빈 값)
$cell_val = function ($key) use ($existing) {
    if (!isset($existing[$key])) return '';
    $v = (float)$existing[$key];
    return $v != 0 ? number_format($v, 2, '.', '') : '';
};
?>
<?php require __DIR__ . '/partials/sales_nav.php'; ?>

<style>
.s-card { background:#fff; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,.06); border:1px solid #f0f0f0; }
.s-table { border-collapse:collapse; width:100%; }
.s-table th, .s-table td { border:1px solid #e5e7eb; padding:10px 16px; vertical-align:middle; }
.s-table thead th { background:#f9fafb; font-size:11px; font-weight:600; text-align:center; color:#6b7280; white-space:nowrap; }
.s-table .shift-col { text-align:left; background:#f9fafb; white-space:nowrap; }
.cell-inp { width:100%; min-width:150px; border:1px solid #d1d5db; border-radius:8px; padding:10px 12px; font-size:16px; font-weight:700; text-align:right; color:#166534; outline:none; font-variant-numeric:tabular-nums; transition:all .15s; }
.cell-inp:focus { border-color:#16a34a; box-shadow:0 0 0 2px #bbf7d0; background:#f0fdf4; }
.cell-inp::placeholder { color:#cbd5e1; font-weight:400; }
.num { font-variant-numeric:tabular-nums; }
</style>

<!-- Date navigator -->
<div class="s-card px-4 py-3 mb-4 flex items-center gap-3 flex-wrap">
  <a href="pos2_entry.php?date=<?php echo $prev_date; ?>" class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-gray-500 hover:bg-gray-50"><i class="fa-solid fa-chevron-left"></i></a>
  <input type="date" id="entry_date" max="<?php echo $today; ?>" value="<?php echo $date; ?>"
         onchange="location.href='pos2_entry.php?date='+this.value"
         class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-green-500">
  <span class="text-sm font-medium text-gray-700"><?php echo $dow; ?></span>
  <?php if ($date === $today): ?>
    <span class="text-xs px-2 py-0.5 bg-green-100 text-green-700 rounded-full font-medium">Today</span>
  <?php else: ?>
    <a href="pos2_entry.php?date=<?php echo $today; ?>" class="text-xs px-2 py-1 bg-gray-100 text-gray-600 rounded hover:bg-gray-200">Today</a>
  <?php endif; ?>
  <a href="pos2_entry.php?date=<?php echo $next_date; ?>" class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-gray-500 hover:bg-gray-50 <?php echo $is_future ? 'opacity-30 pointer-events-none' : ''; ?>"><i class="fa-solid fa-chevron-right"></i></a>
  <span class="ml-auto text-sm text-gray-400">각 셀에 매출 금액을 직접 입력하세요</span>
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
        <td class="text-center">
          <input type="number" step="0.01" min="0" class="cell-inp" placeholder="0.00"
                 data-shift="<?php echo $sk; ?>" data-field="<?php echo $key; ?>"
                 value="<?php echo $cell_val($key); ?>" oninput="recalc()">
        </td>
        <?php endfor; ?>
        <td class="text-right font-bold" style="color:#16a34a;background:#f0fdf4;white-space:nowrap" id="sub_<?php echo $sk; ?>">0.00</td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="s-card px-5 py-4 flex items-center justify-between mb-4" style="background:#16a34a">
  <div><div class="text-xs" style="color:#bbf7d0">DAY TOTAL <span style="color:#86efac">(6 cells)</span></div>
    <div id="day_total" class="text-2xl font-bold text-white num">₱ 0.00</div></div>
  <div class="flex items-center gap-3">
    <span id="save_msg" style="display:none;font-size:13px;font-weight:600;color:#dcfce7"></span>
    <button type="button" onclick="saveAll()" class="px-6 py-2.5 rounded-xl text-sm font-medium text-white" style="background:#0f766e;border:none;cursor:pointer">
      <i class="fa-solid fa-floppy-disk" style="margin-right:4px"></i> Save
    </button>
  </div>
</div>

<script>
const SALE_DATE = <?php echo json_encode($date); ?>;
const SHIFTS = ['gy','morning','mid'];

function fmt(n){ return (Math.round((n||0)*100)/100).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function peso(n){ return '₱ ' + fmt(n); }

function recalc(){
  let day = 0;
  SHIFTS.forEach(sk=>{
    let sub = 0;
    document.querySelectorAll('.cell-inp[data-shift="'+sk+'"]').forEach(i=>{ sub += parseFloat(i.value)||0; });
    document.getElementById('sub_'+sk).textContent = fmt(sub);
    day += sub;
  });
  document.getElementById('day_total').textContent = peso(day);
}

function saveAll(){
  const fd = new FormData();
  fd.append('sale_date', SALE_DATE);
  document.querySelectorAll('.cell-inp').forEach(i=>{ fd.append(i.dataset.field, parseFloat(i.value)||0); });

  const msg = document.getElementById('save_msg');
  fetch('ajax_save_sales.php', {method:'POST', body:fd}).then(r=>r.json()).then(d=>{
    msg.style.display = 'inline';
    if(d.success){
      msg.style.color = '#dcfce7';
      msg.textContent = '✅ 저장됨 — ' + peso(d.total);
      setTimeout(()=>{ msg.style.display='none'; }, 2500);
    } else {
      msg.style.color = '#fecaca';
      msg.textContent = '❌ ' + (d.error || '저장 실패');
    }
  }).catch(()=>{ msg.style.display='inline'; msg.style.color='#fecaca'; msg.textContent='❌ 통신 오류'; });
}

recalc();
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
