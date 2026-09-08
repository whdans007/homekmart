<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('price_adjustment.page_title') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// Design Ref: §7 Permissions — purchase_management 권한 필요
if (!has_permission('purchase_management')) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => t('price_adjustment.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}
?>

<div class="w-full px-2 sm:px-3 md:px-4 py-8">

    <!-- 제목 -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">
            <i class="fas fa-sliders-h mr-2 text-green-600"></i>
            <?php echo t('price_adjustment.title'); ?>
        </h1>
        <p class="mt-1 text-sm text-gray-500"><?php echo t('company.name'); ?> — <?php echo htmlspecialchars($current_store_name); ?></p>
    </div>

    <!-- Flash 메시지 -->
    <?php if (isset($_SESSION['flash'])): ?>
        <?php
        $flash = $_SESSION['flash'];
        $alert_class = $flash['type'] === 'success'
            ? 'bg-green-50 border-green-200 text-green-800'
            : 'bg-red-50 border-red-200 text-red-800';
        $icon_class = $flash['type'] === 'success'
            ? 'fa-check-circle text-green-400'
            : 'fa-exclamation-circle text-red-400';
        ?>
        <div class="mb-4 <?php echo $alert_class; ?> border rounded-md p-4">
            <div class="flex">
                <i class="fas <?php echo $icon_class; ?> mr-3 mt-0.5"></i>
                <p class="text-sm"><?php echo htmlspecialchars($flash['message']); ?></p>
            </div>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <!-- 검색 폼 -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-4">
        <div class="flex gap-2">
            <div class="relative flex-1">
                <input
                    type="text"
                    id="search-input"
                    placeholder="<?php echo t('price_adjustment.search_placeholder'); ?>"
                    class="w-full rounded-md border border-gray-300 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                    autocomplete="off"
                >
                <ul id="search-preview" class="hidden absolute z-50 left-0 right-0 top-full mt-1 bg-white border border-gray-200 rounded-md shadow-lg max-h-64 overflow-y-auto text-sm"></ul>
            </div>
            <button
                id="search-btn"
                class="inline-flex items-center px-4 py-2 bg-green-600 text-white text-sm font-medium rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 transition-colors duration-200"
            >
                <i class="fas fa-search mr-2"></i>
                <?php echo t('price_adjustment.search_button'); ?>
            </button>
        </div>
    </div>

    <!-- 결과 테이블 영역 -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">

        <!-- 테이블 헤더 (상단 툴바) -->
        <div id="table-toolbar" class="hidden px-4 py-3 border-b border-gray-200 bg-gray-50">
            <div class="flex items-center justify-between mb-2">
                <span id="result-count" class="text-sm text-gray-600"></span>
                <button
                    id="save-all-btn"
                    class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors duration-200"
                >
                    <i class="fas fa-save mr-2"></i>
                    <span id="save-btn-label"><?php echo t('price_adjustment.save_all_button'); ?></span>
                </button>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-sm text-gray-600">
                <i class="fas fa-tag text-orange-400"></i>
                <label class="flex items-center gap-1">
                    <span class="whitespace-nowrap"><?php echo t('price_adjustment.event_name_label'); ?></span>
                    <input type="text" id="event-name" placeholder="<?php echo htmlspecialchars(t('price_adjustment.event_name_placeholder')); ?>"
                        class="border border-gray-300 rounded px-2 py-1 text-sm w-44 focus:outline-none focus:ring-1 focus:ring-orange-400">
                </label>
                <label class="flex items-center gap-1">
                    <span class="whitespace-nowrap"><?php echo t('price_adjustment.event_end_date_label'); ?></span>
                    <input type="date" id="event-end-date"
                        class="border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-orange-400">
                </label>
                <span class="text-xs text-gray-400"><?php echo t('price_adjustment.event_end_date_hint'); ?></span>
            </div>
        </div>

        <!-- 안내 메시지 (초기 상태) -->
        <div id="hint-area" class="py-16 text-center text-gray-400">
            <i class="fas fa-search text-3xl mb-3"></i>
            <p class="text-sm"><?php echo t('price_adjustment.search_hint'); ?></p>
        </div>

        <!-- 검색 결과 없음 -->
        <div id="no-results-area" class="hidden py-16 text-center text-gray-400">
            <i class="fas fa-box-open text-3xl mb-3"></i>
            <p class="text-sm"><?php echo t('price_adjustment.no_results'); ?></p>
        </div>

        <!-- 결과 테이블 -->
        <div id="results-area" class="hidden overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-3 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider w-28">
                            <?php echo t('price_adjustment.col_sku'); ?>
                        </th>
                        <th class="px-3 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            <?php echo t('price_adjustment.col_product_name'); ?>
                        </th>
                        <th class="px-3 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider w-28">
                            <?php echo t('price_adjustment.col_current_cost'); ?>
                        </th>
                        <th class="px-3 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider w-32">
                            <?php echo t('price_adjustment.col_new_cost'); ?>
                        </th>
                        <th class="px-3 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider w-28">
                            <?php echo t('price_adjustment.col_current_selling'); ?>
                        </th>
                        <th class="px-3 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider w-32">
                            <?php echo t('price_adjustment.col_new_selling'); ?>
                        </th>
                        <th class="px-3 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider w-24">
                            <?php echo t('price_adjustment.col_margin'); ?>
                        </th>
                        <th class="px-3 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider w-16">
                            <?php echo t('price_adjustment.col_status'); ?>
                        </th>
                        <th class="px-3 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider w-10">
                        </th>
                    </tr>
                </thead>
                <tbody id="results-tbody" class="divide-y divide-gray-100">
                </tbody>
            </table>
        </div>

    </div>

    <!-- 저장 완료 요약 -->
    <div id="save-summary" class="hidden mt-4 p-4 rounded-md border"></div>

    <!-- 행사가격 관리 목록 -->
    <div class="mt-6 bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-200 bg-orange-50 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-orange-700">
                <i class="fas fa-tags mr-2"></i><?php echo t('price_adjustment.event_management_title'); ?>
            </h2>
            <button id="refresh-events-btn"
                class="text-xs text-orange-600 hover:text-orange-800 flex items-center gap-1">
                <i class="fas fa-sync-alt"></i> <?php echo t('price_adjustment.refresh_button'); ?>
            </button>
        </div>
        <div id="events-loading" class="py-8 text-center text-gray-400 text-sm">
            <i class="fas fa-spinner fa-spin mr-2"></i><?php echo t('price_adjustment.loading_events'); ?>
        </div>
        <div id="events-empty" class="hidden py-10 text-center text-gray-400 text-sm">
            <i class="fas fa-calendar-times text-2xl mb-2 block"></i><?php echo t('price_adjustment.no_events'); ?>
        </div>
        <div id="events-table-wrap" class="hidden overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-3 py-2 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider w-24"><?php echo t('price_adjustment.col_sku'); ?></th>
                        <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider"><?php echo t('price_adjustment.col_product_name'); ?></th>
                        <th class="px-3 py-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider w-28"><?php echo t('price_adjustment.col_original_cost'); ?></th>
                        <th class="px-3 py-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider w-28"><?php echo t('price_adjustment.col_event_cost'); ?></th>
                        <th class="px-3 py-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider w-28"><?php echo t('price_adjustment.col_original_selling'); ?></th>
                        <th class="px-3 py-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider w-28"><?php echo t('price_adjustment.col_event_selling'); ?></th>
                        <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider w-32"><?php echo t('price_adjustment.event_name_label'); ?></th>
                        <th class="px-3 py-2 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider w-24"><?php echo t('price_adjustment.event_end_date_label'); ?></th>
                        <th class="px-3 py-2 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider w-16"><?php echo t('price_adjustment.col_status'); ?></th>
                        <th class="px-3 py-2 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider w-28"><?php echo t('price_adjustment.col_action'); ?></th>
                    </tr>
                </thead>
                <tbody id="events-tbody" class="divide-y divide-gray-100"></tbody>
            </table>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>

<script>
function generateAdjBarcodes() {
    document.querySelectorAll('.adj-barcode:not([data-rendered])').forEach(el => {
        const sku = el.getAttribute('data-sku');
        if (!sku) return;
        try {
            JsBarcode(el, sku, {
                format: "CODE128",
                width: 1,
                height: 22,
                displayValue: false,
                margin: 0
            });
            el.setAttribute('data-rendered', '1');
        } catch (e) {
            el.outerHTML = `<span class="text-red-400 text-xs">ERR</span>`;
        }
    });
}

// Design Ref: §6 마진율 계산
function calcMargin(costPrice, sellingPrice) {
    const cost = parseFloat(costPrice) || 0;
    const sell = parseFloat(sellingPrice) || 0;
    if (cost <= 0) return '-';
    const margin = ((sell - cost) / cost * 100);
    const cls = margin >= 0 ? 'text-green-600' : 'text-red-600';
    return `<span class="${cls}">${margin.toFixed(1)}%</span>`;
}

function formatNumber(n) {
    return parseFloat(n || 0).toLocaleString('ko-KR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// 입력 변경 감지: 현재값과 다르면 data-changed="true"
function checkChanged(row) {
    const origCost    = parseFloat(row.dataset.origCost)    || 0;
    const origSelling = parseFloat(row.dataset.origSelling) || 0;
    const costVal    = row.querySelector('.new-cost').value;
    const sellingVal = row.querySelector('.new-selling').value;
    // 빈 입력은 변경 없음으로 처리
    const newCost    = costVal    !== '' ? parseFloat(costVal)    : origCost;
    const newSelling = sellingVal !== '' ? parseFloat(sellingVal) : origSelling;
    const changed = (newCost !== origCost) || (newSelling !== origSelling);
    row.dataset.changed = changed ? 'true' : 'false';
    row.classList.toggle('bg-yellow-50', changed);
}

// 마진율 업데이트
function updateMargin(row) {
    const cost   = row.querySelector('.new-cost').value   || row.dataset.origCost;
    const sell   = row.querySelector('.new-selling').value || row.dataset.origSelling;
    row.querySelector('.margin-cell').innerHTML = calcMargin(cost, sell);
}

// Design Ref: §5 UI — 상품 행 렌더링
function buildRow(p) {
    const tr = document.createElement('tr');
    tr.className = 'product-row hover:bg-gray-50 transition-colors';
    tr.dataset.productId   = p.id;
    tr.dataset.origCost    = p.cost_price || 0;
    tr.dataset.origSelling = p.selling_price || 0;
    tr.dataset.changed     = 'false';

    tr.innerHTML = `
        <td class="px-3 py-3 text-center">
            ${p.sku ? `<svg class="adj-barcode" data-sku="${escHtml(p.sku)}"></svg>` : ''}
            <div class="text-xs text-gray-500 font-mono mt-0.5">${escHtml(p.sku || '')}</div>
        </td>
        <td class="px-3 py-3">
            <div class="text-sm text-gray-900 font-medium">${escHtml(p.name_ko || p.name_en || '')}</div>
            ${p.name_en && p.name_ko ? `<div class="text-xs text-gray-400 mt-0.5">${escHtml(p.name_en)}</div>` : ''}
        </td>
        <td class="px-3 py-3 text-right text-sm text-gray-600">${formatNumber(p.cost_price)}</td>
        <td class="px-3 py-3 text-right">
            <input type="number"
                class="new-cost w-full text-right border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-blue-400"
                placeholder="${formatNumber(p.cost_price)}"
                min="0" step="0.01"
                value="">
        </td>
        <td class="px-3 py-3 text-right text-sm text-gray-600">${formatNumber(p.selling_price)}</td>
        <td class="px-3 py-3 text-right">
            <input type="number"
                class="new-selling w-full text-right border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-blue-400"
                placeholder="${formatNumber(p.selling_price)}"
                min="0" step="0.01"
                value="">
        </td>
        <td class="px-3 py-3 text-right margin-cell text-sm">${calcMargin(p.cost_price, p.selling_price)}</td>
        <td class="px-3 py-3 text-center status-cell text-gray-300">
            <i class="fas fa-circle text-xs"></i>
        </td>
        <td class="px-3 py-3 text-center">
            <button class="remove-row-btn text-gray-300 hover:text-red-500 transition-colors" title="<?php echo htmlspecialchars(t('price_adjustment.remove_row_tooltip')); ?>">
                <i class="fas fa-times"></i>
            </button>
        </td>
    `;

    tr.querySelector('.new-cost').addEventListener('input', () => {
        checkChanged(tr);
        updateMargin(tr);
    });
    tr.querySelector('.new-selling').addEventListener('input', () => {
        checkChanged(tr);
        updateMargin(tr);
    });
    tr.querySelector('.remove-row-btn').addEventListener('click', () => {
        tr.remove();
        updateRowCount();
    });

    return tr;
}

// 목록에 상품 추가 (중복 제거)
function addProductToList(p) {
    const existing = document.querySelector(`.product-row[data-product-id="${p.id}"]`);
    if (existing) {
        existing.classList.add('ring-2', 'ring-inset', 'ring-blue-400');
        existing.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        setTimeout(() => existing.classList.remove('ring-2', 'ring-inset', 'ring-blue-400'), 1500);
        return;
    }
    document.getElementById('hint-area').classList.add('hidden');
    document.getElementById('no-results-area').classList.add('hidden');
    document.getElementById('results-area').classList.remove('hidden');
    document.getElementById('table-toolbar').classList.remove('hidden');
    document.getElementById('results-tbody').appendChild(buildRow(p));
    updateRowCount();
    generateAdjBarcodes();
}

function updateRowCount() {
    const count = document.querySelectorAll('.product-row').length;
    document.getElementById('result-count').textContent = '<?php echo addslashes(t('price_adjustment.result_count_label')); ?>'.replace('{count}', count);
    if (count === 0) {
        document.getElementById('results-area').classList.add('hidden');
        document.getElementById('table-toolbar').classList.add('hidden');
        document.getElementById('hint-area').classList.remove('hidden');
    }
}

function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// 검색 — 결과를 목록에 누적 추가 (기존 항목 유지)
async function doSearch() {
    const term = document.getElementById('search-input').value.trim();
    if (!term) return;

    const searchBtn = document.getElementById('search-btn');
    searchBtn.disabled = true;
    searchBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i><?php echo addslashes(t('price_adjustment.searching_text')); ?>';

    try {
        const resp = await fetch(`ajax_search_products.php?term=${encodeURIComponent(term)}&limit=10`);
        const data = await resp.json();
        const products = Array.isArray(data) ? data : (data.products || []);

        if (products.length === 0) {
            document.getElementById('no-results-area').classList.remove('hidden');
            setTimeout(() => document.getElementById('no-results-area').classList.add('hidden'), 2000);
        } else {
            products.forEach(p => addProductToList(p));
        }

        searchInput.value = '';
        closePreview();
    } catch (e) {
        alert('<?php echo addslashes(t('price_adjustment.js_search_error')); ?>');
    } finally {
        searchBtn.disabled = false;
        searchBtn.innerHTML = '<i class="fas fa-search mr-2"></i><?php echo t('price_adjustment.search_button'); ?>';
    }
}

// Design Ref: §7 저장 로직 — 변경된 행만 순차 저장
async function saveAll() {
    const rows = [...document.querySelectorAll('.product-row[data-changed="true"]')];

    if (rows.length === 0) {
        showSummary('warning', '<?php echo t('price_adjustment.no_changes'); ?>');
        return;
    }

    const saveBtn = document.getElementById('save-all-btn');
    const saveLabel = document.getElementById('save-btn-label');
    saveBtn.disabled = true;
    saveLabel.textContent = '<?php echo t('price_adjustment.saving'); ?>';

    let successCount = 0;
    const errors = [];

    for (const row of rows) {
        const productId  = row.dataset.productId;
        const costVal    = row.querySelector('.new-cost').value;
        const sellingVal = row.querySelector('.new-selling').value;
        // 빈 입력은 현재값 유지, 0 입력은 0으로 저장
        const newCost    = costVal    !== '' ? parseFloat(costVal)    : parseFloat(row.dataset.origCost)    || 0;
        const newSelling = sellingVal !== '' ? parseFloat(sellingVal) : parseFloat(row.dataset.origSelling) || 0;

        const statusCell = row.querySelector('.status-cell');
        statusCell.innerHTML = '<i class="fas fa-spinner fa-spin text-blue-400 text-xs"></i>';

        try {
            const resp = await fetch('ajax_save_price_change.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    product_id:        parseInt(productId),
                    new_cost_price:    newCost,
                    new_selling_price: newSelling,
                    event_name: document.getElementById('event-name').value.trim() || null,
                    end_date:   document.getElementById('event-end-date').value   || null
                })
            });
            const result = await resp.json();

            if (result.success) {
                successCount++;
                statusCell.innerHTML = '<i class="fas fa-check-circle text-green-500 text-xs"></i>';
                row.dataset.changed = 'false';
                row.classList.remove('bg-yellow-50');
                // 저장 완료 행은 잠시 후 목록에서 제거
                setTimeout(() => { row.remove(); updateRowCount(); }, 800);
            } else {
                statusCell.innerHTML = '<i class="fas fa-times-circle text-red-500 text-xs"></i>';
                errors.push(result.message || '<?php echo addslashes(t('price_adjustment.js_save_failed')); ?>');
            }
        } catch (e) {
            statusCell.innerHTML = '<i class="fas fa-times-circle text-red-500 text-xs"></i>';
            errors.push('<?php echo addslashes(t('price_adjustment.js_network_error')); ?>');
        }
    }

    saveBtn.disabled = false;
    saveLabel.textContent = '<?php echo t('price_adjustment.save_all_button'); ?>';

    if (errors.length === 0) {
        const msg = '<?php echo t('price_adjustment.save_success_count'); ?>'.replace('{n}', successCount);
        showSummary('success', msg);
    } else {
        const msg = '<?php echo addslashes(t('price_adjustment.js_save_summary_partial')); ?>'
            .replace('{success}', successCount)
            .replace('{failCount}', errors.length)
            .replace('{errors}', errors.join(', '));
        showSummary('error', msg);
    }
    loadEvents();
}

function showSummary(type, msg) {
    const el = document.getElementById('save-summary');
    el.classList.remove('hidden', 'bg-green-50', 'border-green-200', 'text-green-800',
                                  'bg-red-50', 'border-red-200', 'text-red-800',
                                  'bg-yellow-50', 'border-yellow-200', 'text-yellow-800');
    if (type === 'success') {
        el.classList.add('bg-green-50', 'border-green-200', 'text-green-800');
        el.innerHTML = `<i class="fas fa-check-circle mr-2"></i>${escHtml(msg)}`;
    } else if (type === 'error') {
        el.classList.add('bg-red-50', 'border-red-200', 'text-red-800');
        el.innerHTML = `<i class="fas fa-exclamation-circle mr-2"></i>${escHtml(msg)}`;
    } else {
        el.classList.add('bg-yellow-50', 'border-yellow-200', 'text-yellow-800');
        el.innerHTML = `<i class="fas fa-info-circle mr-2"></i>${escHtml(msg)}`;
    }
}

// 검색 미리보기
let previewTimer = null;
let previewActive = -1;
let previewProducts = [];

const searchInput   = document.getElementById('search-input');
const previewList   = document.getElementById('search-preview');

function closePreview() {
    previewList.classList.add('hidden');
    previewList.innerHTML = '';
    previewActive = -1;
    previewProducts = [];
}

function buildPreview(products) {
    previewProducts = products;
    previewList.innerHTML = '';
    if (products.length === 0) { closePreview(); return; }

    products.slice(0, 8).forEach((p, idx) => {
        const li = document.createElement('li');
        li.className = 'flex items-center gap-2 px-4 py-2 cursor-pointer hover:bg-green-50 transition-colors';
        li.dataset.idx = idx;
        li.innerHTML = `
            <span class="text-xs text-gray-400 font-mono w-20 shrink-0">
                <i class="fas fa-barcode mr-1"></i>${escHtml(p.sku || '')}
            </span>
            <span class="truncate">
                <span class="text-gray-800">${escHtml(p.name_ko || p.name_en || '')}</span>
                ${p.name_en && p.name_ko ? `<span class="text-gray-400 text-xs ml-1">${escHtml(p.name_en)}</span>` : ''}
            </span>
        `;
        li.addEventListener('mousedown', e => {
            e.preventDefault();
            addProductToList(p);
            searchInput.value = '';
            closePreview();
        });
        previewList.appendChild(li);
    });
    previewList.classList.remove('hidden');
    previewActive = -1;
}

function highlightPreview(idx) {
    const items = previewList.querySelectorAll('li');
    items.forEach(li => li.classList.remove('bg-green-50'));
    if (idx >= 0 && idx < items.length) {
        items[idx].classList.add('bg-green-50');
        previewActive = idx;
    }
}

searchInput.addEventListener('input', () => {
    clearTimeout(previewTimer);
    const term = searchInput.value.trim();
    if (term.length < 1) { closePreview(); return; }
    previewTimer = setTimeout(async () => {
        try {
            const resp = await fetch(`ajax_search_products.php?term=${encodeURIComponent(term)}&limit=8`);
            const data = await resp.json();
            const products = Array.isArray(data) ? data : (data.products || []);
            buildPreview(products);
        } catch (_) { closePreview(); }
    }, 200);
});

searchInput.addEventListener('keydown', e => {
    const items = previewList.querySelectorAll('li');
    if (!previewList.classList.contains('hidden') && items.length > 0) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            highlightPreview(Math.min(previewActive + 1, items.length - 1));
            return;
        }
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            highlightPreview(Math.max(previewActive - 1, 0));
            return;
        }
        if (e.key === 'Enter' && previewActive >= 0) {
            e.preventDefault();
            addProductToList(previewProducts[previewActive]);
            searchInput.value = '';
            closePreview();
            return;
        }
        if (e.key === 'Escape') { closePreview(); return; }
    }
    if (e.key === 'Enter') doSearch();
});

searchInput.addEventListener('blur', () => setTimeout(closePreview, 150));

// 행사가격 목록
async function loadEvents() {
    document.getElementById('events-loading').classList.remove('hidden');
    document.getElementById('events-empty').classList.add('hidden');
    document.getElementById('events-table-wrap').classList.add('hidden');
    try {
        const resp = await fetch('ajax_load_price_events.php');
        const data = await resp.json();
        if (!data.success) throw new Error(data.message);
        renderEvents(data.events || []);
        if (data.auto_ended > 0) {
            showSummary('success', '<?php echo addslashes(t('price_adjustment.js_auto_ended_message')); ?>'.replace('{count}', data.auto_ended));
        }
    } catch (e) {
        document.getElementById('events-loading').classList.add('hidden');
    }
}

function renderEvents(events) {
    document.getElementById('events-loading').classList.add('hidden');
    if (events.length === 0) {
        document.getElementById('events-empty').classList.remove('hidden');
        return;
    }
    document.getElementById('events-table-wrap').classList.remove('hidden');
    const tbody = document.getElementById('events-tbody');
    tbody.innerHTML = '';
    events.forEach(ev => {
        const isActive = ev.status === 'active';
        const tr = document.createElement('tr');
        tr.className = isActive
            ? 'hover:bg-orange-50 transition-colors'
            : 'bg-gray-50 text-gray-400 hover:bg-gray-100 transition-colors';

        const statusBadge = isActive
            ? '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-700"><?php echo addslashes(t('price_adjustment.status_active')); ?></span>'
            : '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-200 text-gray-500"><?php echo addslashes(t('price_adjustment.status_ended')); ?></span>';

        const endDateStr = ev.end_date
            ? `<span class="${isActive && ev.end_date ? 'text-red-500 font-medium' : ''}">${ev.end_date}</span>`
            : '<span class="text-gray-300">-</span>';

        const actionBtns = isActive
            ? `<button onclick="endEvent(${ev.id})"
                    class="text-xs px-2 py-1 bg-yellow-100 text-yellow-700 rounded hover:bg-yellow-200 mr-1">
                    <i class="fas fa-stop-circle mr-1"></i><?php echo addslashes(t('price_adjustment.status_ended')); ?>
               </button>
               <button onclick="deleteEvent(${ev.id})"
                    class="text-xs px-2 py-1 bg-red-100 text-red-600 rounded hover:bg-red-200">
                    <i class="fas fa-trash mr-1"></i><?php echo addslashes(t('common.delete')); ?>
               </button>`
            : `<button onclick="deleteEvent(${ev.id})"
                    class="text-xs px-2 py-1 bg-gray-100 text-gray-500 rounded hover:bg-gray-200">
                    <i class="fas fa-trash mr-1"></i><?php echo addslashes(t('common.delete')); ?>
               </button>`;

        tr.innerHTML = `
            <td class="px-3 py-2 text-center">
                ${ev.sku ? `<svg class="adj-barcode" data-sku="${escHtml(ev.sku)}"></svg>` : ''}
                <div class="text-xs text-gray-500 font-mono mt-0.5">${escHtml(ev.sku || '')}</div>
            </td>
            <td class="px-3 py-2">
                <div class="font-medium text-gray-900 text-sm">${escHtml(ev.name_ko || ev.product_name || '')}</div>
                ${ev.name_en ? `<div class="text-xs text-gray-400 mt-0.5">${escHtml(ev.name_en)}</div>` : ''}
            </td>
            <td class="px-3 py-2 text-right text-xs">${formatNumber(ev.original_cost_price)}</td>
            <td class="px-3 py-2 text-right text-xs font-medium ${isActive ? 'text-orange-600' : ''}">${formatNumber(ev.event_cost_price)}</td>
            <td class="px-3 py-2 text-right text-xs">${formatNumber(ev.original_selling_price)}</td>
            <td class="px-3 py-2 text-right text-xs font-medium ${isActive ? 'text-orange-600' : ''}">${formatNumber(ev.event_selling_price)}</td>
            <td class="px-3 py-2 text-xs">${escHtml(ev.event_name || '-')}</td>
            <td class="px-3 py-2 text-center text-xs">${endDateStr}</td>
            <td class="px-3 py-2 text-center">${statusBadge}</td>
            <td class="px-3 py-2 text-center">${actionBtns}</td>
        `;
        tbody.appendChild(tr);
    });
    generateAdjBarcodes();
}

async function endEvent(id) {
    if (!confirm('<?php echo addslashes(t('price_adjustment.js_confirm_end_event')); ?>')) return;
    try {
        const resp = await fetch('ajax_end_price_event.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ event_id: id })
        });
        const data = await resp.json();
        showSummary(data.success ? 'success' : 'error', data.message);
        if (data.success) loadEvents();
    } catch (e) {
        showSummary('error', '<?php echo addslashes(t('price_adjustment.js_generic_error')); ?>');
    }
}

async function deleteEvent(id) {
    if (!confirm(<?php echo json_encode(t('price_adjustment.js_confirm_delete_event')); ?>)) return;
    try {
        const resp = await fetch('ajax_delete_price_event.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ event_id: id })
        });
        const data = await resp.json();
        showSummary(data.success ? 'success' : 'error', data.message);
        if (data.success) loadEvents();
    } catch (e) {
        showSummary('error', '<?php echo addslashes(t('price_adjustment.js_generic_error')); ?>');
    }
}

// 이벤트 바인딩
document.getElementById('search-btn').addEventListener('click', doSearch);
document.getElementById('refresh-events-btn').addEventListener('click', loadEvents);
document.getElementById('save-all-btn').addEventListener('click', saveAll);
loadEvents();
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
