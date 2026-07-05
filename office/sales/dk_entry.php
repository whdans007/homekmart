<?php
$page_title      = 'Delivery K';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id  = get_office_store_id();
$today     = date('Y-m-d');
$year      = (int)($_GET['year']  ?? date('Y'));
$month     = (int)($_GET['month'] ?? date('n'));
$sales_tab = 'dk';

$conn = get_db_connection();
$stmt = $conn->prepare(
    "SELECT id, sale_date, description, amount
     FROM sales_daily_items
     WHERE store_id=? AND item_type='delivery_k'
       AND YEAR(sale_date)=? AND MONTH(sale_date)=?
     ORDER BY sale_date DESC, id DESC"
);
$stmt->bind_param('iii', $store_id, $year, $month);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

$by_date     = [];
foreach ($rows as $r) { $by_date[$r['sale_date']][] = $r; }
$month_total = array_sum(array_column($rows, 'amount'));

$month_options = [];
for ($i = 0; $i < 12; $i++) {
    $ts = mktime(0,0,0,date('n')-$i,1,date('Y'));
    $month_options[] = ['y'=>(int)date('Y',$ts),'m'=>(int)date('n',$ts),'label'=>date('M Y',$ts)];
}
$days_en = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
?>
<?php require __DIR__ . '/partials/sales_nav.php'; ?>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header border-b border-gray-100 px-4 py-3">
        <h5 class="modal-title text-sm font-semibold text-gray-800">
          <i class="fa-solid fa-motorcycle mr-1 text-blue-600"></i>Add Delivery K
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 py-4 space-y-3">
        <div id="add_error" class="hidden bg-red-50 border border-red-200 text-red-700 rounded-lg p-2 text-xs"></div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Date <span class="text-red-500">*</span></label>
          <input type="date" id="add_date" max="<?php echo $today; ?>" value="<?php echo $today; ?>"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-400">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Description</label>
          <input type="text" id="add_desc" placeholder="Optional"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-400">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Amount <span class="text-red-500">*</span></label>
          <input type="number" id="add_amount" step="0.01" min="0.01" placeholder="0.00"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm text-right focus:ring-2 focus:ring-blue-400">
        </div>
      </div>
      <div class="modal-footer px-4 py-3 flex gap-2 justify-end">
        <button class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm" data-bs-dismiss="modal">Cancel</button>
        <button onclick="addItem()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- Header row -->
<div class="flex items-center justify-between mb-4">
  <h2 class="text-lg font-bold text-gray-800">
    <i class="fa-solid fa-motorcycle mr-2 text-blue-600"></i>Delivery K
  </h2>
  <button onclick="openAdd('<?php echo $today; ?>')"
          class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
    <i class="fa-solid fa-plus mr-1"></i>Add Entry
  </button>
</div>

<!-- Filter bar -->
<form method="GET" class="flex gap-3 mb-4 bg-white p-3 rounded-xl shadow-sm border border-gray-100 items-center flex-wrap">
  <select name="month" id="month_sel" class="border border-gray-300 rounded-lg px-3 py-2 text-sm" onchange="syncYear()">
    <?php foreach ($month_options as $opt): ?>
    <option value="<?php echo $opt['m']; ?>" data-year="<?php echo $opt['y']; ?>"
      <?php echo ($opt['y']===$year && $opt['m']===$month) ? 'selected' : ''; ?>>
      <?php echo $opt['label']; ?>
    </option>
    <?php endforeach; ?>
  </select>
  <input type="hidden" name="year" id="year_hidden" value="<?php echo $year; ?>">
  <button type="submit" class="px-3 py-2 bg-gray-600 text-white rounded-lg text-sm">Search</button>
  <div class="ml-auto text-sm font-semibold text-blue-700">
    Month Total: <?php echo format_amount($month_total); ?>
  </div>
</form>

<!-- List -->
<?php if (empty($by_date)): ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 text-center py-16 text-gray-400">
  <i class="fa-solid fa-motorcycle text-4xl mb-3 block text-gray-200"></i>
  No entries for this period.
</div>
<?php else: ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-gray-50 border-b border-gray-200">
      <tr>
        <th class="px-4 py-3 text-left text-gray-600 font-medium w-36">Date</th>
        <th class="px-4 py-3 text-left text-gray-600 font-medium">Description</th>
        <th class="px-4 py-3 text-right text-gray-600 font-medium w-36">Amount</th>
        <th class="px-4 py-3 w-10"></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($by_date as $date => $items):
      $ts      = strtotime($date);
      $dow     = $days_en[date('w',$ts)];
      $lbl     = date('M j',$ts) . ' ' . $dow;
      $sub     = array_sum(array_column($items, 'amount'));
      $is_today= ($date === $today);
    ?>
      <tr class="bg-blue-50 border-t border-gray-200">
        <td colspan="2" class="px-4 py-2 font-semibold text-blue-800 text-xs">
          <?php echo $lbl; ?><?php if ($is_today) echo ' <span class="text-blue-400">●</span>'; ?>
        </td>
        <td class="px-4 py-2 text-right font-bold text-blue-700 text-xs">
          <?php echo format_amount($sub); ?>
          <button onclick="printDaily('<?php echo $date; ?>')"
                  class="ml-2 text-xs px-2 py-0.5 rounded border border-blue-300 text-blue-500 hover:bg-blue-600 hover:text-white hover:border-blue-600 transition-colors"
                  title="일일집계 출력">
            <i class="fa-solid fa-print"></i>
          </button>
        </td>
        <td class="px-4 py-2 text-right">
          <button onclick="openAdd('<?php echo $date; ?>')" class="text-blue-400 hover:text-blue-600 text-xs">
            <i class="fa-solid fa-plus"></i>
          </button>
        </td>
      </tr>
      <?php foreach ($items as $item): ?>
      <tr class="border-t border-gray-100 hover:bg-gray-50" id="row_<?php echo $item['id']; ?>">
        <td class="px-4 py-2.5 text-gray-400 pl-8 text-xs">—</td>
        <td class="px-4 py-2.5 text-gray-700">
          <?php echo $item['description'] ? htmlspecialchars($item['description']) : '<span class="text-gray-300 italic text-xs">—</span>'; ?>
        </td>
        <td class="px-4 py-2.5 text-right font-mono text-gray-800"><?php echo format_amount((float)$item['amount']); ?></td>
        <td class="px-4 py-2.5 text-right">
          <button onclick="del(<?php echo $item['id']; ?>)" class="text-gray-300 hover:text-red-500">
            <i class="fa-solid fa-trash-can text-xs"></i>
          </button>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<script>
function syncYear(){const s=document.getElementById('month_sel');document.getElementById('year_hidden').value=s.options[s.selectedIndex].dataset.year;s.form.submit();}
function openAdd(date){document.getElementById('add_date').value=date;document.getElementById('add_amount').value='';document.getElementById('add_desc').value='';document.getElementById('add_error').classList.add('hidden');new bootstrap.Modal(document.getElementById('addModal')).show();}
function addItem(){
    const date=document.getElementById('add_date').value,desc=document.getElementById('add_desc').value,amt=document.getElementById('add_amount').value,err=document.getElementById('add_error');
    if(!date||!amt||parseFloat(amt)<=0){err.textContent='Date and Amount are required.';err.classList.remove('hidden');return;}
    err.classList.add('hidden');
    const fd=new FormData();fd.append('item_type','delivery_k');fd.append('sale_date',date);fd.append('description',desc);fd.append('amount',amt);
    fetch('ajax_add_item.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.success)location.reload();else{err.textContent=d.error||'Error';err.classList.remove('hidden');}});
}
function del(id){if(!confirm('Delete this entry?'))return;const fd=new FormData();fd.append('id',id);fetch('ajax_delete_item.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.success)location.reload();});}
function printDaily(date){window.open('print_daily_combined.php?date='+date,'_blank','width=700,height=700');}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
