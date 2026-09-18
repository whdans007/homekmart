<?php
require_once __DIR__ . '/lib/auth.php';
// Design Ref: §5.2 — 지점출고 작성/수정 페이지 (draft 워크플로우: 저장 → 수정 → 최종 출고)
$page_title = t('logistics.branch_outbound.page_title');
require_once __DIR__ . '/partials/header.php';

lc_require_staff();
require_once __DIR__ . '/config/db.php';

// 수정 모드: ?draft_id=N (Plan SC-3)
$draft_id = (int)($_GET['draft_id'] ?? 0);

try {
    $conn = get_lc_db();
    // 물류센터(CENTER) 자신은 출고 목적지가 될 수 없으므로 목록에서 제외
    $stores = $conn->query("SELECT id, name FROM stores WHERE name <> '" . $conn->real_escape_string(LC_CENTER_STORE_NAME) . "' ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
    $conn->close();
} catch (Exception $e) {
    $stores = [];
}
?>

<!-- 헤더 -->
<div class="flex items-center justify-between mb-5">
    <div class="flex items-center gap-3">
        <a href="<?php echo LC_BASE; ?>/branch_outbound_list.php" class="text-gray-400 hover:text-gray-600"><i class="fas fa-arrow-left"></i></a>
        <h2 class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($draft_id ? t('logistics.branch_outbound.edit_title', ['id' => $draft_id]) : t('logistics.branch_outbound.title')); ?></h2>
    </div>
</div>

<div id="formError" class="hidden bg-red-50 border border-red-200 rounded-lg px-4 py-3 mb-4">
    <p class="text-sm text-red-700" id="formErrorMsg"></p>
</div>

<!-- 출고 지점 선택 -->
<div class="flex gap-4 mb-4">
    <div class="w-72">
        <label class="block text-xs font-medium text-gray-500 mb-1"><?php echo htmlspecialchars(t('logistics.branch_outbound.destination_store')); ?> <span class="text-red-500">*</span></label>
        <select id="storeSelect" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500 bg-white">
            <option value=""><?php echo htmlspecialchars(t('logistics.branch_outbound.select_store')); ?></option>
            <?php foreach ($stores as $s): ?>
            <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="flex-1">
        <label class="block text-xs font-medium text-gray-500 mb-1"><?php echo htmlspecialchars(t('logistics.branch_outbound.notes')); ?></label>
        <input type="text" id="notesInput" placeholder="<?php echo htmlspecialchars(t('logistics.branch_outbound.notes_placeholder')); ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
    </div>
</div>

<!-- 출고 단위 토글 (Design Ref: box-pcs-unit §5.1 — inbound_add.php와 동일하게 BOX=주황, PCS=파랑) -->
<div class="flex flex-nowrap items-center gap-3 mb-2 overflow-x-auto whitespace-nowrap">
    <span class="text-xs font-medium text-gray-500 shrink-0"><?php echo htmlspecialchars(t('logistics.branch_outbound.outbound_unit')); ?></span>
    <div id="unitToggle" class="inline-flex rounded-lg border-2 border-gray-300 overflow-hidden shadow-sm shrink-0">
        <button type="button" data-unit="BOX" onclick="setOutboundUnit('BOX')"
                class="unit-toggle-btn px-4 py-1.5 text-sm font-extrabold bg-amber-500 text-white transition-colors">
            <i class="fas fa-box mr-1.5"></i>BOX
        </button>
        <button type="button" data-unit="PACK" onclick="setOutboundUnit('PACK')"
                class="unit-toggle-btn px-4 py-1.5 text-sm font-extrabold bg-white text-gray-400 transition-colors">
            <i class="fas fa-boxes-stacked mr-1.5"></i>PACK
        </button>
        <button type="button" data-unit="PCS" onclick="setOutboundUnit('PCS')"
                class="unit-toggle-btn px-4 py-1.5 text-sm font-extrabold bg-white text-gray-400 transition-colors">
            <i class="fas fa-cube mr-1.5"></i>PCS
        </button>
    </div>
    <span id="unitToggleHint" class="text-sm font-bold text-amber-600 shrink-0"><i class="fas fa-box mr-1"></i><?php echo htmlspecialchars(t('logistics.branch_outbound.shipping_in', ['unit' => 'BOX'])); ?></span>
    <span class="text-xs text-gray-400 shrink-0"><?php echo htmlspecialchars(t('logistics.branch_outbound.scan_hint')); ?></span>
</div>

<!-- 상품 검색 -->
<div class="mb-4">
    <div class="flex gap-2">
        <input type="text" id="barcodeInput"
               placeholder="<?php echo htmlspecialchars(t('logistics.branch_outbound.barcode_placeholder')); ?>"
               autocomplete="off"
               class="flex-1 border-2 border-gray-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-100 transition-colors">
        <button type="button" onclick="searchBarcode()"
                class="px-5 py-2.5 bg-teal-600 text-white text-sm font-semibold rounded-lg hover:bg-teal-700 active:bg-teal-800 transition-colors whitespace-nowrap shadow-sm">
            <i class="fas fa-search mr-1.5" style="margin-right:6px"></i><?php echo htmlspecialchars(t('logistics.branch_outbound.search')); ?>
        </button>
    </div>
    <div id="barcodeStatus" class="mt-2 hidden"></div>
    <div id="barcodeMulti" class="mt-2 hidden"></div>
</div>

<!-- 장바구니 -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-4">
    <div class="px-4 py-2.5 border-b border-gray-100 flex flex-wrap items-center justify-between gap-2">
        <span class="text-sm font-medium text-gray-700"><?php echo htmlspecialchars(t('logistics.branch_outbound.product_list')); ?></span>
        <div class="flex gap-2">
            <button type="button" id="saveBtn" style="color:#fff;font-weight:700"
                    class="px-4 py-1.5 bg-red-600 text-white border-2 border-red-600 text-sm font-bold rounded-lg hover:bg-red-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                <i class="fas fa-save mr-1.5" style="margin-right:6px"></i><?php echo htmlspecialchars(t('logistics.branch_outbound.save')); ?>
            </button>
            <button type="button" id="printBtn"
                    class="px-4 py-1.5 bg-white text-gray-700 border-2 border-gray-300 text-sm font-medium rounded-lg hover:bg-gray-50 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                <i class="fas fa-print mr-1.5" style="margin-right:6px"></i><?php echo htmlspecialchars(t('logistics.branch_outbound.print')); ?>
            </button>
            <button type="button" id="submitBtn"
                    class="px-4 py-1.5 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                <i class="fas fa-check mr-1.5" style="margin-right:6px"></i><?php echo htmlspecialchars(t('logistics.branch_outbound.confirm_order')); ?>
            </button>
            <a href="<?php echo LC_BASE; ?>/branch_outbound_list.php" class="px-4 py-1.5 bg-gray-100 text-gray-600 text-sm font-medium rounded-lg hover:bg-gray-200"><?php echo htmlspecialchars(t('logistics.branch_outbound.cancel')); ?></a>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100">
                <tr>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium w-7">#</th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium w-28"><?php echo htmlspecialchars(t('logistics.branch_outbound.barcode')); ?></th>
                    <th class="px-3 py-2 text-left text-xs text-teal-600 font-medium w-32"><?php echo htmlspecialchars(t('logistics.branch_outbound.brand')); ?></th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.branch_outbound.product_name')); ?></th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium w-24"><?php echo htmlspecialchars(t('logistics.branch_outbound.capacity')); ?></th>
                    <th class="px-3 py-2 text-left text-xs text-teal-600 font-medium w-20"><?php echo htmlspecialchars(t('logistics.branch_outbound.unit')); ?></th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium w-20"><?php echo htmlspecialchars(t('logistics.branch_outbound.pkg')); ?></th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium w-24"><?php echo htmlspecialchars(t('logistics.branch_outbound.current_stock')); ?></th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium w-24"><?php echo htmlspecialchars(t('logistics.branch_outbound.quantity')); ?></th>
                    <th class="px-3 py-2 text-left text-xs text-teal-600 font-medium w-28"><?php echo htmlspecialchars(t('logistics.branch_outbound.avg_cost')); ?></th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium w-72"><?php echo htmlspecialchars(t('logistics.branch_outbound.picking_order')); ?></th>
                    <th class="px-3 py-2 text-center text-xs text-gray-500 font-medium w-12"></th>
                </tr>
            </thead>
            <tbody id="itemsBody">
                <tr id="emptyRow">
                    <td colspan="12" class="px-3 py-6 text-center text-sm text-gray-400"><?php echo htmlspecialchars(t('logistics.branch_outbound.empty')); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- 행 템플릿 -->
<template id="rowTpl">
    <tr class="item-row border-b border-gray-50">
        <td class="px-3 py-2 text-xs text-gray-400 row-num"></td>
        <td class="px-3 py-2">
            <span class="row-barcode text-xs text-gray-500 font-mono">-</span>
        </td>
        <td class="px-3 py-2">
            <div class="brand-en text-sm text-teal-700 font-medium leading-tight">-</div>
            <div class="brand-ko text-xs text-teal-600 leading-tight"></div>
        </td>
        <td class="px-3 py-2">
            <div class="name-en text-sm text-gray-700 font-medium leading-tight">-</div>
            <div class="name-ko text-xs text-gray-500 leading-tight"></div>
        </td>
        <td class="px-3 py-2">
            <span class="row-capacity text-sm text-gray-600">-</span>
        </td>
        <td class="px-3 py-2">
            <span class="row-unit inline-block px-1.5 py-0.5 text-xs font-semibold rounded bg-gray-100 text-gray-600">-</span>
        </td>
        <td class="px-3 py-2">
            <span class="row-ppb text-sm text-gray-600">-</span>
        </td>
        <td class="px-3 py-2">
            <span class="row-stock text-sm text-gray-500">-</span>
        </td>
        <td class="px-3 py-2">
            <input type="number" class="row-qty w-full border border-gray-200 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-teal-500" min="1" value="1">
        </td>
        <td class="px-3 py-2">
            <span class="row-cost text-sm font-semibold text-teal-700">-</span>
        </td>
        <td class="px-3 py-2 align-top">
            <div class="fefo-content text-xs text-gray-500"></div>
        </td>
        <td class="px-3 py-2 text-center"><button type="button" class="row-remove text-gray-300 hover:text-red-400 transition-colors"><i class="fas fa-times text-xs"></i></button></td>
    </tr>
</template>

<script>
(function() {
    var LC_BASE = '<?php echo LC_BASE; ?>';
    var CSRF_TOKEN = '<?php echo htmlspecialchars(lc_csrf_token()); ?>';
    var DRAFT_ID = <?php echo $draft_id; ?>; // 0 = 신규, >0 = 수정 모드
    var I18N = {
        shippingIn: <?php echo json_encode(t('logistics.branch_outbound.shipping_in')); ?>,
        searching: <?php echo json_encode(t('logistics.branch_outbound.searching')); ?>,
        noProducts: <?php echo json_encode(t('logistics.branch_outbound.no_products_matching')); ?>,
        productsFound: <?php echo json_encode(t('logistics.branch_outbound.products_found')); ?>,
        searchError: <?php echo json_encode(t('logistics.branch_outbound.search_error')); ?>,
        move: <?php echo json_encode(t('logistics.branch_outbound.move')); ?>,
        add: <?php echo json_encode(t('logistics.branch_outbound.add')); ?>,
        close: <?php echo json_encode(t('logistics.branch_outbound.close')); ?>,
        escape: <?php echo json_encode(t('logistics.branch_outbound.escape')); ?>,
        enter: <?php echo json_encode(t('logistics.branch_outbound.enter')); ?>,
        added: <?php echo json_encode(t('logistics.branch_outbound.added')); ?>,
        qtyAdded: <?php echo json_encode(t('logistics.branch_outbound.qty_added')); ?>,
        loadingPicking: <?php echo json_encode(t('logistics.branch_outbound.loading_picking')); ?>,
        pickingUnavailable: <?php echo json_encode(t('logistics.branch_outbound.picking_unavailable')); ?>,
        empty: <?php echo json_encode(t('logistics.branch_outbound.empty')); ?>,
        pcsShortage: <?php echo json_encode(t('logistics.branch_outbound.pcs_shortage')); ?>,
        negativeStockAll: <?php echo json_encode(t('logistics.branch_outbound.negative_stock_all')); ?>,
        pickingOrder: <?php echo json_encode(t('logistics.branch_outbound.picking_order_label')); ?>,
        negativeStock: <?php echo json_encode(t('logistics.branch_outbound.negative_stock')); ?>,
        confirmMessage: <?php echo json_encode(t('logistics.branch_outbound.confirm_message')); ?>,
        storeRequired: <?php echo json_encode(t('logistics.branch_outbound.store_required')); ?>,
        itemsRequired: <?php echo json_encode(t('logistics.branch_outbound.items_required')); ?>,
        saveFailed: <?php echo json_encode(t('logistics.branch_outbound.save_failed')); ?>,
        confirmFailed: <?php echo json_encode(t('logistics.branch_outbound.confirm_failed')); ?>,
        requestFailed: <?php echo json_encode(t('logistics.branch_outbound.request_failed')); ?>,
        stockCheckFailed: <?php echo json_encode(t('logistics.branch_outbound.stock_check_failed')); ?>
        ,locationNone: <?php echo json_encode(t('logistics.branch_outbound.location_none')); ?>
        ,expiryNone: <?php echo json_encode(t('logistics.branch_outbound.expiry_none')); ?>
        ,networkError: <?php echo json_encode(t('logistics.branch_outbound.network_error')); ?>
        ,loaded: <?php echo json_encode(t('logistics.branch_outbound.loaded')); ?>
        ,loadFailed: <?php echo json_encode(t('logistics.branch_outbound.load_failed')); ?>
    };
    function tr(key, params) {
        var value = I18N[key] || key;
        Object.keys(params || {}).forEach(function(k) { value = value.replace('{' + k + '}', params[k]); });
        return value;
    }

    var multiProducts = [];
    var activeMultiIdx = -1;
    var mouseSelectEnabled = false;
    var multiScrollWrap = null;
    var barcodeTimer = null;
    var searchInFlight = false;
    var lastQuery = '';
    var multiRows = [];

    // 장바구니 라인: { product_id, name, unit(BOX/PCS), product_unit, stock, qty, avg_cost, _fefoHtml }
    // Design Ref: box-pcs-unit §5.4 — 동일 상품이라도 단위가 다르면 별도 행
    var cart = [];
    var fefoTimers = {};

    // ── 출고 단위 토글 (Design §5.1 — inbound_add.php와 동일하게 BOX=amber, PCS=blue) ──
    window.currentOutboundUnit = 'BOX';
    window.setOutboundUnit = function(unit) {
        window.currentOutboundUnit = unit;
        document.querySelectorAll('#unitToggle .unit-toggle-btn').forEach(function(btn) {
            var u = btn.getAttribute('data-unit');
            btn.classList.remove('bg-amber-500', 'bg-emerald-500', 'bg-blue-500', 'text-white', 'bg-white', 'text-gray-400');
            if (u === unit) {
                // Design Ref: pack-unit §5.2 — BOX=주황, PACK=초록, PCS=파랑
                var activeBg = u === 'BOX' ? 'bg-amber-500' : (u === 'PACK' ? 'bg-emerald-500' : 'bg-blue-500');
                btn.classList.add(activeBg, 'text-white');
            } else {
                btn.classList.add('bg-white', 'text-gray-400');
            }
        });
        var hint = document.getElementById('unitToggleHint');
        if (hint) {
            hint.innerHTML = unit === 'BOX'
                ? '<i class="fas fa-box mr-1"></i>' + tr('shippingIn', {unit: 'BOX'})
                : (unit === 'PACK'
                    ? '<i class="fas fa-boxes-stacked mr-1"></i>' + tr('shippingIn', {unit: 'PACK'})
                    : '<i class="fas fa-cube mr-1"></i>' + tr('shippingIn', {unit: 'PCS'}));
            hint.classList.toggle('text-amber-600', unit === 'BOX');
            hint.classList.toggle('text-emerald-600', unit === 'PACK');
            hint.classList.toggle('text-blue-600', unit === 'PCS');
        }
        barcodeInput.focus();
    };

    // 스캔 시 출고 단위 자동 결정: 보유 단위가 하나뿐이면(서버 stock_unit) 그 단위, 아니면 토글 값
    // Design Ref: box-pcs-unit §5.4 — 단위는 데이터(재고)에서 결정, 토글은 폴백/수동 override
    function resolveScanUnit(product) {
        return (product.stock_unit === 'BOX' || product.stock_unit === 'PACK' || product.stock_unit === 'PCS')
            ? product.stock_unit
            : window.currentOutboundUnit;
    }

    var barcodeInput = document.getElementById('barcodeInput');

    barcodeInput.addEventListener('keydown', function(e) {
        var div = document.getElementById('barcodeMulti');
        var isResultsVisible = !div.classList.contains('hidden') && multiProducts.length > 0;

        if (isResultsVisible && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
            e.preventDefault();
            mouseSelectEnabled = false;
            var next = activeMultiIdx + (e.key === 'ArrowDown' ? 1 : -1);
            next = Math.max(0, Math.min(multiProducts.length - 1, next));
            setActiveMultiRow(next);
            return;
        }

        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(barcodeTimer);
            if (isResultsVisible) {
                selectMulti(activeMultiIdx >= 0 ? activeMultiIdx : 0);
            } else {
                searchBarcode();
            }
        }

        if (e.key === 'Escape' && isResultsVisible) {
            e.preventDefault();
            hideMulti();
        }
    });

    barcodeInput.addEventListener('input', function() {
        clearTimeout(barcodeTimer);
        var val = this.value.trim();

        // Design Ref: §5.5 — "숫자/" 접두 = 빠른 수량 입력 모드. 이 동안은 검색 미실행.
        if (/^(\d+)\/(.*)$/.test(val)) {
            var m = val.match(/^(\d+)\/(.*)$/);
            var rest = m[2].trim();
            if (rest.length === 0) return; // 아직 바코드 입력 전
            if (/^\d{8,}$/.test(rest)) {
                barcodeTimer = setTimeout(searchBarcode, 200);
            } else if (rest.length >= 2) {
                barcodeTimer = setTimeout(searchBarcode, 400);
            }
            return;
        }

        if (/^\d{8,}$/.test(val)) {
            barcodeTimer = setTimeout(searchBarcode, 200);
        } else if (val.length >= 2) {
            barcodeTimer = setTimeout(searchBarcode, 400);
        }
    });

    // Design Ref: §5.5 — 입력값에서 빠른수량/실제검색어 분리
    function parseInputValue(val) {
        var m = val.match(/^(\d+)\/(.*)$/);
        if (m) {
            return { qty: Math.max(1, parseInt(m[1], 10) || 1), query: m[2].trim() };
        }
        return { qty: 1, query: val.trim() };
    }

    window.searchBarcode = function() {
        var raw = barcodeInput.value.trim();
        var parsed = parseInputValue(raw);
        var query = parsed.query;
        if (!query) { barcodeInput.focus(); return; }

        var divEl = document.getElementById('barcodeMulti');
        if (raw === lastQuery && !divEl.classList.contains('hidden')) return;
        if (searchInFlight) return;

        searchInFlight = true;
        lastQuery = raw;
        setStatus('loading', I18N.searching);
        hideMulti();

        fetch(LC_BASE + '/ajax/search_product_by_barcode.php?barcode=' + encodeURIComponent(query))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                searchInFlight = false;
                if (!data.success) {
                    setStatus('error', tr('noProducts', {query: query}));
                    return;
                }
                if (data.products.length === 1) {
                    addToCart(data.products[0], parsed.qty, resolveScanUnit(data.products[0]));
                    return;
                }
                setStatus('warn', tr('productsFound', {count: data.products.length}));
                multiProducts = data.products.map(function(p) { p.__qty = parsed.qty; return p; });
                showMulti(data.products);
            })
            .catch(function() {
                searchInFlight = false;
                setStatus('error', I18N.searchError);
            });
    };

    function showMulti(prods) {
        activeMultiIdx = -1;
        multiRows = [];
        var div = document.getElementById('barcodeMulti');
        div.innerHTML = '';

        var panel = document.createElement('div');
        panel.className = 'bg-white border-2 border-teal-300 rounded-xl shadow-lg overflow-hidden';

        var header = document.createElement('div');
        header.className = 'flex items-center justify-between px-4 py-2.5 bg-teal-600 text-white';
        header.innerHTML =
            '<span class="font-semibold text-sm"><i class="fas fa-boxes mr-2"></i>' + tr('productsFound', {count: prods.length}) + '</span>' +
            '<span class="text-xs text-teal-200 flex items-center gap-1.5">' +
            '<kbd class="px-1.5 py-0.5 bg-teal-700 rounded text-xs">↑↓</kbd> ' + I18N.move +
            '<kbd class="px-1.5 py-0.5 bg-teal-700 rounded text-xs ml-1">' + I18N.enter + '</kbd> ' + I18N.add +
            '<kbd class="px-1.5 py-0.5 bg-teal-700 rounded text-xs ml-1">' + I18N.escape + '</kbd> ' + I18N.close +
            '</span>';
        panel.appendChild(header);

        var tableWrap = document.createElement('div');
        tableWrap.className = 'max-h-64 overflow-y-auto';
        var table = document.createElement('table');
        table.className = 'w-full';

        prods.forEach(function(p, idx) {
            var tr = document.createElement('tr');
            tr.className = 'multi-row border-b border-gray-100 cursor-pointer transition-all duration-100';
            tr.innerHTML =
                '<td class="px-1.5 py-3 w-8 text-center">' +
                    '<span class="row-num inline-flex items-center justify-center w-6 h-6 rounded-full bg-gray-100 text-gray-500 text-xs font-bold">' + (idx + 1) + '</span>' +
                '</td>' +
                '<td class="px-3 py-3 w-28">' +
                    (p.barcode_unit ? '<span class="text-xs text-gray-400 font-mono whitespace-nowrap"><i class="fas fa-barcode mr-1"></i>' + escHtml(p.barcode_unit) + '</span>' : '<span class="text-xs text-gray-300">-</span>') +
                '</td>' +
                '<td class="px-3 py-3 w-28">' +
                    ((p.brand_en || p.brand_ko) ? '<div class="leading-tight">' +
                        '<p class="text-xs font-semibold text-teal-600">' + escHtml(p.brand_en || '') + '</p>' +
                        (p.brand_ko ? '<p class="text-xs text-teal-500">' + escHtml(p.brand_ko) + '</p>' : '') +
                    '</div>' : '<span class="text-xs text-gray-300">-</span>') +
                '</td>' +
                '<td class="px-3 py-3">' +
                    '<div class="leading-tight whitespace-nowrap">' +
                        '<p class="text-sm font-semibold text-gray-900">' + escHtml(p.name_en) + (p.capacity ? ' <span class="text-xs font-normal text-gray-500">' + escHtml(p.capacity) + '</span>' : '') +
                            (p.nearest_expiry_date ? ' <span class="text-xs font-normal ' + expiryTextClass(p.nearest_expiry_date) + '">' + escHtml(p.nearest_expiry_date) + '</span>' : '') +
                        '</p>' +
                        (p.name_ko ? '<p class="text-xs text-gray-500">' + escHtml(p.name_ko) + (p.capacity ? ' ' + escHtml(p.capacity) : '') + '</p>' : '') +
                    '</div>' +
                '</td>' +
                '<td class="px-3 py-3 w-16">' +
                    '<span class="inline-block px-2 py-0.5 bg-gray-100 text-gray-600 text-xs rounded-full font-medium">' + escHtml(p.unit) + '</span>' +
                '</td>' +
                '<td class="px-3 py-3 w-20 text-right">' +
                    '<span class="add-badge inline-flex items-center gap-1 px-3 py-1 bg-teal-600 text-white text-xs font-semibold rounded-lg">' +
                    '<i class="fas fa-plus text-xs"></i>' + I18N.add + '</span>' +
                '</td>';
            tr.addEventListener('click', function() { selectMulti(idx); });
            tr.addEventListener('mouseenter', function() {
                if (mouseSelectEnabled) setActiveMultiRow(idx);
            });
            table.appendChild(tr);
            multiRows.push(tr);
        });

        tableWrap.addEventListener('mousemove', function() { mouseSelectEnabled = true; });
        tableWrap.appendChild(table);
        panel.appendChild(tableWrap);
        div.appendChild(panel);
        div.classList.remove('hidden');

        multiScrollWrap = tableWrap;
        if (prods.length > 0) {
            mouseSelectEnabled = false;
            setActiveMultiRow(0);
        }
    }

    function scrollRowIntoView(tr) {
        if (!multiScrollWrap) return;
        var wr = multiScrollWrap.getBoundingClientRect();
        var rr = tr.getBoundingClientRect();
        if (rr.top < wr.top) {
            multiScrollWrap.scrollTop -= (wr.top - rr.top);
        } else if (rr.bottom > wr.bottom) {
            multiScrollWrap.scrollTop += (rr.bottom - wr.bottom);
        }
    }

    function setActiveMultiRow(idx) {
        multiRows.forEach(function(tr, i) {
            var numBadge = tr.querySelector('.row-num');
            var addBadge = tr.querySelector('.add-badge');
            if (i === idx) {
                tr.style.backgroundColor = '#0f766e';
                tr.querySelectorAll('p, span:not(.add-badge):not(.row-num)').forEach(function(el) { el.style.color = 'rgba(255,255,255,0.9)'; });
                if (numBadge) { numBadge.style.backgroundColor = 'rgba(255,255,255,0.25)'; numBadge.style.color = '#fff'; }
                if (addBadge) { addBadge.style.backgroundColor = '#fff'; addBadge.style.color = '#0f766e'; }
                scrollRowIntoView(tr);
            } else {
                tr.style.backgroundColor = '';
                tr.querySelectorAll('p, span:not(.add-badge):not(.row-num)').forEach(function(el) { el.style.color = ''; });
                if (numBadge) { numBadge.style.backgroundColor = ''; numBadge.style.color = ''; }
                if (addBadge) { addBadge.style.backgroundColor = ''; addBadge.style.color = ''; }
            }
        });
        activeMultiIdx = idx;
    }

    function hideMulti() {
        activeMultiIdx = -1;
        multiRows = [];
        multiScrollWrap = null;
        mouseSelectEnabled = false;
        var div = document.getElementById('barcodeMulti');
        div.classList.add('hidden');
        div.innerHTML = '';
    }

    window.selectMulti = function(idx) {
        var p = multiProducts[idx];
        addToCart(p, p.__qty || 1, resolveScanUnit(p));
        hideMulti();
    };

    // 브랜드(영문 + 한글) 표시 문자열 — 예: "Coca-Cola 코카콜라"
    function brandLabel(product) {
        var parts = [];
        if (product.brand_en) parts.push(product.brand_en);
        if (product.brand_ko) parts.push(product.brand_ko);
        return parts.join(' ');
    }

    // ── 장바구니에 상품 추가 / 수량 누적, 단위별 재고·평균원가 조회 ─────────
    // Design Ref: box-pcs-unit §5.4 — unit 미지정 시 토글의 현재 단위 적용
    function addToCart(product, qty, unit) {
        qty = Math.max(1, parseInt(qty, 10) || 1);
        unit = (unit === 'BOX' || unit === 'PACK' || unit === 'PCS') ? unit : window.currentOutboundUnit;

        // 동일 상품+동일 단위만 수량 누적 (단위 다르면 별도 행)
        var existing = cart.find(function(c) { return c.product_id === product.id && c.unit === unit; });
        var idx;
        if (existing) {
            existing.qty += qty;
            idx = cart.indexOf(existing);
            renderCart();
            setStatus('success', tr('qtyAdded', {name: product.name_en, unit: unit, qty: qty}));
            barcodeInput.value = '';
            focusQtyInput(idx);
            updateFefoPreview(idx);
            return;
        }

        var line = {
            product_id: product.id,
            name_en: product.name_en || '',
            name_ko: product.name_ko || '',
            brand_en: product.brand_en || '',
            brand_ko: product.brand_ko || '',
            barcode: product.barcode_unit || product.barcode_box || product.barcode_logistics || '',
            capacity: product.capacity || '',
            pieces_per_box: product.pieces_per_box || '',
            unit: unit,
            product_unit: product.unit,
            qty: qty,
            stock: null,
            avg_cost: null,
            _fefoHtml: null
        };
        cart.push(line);
        idx = cart.length - 1;
        renderCart();
        setStatus('success', tr('added', {name: product.name_en, unit: unit}));
        barcodeInput.value = '';
        focusQtyInput(idx);
        updateFefoPreview(idx);

        // Design Ref: box-pcs-unit §4.2 — 단위별 재고/유효단가 미리보기
        fetch(LC_BASE + '/ajax/branch_outbound.php?action=get_product_stock&product_id=' + encodeURIComponent(product.id))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && data.product) {
                    var p = data.product;
                    // 같은 상품의 모든 장바구니 행(단위별) 갱신
                    cart.forEach(function(c, ci) {
                        if (c.product_id !== product.id) return;
                        // Design Ref: pack-unit §5 — 단위별(BOX/PACK/PCS) 재고·평균원가 매핑
                        var stockMap = { BOX: p.box_stock, PACK: p.pack_stock, PCS: p.pcs_stock };
                        var costMap  = { BOX: p.box_avg_cost, PACK: p.pack_avg_cost, PCS: p.pcs_avg_cost };
                        c.stock    = parseFloat(stockMap[c.unit]) || 0;
                        c.avg_cost = parseFloat(costMap[c.unit]) || 0;
                    });
                    var refocus = isQtyInputFocused(idx);
                    renderCart();
                    if (refocus) focusQtyInput(idx);
                }
            })
            .catch(function() {});
    }

    // 현재 포커스가 idx번째 행의 수량 입력란인지 확인
    function isQtyInputFocused(idx) {
        var rows = document.querySelectorAll('#itemsBody .item-row');
        var row = rows[idx];
        if (!row) return false;
        var qtyInput = row.querySelector('.row-qty');
        return !!qtyInput && document.activeElement === qtyInput;
    }

    // 지정한 장바구니 행의 수량 입력란에 포커스 + 전체 선택
    function focusQtyInput(idx) {
        var rows = document.querySelectorAll('#itemsBody .item-row');
        var row = rows[idx];
        if (!row) return;
        var qtyInput = row.querySelector('.row-qty');
        if (!qtyInput) return;
        qtyInput.focus();
        qtyInput.select();
    }

    function renderCart() {
        var body = document.getElementById('itemsBody');
        body.innerHTML = '';

        if (cart.length === 0) {
            var empty = document.createElement('tr');
            empty.id = 'emptyRow';
            empty.innerHTML = '<td colspan="11" class="px-3 py-6 text-center text-sm text-gray-400">' + I18N.empty + '</td>';
            body.appendChild(empty);
            return;
        }

        var tpl = document.getElementById('rowTpl');
        cart.forEach(function(line, idx) {
            var clone = tpl.content.cloneNode(true);
            var tr = clone.querySelector('.item-row');
            tr.querySelector('.row-num').textContent = idx + 1;

            tr.querySelector('.row-barcode').textContent = line.barcode || '-';

            var brandEn = tr.querySelector('.brand-en');
            brandEn.textContent = line.brand_en || (line.brand_ko ? '' : '-');
            if (!line.brand_en && !line.brand_ko) {
                brandEn.classList.remove('text-teal-700');
                brandEn.classList.add('text-gray-300');
            }
            tr.querySelector('.brand-ko').textContent = line.brand_ko || '';

            tr.querySelector('.name-en').textContent = line.name_en || '-';
            tr.querySelector('.name-ko').textContent = line.name_ko || '';

            tr.querySelector('.row-capacity').textContent = line.capacity || '-';
            tr.querySelector('.row-ppb').textContent = line.pieces_per_box || '-';

            // Design Ref: box-pcs-unit §5.4 — 단위 배지 (BOX=amber, PCS=blue)
            var unitSpan = tr.querySelector('.row-unit');
            unitSpan.textContent = line.unit;
            unitSpan.classList.remove('bg-gray-100', 'text-gray-600', 'bg-amber-100', 'text-amber-700', 'bg-emerald-100', 'text-emerald-700', 'bg-blue-100', 'text-blue-700');
            // Design Ref: pack-unit §5.2 — BOX=주황, PACK=초록, PCS=파랑
            var badgeBg = line.unit === 'BOX' ? 'bg-amber-100' : (line.unit === 'PACK' ? 'bg-emerald-100' : 'bg-blue-100');
            var badgeFg = line.unit === 'BOX' ? 'text-amber-700' : (line.unit === 'PACK' ? 'text-emerald-700' : 'text-blue-700');
            unitSpan.classList.add(badgeBg, badgeFg);

            var stockSpan = tr.querySelector('.row-stock');
            if (line.stock === null) {
                stockSpan.textContent = '...';
            } else {
                stockSpan.textContent = line.stock + ' ' + line.unit;
                if (line.qty > line.stock) {
                    stockSpan.classList.add('text-red-500', 'font-semibold');
                }
            }

            var qtyInput = tr.querySelector('.row-qty');
            qtyInput.value = line.qty;
            qtyInput.addEventListener('input', function() {
                var v = Math.max(1, parseInt(this.value, 10) || 1);
                line.qty = v;
                // 전체 재렌더 대신 재고 초과 표시만 갱신 (입력 중 포커스 유지)
                if (line.stock !== null) {
                    stockSpan.classList.toggle('text-red-500', line.qty > line.stock);
                    stockSpan.classList.toggle('font-semibold', line.qty > line.stock);
                }
                // Design Ref: §5 — 수량 변경 시 FEFO 피킹 미리보기 갱신(디바운스)
                updateFefoPreview(idx);
            });
            qtyInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    barcodeInput.focus();
                    barcodeInput.select();
                }
            });

            var costSpan = tr.querySelector('.row-cost');
            costSpan.textContent = (line.avg_cost === null) ? '...' : Number(line.avg_cost).toFixed(2);

            // Design Ref: §5 — FEFO 피킹 미리보기(위치/유통기한) 영역, 캐시된 내용 복원
            var fefoContent = clone.querySelector('.fefo-content');
            fefoContent.innerHTML = line._fefoHtml || '<span class="text-gray-300"><i class="fas fa-circle-notch fa-spin mr-1"></i>' + I18N.loadingPicking + '</span>';

            tr.querySelector('.row-remove').addEventListener('click', function() {
                clearTimeout(fefoTimers[idx]);
                delete fefoTimers[idx];
                cart.splice(idx, 1);
                renderCart();
                cart.forEach(function(c, i) { updateFefoPreview(i); });
            });

            body.appendChild(clone);
        });
    }

    // ── FEFO 피킹 미리보기(위치/유통기한) 조회 및 렌더 ─────────────────
    function updateFefoPreview(idx) {
        var line = cart[idx];
        if (!line) return;
        clearTimeout(fefoTimers[idx]);
        fefoTimers[idx] = setTimeout(function() {
            var rows = document.querySelectorAll('#itemsBody .item-row');
            var row = rows[idx];
            var content = row ? row.querySelector('.fefo-content') : null;
            if (content) content.innerHTML = '<span class="text-gray-300"><i class="fas fa-circle-notch fa-spin mr-1"></i>' + I18N.loadingPicking + '</span>';

            fetch(LC_BASE + '/ajax/branch_outbound.php?action=get_fefo_preview&product_id=' + encodeURIComponent(line.product_id) + '&qty=' + encodeURIComponent(line.qty) + '&unit=' + encodeURIComponent(line.unit))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                if (!data.success) { line._fefoHtml = '<span class="text-gray-400">' + I18N.pickingUnavailable + '</span>'; }
                    else { line._fefoHtml = buildFefoHtml(data); }
                    var rows2 = document.querySelectorAll('#itemsBody .item-row');
                    var row2 = rows2[idx];
                    var content2 = row2 ? row2.querySelector('.fefo-content') : null;
                    if (content2) content2.innerHTML = line._fefoHtml;
                })
                .catch(function() {
                line._fefoHtml = '<span class="text-gray-400">' + I18N.pickingUnavailable + '</span>';
                    var rows2 = document.querySelectorAll('#itemsBody .item-row');
                    var row2 = rows2[idx];
                    var content2 = row2 ? row2.querySelector('.fefo-content') : null;
                    if (content2) content2.innerHTML = line._fefoHtml;
                });
        }, 300);
    }

    // 유통기한 D-day에 따른 색상 클래스 (lc_expiry_class와 동일한 기준)
    function expiryBadgeClass(expiryDate) {
        if (!expiryDate) return 'bg-gray-50 text-gray-500';
        var days = Math.floor((new Date(expiryDate + 'T00:00:00') - new Date()) / 86400000);
        if (days < 0) return 'bg-red-50 text-red-700';
        if (days <= 30) return 'bg-orange-50 text-orange-700';
        if (days <= 90) return 'bg-yellow-50 text-yellow-700';
        return 'bg-gray-50 text-gray-600';
    }

    // 유통기한 D-day에 따른 텍스트 색상만(배경 없음) — 검색결과 리스트용
    function expiryTextClass(expiryDate) {
        if (!expiryDate) return 'text-gray-400';
        var days = Math.floor((new Date(expiryDate + 'T00:00:00') - new Date()) / 86400000);
        if (days < 0) return 'text-red-600';
        if (days <= 30) return 'text-orange-600';
        if (days <= 90) return 'text-yellow-700';
        return 'text-gray-500';
    }

    // Plan FR-06: PCS 부족 + BOX 보유 시 박스 개봉 안내 (자동 개봉 없음)
    function breakSuggestHtml(shortfall) {
        return '<a href="' + LC_BASE + '/box_break.php" target="_blank" ' +
            'class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-amber-50 text-amber-700 font-semibold border border-amber-200 hover:bg-amber-100">' +
            '<i class="fas fa-box-open"></i>' + tr('pcsShortage', {count: shortfall}) + '</a>';
    }

    // FEFO 미리보기 응답을 HTML로 변환 (위치/유통기한/lot/수량, 유통기한 빠른 순)
    function buildFefoHtml(data) {
        if (!data.picks || data.picks.length === 0) {
            if (data.shortfall > 0) {
                if (data.suggest_break) return breakSuggestHtml(data.shortfall);
                return '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-red-50 text-red-700 font-semibold">' +
                '<i class="fas fa-exclamation-triangle"></i>' + tr('negativeStockAll', {count: data.shortfall}) + '</span>';
            }
            return '<span class="text-gray-400">-</span>';
        }
        var html = '<div class="flex flex-wrap items-center gap-1.5">';
        html += '<span class="text-gray-400"><i class="fas fa-route mr-1"></i>' + I18N.pickingOrder + '</span>';
        data.picks.forEach(function(p) {
            var cls = expiryBadgeClass(p.expiry_date);
        var loc = p.storage_location ? escHtml(p.storage_location) : I18N.locationNone;
        var exp = p.expiry_date ? escHtml(p.expiry_date) : I18N.expiryNone;
            var lot = p.lot_number ? ' (' + escHtml(p.lot_number) + ')' : '';
            html += '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded ' + cls + '">' +
                '<i class="fas fa-map-marker-alt"></i>' + loc + lot +
                '<span class="mx-0.5">·</span><i class="fas fa-calendar-day"></i>' + exp +
                '<span class="mx-0.5">·</span>' + p.quantity + '</span>';
        });
        if (data.shortfall > 0) {
            html += '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-red-50 text-red-700 font-semibold">' +
            '<i class="fas fa-exclamation-triangle"></i>' + tr('negativeStock', {count: data.shortfall}) + '</span>';
        }
        html += '</div>';
        return html;
    }

    // ── Draft 워크플로우: 저장 → (수정) → 최종 출고 ──────────────────
    // Design Ref: §5.2 — 저장 시 재고 차감 없음(Plan SC-1), 최종 출고 시 FEFO 차감(Plan SC-5)

    function showFormError(msg) {
        document.getElementById('formErrorMsg').textContent = msg;
        document.getElementById('formError').classList.remove('hidden');
    }

    function validateForm() {
        document.getElementById('formError').classList.add('hidden');
        var storeId = document.getElementById('storeSelect').value;
        if (!storeId) { showFormError(I18N.storeRequired); return null; }
        if (cart.length === 0) { showFormError(I18N.itemsRequired); return null; }
        return {
            store_id: storeId,
            notes: document.getElementById('notesInput').value.trim(),
            items: cart.map(function(c) { return { product_id: c.product_id, quantity: c.qty, unit: c.unit }; })
        };
    }

    // 저장(save_draft) 또는 수정(update_draft) — 성공 시 draft_id를 콜백에 전달
    function saveDraft(form, onSuccess, onFail) {
        var fd = new FormData();
        fd.append('action', DRAFT_ID ? 'update_draft' : 'save_draft');
        fd.append('csrf_token', CSRF_TOKEN);
        if (DRAFT_ID) fd.append('draft_id', DRAFT_ID);
        fd.append('store_id', form.store_id);
        fd.append('notes', form.notes);
        fd.append('items', JSON.stringify(form.items));

        fetch(LC_BASE + '/ajax/branch_outbound.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) { showFormError(data.message || I18N.saveFailed); onFail(); return; }
                if (!DRAFT_ID && data.draft_id) DRAFT_ID = data.draft_id;
                onSuccess(DRAFT_ID);
            })
            .catch(function() { showFormError(I18N.networkError); onFail(); });
    }

    // [저장] — 출고대기 목록으로 이동
    document.getElementById('saveBtn').addEventListener('click', function() {
        var form = validateForm();
        if (!form) return;
        var btn = this;
        btn.disabled = true;
        saveDraft(form, function() {
            window.location.href = LC_BASE + '/branch_outbound_list.php';
        }, function() { btn.disabled = false; });
    });

    // [출력] — 현재 내용을 저장한 뒤 인쇄 페이지를 새 탭으로 (피킹 오더 포함)
    document.getElementById('printBtn').addEventListener('click', function() {
        var form = validateForm();
        if (!form) return;
        var btn = this;
        btn.disabled = true;
        // 팝업 차단 회피: 클릭 시점에 빈 탭을 먼저 연 뒤 저장 성공 시 URL 지정
        var printWin = window.open('', '_blank');
        saveDraft(form, function(draftId) {
            btn.disabled = false;
            var url = LC_BASE + '/print_branch_outbound.php?draft_id=' + draftId;
            if (printWin) printWin.location = url;
            else window.open(url, '_blank');
        }, function() {
            btn.disabled = false;
            if (printWin) printWin.close();
        });
    });

    // [최종 출고] — 저장 후 ship_draft 연속 호출 (Plan SC-6)
    document.getElementById('submitBtn').addEventListener('click', function() {
        var form = validateForm();
        if (!form) return;

        if (!confirm(tr('confirmMessage', {count: cart.length}))) return;

        var btn = this;
        var saveBtn = document.getElementById('saveBtn');
        btn.disabled = true;
        saveBtn.disabled = true;

        saveDraft(form, function(draftId) {
            var fd = new FormData();
            fd.append('action', 'ship_draft');
            fd.append('csrf_token', CSRF_TOKEN);
            fd.append('draft_id', draftId);

            fetch(LC_BASE + '/ajax/branch_outbound.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) {
                        showFormError(data.message || I18N.confirmFailed);
                        btn.disabled = false; saveBtn.disabled = false;
                        return;
                    }
                    window.location.href = LC_BASE + '/order_detail.php?id=' + data.order_id;
                })
                .catch(function() {
                    showFormError(I18N.networkError);
                    btn.disabled = false; saveBtn.disabled = false;
                });
        }, function() { btn.disabled = false; saveBtn.disabled = false; });
    });

    // ── 수정 모드: draft 복원 (Design §5.2, get_draft) ────────────────
    if (DRAFT_ID) {
        fetch(LC_BASE + '/ajax/branch_outbound.php?action=get_draft&draft_id=' + DRAFT_ID)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) {
                showFormError(data.message || I18N.loadFailed);
                    return;
                }
                document.getElementById('storeSelect').value = data.draft.store_id;
                document.getElementById('notesInput').value = data.draft.notes || '';
                data.draft.items.forEach(function(it) {
                    addToCart({
                        id: it.product_id,
                        name_en: it.name_en || ('#' + it.product_id),
                        name_ko: it.name_ko,
                        brand_en: it.brand_en,
                        brand_ko: it.brand_ko,
                        barcode_unit: it.barcode_unit,
                        barcode_box: it.barcode_box,
                        barcode_logistics: it.barcode_logistics,
                        capacity: it.capacity,
                        pieces_per_box: it.pieces_per_box,
                        unit: it.unit || ''
                    }, it.quantity, it.order_unit);
                });
                setStatus('success', tr('loaded', {id: DRAFT_ID, count: data.draft.items.length}));
                barcodeInput.focus();
            })
            .catch(function() { showFormError(I18N.loadFailed); });
    }

    // ── 상태 메시지 ────────────────────────────────────────────────
    function setStatus(type, msg) {
        var el = document.getElementById('barcodeStatus');
        var styles = {
            loading: 'flex items-center gap-2 px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm text-gray-600',
            success: 'flex items-center gap-2 px-3 py-2 bg-green-50 border border-green-200 rounded-lg text-sm text-green-700 font-medium',
            error:   'flex items-center gap-2 px-3 py-2 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700',
            warn:    'flex items-center gap-2 px-3 py-2 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800',
        };
        var icons = {
            loading: '<i class="fas fa-circle-notch fa-spin text-gray-400"></i>',
            success: '<i class="fas fa-check-circle text-green-500"></i>',
            error:   '<i class="fas fa-exclamation-circle text-red-500"></i>',
            warn:    '<i class="fas fa-list-ul text-amber-500"></i>',
        };
        el.className = styles[type] || styles.warn;
        el.innerHTML = (icons[type] || '') + '<span>' + escHtml(msg) + '</span>';
        el.classList.remove('hidden');
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    barcodeInput.focus();
})();
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
