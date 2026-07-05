<?php
// Design Ref: §1 — Cash Disbursement main UI (SortableJS + localStorage)
$page_title      = 'Cash Disbursement';
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

<!-- SortableJS CDN -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>

<style>
.cd-card {
    background:#fff; border:1px solid #e5e7eb; border-radius:8px;
    padding:10px 12px; margin-bottom:6px; cursor:grab; user-select:none;
    transition:box-shadow .15s, opacity .15s;
}
.cd-card:active { cursor:grabbing; }
.cd-card.auto-placed { background:#f0fdf4; border-color:#86efac; }
.cd-card.sortable-ghost { opacity:.3; }
.cd-card.sortable-chosen { box-shadow:0 4px 12px rgba(0,0,0,.15); }

.drop-zone {
    min-height:60px; border:2px dashed #e5e7eb; border-radius:8px;
    padding:6px; transition:border-color .2s, background .2s;
}
.drop-zone.drag-over { border-color:#6ee7b7; background:#f0fdf4; }
.drop-zone.drag-over-warn { border-color:#fbbf24; background:#fffbeb; }

.section-row {
    display:grid;
    grid-template-columns: 28px 90px 90px 1fr 1fr 100px 28px;
    gap:4px; align-items:center;
    padding:5px 8px; font-size:12px; border-bottom:1px solid #f3f4f6;
}
.section-row:last-child { border-bottom:none; }
.drag-handle { cursor:grab; color:#9ca3af; font-size:14px; }
.drag-handle:active { cursor:grabbing; }

.total-bar {
    display:flex; gap:24px; justify-content:flex-end;
    padding:10px 12px; background:#f9fafb; border-top:2px solid #e5e7eb;
    font-size:13px; font-weight:600;
}

.section-header {
    display:flex; align-items:center; justify-content:space-between;
    padding:8px 10px; border-radius:6px; margin-bottom:4px; font-size:13px; font-weight:600;
}

</style>

<div class="flex items-center justify-between mb-4">
  <h2 class="text-xl font-bold text-gray-800">
    <i class="fa-solid fa-money-bill-wave mr-2 text-amber-600"></i>Cash Disbursement
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
    <i class="fa-solid fa-calendar-day mr-1 text-amber-500"></i>Date:
  </label>
  <a id="cd_prev_btn" href="index.php?date=<?php echo $prev_date; ?>"
     class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-gray-500 hover:bg-gray-50">
    <i class="fa-solid fa-chevron-left"></i>
  </a>
  <input type="date" id="cd_date" max="<?php echo $today; ?>" value="<?php echo $date; ?>"
         onchange="if(this.value) window.location.href='index.php?date='+this.value"
         class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-amber-400">
  <a id="cd_next_btn" href="index.php?date=<?php echo $next_date; ?>"
     class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-gray-500 hover:bg-gray-50 <?php echo $is_future ? 'opacity-30 pointer-events-none' : ''; ?>">
    <i class="fa-solid fa-chevron-right"></i>
  </a>
  <?php if ($date === $today): ?>
  <span class="text-xs text-amber-600 font-medium">Today</span>
  <?php endif; ?>
  <span id="load_status" class="text-xs text-gray-400 ml-2"></span>
  <span id="save_status" class="text-xs ml-2"></span>
</div>

<div class="flex gap-4" style="align-items:flex-start">

  <!-- LEFT: SOURCE LIST -->
  <div class="w-72 flex-shrink-0" style="position:sticky;top:58px;">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
      <div class="px-4 py-3 bg-gray-50 border-b border-gray-100 font-semibold text-sm text-gray-700">
        <i class="fa-solid fa-list mr-1 text-gray-500"></i>Source List
        <span id="unplaced_count" class="ml-2 text-xs text-gray-400"></span>
      </div>
      <div id="source_list" class="p-3 min-h-24" style="max-height:50vh;overflow-y:auto;">
        <div class="text-center text-gray-400 text-xs py-4" id="source_empty">
          Select a date to load purchases.
        </div>
      </div>
    </div>
  </div>

  <!-- RIGHT: FORM -->
  <div class="flex-1">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">

      <!-- Form header -->
      <div class="px-5 py-3 bg-amber-50 border-b border-amber-100">
        <div class="flex justify-between items-center">
          <div class="text-lg font-bold text-amber-800">Cash Disbursement</div>
          <button onclick="saveToDb()" id="btn_save"
                  class="flex items-center gap-1.5 px-4 py-1.5 bg-red-600 hover:bg-red-700 active:bg-red-800 text-white rounded-lg text-sm font-bold shadow-md transition-all">
            <i class="fa-solid fa-floppy-disk"></i>SAVE
          </button>
        </div>
        <div class="flex gap-6 mt-1 text-xs text-amber-700">
          <span id="form_date_label"><?php echo date('F j, Y', strtotime($date)); ?></span>
          <span>PREPARED: <strong>LIZA</strong></span>
          <span>APPROVED: <strong>SIR MIN</strong></span>
        </div>
      </div>

      <!-- Column headers -->
      <div class="section-row bg-gray-50 text-gray-500 text-xs font-semibold border-b border-gray-200" style="font-size:10px">
        <span></span>
        <span>OR/SI NO.</span>
        <span>DATE</span>
        <span>COMPANY (SUPPLIER)</span>
        <span>DETAILS</span>
        <span class="text-right">AMOUNT</span>
        <span></span>
      </div>

      <?php
      $sections = [
          'korean'      => ['label'=>'1. KOREAN',         'color'=>'blue'],
          'local'       => ['label'=>'2. LOCAL',           'color'=>'green'],
          'fixed'       => ['label'=>'3. FIXED EXPENSES',  'color'=>'orange'],
          'maintenance' => ['label'=>'4. MAINTENANCE',     'color'=>'teal'],
          'others'      => ['label'=>'5. OTHERS',          'color'=>'purple'],
      ];
      $color_map = [
          'blue'   => ['bg'=>'#eff6ff','border'=>'#bfdbfe','text'=>'#1d4ed8'],
          'green'  => ['bg'=>'#f0fdf4','border'=>'#bbf7d0','text'=>'#15803d'],
          'orange' => ['bg'=>'#fff7ed','border'=>'#fed7aa','text'=>'#c2410c'],
          'teal'   => ['bg'=>'#f0fdfa','border'=>'#99f6e4','text'=>'#0f766e'],
          'purple' => ['bg'=>'#faf5ff','border'=>'#e9d5ff','text'=>'#7e22ce'],
      ];
      foreach ($sections as $sec_key => $sec):
          $c = $color_map[$sec['color']];
      ?>
      <!-- Section: <?php echo $sec['label']; ?> -->
      <div>
        <div class="section-header"
             style="background:<?php echo $c['bg']; ?>;border-left:3px solid <?php echo $c['border']; ?>">
          <span style="color:<?php echo $c['text']; ?>"><?php echo $sec['label']; ?></span>
          <span id="total_<?php echo $sec_key; ?>" class="text-xs font-mono" style="color:<?php echo $c['text']; ?>">₱ 0.00</span>
        </div>
        <div id="section_<?php echo $sec_key; ?>" class="drop-zone mx-3 mb-2">
          <!-- Items dropped here -->
        </div>
      </div>
      <?php endforeach; ?>

      <!-- Totals -->
      <div class="total-bar">
        <span class="text-gray-500">SUPPLIER: <span id="total_supplier" class="text-gray-800">₱ 0.00</span></span>
        <span class="text-gray-500">OTHER EXP: <span id="total_other_exp" class="text-gray-800">₱ 0.00</span></span>
        <span class="text-gray-500 font-bold">GRAND TOTAL: <span id="total_grand" class="text-amber-700 text-base">₱ 0.00</span></span>
      </div>
    </div>
  </div>
</div>


<script>
const STORE_ID = <?php echo (int)$store_id; ?>;
const STATE_KEY = () => 'cd_draft_' + STORE_ID + '_' + document.getElementById('cd_date').value;

let allItems   = [];
let sections   = { korean:[], local:[], fixed:[], maintenance:[], others:[] };
let placedIds  = new Set();
let rowCounter = 0;
let isDirty    = false;

// ── 섹션 키 보완 (구버전 저장 데이터 호환) ──────────────────
function ensureAllSections(s) {
    ['korean','local','fixed','maintenance','others'].forEach(k => {
        if (!Array.isArray(s[k])) s[k] = [];
    });
}

// ── placedIds 재계산 ─────────────────────────────────────────
function rebuildPlacedIds() {
    placedIds = new Set();
    ['korean','local','fixed','maintenance','others'].forEach(sec => {
        (sections[sec] || []).forEach(r => { if (r.item_id) placedIds.add(r.item_id); });
    });
}

// ── rowCounter를 기존 row ID 최댓값에 맞춰 동기화 ──────────
function syncRowCounter() {
    let max = 0;
    ['korean','local','fixed','maintenance','others'].forEach(sec => {
        (sections[sec] || []).forEach(row => {
            const n = parseInt((row.id || '').replace('row_', ''), 10);
            if (!isNaN(n) && n > max) max = n;
        });
    });
    if (max > rowCounter) rowCounter = max;
}

// ── 저장된 state에 중복 row ID가 있으면 새 고유 ID로 교체 ──
// syncRowCounter() 호출 후 실행해야 rowCounter가 충분히 큰 값을 가짐
function deduplicateRowIds() {
    const seen = new Set();
    ['korean','local','fixed','maintenance','others'].forEach(sec => {
        (sections[sec] || []).forEach(row => {
            while (!row.id || seen.has(row.id)) {
                row.id = 'row_' + (++rowCounter);
            }
            seen.add(row.id);
        });
    });
}

// ── SortableJS Setup ──────────────────────────────────────
const sortableOptions = (secKey) => ({
    group: { name:'cd', pull: secKey === 'source' ? 'clone' : true, put: secKey !== 'source' },
    sort: secKey !== 'source',
    animation: 150,
    handle: secKey === 'source' ? undefined : '.drag-handle',
    ghostClass: 'sortable-ghost',
    chosenClass: 'sortable-chosen',
    onAdd: (evt) => {
        if (secKey === 'source') return;
        const itemId = evt.item.dataset.itemId;
        const rowId  = evt.item.dataset.rowId;

        if (itemId) {
            // Dragged from source list
            const item = allItems.find(i => String(i.id) === String(itemId));
            if (item && !sections[secKey].find(r => String(r.item_id) === String(itemId))) {
                sections[secKey].splice(evt.newIndex, 0, buildRow(item));
                rebuildPlacedIds();
                saveMapping(item.supplier_name, secKey);
            }
        } else if (rowId) {
            // Dragged from another section
            const fromSecKey = evt.from.id.replace('section_', '');
            const idx = (sections[fromSecKey] || []).findIndex(r => r.id === rowId);
            if (idx !== -1) {
                const [movedRow] = sections[fromSecKey].splice(idx, 1);
                sections[secKey].splice(evt.newIndex, 0, movedRow);
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

const sourceSort = Sortable.create(document.getElementById('source_list'), {
    group: { name:'cd', pull:'clone', put:false },
    sort: false,
    animation:150,
    ghostClass:'sortable-ghost',
    chosenClass:'sortable-chosen'
});

['korean','local','fixed','maintenance','others'].forEach(s => {
    Sortable.create(document.getElementById('section_' + s), sortableOptions(s));
});

// ── Data Builders ──────────────────────────────────────────
function buildRow(item, orSiNo) {
    return {
        id:       'row_' + (++rowCounter),
        item_id:  item.id,
        or_si_no: orSiNo || item.cv_no || '',
        date:     item.date,
        supplier: item.supplier_name,
        details:  item.details,
        amount:   item.amount,
        manual:   false
    };
}


// ── Load Purchases ─────────────────────────────────────────
// Priority: 1) DB saved state  2) localStorage draft  3) auto-placement
function loadPurchases(date) {
    document.getElementById('form_date_label').textContent =
        new Date(date + 'T00:00:00').toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'});
    document.getElementById('load_status').textContent = 'Loading...';
    setSaveStatus('loading');

    // Reset
    sections  = { korean:[], local:[], fixed:[], maintenance:[], others:[] };
    placedIds = new Set();
    allItems  = [];

    fetch('ajax_load_purchases.php?date=' + date)
    .then(r => r.text())
    .then(txt => JSON.parse(txt.replace(/^﻿/, '')))
    .then(d => {
        if (!d.success) {
            document.getElementById('load_status').textContent = 'Error';
            setSaveStatus('error');
            return;
        }
        allItems = d.items;
        if (d.er_found === false) {
            document.getElementById('load_status').innerHTML =
                '<span class="text-orange-500">⚠ 해당 날짜의 Expense Report가 없습니다. ER을 먼저 작성해 주세요.</span>';
        } else {
            document.getElementById('load_status').textContent = allItems.length + ' items loaded';
        }
        document.getElementById('source_empty').style.display = 'none';

        if (d.saved_state && d.saved_state.sections) {
            // Priority 1: DB에 저장된 상태 복원
            sections = d.saved_state.sections;
            ensureAllSections(sections);  // 구버전 저장 데이터에 없는 섹션 보완
            rebuildPlacedIds();
            syncRowCounter();        // rowCounter를 기존 max ID보다 높게 설정
            deduplicateRowIds();     // 기존 저장 데이터의 중복 ID 제거
            isDirty = false;
            setSaveStatus('saved', d.saved_state.saved_at);
        } else {
            const draft = loadDraft(date);
            if (draft && draft.sections) {
                // Priority 2: 이번 세션 draft 복원
                sections = draft.sections;
                ensureAllSections(sections);  // localStorage draft도 동일하게 보완
                rebuildPlacedIds();
                syncRowCounter();        // rowCounter를 기존 max ID보다 높게 설정
                deduplicateRowIds();     // draft의 중복 ID 제거
                isDirty = true;
                setSaveStatus('draft');
            } else {
                // Priority 3: 자동배치 (참고용, 저장 안됨)
                allItems.forEach(item => {
                    if (item.auto_section) {
                        sections[item.auto_section].push(buildRow(item));
                    }
                });
                rebuildPlacedIds();
                isDirty = false;
                setSaveStatus('unsaved');
            }
        }

        renderAll();
        calcTotals();
    })
    .catch(() => {
        document.getElementById('load_status').textContent = 'Error loading data';
        setSaveStatus('error');
    });
}

// ── Render ─────────────────────────────────────────────────
function renderSourceList() {
    const list = document.getElementById('source_list');
    [...list.querySelectorAll('.cd-card')].forEach(el => el.remove());

    // Items (today + carryover)
    const cdDate = document.getElementById('cd_date').value;
    const typeIcon = {'product':'💵','equipment':'🔧','receipt':'🧾'};
    let unplaced = 0;
    allItems.forEach(item => {
        if (placedIds.has(item.id)) return; // 배치된 항목은 Source List에서 제거
        unplaced++;
        const isCarryover = item.date && item.date < cdDate;
        const card = document.createElement('div');
        card.className = 'cd-card' + (item.auto_section ? ' auto-placed' : '');
        card.dataset.itemId = item.id;
        card.innerHTML = `
            <div class="flex justify-between items-start">
                <div class="font-medium text-gray-800 text-xs">${esc(item.supplier_name)}</div>
                <div class="text-xs font-mono text-gray-700 ml-2">₱${fmt(item.amount)}</div>
            </div>
            <div class="text-xs text-gray-500 truncate">${typeIcon[item.type]||'📄'} ${esc(item.details)}</div>
            ${isCarryover ? `<span class="text-xs text-orange-500 font-medium">↩ ${item.date}</span>` : ''}
            ${item.auto_section ? `<span class="text-xs text-green-600">🟢 추천: ${item.auto_section}</span>` : ''}
        `;
        // SortableJS handles all drag — no native drag events needed
        list.appendChild(card);
    });

    document.getElementById('unplaced_count').textContent = unplaced > 0 ? `(${unplaced} unplaced)` : '';
}

function renderSection(secKey) {
    const zone = document.getElementById('section_' + secKey);
    zone.innerHTML = '';
    (sections[secKey] || []).forEach(row => {
        const div = document.createElement('div');
        div.className = 'section-row';
        div.dataset.rowId = row.id;
        div.innerHTML = `
            <span class="drag-handle"><i class="fa-solid fa-grip-lines"></i></span>
            <span class="text-gray-600 text-xs truncate">${esc(row.or_si_no)}</span>
            <span class="text-gray-500 text-xs">${row.date}</span>
            <span class="text-gray-800 text-xs font-medium truncate">${esc(row.supplier)}</span>
            <span class="text-gray-500 text-xs truncate">${esc(row.details)}</span>
            <span class="text-right font-mono text-gray-800 text-xs">₱${fmt(row.amount)}</span>
            <button onclick="removeRow('${secKey}','${row.id}','${row.item_id || ''}')"
                    class="text-gray-300 hover:text-red-500 text-xs">×</button>
        `;
        zone.appendChild(div);
    });
}

function renderAll() {
    renderSourceList();
    ['korean','local','fixed','maintenance','others'].forEach(renderSection);
    calcTotals();
}

// ── Row Actions ────────────────────────────────────────────
function removeRow(secKey, rowId, itemId) {
    const idx = (sections[secKey] || []).findIndex(r => r.id === rowId);
    if (idx !== -1) sections[secKey].splice(idx, 1);
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




// ── Mapping Save ───────────────────────────────────────────
function saveMapping(supplierName, section) {
    const fd = new FormData();
    fd.append('supplier_name', supplierName);
    fd.append('section', section);
    fetch('ajax_save_mapping.php', { method:'POST', body:fd }).catch(() => {});
}

// ── DB Save ────────────────────────────────────────────────
function saveToDb() {
    const date = document.getElementById('cd_date').value;
    const payload = JSON.stringify({ sections });
    const btn = document.getElementById('btn_save');
    btn.disabled = true;
    setSaveStatus('saving');

    const fd = new FormData();
    fd.append('date', date);
    fd.append('state', payload);

    fetch('ajax_save_cd.php', { method:'POST', body:fd })
    .then(r => r.json())
    .then(d => {
        btn.disabled = false;
        if (d.success) {
            isDirty = false;
            // Clear draft since DB is now the source of truth
            try { localStorage.removeItem(STATE_KEY()); } catch(e) {}
            const now = new Date().toLocaleString('en-US',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
            setSaveStatus('saved', now);
        } else {
            setSaveStatus('error');
        }
    })
    .catch(() => {
        btn.disabled = false;
        setSaveStatus('error');
    });
}

// ── Save Status Display ────────────────────────────────────
function setSaveStatus(status, info) {
    const el = document.getElementById('save_status');
    const btn = document.getElementById('btn_save');
    if (status === 'saved') {
        el.innerHTML = `<span class="text-green-600"><i class="fa-solid fa-circle-check mr-1"></i>Saved${info ? ' · ' + info : ''}</span>`;
        btn.classList.replace('bg-red-600','bg-red-400');
        btn.classList.replace('hover:bg-red-700','hover:bg-red-500');
    } else if (status === 'draft') {
        el.innerHTML = `<span class="text-amber-500"><i class="fa-solid fa-pen-to-square mr-1"></i>Draft (unsaved)</span>`;
        btn.classList.replace('bg-red-400','bg-red-600');
        btn.classList.replace('hover:bg-red-500','hover:bg-red-700');
    } else if (status === 'unsaved') {
        el.innerHTML = `<span class="text-gray-400">Not saved</span>`;
        btn.classList.replace('bg-red-400','bg-red-600');
        btn.classList.replace('hover:bg-red-500','hover:bg-red-700');
    } else if (status === 'saving') {
        el.innerHTML = `<span class="text-blue-500"><i class="fa-solid fa-spinner fa-spin mr-1"></i>Saving...</span>`;
    } else if (status === 'error') {
        el.innerHTML = `<span class="text-red-500"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Save failed</span>`;
    } else if (status === 'loading') {
        el.innerHTML = '';
    }
}

// ── Dirty tracking (localStorage draft) ───────────────────
function markDirty() {
    isDirty = true;
    setSaveStatus('draft');
    // Auto-save to localStorage as draft
    try {
        localStorage.setItem(STATE_KEY(), JSON.stringify({ sections }));
    } catch(e) {}
}

// ── localStorage draft (session only) ─────────────────────
function loadDraft(date) {
    try {
        const key  = 'cd_draft_' + STORE_ID + '_' + date;
        const data = localStorage.getItem(key);
        return data ? JSON.parse(data) : null;
    } catch(e) { return null; }
}

// ── Totals ─────────────────────────────────────────────────
function fmt(n) { return parseFloat(n||0).toLocaleString('en',{minimumFractionDigits:2}); }
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function calcTotals() {
    const sum = (sec) => (sections[sec] || []).reduce((t,r) => t + (parseFloat(r.amount)||0), 0);
    const k = sum('korean'), l = sum('local'), f = sum('fixed'), m = sum('maintenance'), o = sum('others');
    const supplier  = k + l;
    const other_exp = f + m + o;
    const grand     = supplier + other_exp;
    document.getElementById('total_korean').textContent       = '₱ ' + fmt(k);
    document.getElementById('total_local').textContent        = '₱ ' + fmt(l);
    document.getElementById('total_fixed').textContent        = '₱ ' + fmt(f);
    document.getElementById('total_maintenance').textContent  = '₱ ' + fmt(m);
    document.getElementById('total_others').textContent       = '₱ ' + fmt(o);
    document.getElementById('total_supplier').textContent     = '₱ ' + fmt(supplier);
    document.getElementById('total_other_exp').textContent    = '₱ ' + fmt(other_exp);
    document.getElementById('total_grand').textContent        = '₱ ' + fmt(grand);
}

// ── Print / Export ─────────────────────────────────────────
function getSectionsPayload() {
    return { date: document.getElementById('cd_date').value, sections };
}

function openPrint() {
    const payload = JSON.stringify(getSectionsPayload());
    const form = document.createElement('form');
    form.method = 'POST'; form.action = 'print_cd.php'; form.target = '_blank';
    const inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = 'data'; inp.value = payload;
    form.appendChild(inp); document.body.appendChild(form);
    form.submit(); form.remove();
}

function openExport() {
    const payload = JSON.stringify(getSectionsPayload());
    const form = document.createElement('form');
    form.method = 'POST'; form.action = 'export_cd.php'; form.target = '_blank';
    const inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = 'data'; inp.value = payload;
    form.appendChild(inp); document.body.appendChild(form);
    form.submit(); form.remove();
}

// ── Init ───────────────────────────────────────────────────
loadPurchases('<?php echo $date; ?>');
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
