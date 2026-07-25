<?php
$page_title      = 'Cheque Expense Report';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id  = get_office_store_id();
$today     = date('Y-m-d');
$date      = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : $today;
$prev_date = date('Y-m-d', strtotime($date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($date . ' +1 day'));
$is_future = $next_date > $today;
?>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>

<style>
.cer-card {
    background:#fff; border:1px solid #e5e7eb; border-radius:8px;
    padding:8px 10px; margin-bottom:5px; cursor:grab; user-select:none;
    transition:box-shadow .15s, opacity .15s;
}
.cer-card:active { cursor:grabbing; }
.cer-card.auto-placed { background:#f0fdf4; border-color:#86efac; }
.cer-card.placed { opacity:.4; cursor:default; }
.cer-card.sortable-ghost { opacity:.3; }
.cer-card.sortable-chosen { box-shadow:0 4px 12px rgba(0,0,0,.15); }

.drop-zone {
    min-height:50px; border:2px dashed #e5e7eb; border-radius:8px;
    padding:4px; transition:border-color .2s, background .2s;
}
.drop-zone.drag-over { border-color:#a78bfa; background:#f5f3ff; }

.cer-row {
    display:grid;
    grid-template-columns: 24px 100px 1fr 56px 72px 1fr 86px 54px 24px;
    gap:3px; align-items:center;
    padding:4px 6px; font-size:11px; border-bottom:1px solid #f3f4f6;
    transition:background .15s;
}
.cer-row:last-child { border-bottom:none; }
.cer-row.returned-row { background:#fff1f2; }

.drag-handle { cursor:grab; color:#9ca3af; font-size:13px; }
.drag-handle:active { cursor:grabbing; }

.check-input {
    width:100%; border:1px solid #d1d5db; border-radius:4px;
    padding:2px 4px; font-size:11px; font-family:monospace;
    text-align:center;
}
.check-input.is-returned {
    text-decoration:line-through; color:#9ca3af; background:#fef2f2;
}
.new-check-wrap { display:none; }
.new-check-wrap.visible { display:block; }
.new-check-input {
    width:100%; border:1px solid #fca5a5; border-radius:4px;
    padding:2px 4px; font-size:11px; font-family:monospace;
    text-align:center; color:#b91c1c;
}
.amount-input {
    width:100%; border:1px solid #d1d5db; border-radius:4px;
    padding:2px 4px; font-size:11px; font-family:monospace;
    text-align:right;
}
.returned-cb-wrap {
    display:flex; flex-direction:column; align-items:center; gap:1px;
    font-size:9px; color:#6b7280;
}

.section-header {
    display:flex; align-items:center; justify-content:space-between;
    padding:7px 10px; border-radius:6px; margin-bottom:3px;
    font-size:13px; font-weight:600;
}

.col-header-row {
    display:grid;
    grid-template-columns: 24px 100px 1fr 56px 72px 1fr 86px 54px 24px;
    gap:3px; padding:3px 6px;
    font-size:9px; font-weight:600; color:#9ca3af; letter-spacing:.3px;
    background:#f9fafb; border-bottom:1px solid #e5e7eb;
}

.total-bar {
    display:flex; gap:20px; justify-content:flex-end;
    padding:10px 12px; background:#f9fafb; border-top:2px solid #e5e7eb;
    font-size:13px; font-weight:600;
}
/* Return source card */
.return-card {
    background:#fff1f2; border:1px solid #fca5a5; border-left:4px solid #ef4444;
    border-radius:8px; padding:8px 10px; margin-bottom:5px;
    cursor:grab; user-select:none;
}
.return-card.placed { opacity:.4; cursor:default; }
.return-card.sortable-ghost { opacity:.3; }
/* Return / Re-issue section rows */
.cer-row.return-row  { background:#fff1f2; }
.cer-row.reissue-row { background:#eff6ff; border-left:3px solid #3b82f6; }
</style>

<div class="flex items-center justify-between mb-4">
  <h2 class="text-xl font-bold text-gray-800">
    <i class="fa-solid fa-file-invoice-dollar mr-2 text-violet-600"></i>Cheque Expense Report
  </h2>
  <div class="flex gap-2">
    <button onclick="openPrint()"
            class="px-3 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg text-sm">
      <i class="fa-solid fa-print mr-1"></i>Print
    </button>
    <button onclick="openExport()"
            class="px-3 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm">
      <i class="fa-solid fa-file-excel mr-1"></i>Excel
    </button>
  </div>
</div>

<!-- Date selector -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-3 mb-4 flex items-center gap-2 sticky top-0 z-20">
  <label class="text-sm font-medium text-gray-700">
    <i class="fa-solid fa-calendar-day mr-1 text-violet-500"></i>Cheque Issued Date:
  </label>
  <a id="cer_prev_btn" href="index.php?date=<?php echo $prev_date; ?>"
     class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-gray-500 hover:bg-gray-50">
    <i class="fa-solid fa-chevron-left"></i>
  </a>
  <input type="date" id="cer_date" max="<?php echo $today; ?>" value="<?php echo $date; ?>"
         onchange="if(this.value) window.location.href='index.php?date='+this.value"
         class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-violet-400">
  <a id="cer_next_btn" href="index.php?date=<?php echo $next_date; ?>"
     class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-gray-500 hover:bg-gray-50 <?php echo $is_future ? 'opacity-30 pointer-events-none' : ''; ?>">
    <i class="fa-solid fa-chevron-right"></i>
  </a>
  <?php if ($date === $today): ?>
  <span class="text-xs text-violet-600 font-medium">Today</span>
  <?php endif; ?>
  <span id="load_status" class="text-xs text-gray-400 ml-2"></span>
  <span id="save_status" class="text-xs ml-2"></span>
</div>

<div class="flex gap-4" style="align-items:flex-start">

  <!-- SOURCE LIST -->
  <div class="w-64 flex-shrink-0" style="position:sticky;top:58px;">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="px-4 py-3 bg-gray-50 border-b border-gray-100 font-semibold text-sm text-gray-700">
        <i class="fa-solid fa-list mr-1 text-gray-500"></i>미결제 수표
        <span id="unplaced_count" class="ml-2 text-xs text-gray-400"></span>
      </div>
      <div id="source_list" class="p-2 min-h-20" style="max-height:50vh;overflow-y:auto;">
        <div class="text-center text-gray-400 text-xs py-4" id="source_empty">
          Loading...
        </div>
      </div>
    </div>
  </div>

  <!-- FORM -->
  <div class="flex-1">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">

      <div class="px-5 py-3 bg-violet-50 border-b border-violet-100">
        <div class="flex justify-between items-center">
          <div class="text-lg font-bold text-violet-800">Cheque Expense Report</div>
          <div class="flex gap-2">
            <button onclick="openReturnModal()"
                    class="flex items-center gap-1.5 px-3 py-1.5 bg-white border border-red-300 text-red-600 rounded-lg text-sm font-medium hover:bg-red-50">
              <i class="fa-solid fa-rotate-left"></i>Return
            </button>
            <button onclick="saveToDb()" id="btn_save"
                    class="flex items-center gap-1.5 px-4 py-1.5 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-bold shadow-md">
              <i class="fa-solid fa-floppy-disk"></i>SAVE
            </button>
          </div>
        </div>
        <div class="flex gap-6 mt-1 text-xs text-violet-700">
          <span id="form_date_label"><?php echo date('F j, Y', strtotime($date)); ?></span>
          <span>PREPARED: <strong>LIZA</strong></span>
          <span>APPROVED: <strong>SIR MIN</strong></span>
        </div>
      </div>

      <!-- Column headers -->
      <div class="col-header-row">
        <span></span>
        <span>CHECK NO.</span>
        <span>SUPPLIER NAME</span>
        <span>DATE</span>
        <span>SALES INV.</span>
        <span>PARTICULAR</span>
        <span class="text-right">AMOUNT</span>
        <span class="text-center">RETURN</span>
        <span></span>
      </div>

      <?php
      $sections = [
          'korean' => ['label'=>'1. KOREAN',         'color'=>'blue'],
          'local'  => ['label'=>'2. LOCAL',           'color'=>'green'],
          'fixed'  => ['label'=>'3. FIXED EXPENSE',   'color'=>'orange'],
          'others' => ['label'=>'4. OTHERS',          'color'=>'purple'],
      ];
      $color_map = [
          'blue'   => ['bg'=>'#eff6ff','border'=>'#bfdbfe','text'=>'#1d4ed8'],
          'green'  => ['bg'=>'#f0fdf4','border'=>'#bbf7d0','text'=>'#15803d'],
          'orange' => ['bg'=>'#fff7ed','border'=>'#fed7aa','text'=>'#c2410c'],
          'purple' => ['bg'=>'#faf5ff','border'=>'#e9d5ff','text'=>'#7e22ce'],
      ];
      foreach ($sections as $sec_key => $sec):
          $c = $color_map[$sec['color']];
      ?>
      <div>
        <div class="section-header"
             style="background:<?php echo $c['bg']; ?>;border-left:3px solid <?php echo $c['border']; ?>">
          <span style="color:<?php echo $c['text']; ?>"><?php echo $sec['label']; ?></span>
          <span id="total_<?php echo $sec_key; ?>" class="text-xs font-mono" style="color:<?php echo $c['text']; ?>">₱ 0.00</span>
        </div>
        <div id="section_<?php echo $sec_key; ?>" class="drop-zone mx-3 mb-2">
        </div>
      </div>
      <?php endforeach; ?>

      <div class="total-bar">
        <span class="text-gray-500 font-bold">TOTAL EXPENSES: <span id="total_grand" class="text-violet-700 text-base">₱ 0.00</span></span>
      </div>
    </div>
  </div>
</div>

<script>
const STORE_ID  = <?php echo (int)$store_id; ?>;
const STATE_KEY = () => 'cer_draft_' + STORE_ID + '_' + document.getElementById('cer_date').value;

let allItems    = [];
let returnCards = [];
let returnedIds = new Set();
let sections    = { korean:[], local:[], fixed:[], others:[] };
let placedIds   = new Set();
let rowCounter  = 0;
let isDirty     = false;
let lastCheckNo = '';   // 마지막 발행 체크번호

function incrementCheckNo(checkNo) {
    if (!checkNo) return '';
    const match = String(checkNo).match(/^(.*?)(\d+)$/);
    if (!match) return '';
    const prefix = match[1];
    const numStr = match[2];
    const next   = String(parseInt(numStr, 10) + 1).padStart(numStr.length, '0');
    return prefix + next;
}

function nextAutoCheckNo() {
    if (!lastCheckNo) return '';
    lastCheckNo = incrementCheckNo(lastCheckNo);
    return lastCheckNo;
}

function rebuildPlacedIds() {
    placedIds   = new Set();
    returnedIds = new Set();
    ['korean','local','fixed','others'].forEach(sec => {
        (sections[sec] || []).forEach(r => {
            if (r.item_id) placedIds.add(r.item_id);
            if (r.is_return && r.item_id) returnedIds.add(r.item_id);
        });
    });
}

// ── SortableJS ────────────────────────────────────────────
const sortableOptions = (secKey) => ({
    group: { name:'cer', pull: secKey === 'source' ? 'clone' : true, put: secKey !== 'source' },
    sort: secKey !== 'source',
    animation: 150,
    handle: secKey === 'source' ? undefined : '.drag-handle',
    ghostClass: 'sortable-ghost',
    chosenClass: 'sortable-chosen',
    onAdd: (evt) => {
        if (secKey === 'source') return;
        const itemId = evt.item.dataset.itemId;
        const rowId  = evt.item.dataset.rowId;

        if (evt.item.dataset.isReturn === '1') {
            const d = evt.item.dataset;
            if (!returnedIds.has(d.itemId)) {
                // 1) Return row (strikethrough)
                sections[secKey].push(buildReturnRow(d));
                // 2) Re-issue row (new check number, same content)
                if (d.reissue === '1') {
                    sections[secKey].push(buildReissueRow(d));
                }
                rebuildPlacedIds();
            }
            evt.item.remove();
            renderSection(secKey);
            renderSourceList();
            markDirty();
            calcTotals();
            return;
        } else if (itemId) {
            const item = allItems.find(i => i.id === itemId);
            if (item && !sections[secKey].find(r => r.item_id === itemId)) {
                sections[secKey].push(buildRow(item));
                rebuildPlacedIds();
                saveMapping(item.supplier_name, secKey);
            }
        } else if (rowId) {
            const fromSecKey = evt.from.id.replace('section_', '');
            const idx = (sections[fromSecKey] || []).findIndex(r => r.id === rowId);
            if (idx !== -1) {
                const [movedRow] = sections[fromSecKey].splice(idx, 1);
                sections[secKey].push(movedRow);
                rebuildPlacedIds();
            }
            evt.item.remove();
            renderSection(fromSecKey);
            renderSection(secKey);
            renderSourceList();
            markDirty();
            calcTotals();
            return;
        } else {
            evt.item.remove();
            return;
        }
        evt.item.remove();
        renderSection(secKey);
        renderSourceList();
        markDirty();
        calcTotals();
    },
    onUpdate: () => { syncSectionOrder(secKey); markDirty(); }
});

Sortable.create(document.getElementById('source_list'), {
    group: { name:'cer', pull:'clone', put:false },
    sort: false, animation:150,
    ghostClass:'sortable-ghost', chosenClass:'sortable-chosen',
    filter: '.placed'
});
['korean','local','fixed','others'].forEach(s => {
    Sortable.create(document.getElementById('section_' + s), sortableOptions(s));
});

// ── Row Builders ────────────────────────────────────────
function buildRow(item) {
    return {
        id:           'row_' + (++rowCounter),
        item_id:      item.id,
        check_no:     nextAutoCheckNo(),
        date:         item.date,
        supplier:     item.supplier_name,
        sales_invoice:item.sales_invoice,
        particular:   item.particular,
        amount:       item.amount,
        returned:     false,
        new_check_no: ''
    };
}

function buildReturnRow(d) {
    return {
        id:           'row_' + (++rowCounter),
        item_id:      d.itemId,
        is_return:    true,
        check_no:     d.origCheckNo || '',   // original check# (will show struck-through)
        new_check_no: '',
        date:         document.getElementById('cer_date').value,
        supplier:     d.supplier,
        sales_invoice:d.salesInvoice || '',
        particular:   d.particular || '',
        amount:       parseFloat(d.amount) || 0,
        returned:     true   // for print_cer.php compatibility
    };
}

function buildReissueRow(d) {
    return {
        id:           'row_' + (++rowCounter),
        item_id:      null,          // 재발행은 신규 row — 원본 PP ID 없음
        is_reissue:   true,
        check_no:     nextAutoCheckNo(),
        new_check_no: '',
        date:         document.getElementById('cer_date').value,
        supplier:     d.supplier,
        sales_invoice:d.salesInvoice || '',
        particular:   d.particular || '',
        amount:       parseFloat(d.amount) || 0,
        returned:     false
    };
}

// ── Load ─────────────────────────────────────────────────
function loadCheques(date) {
    document.getElementById('form_date_label').textContent =
        new Date(date + 'T00:00:00').toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'});
    document.getElementById('load_status').textContent = 'Loading...';
    setSaveStatus('loading');

    sections  = { korean:[], local:[], fixed:[], others:[] };
    placedIds = new Set();
    allItems  = [];

    fetch('ajax_load_cheques.php?date=' + date + '&store=' + STORE_ID)
    .then(r => r.text())
    .then(txt => JSON.parse(txt.replace(/^﻿/, '')))
    .then(d => {
        if (!d.success) { document.getElementById('load_status').textContent = 'Error'; return; }
        allItems    = d.items;
        lastCheckNo = d.last_check_no || '';
        document.getElementById('load_status').textContent = allItems.length + ' items';
        document.getElementById('source_empty').style.display = 'none';

        if (d.saved_state && d.saved_state.sections) {
            sections = d.saved_state.sections;
            rebuildPlacedIds();
            // Restore row counter to avoid ID collisions
            ['korean','local','fixed','others'].forEach(s => {
                (sections[s] || []).forEach(r => {
                    const n = parseInt((r.id||'').replace('row_',''));
                    if (n > rowCounter) rowCounter = n;
                });
            });
            isDirty = false;
            setSaveStatus('saved', d.saved_state.saved_at);
        } else {
            const draft = loadDraft(date);
            if (draft && draft.sections) {
                sections = draft.sections;
                rebuildPlacedIds();
                isDirty = true;
                setSaveStatus('draft');
            } else {
                allItems.forEach(item => {
                    if (item.auto_section) sections[item.auto_section].push(buildRow(item));
                });
                rebuildPlacedIds();
                isDirty = false;
                setSaveStatus('unsaved');
            }
        }
        ensureKimTaeHyunRow();
        renderAll();
        calcTotals();
    })
    .catch(() => { document.getElementById('load_status').textContent = 'Error'; setSaveStatus('error'); });
}

// KIM TAE HYUN — 매일 반복되는 CASH OUT 항목. 저장 여부와 무관하게 1. KOREAN 섹션에
// 항상 존재를 보장한다 (이월 아님: 매번 AMOUNT/체크번호는 빈 채로 새로 추가됨).
const KIM_TAE_HYUN_SUPPLIER   = 'KIM TAE HYUN';
const KIM_TAE_HYUN_PARTICULAR = 'CASH OUT TOTAL EXPENSE';

function ensureKimTaeHyunRow() {
    const exists = (sections.korean || []).some(r => r.supplier === KIM_TAE_HYUN_SUPPLIER);
    if (exists) return;
    sections.korean.push({
        id:            'row_' + (++rowCounter),
        item_id:       null,
        check_no:      '',
        date:          document.getElementById('cer_date').value,
        supplier:      KIM_TAE_HYUN_SUPPLIER,
        sales_invoice: '',
        particular:    KIM_TAE_HYUN_PARTICULAR,
        amount:        '',
        returned:      false,
        new_check_no:  '',
        editable_amount: true
    });
    rebuildPlacedIds();
    markDirty();
}

// ── Render Source List ────────────────────────────────────
function renderSourceList() {
    const list = document.getElementById('source_list');
    [...list.querySelectorAll('.cer-card')].forEach(el => el.remove());

    let unplaced = 0;
    allItems.forEach(item => {
        const placed = placedIds.has(item.id);
        if (!placed) unplaced++;
        const card = document.createElement('div');
        card.className = 'cer-card' + (placed ? ' placed' : '') + (item.auto_section && !placed ? ' auto-placed' : '');
        card.dataset.itemId = item.id;
        card.innerHTML = `
            <div class="flex justify-between items-start">
                <div class="font-medium text-gray-800 text-xs">${esc(item.supplier_name)}</div>
                <div class="text-xs font-mono text-gray-700 ml-2">₱${fmt(item.amount)}</div>
            </div>
            <div class="text-xs text-violet-500 font-medium">${fmtDate(item.date)}</div>
            <div class="text-xs text-gray-500 truncate">${esc(item.particular)}</div>
            ${item.auto_section && !placed ? `<span class="text-xs text-green-600">🟢 ${item.auto_section}</span>` : ''}
            ${placed ? `<span class="text-xs text-gray-400">✅ placed</span>` : ''}
        `;
        list.appendChild(card);
    });
    // Return cards
    const visibleReturns = returnCards.filter(rc => !returnedIds.has(rc.item_id));
    if (visibleReturns.length > 0) {
        const div = document.createElement('div');
        div.className = 'text-xs text-red-500 font-semibold px-1 py-2 mt-2 border-t border-red-100';
        div.innerHTML = '<i class="fa-solid fa-rotate-left mr-1"></i>반품/재발행';
        list.appendChild(div);
        visibleReturns.forEach(rc => {
            const card = document.createElement('div');
            card.className = 'return-card' + (returnedIds.has(rc.item_id) ? ' placed' : '');
            card.dataset.isReturn    = '1';
            card.dataset.itemId      = rc.item_id;
            card.dataset.origCheckNo = rc.check_no;
            card.dataset.supplier    = rc.supplier;
            card.dataset.salesInvoice= rc.sales_invoice;
            card.dataset.particular  = rc.particular;
            card.dataset.amount      = rc.amount;
            card.dataset.reissue     = rc.reissue ? '1' : '0';
            const badge = rc.reissue
                ? `<span class="text-xs font-bold text-red-700"><i class="fa-solid fa-rotate-left mr-1"></i>RETURN + RE-ISSUE</span>`
                : `<span class="text-xs font-bold text-orange-600"><i class="fa-solid fa-rotate-left mr-1"></i>RETURN ONLY</span>`;
            card.innerHTML = `
                <div class="flex justify-between items-center">
                    ${badge}
                    <span class="text-xs font-mono text-red-600">₱${fmt(rc.amount)}</span>
                </div>
                <div class="text-xs font-medium text-gray-800 mt-0.5">${esc(rc.supplier)}</div>
                ${rc.check_no ? `<div class="text-xs text-gray-400"><s>${esc(rc.check_no)}</s></div>` : ''}
                <div class="text-xs text-violet-500">${fmtDate(rc.date)}</div>
            `;
            list.appendChild(card);
        });
    }

    document.getElementById('unplaced_count').textContent = unplaced > 0 ? `(${unplaced})` : '';
}

// ── Render Section ────────────────────────────────────────
function renderSection(secKey) {
    const zone = document.getElementById('section_' + secKey);
    zone.innerHTML = '';
    (sections[secKey] || []).forEach(row => {
        const div = document.createElement('div');
        const isReturn  = row.is_return  || false;
        const isReissue = row.is_reissue || false;
        div.className   = 'cer-row'
            + (row.returned ? ' returned-row' : '')
            + (isReturn  ? ' return-row'  : '')
            + (isReissue ? ' reissue-row' : '');
        div.dataset.rowId = row.id;

        div.innerHTML = `
            <span class="drag-handle"><i class="fa-solid fa-grip-lines"></i></span>
            <div>
              ${isReturn  ? `<div class="text-xs text-orange-600 font-bold mb-0.5"><i class="fa-solid fa-rotate-left mr-0.5"></i>RETURN</div>` : ''}
              ${isReissue ? `<div class="text-xs text-blue-600 font-bold mb-0.5"><i class="fa-solid fa-arrow-right mr-0.5"></i>RE-ISSUE</div>` : ''}
              <input type="text" class="check-input${(row.returned || isReturn) ? ' is-returned' : ''}"
                     value="${esc(row.check_no)}"
                     placeholder="${isReissue ? 'New Check No.' : 'No.'}"
                     title="${isReturn ? 'Original Check No.' : isReissue ? 'New Check No.' : 'Check Number'}"
                     ${isReturn ? 'readonly style="cursor:default"' : ''}>
              ${!isReturn ? `<div class="new-check-wrap${row.returned ? ' visible' : ''}">
                <input type="text" class="new-check-input mt-0.5"
                       value="${esc(row.new_check_no)}" placeholder="New No.">
              </div>` : ''}
            </div>
            <span class="text-gray-800 text-xs font-medium truncate">${esc(row.supplier)}</span>
            <span class="text-gray-500 text-xs text-center">${fmtDate(row.date)}</span>
            <span class="text-gray-500 text-xs truncate" title="${esc(row.sales_invoice)}">${esc(row.sales_invoice)}</span>
            <span class="text-gray-600 text-xs truncate" title="${esc(row.particular)}">${esc(row.particular)}</span>
            ${row.editable_amount
              ? `<input type="number" step="0.01" min="0" class="amount-cell amount-input"
                        value="${row.amount === '' || row.amount === null || row.amount === undefined ? '' : row.amount}" placeholder="0.00">`
              : `<span class="amount-cell text-right font-mono text-xs font-medium${row.returned ? ' line-through text-gray-400' : ' text-gray-800'}">₱${fmt(row.amount)}</span>`
            }
            <div class="returned-cb-wrap">
              <input type="checkbox" class="returned-cb" ${row.returned ? 'checked' : ''} title="Mark as Returned">
              <span>RTN</span>
            </div>
            <button type="button" onclick="removeRow('${secKey}','${row.id}')"
                    class="text-gray-300 hover:text-red-500 text-xs">×</button>
        `;

        // Attach input listeners (avoid re-render loops)
        const checkInput    = div.querySelector('.check-input');
        const newCheckInput = div.querySelector('.new-check-input');
        const returnedCb    = div.querySelector('.returned-cb');
        const newWrap       = div.querySelector('.new-check-wrap');
        const amountInput   = div.querySelector('input.amount-input');

        checkInput.addEventListener('input', e => {
            row.check_no = e.target.value;
            if (e.target.value) lastCheckNo = e.target.value;
            markDirty();
        });

        newCheckInput.addEventListener('input', e => { row.new_check_no = e.target.value; markDirty(); });

        if (amountInput) {
            amountInput.addEventListener('input', e => {
                row.amount = e.target.value === '' ? '' : (parseFloat(e.target.value) || 0);
                markDirty();
                calcTotals();
            });
        }

        returnedCb.addEventListener('change', e => {
            row.returned = e.target.checked;
            div.classList.toggle('returned-row', row.returned);
            checkInput.classList.toggle('is-returned', row.returned);
            if (newWrap) newWrap.classList.toggle('visible', row.returned);
            const amtCell = div.querySelector('.amount-cell');
            if (amtCell) {
                amtCell.classList.toggle('line-through', row.returned);
                amtCell.classList.toggle('text-gray-400', row.returned);
            }
            if (!row.returned) { row.new_check_no = ''; newCheckInput.value = ''; }
            markDirty();
            calcTotals();
        });

        zone.appendChild(div);
    });
}

function renderAll() {
    renderSourceList();
    ['korean','local','fixed','others'].forEach(renderSection);
}

// ── Row Actions ────────────────────────────────────────
function removeRow(secKey, rowId) {
    sections[secKey] = (sections[secKey] || []).filter(r => r.id !== rowId);
    rebuildPlacedIds();
    renderSection(secKey);
    renderSourceList();
    markDirty();
    calcTotals();
}

function syncSectionOrder(secKey) {
    const zone = document.getElementById('section_' + secKey);
    const rowIds = [...zone.querySelectorAll('[data-row-id]')].map(el => el.dataset.rowId);
    sections[secKey].sort((a,b) => rowIds.indexOf(a.id) - rowIds.indexOf(b.id));
}



function saveMapping(supplierName, section) {
    const fd = new FormData();
    fd.append('supplier_name', supplierName);
    fd.append('section', section);
    fetch('../cash_disbursement/ajax_save_mapping.php', { method:'POST', body:fd }).catch(() => {});
}

// ── Sync section inputs before save/print ─────────────
function syncAllInputs() {
    ['korean','local','fixed','others'].forEach(secKey => {
        const zone = document.getElementById('section_' + secKey);
        zone.querySelectorAll('[data-row-id]').forEach(div => {
            const rowId = div.dataset.rowId;
            const row   = (sections[secKey] || []).find(r => r.id === rowId);
            if (!row) return;
            const ci = div.querySelector('.check-input');
            const ni = div.querySelector('.new-check-input');
            const cb = div.querySelector('.returned-cb');
            if (ci) row.check_no     = ci.value;
            if (ni) row.new_check_no = ni.value;
            if (cb) row.returned     = cb.checked;
        });
    });
}

// ── DB Save ────────────────────────────────────────────
function saveToDb() {
    syncAllInputs();
    const date    = document.getElementById('cer_date').value;
    const payload = JSON.stringify({ sections });
    const btn     = document.getElementById('btn_save');
    btn.disabled  = true;
    setSaveStatus('saving');

    const fd = new FormData();
    fd.append('date', date);
    fd.append('state', payload);

    fetch('ajax_save_cer.php', { method:'POST', body:fd })
    .then(r => r.json())
    .then(d => {
        btn.disabled = false;
        if (d.success) {
            isDirty = false;
            try { localStorage.removeItem(STATE_KEY()); } catch(e) {}
            const now = new Date().toLocaleString('en-US',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
            setSaveStatus('saved', now);
        } else { setSaveStatus('error'); }
    })
    .catch(() => { btn.disabled = false; setSaveStatus('error'); });
}

// ── Save Status ────────────────────────────────────────
function setSaveStatus(status, info) {
    const el  = document.getElementById('save_status');
    const btn = document.getElementById('btn_save');
    if (status === 'saved') {
        el.innerHTML = `<span class="text-green-600"><i class="fa-solid fa-circle-check mr-1"></i>Saved${info ? ' · ' + info : ''}</span>`;
        btn.classList.replace('bg-red-600','bg-red-400');
        btn.classList.replace('hover:bg-red-700','hover:bg-red-500');
    } else if (status === 'draft' || status === 'unsaved') {
        el.innerHTML = status === 'draft'
            ? `<span class="text-amber-500"><i class="fa-solid fa-pen-to-square mr-1"></i>Draft</span>`
            : `<span class="text-gray-400">Not saved</span>`;
        btn.classList.replace('bg-red-400','bg-red-600');
        btn.classList.replace('hover:bg-red-500','hover:bg-red-700');
    } else if (status === 'saving') {
        el.innerHTML = `<span class="text-blue-500"><i class="fa-solid fa-spinner fa-spin mr-1"></i>Saving...</span>`;
    } else if (status === 'error') {
        el.innerHTML = `<span class="text-red-500"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Error</span>`;
    } else { el.innerHTML = ''; }
}

function markDirty() {
    isDirty = true;
    setSaveStatus('draft');
    try { localStorage.setItem(STATE_KEY(), JSON.stringify({ sections })); } catch(e) {}
}

function loadDraft(date) {
    try {
        const data = localStorage.getItem('cer_draft_' + STORE_ID + '_' + date);
        return data ? JSON.parse(data) : null;
    } catch(e) { return null; }
}

// ── Totals ─────────────────────────────────────────────
function fmt(n) { return parseFloat(n||0).toLocaleString('en',{minimumFractionDigits:2}); }
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function fmtDate(s) {
    if (!s) return '';
    const p = s.split('-');
    return (p[1]||'') + '/' + (p[2]||'');
}

function calcTotals() {
    let grand = 0;
    ['korean','local','fixed','others'].forEach(sec => {
        const t = (sections[sec] || [])
            .filter(r => !r.returned)
            .reduce((s,r) => s + (parseFloat(r.amount)||0), 0);
        document.getElementById('total_' + sec).textContent = '₱ ' + fmt(t);
        grand += t;
    });
    document.getElementById('total_grand').textContent = '₱ ' + fmt(grand);
}

// ── Return Modal ───────────────────────────────────────
function openReturnModal() {
    const modal = new bootstrap.Modal(document.getElementById('returnModal'));
    modal.show();
    const res = document.getElementById('return_modal_result');
    res.innerHTML = '<div class="text-center py-6 text-gray-400"><i class="fa-solid fa-spinner fa-spin mr-1"></i>Loading...</div>';

    const cerDate = document.getElementById('cer_date').value;
    fetch('ajax_get_paid_cheques.php?date=' + cerDate)
    .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
    .then(txt => {
        try { return JSON.parse(txt.replace(/^﻿/, '')); }
        catch(e) { throw new Error('JSON parse error: ' + txt.substring(0, 200)); }
    })
    .then(d => {
        if (!d.success || !d.items.length) {
            res.innerHTML = '<div class="text-center py-8 text-gray-400">결제 완료된 수표가 없습니다.</div>';
            return;
        }
        let html = '<table class="min-w-full text-sm divide-y divide-gray-100">';
        html += '<thead class="bg-gray-50"><tr>'
            + '<th class="px-3 py-2 text-left text-xs text-gray-500">Date</th>'
            + '<th class="px-3 py-2 text-left text-xs text-gray-500">Check No.</th>'
            + '<th class="px-3 py-2 text-left text-xs text-gray-500">Supplier</th>'
            + '<th class="px-3 py-2 text-left text-xs text-gray-500">Particular</th>'
            + '<th class="px-3 py-2 text-right text-xs text-gray-500">Amount</th>'
            + '<th class="px-3 py-2"></th>'
            + '</tr></thead><tbody class="divide-y divide-gray-50">';

        d.items.forEach(item => {
            const alreadyAdded = returnCards.some(rc => rc.item_id === 'p_' + item.id);
            const dataAttrs = `data-id="${item.id}"
                           data-supplier="${escHtml(item.supplier_name)}"
                           data-check-no="${escHtml(item.check_no || '')}"
                           data-amount="${item.amount}"
                           data-date="${escHtml(item.date)}"
                           data-sales-invoice="${escHtml(item.sales_invoice || '')}"
                           data-particular="${escHtml(item.particular || '')}"`;
            html += `<tr class="hover:bg-red-50">
                <td class="px-3 py-2 text-xs text-gray-500 whitespace-nowrap">${escHtml(item.date)}</td>
                <td class="px-3 py-2 text-xs font-mono text-gray-700">${escHtml(item.check_no || '—')}</td>
                <td class="px-3 py-2 text-xs font-medium text-gray-800">${escHtml(item.supplier_name)}</td>
                <td class="px-3 py-2 text-xs text-gray-500 max-w-xs truncate">${escHtml(item.particular || '')}</td>
                <td class="px-3 py-2 text-xs text-right font-mono">₱${parseFloat(item.amount).toLocaleString('en',{minimumFractionDigits:2})}</td>
                <td class="px-3 py-2 text-right whitespace-nowrap">
                  ${alreadyAdded
                    ? '<span class="text-xs text-gray-400">Added</span>'
                    : `<div class="flex gap-1">
                         <button class="px-2 py-1 bg-orange-500 hover:bg-orange-600 text-white rounded text-xs return-select-btn" ${dataAttrs} data-reissue="0">Return</button>
                         <button class="px-2 py-1 bg-red-600 hover:bg-red-700 text-white rounded text-xs return-select-btn" ${dataAttrs} data-reissue="1">Re-issue</button>
                       </div>`}
                </td>
            </tr>`;
        });
        html += '</tbody></table>';
        res.innerHTML = html;

        res.querySelectorAll('.return-select-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                addReturnCard(this.dataset, this.dataset.reissue === '1');
                this.closest('tr').querySelector('td:last-child').innerHTML =
                    this.dataset.reissue === '1'
                        ? '<span class="text-xs text-red-600 font-medium">Re-issue added</span>'
                        : '<span class="text-xs text-orange-500 font-medium">Return added</span>';
            });
        });
    })
    .catch(err => {
        res.innerHTML = `<div class="text-center py-6 text-red-400"><i class="fa-solid fa-circle-exclamation mr-1"></i>${err.message}</div>`;
    });
}

function addReturnCard(d, isReissue = false) {
    const item_id = 'p_' + d.id;
    if (returnCards.some(rc => rc.item_id === item_id)) return;
    returnCards.push({
        item_id:      item_id,
        supplier:     d.supplier,
        check_no:     d.checkNo || '',
        amount:       parseFloat(d.amount) || 0,
        date:         d.date,
        sales_invoice:d.salesInvoice || '',
        particular:   d.particular || '',
        reissue:      isReissue,
    });
    renderSourceList();
    markDirty();
}

function escHtml(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Print / Export ─────────────────────────────────────
function getPayload() {
    syncAllInputs();
    return { date: document.getElementById('cer_date').value, sections };
}

function openPrint() {
    const form = document.createElement('form');
    form.method = 'POST'; form.action = 'print_cer.php'; form.target = '_blank';
    const inp = document.createElement('input');
    inp.type='hidden'; inp.name='data'; inp.value=JSON.stringify(getPayload());
    form.appendChild(inp); document.body.appendChild(form);
    form.submit(); form.remove();
}

function openExport() {
    const form = document.createElement('form');
    form.method = 'POST'; form.action = 'export_cer.php'; form.target = '_blank';
    const inp = document.createElement('input');
    inp.type='hidden'; inp.name='data'; inp.value=JSON.stringify(getPayload());
    form.appendChild(inp); document.body.appendChild(form);
    form.submit(); form.remove();
}

// ── Init ───────────────────────────────────────────────
loadCheques('<?php echo $date; ?>');
</script>

<!-- Return Modal -->
<div class="modal fade" id="returnModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header border-b border-gray-100 px-4 py-3">
        <h5 class="modal-title text-base font-semibold text-gray-800">
          <i class="fa-solid fa-rotate-left mr-2 text-red-600"></i>Return / Re-issue Cheque
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 py-3">
        <p class="text-xs text-gray-500 mb-3">결제 완료된 수표 목록입니다. 반품할 항목을 선택하면 소스 리스트에 추가됩니다.</p>
        <div id="return_modal_result" class="text-sm text-gray-400 text-center py-6">
          <i class="fa-solid fa-spinner fa-spin mr-1"></i>Loading...
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
