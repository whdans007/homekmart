<?php
// ── AJAX: 일괄 저장 — header.php 출력 전에 처리해야 순수 JSON 응답 가능 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_bulk') {
    require_once __DIR__ . '/../lib/office_helper.php';
    header('Content-Type: application/json; charset=utf-8');
    $store_id   = get_office_store_id();
    $conn       = get_db_connection();
    $ids        = json_decode($_POST['ids'] ?? '[]', true);
    $new_name   = trim($_POST['new_name']   ?? '');
    $item_code  = trim($_POST['item_code']  ?? '');
    $supplier   = trim($_POST['supplier']   ?? '');
    $department = trim($_POST['department'] ?? '');

    if (empty($ids)) { echo json_encode(['success'=>false,'error'=>'선택된 항목 없음']); exit; }

    $sets=[]; $vals=[]; $types='';
    if ($new_name!=='')   { $sets[]='item_name=?';  $vals[]=$new_name;   $types.='s'; }
    if ($item_code!=='')  { $sets[]='item_code=?';  $vals[]=$item_code;  $types.='s'; }
    if ($supplier!=='')   { $sets[]='supplier=?';   $vals[]=$supplier;   $types.='s'; }
    if ($department!=='') { $sets[]='department=?'; $vals[]=$department; $types.='s'; }
    if (!$sets) { echo json_encode(['success'=>false,'error'=>'변경 항목 없음']); exit; }

    $id_list = implode(',', array_map('intval', $ids));
    $vals[]  = $store_id;
    $types  .= 'i';

    $stmt = $conn->prepare(
        "UPDATE pos_sales_data d
         JOIN pos_sales_uploads u ON u.id = d.upload_id
         SET " . implode(',', $sets) . "
         WHERE d.id IN ({$id_list}) AND u.store_id = ?"
    );
    $stmt->bind_param($types, ...$vals);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $conn->close();
    echo json_encode(['success'=>true,'affected'=>$affected]);
    exit;
}

$page_title      = 'POS Item Bulk Edit';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id = get_office_store_id();
$conn     = get_db_connection();

// ── 검색 조건 ─────────────────────────────────────────────────────────────
$q = trim($_GET['q'] ?? '');
$where = "u.store_id = {$store_id}";
if ($q !== '') {
    $esc    = $conn->real_escape_string($q);
    $where .= " AND (d.item_name LIKE '%{$esc}%'
                  OR d.item_code LIKE '%{$esc}%'
                  OR d.supplier  LIKE '%{$esc}%'
                  OR d.department LIKE '%{$esc}%'
                  OR d.si_no     LIKE '%{$esc}%')";
}

$total = (int)$conn->query(
    "SELECT COUNT(*) FROM pos_sales_data d
     JOIN pos_sales_uploads u ON u.id=d.upload_id WHERE {$where}"
)->fetch_row()[0];

$per_page    = 1000;
$page        = max(1, (int)($_GET['p'] ?? 1));
$offset      = ($page - 1) * $per_page;
$total_pages = (int)ceil($total / $per_page);

$rows = $conn->query(
    "SELECT d.id, d.item_name, d.item_code, d.supplier, d.department,
            d.sale_date, d.si_no, d.cashier
     FROM pos_sales_data d
     JOIN pos_sales_uploads u ON u.id = d.upload_id
     WHERE {$where}
     ORDER BY u.uploaded_at DESC, d.row_no ASC
     LIMIT {$per_page} OFFSET {$offset}"
)->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>

<div class="flex items-center justify-between mb-4">
  <h2 class="text-xl font-bold text-gray-800">
    <i class="fa-solid fa-pen-to-square mr-2 text-orange-600"></i>Item Bulk Edit
  </h2>
  <a href="data.php" class="text-sm text-gray-400 hover:text-gray-600">
    <i class="fa-solid fa-arrow-left mr-1"></i>데이터 목록
  </a>
</div>

<!-- 토스트 -->
<div id="toast" class="hidden fixed top-4 right-4 z-50 px-4 py-2.5 rounded-xl text-sm font-medium shadow-lg"></div>

<!-- 검색 (서버) -->
<form method="GET" class="flex gap-2 mb-2">
  <div class="relative flex-1">
    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
    <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>"
           placeholder="상품명, 코드, 공급처, 부서, SI No. 검색..."
           class="w-full border border-gray-300 rounded-lg pl-8 pr-3 py-2 text-sm focus:ring-2 focus:ring-orange-400">
  </div>
  <button type="submit" class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-lg text-sm font-medium">
    <i class="fa-solid fa-magnifying-glass mr-1"></i>검색
  </button>
  <a href="?q=OVERRIDE" class="px-4 py-2 bg-red-50 hover:bg-red-100 text-red-600 rounded-lg text-sm font-medium whitespace-nowrap border border-red-200">
    <i class="fa-solid fa-triangle-exclamation mr-1"></i>OVERRIDE
  </a>
  <?php if ($q): ?>
  <a href="item_edit.php" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-600 rounded-lg text-sm">초기화</a>
  <?php endif; ?>
</form>

<?php if ($q && !empty($rows)): ?>
<!-- 결과 내 재검색 (클라이언트) -->
<div class="flex items-center gap-2 mb-3 bg-orange-50 border border-orange-100 rounded-lg px-3 py-2">
  <i class="fa-solid fa-filter text-orange-400 text-xs"></i>
  <span class="text-xs text-orange-500 font-medium whitespace-nowrap">결과 내 재검색:</span>
  <input type="text" id="refine_input" oninput="refineRows(this.value)"
         placeholder="현재 결과에서 추가 필터..."
         class="flex-1 border border-orange-200 rounded px-2.5 py-1 text-xs focus:ring-2 focus:ring-orange-400 bg-white">
  <span id="refine_count" class="text-xs text-orange-400 whitespace-nowrap"></span>
  <button onclick="document.getElementById('refine_input').value=''; refineRows('');"
          class="text-xs text-orange-400 hover:text-orange-600 px-1">✕</button>
</div>
<?php endif; ?>

<?php if (!$q): ?>
<div class="text-center py-16 text-gray-400">
  <i class="fa-solid fa-magnifying-glass text-4xl mb-3"></i>
  <p>검색어를 입력하면 해당 데이터가 모두 표시됩니다.</p>
  <p class="text-xs mt-1">예: 상품명, 공급처, 부서명으로 검색</p>
</div>
<?php elseif (empty($rows)): ?>
<div class="text-center py-12 text-gray-400">검색 결과 없음</div>
<?php else: ?>

<!-- 선택 툴바 -->
<div class="flex items-center gap-3 mb-2 text-sm bg-orange-50 border border-orange-100 rounded-xl px-4 py-2.5">
  <label class="flex items-center gap-1.5 cursor-pointer select-none text-gray-600">
    <input type="checkbox" id="chk_all" onchange="toggleAll(this)"
           class="w-4 h-4 rounded border-gray-300 text-orange-500">
    <span class="text-xs font-medium">전체선택</span>
  </label>
  <span id="sel_count" class="text-xs text-orange-600 font-medium"></span>
  <span class="text-xs text-gray-400">
    검색결과 <strong><?php echo number_format($total); ?></strong>건
    <?php if ($total_pages > 1): ?>/ 현재 페이지 <?php echo number_format(count($rows)); ?>건<?php endif; ?>
  </span>
  <button onclick="printTable()" id="btn_print"
          class="ml-auto px-3 py-1.5 bg-gray-600 hover:bg-gray-700 text-white rounded-lg text-xs font-medium">
    <i class="fa-solid fa-print mr-1"></i>인쇄
  </button>
  <button onclick="openBulkPanel()" id="btn_bulk"
          class="px-3 py-1.5 bg-orange-600 hover:bg-orange-700 text-white rounded-lg text-xs font-medium">
    <i class="fa-solid fa-wand-magic-sparkles mr-1"></i>선택 항목 일괄변경
  </button>
</div>

<!-- 테이블 -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mb-3">
  <div class="overflow-x-auto" style="max-height:60vh;overflow-y:auto">
    <table class="min-w-full text-xs divide-y divide-gray-100">
      <thead class="bg-gray-50 sticky top-0 z-10">
        <tr id="sort_header">
          <th class="px-3 py-2.5 w-8"></th>
          <th class="px-3 py-2.5 text-left text-gray-500 font-semibold cursor-pointer hover:bg-orange-50 select-none" data-sortcol="1">날짜 <span class="sort-icon" style="color:#d1d5db">↕</span></th>
          <th class="px-3 py-2.5 text-left text-gray-500 font-semibold cursor-pointer hover:bg-orange-50 select-none" data-sortcol="2">SI NO. <span class="sort-icon" style="color:#d1d5db">↕</span></th>
          <th class="px-3 py-2.5 text-left text-gray-500 font-semibold cursor-pointer hover:bg-orange-50 select-none" data-sortcol="3">ITEMNAME <span class="sort-icon" style="color:#d1d5db">↕</span></th>
          <th class="px-3 py-2.5 text-left text-gray-500 font-semibold cursor-pointer hover:bg-orange-50 select-none" data-sortcol="4">ITEMCODE <span class="sort-icon" style="color:#d1d5db">↕</span></th>
          <th class="px-3 py-2.5 text-left text-gray-500 font-semibold cursor-pointer hover:bg-orange-50 select-none" data-sortcol="5">SUPPLIER <span class="sort-icon" style="color:#d1d5db">↕</span></th>
          <th class="px-3 py-2.5 text-left text-gray-500 font-semibold cursor-pointer hover:bg-orange-50 select-none" data-sortcol="6">DEPARTMENT <span class="sort-icon" style="color:#d1d5db">↕</span></th>
          <th class="px-3 py-2.5 text-left text-gray-500 font-semibold cursor-pointer hover:bg-orange-50 select-none" data-sortcol="7">CASHIER <span class="sort-icon" style="color:#d1d5db">↕</span></th>
        </tr>
      </thead>
      <tbody id="item_tbody" class="divide-y divide-gray-50">
      <?php foreach ($rows as $row): ?>
        <tr class="hover:bg-orange-50 data-tr">
          <td class="px-3 py-1.5 text-center">
            <input type="checkbox" class="row-chk w-4 h-4 rounded border-gray-300 text-orange-500"
                   value="<?php echo $row['id']; ?>" onchange="updateCount()">
          </td>
          <td class="px-3 py-1.5 text-gray-500 whitespace-nowrap"><?php echo htmlspecialchars($row['sale_date'] ?? ''); ?></td>
          <td class="px-3 py-1.5 text-gray-500 font-mono whitespace-nowrap"><?php echo htmlspecialchars($row['si_no'] ?? ''); ?></td>
          <td class="px-3 py-1.5 font-medium text-gray-800 whitespace-nowrap"><?php echo htmlspecialchars($row['item_name'] ?? ''); ?></td>
          <td class="px-3 py-1.5 font-mono text-gray-600 whitespace-nowrap"><?php echo htmlspecialchars($row['item_code'] ?? ''); ?></td>
          <td class="px-3 py-1.5 text-gray-600 whitespace-nowrap"><?php echo htmlspecialchars($row['supplier'] ?? ''); ?></td>
          <td class="px-3 py-1.5 text-gray-600 whitespace-nowrap"><?php echo htmlspecialchars($row['department'] ?? ''); ?></td>
          <td class="px-3 py-1.5 text-gray-500 whitespace-nowrap"><?php echo htmlspecialchars($row['cashier'] ?? ''); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- 페이지네이션 -->
<?php if ($total_pages > 1): ?>
<div class="flex justify-center gap-1 mb-4">
  <?php if ($page > 1): ?>
  <a href="?q=<?php echo urlencode($q); ?>&p=<?php echo $page-1; ?>"
     class="px-3 py-1.5 rounded border border-gray-200 text-xs hover:bg-gray-50">이전</a>
  <?php endif; ?>
  <?php for ($i = max(1,$page-4); $i <= min($total_pages,$page+4); $i++): ?>
  <a href="?q=<?php echo urlencode($q); ?>&p=<?php echo $i; ?>"
     class="px-2.5 py-1.5 rounded text-xs <?php echo $i===$page ? 'bg-orange-600 text-white' : 'border border-gray-200 hover:bg-gray-50'; ?>">
    <?php echo $i; ?>
  </a>
  <?php endfor; ?>
  <?php if ($page < $total_pages): ?>
  <a href="?q=<?php echo urlencode($q); ?>&p=<?php echo $page+1; ?>"
     class="px-3 py-1.5 rounded border border-gray-200 text-xs hover:bg-gray-50">다음</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- 일괄변경 패널 (모달) -->
<div id="bulk_panel" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40">
  <div class="bg-white rounded-2xl shadow-2xl w-80 p-6">
    <div class="flex items-center justify-between mb-4">
      <h3 class="font-bold text-gray-800">
        <i class="fa-solid fa-wand-magic-sparkles mr-1 text-orange-500"></i>일괄변경
      </h3>
      <button onclick="closeBulkPanel()" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
    </div>
    <p class="text-xs text-gray-500 mb-4" id="bulk_desc"></p>
    <div class="space-y-3">
      <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">새 ITEMNAME <span class="text-gray-400">(빈 값이면 미변경)</span></label>
        <input type="text" id="bulk_new_name" placeholder="변경 안 함"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-orange-400">
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">ITEMCODE</label>
        <input type="text" id="bulk_item_code" placeholder="변경 안 함"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:ring-2 focus:ring-orange-400">
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">SUPPLIER</label>
        <input type="text" id="bulk_supplier" placeholder="변경 안 함"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-orange-400">
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">DEPARTMENT</label>
        <input type="text" id="bulk_department" placeholder="변경 안 함"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-orange-400">
      </div>
    </div>
    <div class="flex gap-2 mt-5">
      <button onclick="closeBulkPanel()"
              class="flex-1 py-2 border border-gray-300 text-gray-600 rounded-lg text-sm hover:bg-gray-50">취소</button>
      <button onclick="saveBulk()" id="btn_save_bulk"
              class="flex-1 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-lg text-sm font-medium">
        <i class="fa-solid fa-floppy-disk mr-1"></i>저장
      </button>
    </div>
  </div>
</div>

<?php endif; ?>

<script>
function toggleAll(chk) {
    document.querySelectorAll('.row-chk').forEach(c => {
        const tr = c.closest('tr');
        if (tr && tr.style.display !== 'none') c.checked = chk.checked;
    });
    updateCount();
}

function updateCount() {
    const allChks     = [...document.querySelectorAll('.row-chk')];
    const visibleChks = allChks.filter(c => { const tr = c.closest('tr'); return tr && tr.style.display !== 'none'; });
    const n  = visibleChks.filter(c => c.checked).length;
    const el = document.getElementById('sel_count');
    if (el) el.textContent = n > 0 ? n + '개 선택됨' : '';
    const ca = document.getElementById('chk_all');
    if (ca) {
        ca.checked       = visibleChks.length > 0 && n === visibleChks.length;
        ca.indeterminate = n > 0 && n < visibleChks.length;
    }
}

function openBulkPanel() {
    const n = document.querySelectorAll('.row-chk:checked').length;
    if (n === 0) { showToast('항목을 먼저 선택하세요', 'red'); return; }
    document.getElementById('bulk_desc').textContent =
        '선택된 ' + n + '개 행에 아래 값을 적용합니다. 빈 칸은 변경하지 않습니다.';
    document.getElementById('bulk_panel').classList.remove('hidden');
}

function closeBulkPanel() {
    document.getElementById('bulk_panel').classList.add('hidden');
}

function saveBulk() {
    const ids = [...document.querySelectorAll('.row-chk:checked')]
        .filter(c => { const tr = c.closest('tr'); return tr && tr.style.display !== 'none'; })
        .map(c => c.value);
    if (!ids.length) return;

    const btn = document.getElementById('btn_save_bulk');
    btn.disabled = true; btn.textContent = '저장 중...';

    const fd = new FormData();
    fd.append('action',     'update_bulk');
    fd.append('ids',        JSON.stringify(ids));
    fd.append('new_name',   document.getElementById('bulk_new_name').value.trim());
    fd.append('item_code',  document.getElementById('bulk_item_code').value.trim());
    fd.append('supplier',   document.getElementById('bulk_supplier').value.trim());
    fd.append('department', document.getElementById('bulk_department').value.trim());

    fetch('item_edit.php', {method:'POST', body:fd})
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            showToast('✅ ' + d.affected + '건 저장 완료', 'green');
            closeBulkPanel();
            ['bulk_new_name','bulk_item_code','bulk_supplier','bulk_department']
                .forEach(id => document.getElementById(id).value = '');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast('❌ ' + (d.error || '오류'), 'red');
        }
    })
    .catch(() => showToast('❌ 오류', 'red'))
    .finally(() => { btn.disabled = false; btn.textContent = '저장'; });
}

// 정렬
(function() {
    let _col = -1, _asc = true;

    function doSort(colIdx) {
        const tbody = document.getElementById('item_tbody');
        if (!tbody) return;

        _asc = (_col === colIdx) ? !_asc : true;
        _col = colIdx;

        var rows = [];
        for (var i = 0; i < tbody.rows.length; i++) rows.push(tbody.rows[i]);

        rows.sort(function(a, b) {
            var tda = a.cells[colIdx], tdb = b.cells[colIdx];
            var va  = tda ? tda.textContent.trim().toLowerCase() : '';
            var vb  = tdb ? tdb.textContent.trim().toLowerCase() : '';
            var na  = parseFloat(va), nb = parseFloat(vb);
            var cmp = (!isNaN(na) && !isNaN(nb)) ? na - nb : va.localeCompare(vb);
            return _asc ? cmp : -cmp;
        });

        for (var j = 0; j < rows.length; j++) tbody.appendChild(rows[j]);

        // 아이콘 초기화
        var icons = document.querySelectorAll('#sort_header .sort-icon');
        for (var k = 0; k < icons.length; k++) {
            icons[k].textContent = '↕';
            icons[k].style.color = '#d1d5db';
        }
        // 활성 아이콘
        var ths = document.querySelectorAll('#sort_header th[data-sortcol]');
        for (var m = 0; m < ths.length; m++) {
            if (parseInt(ths[m].getAttribute('data-sortcol')) === colIdx) {
                var ic = ths[m].querySelector('.sort-icon');
                if (ic) { ic.textContent = _asc ? '↑' : '↓'; ic.style.color = '#ea580c'; }
            }
        }
    }

    // DOMContentLoaded 후 이벤트 등록
    document.addEventListener('DOMContentLoaded', function() {
        var ths = document.querySelectorAll('#sort_header th[data-sortcol]');
        for (var i = 0; i < ths.length; i++) {
            (function(th) {
                var col = parseInt(th.getAttribute('data-sortcol'));
                th.addEventListener('click', function() { doSort(col); });
            })(ths[i]);
        }
    });
})();

function refineRows(q) {
    const kw   = q.trim().toLowerCase();
    const rows = document.querySelectorAll('#item_tbody tr.data-tr');
    let visible = 0;
    rows.forEach(tr => {
        const text  = tr.innerText.toLowerCase();
        const match = !kw || text.includes(kw);
        tr.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    const el = document.getElementById('refine_count');
    if (el) el.textContent = kw ? visible + ' / ' + rows.length + '건' : '';
    updateCount();
}

function printTable() {
    const rows = document.querySelectorAll('#item_tbody tr.data-tr');
    const visibleRows = [...rows].filter(tr => tr.style.display !== 'none');
    if (!visibleRows.length) { showToast('출력할 데이터가 없습니다', 'red'); return; }

    const q = <?php echo json_encode($q); ?>;
    const headers = ['날짜', 'SI NO.', 'ITEMNAME', 'ITEMCODE', 'SUPPLIER', 'DEPARTMENT', 'CASHIER'];

    let tableRows = '';
    visibleRows.forEach(tr => {
        const cells = tr.querySelectorAll('td');
        tableRows += '<tr>';
        // cells[0] is checkbox — skip
        for (let i = 1; i < cells.length; i++) {
            tableRows += '<td>' + (cells[i].textContent.trim() || '&nbsp;') + '</td>';
        }
        tableRows += '</tr>';
    });

    const win = window.open('', '_blank', 'width=1000,height=700');
    win.document.write(`<!DOCTYPE html><html><head>
<meta charset="utf-8">
<title>Item List — ${q ? q + ' — ' : ''}${visibleRows.length}건</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, sans-serif; font-size: 11px; padding: 16px; }
  h2 { font-size: 14px; margin-bottom: 6px; }
  .meta { font-size: 11px; color: #555; margin-bottom: 12px; }
  table { width: 100%; border-collapse: collapse; }
  th { background: #f3f4f6; border: 1px solid #d1d5db; padding: 5px 8px; text-align: left; font-size: 11px; white-space: nowrap; }
  td { border: 1px solid #e5e7eb; padding: 4px 8px; white-space: nowrap; }
  tr:nth-child(even) td { background: #fafafa; }
  @media print {
    @page { margin: 10mm; }
    button { display: none; }
  }
</style>
</head><body>
<h2><i>Item List</i>${q ? ' — 검색: ' + q : ''}</h2>
<div class="meta">출력일: <?php echo date('Y-m-d H:i'); ?> &nbsp;|&nbsp; 총 ${visibleRows.length}건</div>
<table>
  <thead><tr>${headers.map(h => '<th>' + h + '</th>').join('')}</tr></thead>
  <tbody>${tableRows}</tbody>
</table>
<br>
<button onclick="window.print()" style="padding:6px 16px;background:#ea580c;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:12px;">인쇄</button>
</body></html>`);
    win.document.close();
    win.focus();
}

function showToast(msg, color) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'fixed top-4 right-4 z-50 px-4 py-2.5 rounded-xl text-sm font-medium shadow-lg '
        + (color === 'green' ? 'bg-green-600 text-white' : 'bg-red-600 text-white');
    t.classList.remove('hidden');
    setTimeout(() => t.classList.add('hidden'), 3000);
}

// 모달 외부 클릭 닫기
document.getElementById('bulk_panel')?.addEventListener('click', function(e) {
    if (e.target === this) closeBulkPanel();
});
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
