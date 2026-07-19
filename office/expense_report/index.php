<?php
$page_title      = 'Daily Expense Report';
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
.er-source-card {
    background:#fff; border:1px solid #e5e7eb; border-radius:7px;
    padding:7px 10px; margin-bottom:4px; cursor:grab; user-select:none;
    transition:box-shadow .15s, opacity .15s; font-size:11px;
}
.er-source-card:active { cursor:grabbing; }
.er-source-card.placed {
    background:#f0fdf4; border-color:#86efac; cursor:default;
    opacity:1;
}
.er-source-card.placed .er-card-supplier { color:#15803d; }
.er-source-card.placed .er-card-amount  { color:#15803d; }
.er-source-card.placed .er-card-detail  { color:#4ade80; }
.er-source-card.auto-placed { background:#f0fdf4; border-color:#86efac; }
.er-source-card.sortable-ghost { opacity:.3; }

.er-drop {
    min-height:60px; border:2px dashed #e5e7eb; border-radius:8px;
    padding:4px; transition:border-color .2s, background .2s; flex:1;
}
.er-drop.drag-over { border-color:#6ee7b7; background:#f0fdf4; }

.er-row {
    display:grid;
    grid-template-columns: 20px 1fr 1fr 72px 20px;
    gap:3px; align-items:center;
    padding:3px 5px; font-size:11px; border-bottom:1px solid #f3f4f6;
}
.er-row:last-child { border-bottom:none; }
.er-drag { cursor:grab; color:#9ca3af; font-size:12px; }

.sec-box {
    background:#fff; border-radius:10px; border:1px solid #e5e7eb;
    box-shadow:0 1px 3px rgba(0,0,0,.06); overflow:hidden;
    display:flex; flex-direction:column;
}
.sec-head {
    padding:8px 12px; font-size:12px; font-weight:700;
    display:flex; justify-content:space-between; align-items:center;
}
.sec-col-hdr {
    display:grid; grid-template-columns:20px 1fr 1fr 72px 20px;
    gap:3px; padding:2px 5px; font-size:9px; font-weight:600;
    color:#9ca3af; background:#f9fafb; border-top:1px solid #e5e7eb;
}
.total-bar {
    padding:6px 10px; background:#f9fafb; border-top:2px solid #e5e7eb;
    font-size:12px; font-weight:700; text-align:right;
}
</style>

<!-- Toolbar -->
<div class="flex items-center justify-between mb-4">
  <h2 class="text-xl font-bold text-gray-800">
    <i class="fa-solid fa-receipt mr-2 text-teal-600"></i>Daily Expense Report
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
    <button onclick="openMonthlyExport()"
            class="px-3 py-2 bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg text-sm">
      <i class="fa-solid fa-calendar-days mr-1"></i>Monthly Excel
    </button>
  </div>
</div>

<!-- Date selector -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-3 mb-4 flex items-center gap-2 sticky top-0 z-20">
  <label class="text-sm font-medium text-gray-700">
    <i class="fa-solid fa-calendar-day mr-1 text-teal-500"></i>Date:
  </label>
  <a href="index.php?date=<?php echo $prev_date; ?>"
     class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-gray-500 hover:bg-gray-50">
    <i class="fa-solid fa-chevron-left"></i>
  </a>
  <input type="date" id="er_date" max="<?php echo $today; ?>" value="<?php echo $date; ?>"
         onchange="if(this.value) window.location.href='index.php?date='+this.value"
         class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-teal-400">
  <a href="index.php?date=<?php echo $next_date; ?>"
     class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-gray-500 hover:bg-gray-50 <?php echo $is_future ? 'opacity-30 pointer-events-none':'' ?>">
    <i class="fa-solid fa-chevron-right"></i>
  </a>
  <?php if ($date === $today): ?><span class="text-xs text-teal-600 font-medium">Today</span><?php endif; ?>
  <span id="load_status" class="text-xs text-gray-400 ml-2"></span>
  <span id="save_status" class="text-xs ml-2"></span>
  <div class="ml-auto flex gap-2">
    <button onclick="clearDraft()" title="임시 저장 초기화"
            class="px-3 py-1.5 border border-gray-300 text-gray-500 rounded-lg text-xs hover:bg-gray-50">
      <i class="fa-solid fa-rotate-left mr-1"></i>Reset
    </button>
    <button onclick="saveToDb()" id="btn_save"
            class="flex items-center gap-1.5 px-4 py-1.5 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-bold shadow">
      <i class="fa-solid fa-floppy-disk"></i>SAVE
    </button>
  </div>
</div>


<div class="flex gap-3" style="align-items:flex-start;min-height:620px">

  <!-- SOURCE LIST -->
  <div class="w-64 flex-shrink-0" style="position:sticky;top:58px;">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="px-3 py-2.5 bg-gray-50 border-b border-gray-100 font-semibold text-sm text-gray-700 flex justify-between items-center">
        <span><i class="fa-solid fa-list mr-1 text-gray-500"></i>Items</span>
        <span id="unplaced_count" class="text-xs text-gray-400"></span>
      </div>
      <div id="source_list" class="p-2 min-h-20" style="max-height:50vh;overflow-y:auto;">
        <div class="text-center text-gray-400 text-xs py-4" id="source_empty">Select a date.</div>
      </div>
    </div>

  </div>

  <!-- 5-SECTION GRID
       col1/row1: CASH SELLING     col2/row1: CASH NOT SELLING
       col1/row2~3: PAY THRU CHECK col2/row2: OTHER EXP CHECK
                                   col2/row3: OTHER EXP CASH
  -->
  <div class="flex-1" style="display:grid;grid-template-columns:1fr 1fr;grid-template-rows:auto 1fr 1fr;gap:12px;min-height:540px;align-items:stretch">

    <?php
    $sections_cfg = [
        'selling'         => ['label'=>'PARTICULARS',    'sub'=>'CASH SELLING',
                              'color'=>['bg'=>'#eff6ff','border'=>'#bfdbfe','text'=>'#1d4ed8'],
                              'place'=>'grid-column:1;grid-row:1'],
        'not_selling'     => ['label'=>'PARTICULARS',    'sub'=>'CASH NOT SELLING',
                              'color'=>['bg'=>'#f0fdf4','border'=>'#bbf7d0','text'=>'#15803d'],
                              'place'=>'grid-column:2;grid-row:1'],
        'check_sup'       => ['label'=>'SUPPLIERS',      'sub'=>'PAY THRU CHECK',
                              'color'=>['bg'=>'#fff7ed','border'=>'#fed7aa','text'=>'#c2410c'],
                              'place'=>'grid-column:1;grid-row:2/4'],
        'other_exp_check' => ['label'=>'OTHER EXPENSES', 'sub'=>'SALARY / ELECTRIC / WATER / RENT — CHECK (수표)',
                              'color'=>['bg'=>'#faf5ff','border'=>'#e9d5ff','text'=>'#7e22ce'],
                              'place'=>'grid-column:2;grid-row:2'],
        'other_exp_cash'  => ['label'=>'OTHER EXPENSES', 'sub'=>'SALARY / ELECTRIC / WATER / RENT — CASH (현금)',
                              'color'=>['bg'=>'#fdf4ff','border'=>'#f0abfc','text'=>'#86198f'],
                              'place'=>'grid-column:2;grid-row:3'],
    ];
    foreach ($sections_cfg as $sec_key => $sc):
        $c = $sc['color'];
    ?>
    <div class="sec-box" style="<?php echo $sc['place']; ?>">
      <div class="sec-head" style="background:<?php echo $c['bg']; ?>;border-bottom:2px solid <?php echo $c['border']; ?>">
        <div>
          <div style="color:<?php echo $c['text']; ?>"><?php echo $sc['label']; ?></div>
          <div class="text-xs font-normal" style="color:<?php echo $c['text']; ?>;opacity:.8"><?php echo $sc['sub']; ?></div>
        </div>
        <span id="total_<?php echo $sec_key; ?>" class="text-sm font-mono" style="color:<?php echo $c['text']; ?>">₱ 0.00</span>
      </div>
      <div class="sec-col-hdr">
        <span></span><span>SUPPLIER</span><span>DETAILS</span>
        <span class="text-right">AMOUNT</span><span></span>
      </div>
      <div id="section_<?php echo $sec_key; ?>" class="er-drop p-1">
      </div>
    </div>
    <?php endforeach; ?>

  </div><!-- end sections grid -->
</div>

<!-- Grand total bar -->
<div class="mt-3 bg-white rounded-xl shadow-sm border border-gray-100 px-5 py-3 flex justify-between items-center">
  <span class="text-sm font-semibold text-gray-600">TOTAL EXPENSES</span>
  <span id="total_grand" class="text-lg font-bold text-teal-700">₱ 0.00</span>
</div>

<script>
const STORE_ID  = <?php echo (int)$store_id; ?>;
const STATE_KEY = () => 'er_draft_' + STORE_ID + '_' + document.getElementById('er_date').value;

let allItems          = [];
let sections          = { selling:[], not_selling:[], check_sup:[], other_exp_check:[], other_exp_cash:[] };
let placedIds         = new Set();
let externallyPlaced  = new Set(); // 다른 날짜 ER에 배치된 항목
let rowCounter = 0;
let isDirty    = false;

function rebuildPlacedIds() {
    placedIds = new Set();
    Object.keys(sections).forEach(s =>
        (sections[s]||[]).forEach(r => { if (r.item_id) placedIds.add(r.item_id); })
    );
}

// ── SortableJS ──────────────────────────────────────────
const SEC_KEYS = ['selling','not_selling','check_sup','other_exp_check','other_exp_cash'];

const sortOpts = (secKey) => ({
    group: { name:'er', pull: secKey==='source'?'clone':true, put: secKey!=='source' },
    sort: secKey !== 'source',
    animation: 150,
    handle: secKey === 'source' ? undefined : '.er-drag',
    ghostClass: 'sortable-ghost',
    onAdd: (evt) => {
        if (secKey === 'source') return;
        const itemId = evt.item.dataset.itemId;
        const rowId  = evt.item.dataset.rowId;
        if (itemId) {
            const item = allItems.find(i => i.id === itemId);
            if (item && !sections[secKey].find(r => r.item_id === itemId)) {
                sections[secKey].push(buildRow(item));
                rebuildPlacedIds();
            }
        } else if (rowId) {
            const fromKey = evt.from.id.replace('section_','');
            const idx = (sections[fromKey]||[]).findIndex(r => r.id === rowId);
            if (idx !== -1) {
                const [moved] = sections[fromKey].splice(idx, 1);
                sections[secKey].push(moved);
                rebuildPlacedIds();
            }
            evt.item.remove();
            renderSection(fromKey);
            renderSection(secKey);
            renderSourceList();
            markDirty(); calcTotals(); return;
        } else { evt.item.remove(); return; }
        evt.item.remove();
        renderSection(secKey);
        renderSourceList();
        markDirty(); calcTotals();
    },
    onUpdate: () => { syncOrder(secKey); markDirty(); }
});

Sortable.create(document.getElementById('source_list'), {
    group:{name:'er',pull:'clone',put:false}, sort:false, animation:150,
    ghostClass:'sortable-ghost', filter:'.placed'
});
SEC_KEYS.forEach(s => Sortable.create(document.getElementById('section_'+s), sortOpts(s)));

// ── Row Builder ─────────────────────────────────────────
function buildRow(item) {
    return {
        id:      'row_'+(++rowCounter),
        item_id: item.id,
        supplier:item.supplier,
        details: item.details,
        amount:  item.amount,
        cv_no:   item.cv_no || '',
        date:    item.date  || '',
    };
}

// ── Load ────────────────────────────────────────────────
function loadItems(date) {
    document.getElementById('load_status').textContent = 'Loading...';
    setSaveStatus('loading');
    sections  = { selling:[], not_selling:[], check_sup:[], other_exp_check:[], other_exp_cash:[] };
    placedIds = new Set();
    allItems  = [];

    fetch('ajax_load_items.php?date=' + date)
    .then(r => r.text())
    .then(txt => JSON.parse(txt.replace(/^﻿/,'')))
    .then(d => {
        if (!d.success) { document.getElementById('load_status').textContent = 'Error'; return; }
        allItems = d.items;
        document.getElementById('load_status').textContent = allItems.length + ' items';
        document.getElementById('source_empty').style.display = 'none';

        if (d.saved_state && d.saved_state.sections) {
            sections = d.saved_state.sections;
            // 구 저장 데이터 하위 호환: other_exp → other_exp_cash
            if (sections.other_exp && !sections.other_exp_check && !sections.other_exp_cash) {
                sections.other_exp_cash  = sections.other_exp;
                sections.other_exp_check = [];
                delete sections.other_exp;
            }
            if (!sections.other_exp_check) sections.other_exp_check = [];
            if (!sections.other_exp_cash)  sections.other_exp_cash  = [];
            rebuildPlacedIds();
            SEC_KEYS.forEach(s => (sections[s]||[]).forEach(r => {
                const n = parseInt((r.id||'').replace('row_',''));
                if (n > rowCounter) rowCounter = n;
            }));
            // 섹션에 있는 항목이 allItems에 없으면 추가
            // (is_er_placed=1로 필터됐어도 취소 시 즉시 소스 목록에 돌아오게)
            const existingIds = new Set(allItems.map(i => i.id));
            SEC_KEYS.forEach(s => (sections[s]||[]).forEach(r => {
                if (!r.item_id || existingIds.has(r.item_id)) return;
                const type = r.item_id.startsWith('e_') ? 'equipment'
                           : r.item_id.startsWith('pc_') ? 'product_check' : 'product_cash';
                allItems.push({
                    id: r.item_id, type,
                    supplier: r.supplier || '', details: r.details || '',
                    amount: parseFloat(r.amount)||0, date: r.date||'',
                    cv_no: r.cv_no||'', auto_section: null,
                });
                existingIds.add(r.item_id);
            }));
            isDirty = false;
            setSaveStatus('saved', d.saved_state.saved_at);
        } else {
            const draft = loadDraft(date);
            if (draft && draft.sections) {
                sections = draft.sections;
                // 구 draft 하위 호환: other_exp → other_exp_cash
                if (sections.other_exp && !sections.other_exp_check && !sections.other_exp_cash) {
                    sections.other_exp_cash  = sections.other_exp;
                    sections.other_exp_check = [];
                    delete sections.other_exp;
                }
                if (!sections.other_exp_check) sections.other_exp_check = [];
                if (!sections.other_exp_cash)  sections.other_exp_cash  = [];
                rebuildPlacedIds();
                // draft 항목도 동일하게 allItems에 보완
                const existingIds2 = new Set(allItems.map(i => i.id));
                SEC_KEYS.forEach(s => (sections[s]||[]).forEach(r => {
                    if (!r.item_id || existingIds2.has(r.item_id)) return;
                    const type = r.item_id.startsWith('e_') ? 'equipment'
                               : r.item_id.startsWith('pc_') ? 'product_check' : 'product_cash';
                    allItems.push({
                        id: r.item_id, type,
                        supplier: r.supplier||'', details: r.details||'',
                        amount: parseFloat(r.amount)||0, date: r.date||'',
                        cv_no: r.cv_no||'', auto_section: null,
                    });
                }));
                isDirty = true; setSaveStatus('draft');
            } else {
                // 자동 배치 없음 — 모든 항목을 소스 리스트에만 표시
                rebuildPlacedIds(); isDirty = false; setSaveStatus('unsaved');
            }
        }
        externallyPlaced = new Set(d.externally_placed || []);
        renderAll(); calcTotals();
    })
    .catch(() => { document.getElementById('load_status').textContent = 'Error'; });
}

// ── Render ──────────────────────────────────────────────
function renderSourceList() {
    const list = document.getElementById('source_list');
    [...list.querySelectorAll('.er-source-card')].forEach(el => el.remove());
    let unplaced = 0;
    const typeIcon = {'product_cash':'💵','product_check':'🔖','equipment':'🔧'};
    allItems.forEach(item => {
        const placed = placedIds.has(item.id) || externallyPlaced.has(item.id);
        if (placed) return;
        unplaced++;
        const card = document.createElement('div');
        card.className = 'er-source-card';
        card.dataset.itemId = item.id;
        card.draggable = true;
        const currentDate = document.getElementById('er_date').value;
        const isCarryover = item.date && item.date < currentDate;
        card.innerHTML = `
            <div class="flex justify-between items-start gap-1">
                <div class="er-card-supplier font-medium truncate flex-1 ${placed ? 'text-green-700' : 'text-gray-800'}">${esc(item.supplier)}</div>
                <div class="er-card-amount font-mono flex-shrink-0 ${placed ? 'text-green-700' : 'text-gray-700'}">₱${fmt(item.amount)}</div>
            </div>
            <div class="er-card-detail flex items-center gap-1 ${placed ? 'text-green-400' : 'text-gray-400'}">
                <span>${typeIcon[item.type]||'📄'}</span>
                <span class="truncate">${esc(item.details)}</span>
            </div>
            ${isCarryover ? `<span class="text-xs text-orange-500 font-medium">↩ ${item.date}</span>` : ''}
            <div class="flex gap-1 mt-1">
                <button onclick="event.stopPropagation(); openEditModal('${item.id}')"
                        class="text-xs text-blue-500 hover:text-blue-700 px-1">
                    <i class="fa-solid fa-pen-to-square"></i>
                </button>
                <button onclick="event.stopPropagation(); deleteItem('${item.id}')"
                        class="text-xs text-red-400 hover:text-red-600 px-1">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </div>
        `;
        if (!placed) {
            card.addEventListener('dragstart', e => {
                e.dataTransfer.setData('text/plain', item.id);
                e.dataTransfer.effectAllowed = 'move';
            });
        }
        list.appendChild(card);
    });
    document.getElementById('unplaced_count').textContent = unplaced > 0 ? `(${unplaced})` : '';
}

function renderSection(secKey) {
    const zone = document.getElementById('section_'+secKey);
    zone.innerHTML = '';
    const erDate = document.getElementById('er_date').value;
    (sections[secKey]||[]).forEach(row => {
        const div = document.createElement('div');
        div.className = 'er-row'; div.dataset.rowId = row.id;
        const isOtherDate = row.date && row.date !== erDate;
        const dateBadge   = isOtherDate
            ? `<span style="color:#f97316;font-size:9px;font-weight:600;white-space:nowrap">[${row.date.slice(5)}]</span>`
            : '';
        div.innerHTML = `
            <span class="er-drag"><i class="fa-solid fa-grip-lines"></i></span>
            <span class="text-gray-800 text-xs font-medium truncate" title="${esc(row.supplier)}">${esc(row.supplier)}</span>
            <span class="text-gray-500 text-xs truncate" title="${esc(row.details)}">${dateBadge} ${esc(row.details)}</span>
            <span class="text-right font-mono text-gray-800 text-xs${(parseFloat(row.amount)||0) >= 50000 ? ' font-bold' : ''}">₱${fmt(row.amount)}</span>
            <button onclick="removeRow('${secKey}','${row.id}')" class="text-gray-300 hover:text-red-500 text-xs">×</button>
        `;
        zone.appendChild(div);
    });
}

function renderAll() {
    renderSourceList();
    SEC_KEYS.forEach(renderSection);
}

// ── Actions ─────────────────────────────────────────────
function removeRow(secKey, rowId) {
    const row = (sections[secKey]||[]).find(r => r.id === rowId);
    const itemId = row ? row.item_id : null;

    sections[secKey] = (sections[secKey]||[]).filter(r => r.id !== rowId);
    rebuildPlacedIds();

    // is_er_placed=0 으로 즉시 복원 (미사용 영수증 상태)
    if (itemId) {
        const fd = new FormData();
        fd.append('item_id', itemId);
        fetch('ajax_unplace_item.php', {method:'POST', body:fd}).catch(() => {});
    }

    renderSection(secKey);
    renderSourceList();
    markDirty(); calcTotals();
}

function syncOrder(secKey) {
    const zone = document.getElementById('section_'+secKey);
    const ids = [...zone.querySelectorAll('[data-row-id]')].map(el => el.dataset.rowId);
    sections[secKey].sort((a,b) => ids.indexOf(a.id)-ids.indexOf(b.id));
}

// ── Totals ───────────────────────────────────────────────
function fmt(n) { return parseFloat(n||0).toLocaleString('en',{minimumFractionDigits:2}); }
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function calcTotals() {
    let grand = 0;
    SEC_KEYS.forEach(s => {
        const t = (sections[s]||[]).reduce((x,r)=>x+(parseFloat(r.amount)||0),0);
        document.getElementById('total_'+s).textContent = '₱ '+fmt(t);
        grand += t;
    });
    document.getElementById('total_grand').textContent = '₱ '+fmt(grand);
}

// ── Save ─────────────────────────────────────────────────
function saveToDb() {
    const date = document.getElementById('er_date').value;
    const btn  = document.getElementById('btn_save');
    btn.disabled = true; setSaveStatus('saving');
    const fd = new FormData();
    fd.append('date', date); fd.append('state', JSON.stringify({sections}));
    fetch('ajax_save_er.php',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(d=>{
        btn.disabled = false;
        if (d.success) {
            isDirty = false;
            try { localStorage.removeItem(STATE_KEY()); } catch(e){}
            const now = new Date().toLocaleString('en-US',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
            setSaveStatus('saved',now);
        } else setSaveStatus('error');
    })
    .catch(()=>{ btn.disabled=false; setSaveStatus('error'); });
}

function setSaveStatus(s,info) {
    const el=document.getElementById('save_status');
    const btn=document.getElementById('btn_save');
    if(s==='saved'){
        el.innerHTML=`<span class="text-green-600"><i class="fa-solid fa-circle-check mr-1"></i>Saved${info?' · '+info:''}</span>`;
        btn.classList.replace('bg-red-600','bg-red-400'); btn.classList.replace('hover:bg-red-700','hover:bg-red-500');
    } else if(s==='draft'||s==='unsaved'){
        el.innerHTML=s==='draft'?`<span class="text-amber-500">Draft</span>`:`<span class="text-gray-400">Not saved</span>`;
        btn.classList.replace('bg-red-400','bg-red-600'); btn.classList.replace('hover:bg-red-500','hover:bg-red-700');
    } else if(s==='saving'){
        el.innerHTML=`<span class="text-blue-500"><i class="fa-solid fa-spinner fa-spin mr-1"></i>Saving...</span>`;
    } else if(s==='error'){
        el.innerHTML=`<span class="text-red-500">Error</span>`;
    } else el.innerHTML='';
}

function markDirty() {
    isDirty=true; setSaveStatus('draft');
    try { localStorage.setItem(STATE_KEY(), JSON.stringify({sections})); } catch(e){}
}

function loadDraft(date) {
    try { const d=localStorage.getItem('er_draft_'+STORE_ID+'_'+date); return d?JSON.parse(d):null; } catch(e){return null;}
}


function clearDraft() {
    const date = document.getElementById('er_date').value;
    try { localStorage.removeItem('er_draft_'+STORE_ID+'_'+date); } catch(e){}
    sections = { selling:[], not_selling:[], check_sup:[], other_exp_check:[], other_exp_cash:[] };
    rebuildPlacedIds();
    isDirty = false;
    setSaveStatus('unsaved');
    renderAll(); calcTotals();
}

// ── Print / Export ────────────────────────────────────────
function openPrint() {
    const form=document.createElement('form');
    form.method='POST'; form.action='print_er.php'; form.target='_blank';
    const inp=document.createElement('input');
    inp.type='hidden'; inp.name='data'; inp.value=JSON.stringify({date:document.getElementById('er_date').value,sections});
    form.appendChild(inp); document.body.appendChild(form); form.submit(); form.remove();
}
function openExport() {
    const form=document.createElement('form');
    form.method='POST'; form.action='export_er.php'; form.target='_blank';
    const inp=document.createElement('input');
    inp.type='hidden'; inp.name='data'; inp.value=JSON.stringify({date:document.getElementById('er_date').value,sections});
    form.appendChild(inp); document.body.appendChild(form); form.submit(); form.remove();
}

function openMonthlyExport() {
    const d = new Date(document.getElementById('er_date').value + 'T00:00:00');
    window.location.href = 'export_er_monthly.php?year=' + d.getFullYear() + '&month=' + (d.getMonth()+1);
}

loadItems('<?php echo $date; ?>');

// ── Add / Edit / Delete ──────────────────────────────────
function openAddModal() {
    const date = document.getElementById('er_date').value;
    document.getElementById('modal_title').textContent = 'Add Expense Item';
    document.getElementById('modal_item_id').value = '';
    document.getElementById('modal_type').value = 'product';
    document.getElementById('modal_payment').value = 'cash';
    document.getElementById('modal_date').value = date;
    document.getElementById('modal_supplier').value = '';
    document.getElementById('modal_details').value = '';
    document.getElementById('modal_amount').value = '';
    togglePaymentField();
    document.getElementById('item_modal').classList.remove('hidden');
    document.getElementById('modal_supplier').focus();
}

function openEditModal(itemId) {
    const item = allItems.find(i => i.id === itemId);
    if (!item) return;
    document.getElementById('modal_title').textContent = 'Edit Expense Item';
    document.getElementById('modal_item_id').value = item.id;
    document.getElementById('modal_type').value =
        item.type === 'equipment' ? 'equipment' : 'product';
    document.getElementById('modal_payment').value =
        item.type === 'product_check' ? 'check' : 'cash';
    document.getElementById('modal_date').value = item.date || document.getElementById('er_date').value;
    document.getElementById('modal_supplier').value = item.supplier;
    document.getElementById('modal_details').value = item.details;
    document.getElementById('modal_amount').value = item.amount;
    document.getElementById('modal_type').disabled = true;
    togglePaymentField();
    document.getElementById('item_modal').classList.remove('hidden');
}

function closeModal() {
    document.getElementById('item_modal').classList.add('hidden');
    document.getElementById('modal_type').disabled = false;
}

function togglePaymentField() {
    const type = document.getElementById('modal_type').value;
    const payRow = document.getElementById('payment_row');
    payRow.style.display = type === 'product' ? '' : 'none';
}

async function saveModal() {
    const itemId   = document.getElementById('modal_item_id').value;
    const isEdit   = itemId !== '';
    const url      = isEdit ? 'ajax_edit_item.php' : 'ajax_add_item.php';
    const fd = new FormData();

    if (isEdit) {
        fd.append('item_id', itemId);
    } else {
        fd.append('type', document.getElementById('modal_type').value);
        fd.append('payment_type', document.getElementById('modal_payment').value);
    }
    fd.append('date',             document.getElementById('modal_date').value);
    fd.append('supplier_name',    document.getElementById('modal_supplier').value.trim());
    fd.append('delivery_content', document.getElementById('modal_details').value.trim());
    fd.append('amount',           document.getElementById('modal_amount').value);

    const res  = await fetch(url, { method: 'POST', body: fd });
    const data = await res.json();

    if (!data.success) { alert(data.error || 'Error'); return; }

    closeModal();

    if (isEdit) {
        const item = allItems.find(i => i.id === itemId);
        if (item) {
            item.supplier = document.getElementById('modal_supplier').value.trim();
            item.details  = document.getElementById('modal_details').value.trim();
            item.amount   = parseFloat(document.getElementById('modal_amount').value) || 0;
            // 섹션에 배치된 행도 업데이트
            Object.keys(sections).forEach(s => {
                (sections[s]||[]).forEach(r => {
                    if (r.item_id === itemId) {
                        r.supplier = item.supplier;
                        r.details  = item.details;
                        r.amount   = item.amount;
                    }
                });
            });
        }
    } else {
        allItems.push(data.item);
    }

    renderAll(); calcTotals(); markDirty();
}

async function deleteItem(itemId) {
    if (!confirm('이 항목을 삭제하시겠습니까?\nReceipt가 연결된 경우 삭제되지 않습니다.')) return;
    const date = document.getElementById('er_date').value;
    const fd = new FormData();
    fd.append('item_id', itemId);
    fd.append('date', date);

    const res  = await fetch('ajax_delete_item.php', { method: 'POST', body: fd });
    const data = await res.json();

    if (!data.success) { alert(data.error || '삭제 실패'); return; }

    // allItems에서 제거
    allItems = allItems.filter(i => i.id !== itemId);
    // 섹션에서도 제거
    Object.keys(sections).forEach(s => {
        sections[s] = (sections[s]||[]).filter(r => r.item_id !== itemId);
    });
    rebuildPlacedIds();
    renderAll(); calcTotals(); markDirty();
}
</script>

<!-- Add / Edit Modal -->
<div id="item_modal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center">
  <div class="bg-white rounded-xl shadow-xl w-80 p-5">
    <div class="flex justify-between items-center mb-4">
      <h3 id="modal_title" class="font-bold text-gray-800 text-sm">Add Expense Item</h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 text-lg leading-none">&times;</button>
    </div>

    <input type="hidden" id="modal_item_id">

    <div class="space-y-3 text-sm">
      <div id="payment_row" class="grid grid-cols-2 gap-2">
        <div>
          <label class="block text-xs text-gray-500 mb-1">Type</label>
          <select id="modal_type" onchange="togglePaymentField()"
                  class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm">
            <option value="product">Product</option>
            <option value="equipment">Equipment</option>
          </select>
        </div>
        <div>
          <label class="block text-xs text-gray-500 mb-1">Payment</label>
          <select id="modal_payment" class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm">
            <option value="cash">Cash</option>
            <option value="check">Check</option>
          </select>
        </div>
      </div>

      <div>
        <label class="block text-xs text-gray-500 mb-1">Date</label>
        <input type="date" id="modal_date"
               class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm">
      </div>

      <div>
        <label class="block text-xs text-gray-500 mb-1">Supplier <span class="text-red-500">*</span></label>
        <input type="text" id="modal_supplier" placeholder="공급처명"
               class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm">
      </div>

      <div>
        <label class="block text-xs text-gray-500 mb-1">Details</label>
        <input type="text" id="modal_details" placeholder="내용"
               class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm">
      </div>

      <div>
        <label class="block text-xs text-gray-500 mb-1">Amount <span class="text-red-500">*</span></label>
        <input type="number" id="modal_amount" placeholder="0.00" step="0.01" min="0"
               class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm">
      </div>
    </div>

    <div class="flex gap-2 mt-4">
      <button onclick="closeModal()"
              class="flex-1 py-2 border border-gray-300 rounded-lg text-sm text-gray-600 hover:bg-gray-50">
        Cancel
      </button>
      <button onclick="saveModal()"
              class="flex-1 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium">
        Save
      </button>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
