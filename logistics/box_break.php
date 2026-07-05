<?php
// Design Ref: box-pcs-unit.design.md §5.4 — 박스 개봉 페이지 (BOX→PCS 전환 + 파손 등록)
// Plan FR-07/FR-08: BOX lot 선택 → 박스 수·파손 수 입력 → PCS lot 생성 + 파손 이력
$page_title = 'Box Break - Logistics Center';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/unit_helper.php';
require_once __DIR__ . '/lib/inventory_helper.php';

lc_require_staff();

$prefill_product_id = (int)($_GET['product_id'] ?? 0);

// 최근 개봉/파손 이력 (서버 렌더) + 프리필 상품 정보
$history = [];
$prefill_product = null;
try {
    $conn = get_lc_db();
    if ($prefill_product_id) {
        $st = $conn->prepare("SELECT id, name_en, name_ko FROM lc_products WHERE id = ?");
        $st->bind_param('i', $prefill_product_id);
        $st->execute();
        $prefill_product = $st->get_result()->fetch_assoc();
        $st->close();
    }
    $history = $conn->query(
        "SELECT bb.*, CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS product_name,
                u.full_name AS created_by_name
         FROM lc_box_breaks bb
         JOIN lc_products p ON bb.product_id = p.id
         LEFT JOIN users u ON bb.created_by = u.id
         ORDER BY bb.id DESC
         LIMIT 20"
    )->fetch_all(MYSQLI_ASSOC);
    $conn->close();
} catch (Exception $e) { $history = []; }
?>

<!-- 헤더 -->
<div class="flex items-center justify-between mb-5">
    <div class="flex items-center gap-3">
        <a href="<?php echo LC_BASE; ?>/inventory.php" class="text-gray-400 hover:text-gray-600"><i class="fas fa-arrow-left"></i></a>
        <h2 class="text-lg font-bold text-gray-900"><i class="fas fa-box-open text-amber-500 mr-2"></i>Box Break</h2>
        <span class="text-xs text-gray-400">Convert BOX/PACK stock into individual (PCS) stock. Damaged quantities will be excluded from the registration.</span>
    </div>
</div>

<!-- 상품 검색 -->
<div class="mb-4">
    <div class="flex gap-2">
        <input type="text" id="barcodeInput"
               placeholder="Scan barcode or enter product name (Korean/English)..."
               autocomplete="off"
               class="flex-1 border-2 border-gray-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-100 transition-colors">
        <button type="button" onclick="searchBarcode()"
                class="px-5 py-2.5 bg-amber-500 text-white text-sm font-semibold rounded-lg hover:bg-amber-600 transition-colors whitespace-nowrap shadow-sm">
            <i class="fas fa-search mr-1.5"></i>Search
        </button>
    </div>
    <div id="barcodeStatus" class="mt-2 hidden"></div>
    <div id="barcodeMulti" class="mt-2 hidden"></div>
</div>

<!-- 선택된 상품 + 재고 요약 -->
<div id="productPanel" class="hidden bg-white rounded-lg border border-gray-200 p-4 mb-4">
    <div class="flex items-center justify-between flex-wrap gap-2">
        <div>
            <p id="selProductName" class="font-semibold text-gray-900"></p>
            <p class="text-xs text-gray-400 mt-0.5">Current stock: <span id="selProductStock" class="font-semibold text-teal-700"></span></p>
        </div>
        <button type="button" onclick="clearProduct()" class="text-xs text-gray-400 hover:text-red-400"><i class="fas fa-times mr-1"></i>Change Product</button>
    </div>
</div>

<!-- BOX lot 목록 -->
<div id="lotsCard" class="hidden bg-white rounded-lg border border-gray-200 overflow-hidden mb-4">
    <div class="px-4 py-2.5 border-b border-gray-100">
        <span class="text-sm font-medium text-gray-700">Select BOX/PACK Stock to Open <span class="text-xs text-gray-400 font-normal">(earliest expiry first)</span></span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100">
                <tr>
                    <th class="px-3 py-2 w-10"></th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium">Unit</th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium">Lot Number</th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium">Expiry Date</th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium">Location</th>
                    <th class="px-3 py-2 text-right text-xs text-gray-500 font-medium">Remaining</th>
                    <th class="px-3 py-2 text-right text-xs text-gray-500 font-medium">Pieces per Unit</th>
                    <th class="px-3 py-2 text-right text-xs text-gray-500 font-medium">BOX Cost</th>
                    <th class="px-3 py-2 text-right text-xs text-teal-600 font-medium">PCS Cost</th>
                </tr>
            </thead>
            <tbody id="lotsBody"></tbody>
        </table>
    </div>
</div>

<!-- 개봉 입력 폼 -->
<div id="breakForm" class="hidden bg-white rounded-lg border-2 border-amber-300 p-5 mb-4">
    <h3 class="text-sm font-bold text-gray-800 mb-4"><i class="fas fa-box-open text-amber-500 mr-1.5"></i>Box Break Details</h3>
    <div id="breakError" class="hidden bg-red-50 border border-red-200 rounded-lg px-4 py-2.5 mb-3 text-sm text-red-700"></div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Number of <span id="breakUnitLabel">Boxes</span> to Open <span class="text-red-500">*</span></label>
            <input type="number" id="boxesInput" min="1" value="1"
                   oninput="updateBreakPreview()"
                   class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-amber-400">
            <p class="text-xs text-gray-400 mt-0.5">Max <span id="maxBoxes">-</span></p>
        </div>
        <div>
            <label class="block text-xs font-medium text-orange-500 mb-1">Damaged Quantity (PCS)</label>
            <input type="number" id="damagedInput" min="0" value="0"
                   oninput="updateBreakPreview()"
                   class="w-full border border-orange-200 rounded-md px-3 py-2 text-sm font-semibold text-center focus:outline-none focus:ring-2 focus:ring-orange-300">
            <p class="text-xs text-gray-400 mt-0.5">Within the number of pieces opened</p>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Notes</label>
            <input type="text" id="breakNotes" placeholder="e.g. Damaged during transport"
                   class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400">
        </div>
    </div>

    <!-- Preview (Design §5.4 — real-time calculation) -->
    <div id="breakPreview" class="mt-4 px-4 py-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-900"></div>

    <div class="flex gap-2 mt-4">
        <button type="button" id="breakSubmitBtn" onclick="submitBreak()"
                class="px-6 py-2 bg-amber-500 text-white text-sm font-bold rounded-lg hover:bg-amber-600 transition-colors disabled:opacity-50">
            <i class="fas fa-box-open mr-1.5"></i>Open Box
        </button>
        <button type="button" onclick="hideBreakForm()"
                class="px-5 py-2 bg-gray-100 text-gray-600 text-sm font-medium rounded-lg hover:bg-gray-200">Cancel</button>
    </div>
</div>

<!-- 개봉/파손 이력 -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <div class="px-4 py-2.5 border-b border-gray-100">
        <span class="text-sm font-medium text-gray-700">Recent Box Break / Damage History</span>
        <span class="ml-2 text-xs text-gray-400 font-normal">Last 20 records</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100">
                <tr>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium">Date/Time</th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium">Product</th>
                    <th class="px-3 py-2 text-right text-xs text-gray-500 font-medium">Boxes Opened</th>
                    <th class="px-3 py-2 text-right text-xs text-teal-600 font-medium">PCS Created</th>
                    <th class="px-3 py-2 text-right text-xs text-red-500 font-medium">Damaged</th>
                    <th class="px-3 py-2 text-right text-xs text-red-500 font-medium">Damage Loss</th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium">Staff</th>
                    <th class="px-3 py-2 text-left text-xs text-gray-500 font-medium">Notes</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php if (empty($history)): ?>
                <tr><td colspan="8" class="px-4 py-6 text-center text-sm text-gray-400">No box break history.</td></tr>
                <?php else: foreach ($history as $h): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-3 py-2 text-xs text-gray-500 font-mono"><?php echo date('Y-m-d H:i', strtotime($h['created_at'])); ?></td>
                    <td class="px-3 py-2 text-gray-800 font-medium"><?php echo htmlspecialchars($h['product_name']); ?></td>
                    <td class="px-3 py-2 text-right font-semibold"><?php echo number_format($h['boxes_opened']); ?> <span class="text-xs text-gray-400 font-normal">(×<?php echo (int)$h['pieces_per_box']; ?>)</span></td>
                    <td class="px-3 py-2 text-right font-semibold text-teal-700">+<?php echo number_format($h['pcs_created']); ?> PCS</td>
                    <td class="px-3 py-2 text-right <?php echo $h['damaged_qty'] > 0 ? 'font-semibold text-red-600' : 'text-gray-300'; ?>"><?php echo $h['damaged_qty'] > 0 ? number_format($h['damaged_qty']) : '-'; ?></td>
                    <td class="px-3 py-2 text-right <?php echo (float)$h['damage_cost'] > 0 ? 'font-semibold text-red-600' : 'text-gray-300'; ?>"><?php echo (float)$h['damage_cost'] > 0 ? number_format((float)$h['damage_cost'], 2) : '-'; ?></td>
                    <td class="px-3 py-2 text-xs text-gray-500"><?php echo htmlspecialchars($h['created_by_name'] ?? '-'); ?></td>
                    <td class="px-3 py-2 text-xs text-gray-400 italic"><?php echo htmlspecialchars($h['notes'] ?? ''); ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function() {
    var LC_BASE = '<?php echo LC_BASE; ?>';
    var CSRF_TOKEN = '<?php echo htmlspecialchars(lc_csrf_token()); ?>';
    var PREFILL_PRODUCT_ID = <?php echo $prefill_product_id; ?>;

    var currentProduct = null;  // { id, name_en, name_ko }
    var currentLots = [];
    var selectedLot = null;     // get_box_lots 응답의 lot 객체
    var barcodeTimer = null;
    var searchInFlight = false;

    var barcodeInput = document.getElementById('barcodeInput');

    // ── 상품 검색 (inbound_add 패턴 간소화 버전) ──────────────────
    barcodeInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); clearTimeout(barcodeTimer); searchBarcode(); }
    });
    barcodeInput.addEventListener('input', function() {
        clearTimeout(barcodeTimer);
        var val = this.value.trim();
        if (/^\d{8,}$/.test(val)) barcodeTimer = setTimeout(searchBarcode, 200);
        else if (val.length >= 2)  barcodeTimer = setTimeout(searchBarcode, 400);
    });

    window.searchBarcode = function() {
        var q = barcodeInput.value.trim();
        if (!q) { barcodeInput.focus(); return; }
        if (searchInFlight) return;
        searchInFlight = true;
        setStatus('loading', 'Searching...');

        fetch(LC_BASE + '/ajax/search_product_by_barcode.php?barcode=' + encodeURIComponent(q))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                searchInFlight = false;
                if (!data.success) { setStatus('error', "No products matching '" + q + "'."); return; }
                if (data.products.length === 1) { selectProduct(data.products[0]); return; }
                showMulti(data.products);
            })
            .catch(function() { searchInFlight = false; setStatus('error', 'An error occurred while searching.'); });
    };

    function showMulti(prods) {
        var div = document.getElementById('barcodeMulti');
        div.innerHTML = '';
        var panel = document.createElement('div');
        panel.className = 'bg-white border-2 border-amber-300 rounded-xl shadow-lg overflow-hidden max-h-64 overflow-y-auto';
        prods.forEach(function(p) {
            var row = document.createElement('div');
            row.className = 'px-4 py-2.5 border-b border-gray-100 cursor-pointer hover:bg-amber-50 flex items-center justify-between';
            row.innerHTML =
                '<div><p class="font-semibold text-gray-900 text-sm">' + escHtml(p.name_en) + '</p>' +
                (p.name_ko ? '<p class="text-xs text-gray-500">' + escHtml(p.name_ko) + '</p>' : '') + '</div>' +
                '<span class="text-xs px-2 py-0.5 bg-gray-100 text-gray-600 rounded-full">' + escHtml(p.unit || '') + '</span>';
            row.addEventListener('click', function() { selectProduct(p); });
            panel.appendChild(row);
        });
        div.appendChild(panel);
        div.classList.remove('hidden');
        setStatus('warn', prods.length + ' products found. Please select.');
    }

    function hideMulti() {
        var div = document.getElementById('barcodeMulti');
        div.classList.add('hidden');
        div.innerHTML = '';
    }

    function selectProduct(p) {
        currentProduct = p;
        hideMulti();
        barcodeInput.value = '';
        document.getElementById('selProductName').textContent =
            p.name_en + (p.name_ko ? ' (' + p.name_ko + ')' : '');
        document.getElementById('productPanel').classList.remove('hidden');
        setStatus('success', '✓ ' + p.name_en + ' selected');
        loadBoxLots(p.id);
    }

    window.clearProduct = function() {
        currentProduct = null;
        currentLots = [];
        selectedLot = null;
        document.getElementById('productPanel').classList.add('hidden');
        document.getElementById('lotsCard').classList.add('hidden');
        hideBreakForm();
        barcodeInput.focus();
    };

    // ── BOX lot 목록 로드 ──────────────────────────────────────────
    function loadBoxLots(productId) {
        fetch(LC_BASE + '/ajax/box_break.php?action=get_box_lots&product_id=' + encodeURIComponent(productId))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) { setStatus('error', data.message || 'Failed to load BOX stock'); return; }
                currentLots = data.lots;
                var stock = data.stock || {};
                // Design Ref: pack-unit §5 — BOX/PACK/PCS 단위별 재고 표기
                var parts = ['BOX','PACK','PCS'].filter(function(u){ return stock[u]; })
                    .map(function(u){ return stock[u] + ' ' + u; });
                document.getElementById('selProductStock').textContent = parts.length ? parts.join(' + ') : '0';
                renderLots();
            })
            .catch(function() { setStatus('error', 'Error loading BOX stock'); });
    }

    function renderLots() {
        var body = document.getElementById('lotsBody');
        body.innerHTML = '';
        document.getElementById('lotsCard').classList.remove('hidden');
        hideBreakForm();

        if (currentLots.length === 0) {
            body.innerHTML = '<tr><td colspan="9" class="px-4 py-6 text-center text-sm text-gray-400">No BOX/PACK stock available to open.</td></tr>';
            return;
        }

        currentLots.forEach(function(lot, idx) {
            var tr = document.createElement('tr');
            tr.className = 'border-b border-gray-50 cursor-pointer hover:bg-amber-50 transition-colors';
            // Design Ref: pack-unit §5.2 — 단위 뱃지 (BOX=주황, PACK=초록)
            var unitBadge = lot.unit === 'PACK'
                ? '<span class="text-xs px-2 py-0.5 bg-emerald-100 text-emerald-700 rounded-full font-semibold">PACK</span>'
                : '<span class="text-xs px-2 py-0.5 bg-amber-100 text-amber-700 rounded-full font-semibold">BOX</span>';
            tr.innerHTML =
                '<td class="px-3 py-2.5 text-center"><input type="radio" name="lotRadio" class="w-4 h-4 text-amber-500"></td>' +
                '<td class="px-3 py-2.5">' + unitBadge + '</td>' +
                '<td class="px-3 py-2.5 font-mono text-xs text-gray-600">' + escHtml(lot.lot_number || '-') + '</td>' +
                '<td class="px-3 py-2.5 text-xs">' + escHtml(lot.expiry_date || '-') + '</td>' +
                '<td class="px-3 py-2.5 text-xs font-mono">' + escHtml(lot.storage_location || '-') + '</td>' +
                '<td class="px-3 py-2.5 text-right font-bold text-gray-800">' + lot.quantity_remain + '</td>' +
                '<td class="px-3 py-2.5 text-right text-gray-600">' + lot.pieces_per_box + '</td>' +
                '<td class="px-3 py-2.5 text-right font-mono text-xs">' + Number(lot.cost_price_box).toFixed(2) + '</td>' +
                '<td class="px-3 py-2.5 text-right font-mono text-xs font-semibold text-teal-700">' + Number(lot.cost_price_pcs).toFixed(2) + '</td>';
            tr.addEventListener('click', function() {
                tr.querySelector('input[name="lotRadio"]').checked = true;
                selectLot(idx);
            });
            body.appendChild(tr);
        });
    }

    // ── 개봉 폼 ────────────────────────────────────────────────────
    function selectLot(idx) {
        selectedLot = currentLots[idx];
        document.getElementById('breakForm').classList.remove('hidden');
        document.getElementById('breakError').classList.add('hidden');
        var boxesInput = document.getElementById('boxesInput');
        boxesInput.max = selectedLot.quantity_remain;
        boxesInput.value = 1;
        document.getElementById('damagedInput').value = 0;
        // Design Ref: pack-unit §5 — 선택 lot 단위(BOX/PACK)로 라벨/최대치 표기
        var unit = selectedLot.unit || 'BOX';
        document.getElementById('maxBoxes').textContent = selectedLot.quantity_remain + ' ' + unit;
        document.getElementById('breakUnitLabel').textContent = (unit === 'PACK') ? 'Packs' : 'Boxes';
        if (parseInt(selectedLot.pieces_per_box, 10) <= 1) {
            showBreakError('⚠ This product has no pieces-per-box setting. Opening will create only 1 PCS.'); // FR-11
        }
        updateBreakPreview();
        boxesInput.focus();
        boxesInput.select();
    }

    window.hideBreakForm = function() {
        selectedLot = null;
        document.getElementById('breakForm').classList.add('hidden');
        document.querySelectorAll('input[name="lotRadio"]').forEach(function(r) { r.checked = false; });
    };

    function showBreakError(msg) {
        var el = document.getElementById('breakError');
        el.textContent = msg;
        el.classList.remove('hidden');
    }

    // Plan SC-4 preview: "Open 2 BOX → Create 39 PCS (damaged 1, loss 5.00)"
    window.updateBreakPreview = function() {
        if (!selectedLot) return;
        var boxes   = Math.max(1, parseInt(document.getElementById('boxesInput').value, 10) || 1);
        var damaged = Math.max(0, parseInt(document.getElementById('damagedInput').value, 10) || 0);
        var ppb     = Math.max(1, parseInt(selectedLot.pieces_per_box, 10) || 1);
        var maxPcs  = boxes * ppb;
        var created = maxPcs - damaged;
        var loss    = damaged * Number(selectedLot.cost_price_pcs);

        var el = document.getElementById('breakPreview');
        if (boxes > parseInt(selectedLot.quantity_remain, 10)) {
            el.innerHTML = '<i class="fas fa-exclamation-triangle mr-1.5"></i>Cannot open more than the remaining boxes (' + selectedLot.quantity_remain + ').';
            return;
        }
        if (damaged > maxPcs) {
            el.innerHTML = '<i class="fas fa-exclamation-triangle mr-1.5"></i>Damaged quantity exceeds the opened pieces count (' + maxPcs + ').';
            return;
        }
        el.innerHTML =
            '<i class="fas fa-arrow-right mr-1.5"></i>Open <strong>' + boxes + ' ' + (selectedLot.unit || 'BOX') + '</strong> → ' +
            '<strong class="text-teal-700">Create ' + created + ' PCS</strong>' +
            (damaged > 0 ? ' <span class="text-red-600">(damaged ' + damaged + ', loss ' + loss.toFixed(2) + ')</span>' : '') +
            (created === 0 ? ' <span class="text-red-600 font-bold">— All damaged: no PCS stock will be created</span>' : '');
    };

    // ── 개봉 실행 ──────────────────────────────────────────────────
    window.submitBreak = function() {
        if (!selectedLot) return;
        var boxes   = parseInt(document.getElementById('boxesInput').value, 10) || 0;
        var damaged = parseInt(document.getElementById('damagedInput').value, 10) || 0;
        var ppb     = Math.max(1, parseInt(selectedLot.pieces_per_box, 10) || 1);

        document.getElementById('breakError').classList.add('hidden');
        if (boxes <= 0) { showBreakError('Please enter the number of boxes to open.'); return; }
        if (boxes > parseInt(selectedLot.quantity_remain, 10)) { showBreakError('Cannot open more than the remaining boxes.'); return; }
        if (damaged < 0 || damaged > boxes * ppb) { showBreakError('Invalid damaged quantity.'); return; }

        var created = boxes * ppb - damaged;
        if (!confirm('Open ' + boxes + ' ' + (selectedLot.unit || 'BOX') + '.\nCreate ' + created + ' PCS' + (damaged > 0 ? ' / Damaged ' + damaged : '') + '\nThis cannot be undone. Continue?')) return;

        var btn = document.getElementById('breakSubmitBtn');
        btn.disabled = true;

        var fd = new FormData();
        fd.append('action', 'submit_break');
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('inventory_id', selectedLot.inventory_id);
        fd.append('boxes_opened', boxes);
        fd.append('damaged_qty', damaged);
        fd.append('notes', document.getElementById('breakNotes').value.trim());

        fetch(LC_BASE + '/ajax/box_break.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) { showBreakError(data.message || 'Failed to process the box break.'); btn.disabled = false; return; }
                // Success — reload the page to refresh lot list/history (keep selected product)
                window.location.href = LC_BASE + '/box_break.php' + (currentProduct ? '?product_id=' + currentProduct.id : '');
            })
            .catch(function() { showBreakError('A network error occurred.'); btn.disabled = false; });
    };

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
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── ?product_id=N 프리필 (inventory.php 개봉 링크) ─────────────
    var PREFILL_PRODUCT = <?php echo $prefill_product ? json_encode($prefill_product, JSON_UNESCAPED_UNICODE) : 'null'; ?>;
    if (PREFILL_PRODUCT) {
        selectProduct(PREFILL_PRODUCT);
    }

    barcodeInput.focus();
})();
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
