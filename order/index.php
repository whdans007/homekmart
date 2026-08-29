<?php
// Design Ref: §7.1 — 메인 검색 + 장바구니 UI
$page_title = '발주 검색';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/lib/order_helper.php';

$conn = get_ord_db();
$stmt = $conn->prepare("SELECT id, name FROM order_vendors WHERE is_active = 1 ORDER BY name");
$stmt->execute();
$vendors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();
?>

<style>
#resultBody tr.row-focused { background-color: #fef08a !important; outline: 2px solid #eab308; outline-offset: -2px; }
</style>

<div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-4 space-y-3">
            <div class="flex gap-2">
                <input type="text" id="searchInput" placeholder="상품명으로 검색..."
                       class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-indigo-500 focus:border-indigo-500">
                <button id="searchBtn" class="px-5 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                    <i class="fas fa-search mr-1"></i>검색
                </button>
            </div>
            <div class="flex flex-wrap gap-1.5" id="vendorTabs">
                <button onclick="selectVendor(0, this)"
                        class="vendor-tab active px-3 py-1 rounded-full text-xs font-medium border transition-colors bg-indigo-600 text-white border-indigo-600">
                    전체
                </button>
                <?php foreach ($vendors as $v): ?>
                <button onclick="selectVendor(<?php echo $v['id']; ?>, this)"
                        class="vendor-tab px-3 py-1 rounded-full text-xs font-medium border transition-colors bg-white text-gray-600 border-gray-300 hover:border-indigo-400 hover:text-indigo-600">
                    <?php echo htmlspecialchars($v['name']); ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 검색 결과 -->
        <div style="display:flex; flex-direction:row; gap:1rem; align-items:stretch;">
            <div id="searchResults" class="bg-white rounded-xl shadow-sm border border-gray-200 min-h-48 overflow-hidden" style="flex:1.6; min-width:0;">
                <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
                    <span class="text-sm font-medium text-gray-600">검색 결과 <span id="resultCount" class="text-indigo-600">—</span></span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm" style="table-layout:fixed; width:100%;">
                        <colgroup>
                            <col style="width:130px">
                            <col style="width:90px">
                            <col>
                            <col style="width:60px">
                            <col style="width:42px">
                            <col style="width:85px">
                            <col style="width:85px">
                            <col style="width:95px">
                            <col style="width:85px">
                            <col style="width:90px">
                        </colgroup>
                        <thead class="bg-gray-50 border-b border-gray-100">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 whitespace-nowrap">업체명</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 whitespace-nowrap">브랜드</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 whitespace-nowrap">상품명</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 whitespace-nowrap">발주단위</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold text-gray-500 whitespace-nowrap">입수량</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold text-gray-500 whitespace-nowrap">낱개 단가</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold text-gray-500 whitespace-nowrap">박스 단가</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 whitespace-nowrap">리마크</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 whitespace-nowrap">EXPIRY</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody id="resultBody" class="divide-y divide-gray-50">
                            <tr><td colspan="10" class="px-4 py-8 text-center text-gray-400"><i class="fas fa-spinner fa-spin mr-1"></i>불러오는 중...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div id="pagination" class="px-4 py-3 border-t border-gray-100 flex justify-center gap-2"></div>
            </div>

            <div style="flex:1.1; min-width:0; max-width:540px; height:1000px; display:flex; flex-direction:column; gap:1rem;">
                <!-- 입고 히스토리 (전 점포) -->
                <div id="historyPanel" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden flex flex-col flex-1 min-h-0">
                    <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between shrink-0">
                        <span class="text-sm font-medium text-gray-600">
                            <i class="fas fa-clock-rotate-left mr-1 text-gray-400"></i>입고 히스토리 (전 점포)
                            <span id="historyCount" class="text-indigo-600 ml-1"></span>
                        </span>
                    </div>
                    <div class="overflow-auto flex-1 min-h-0">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 border-b border-gray-100 sticky top-0 z-10">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 whitespace-nowrap">날짜</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 whitespace-nowrap">업체명 / 품명</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold text-gray-500 whitespace-nowrap">입고가</th>
                                </tr>
                            </thead>
                            <tbody id="historyBody" class="divide-y divide-gray-50">
                                <tr><td colspan="3" class="px-4 py-6 text-center text-gray-400 text-sm">검색어를 입력하면 전 점포의 입고 이력이 표시됩니다.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 물류센터 재고 -->
                <div id="logisticsPanel" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden flex flex-col flex-1 min-h-0">
                    <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between shrink-0">
                        <span class="text-sm font-medium text-gray-600">
                            <i class="fas fa-warehouse mr-1 text-gray-400"></i>물류센터
                            <span id="logisticsCount" class="text-indigo-600 ml-1"></span>
                        </span>
                    </div>
                    <div class="overflow-auto flex-1 min-h-0">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 border-b border-gray-100 sticky top-0 z-10">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 whitespace-nowrap">브랜드 / 품명</th>
                                    <th class="px-3 py-2 text-right text-xs font-semibold text-gray-500 whitespace-nowrap">재고</th>
                                    <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 whitespace-nowrap">유통기한</th>
                                    <th class="px-3 py-2"></th>
                                </tr>
                            </thead>
                            <tbody id="logisticsBody" class="divide-y divide-gray-50">
                                <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400 text-sm">검색어를 입력하면 물류센터 재고가 표시됩니다.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

<script>
let currentPage = 1;
let selectedVendorId = 0;

function selectVendor(vendorId, btn) {
    selectedVendorId = vendorId;
    // 업체 선택 시 검색어 초기화 (해당 업체 전체 목록을 보여주기 위함)
    document.getElementById('searchInput').value = '';
    document.querySelectorAll('.vendor-tab').forEach(b => {
        b.classList.remove('bg-indigo-600', 'text-white', 'border-indigo-600', 'active');
        b.classList.add('bg-white', 'text-gray-600', 'border-gray-300');
    });
    btn.classList.remove('bg-white', 'text-gray-600', 'border-gray-300');
    btn.classList.add('bg-indigo-600', 'text-white', 'border-indigo-600', 'active');
    doSearch(1);
}

function doSearch(page = 1) {
    currentPage = page;
    const keyword  = document.getElementById('searchInput').value.trim();
    const vendorId = selectedVendorId;
    // 특정 업체를 선택한 경우 페이징 없이 전체 목록을 한 번에 표시
    const showAll  = vendorId > 0;

    fetch(ORD_BASE + '/ajax/search.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({keyword, vendor_id: vendorId, page, limit: 50, all: showAll ? 1 : 0, csrf_token: CSRF_TOKEN})
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) { showFlash('error', res.error); return; }
        document.getElementById('resultCount').textContent = res.total + '건';
        renderResults(res.data);
        if (showAll) {
            // 전체 표시 모드에서는 페이지네이션 숨김
            document.getElementById('pagination').innerHTML = '';
        } else {
            renderPagination(res.total, page, 50);
        }
    })
    .catch(() => showFlash('error', '검색 중 오류가 발생했습니다.'));

    loadPurchaseHistory(keyword);
    loadLogisticsStock(keyword);
}

function loadPurchaseHistory(keyword) {
    const body = document.getElementById('historyBody');
    const countEl = document.getElementById('historyCount');
    if (!keyword) {
        body.innerHTML = '<tr><td colspan="3" class="px-4 py-6 text-center text-gray-400 text-sm">검색어를 입력하면 전 점포의 입고 이력이 표시됩니다.</td></tr>';
        countEl.textContent = '';
        return;
    }
    body.innerHTML = '<tr><td colspan="3" class="px-4 py-6 text-center text-gray-400 text-sm"><i class="fas fa-spinner fa-spin mr-1"></i>불러오는 중...</td></tr>';

    fetch(ORD_BASE + '/ajax/purchase_history.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({keyword, csrf_token: CSRF_TOKEN})
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) { body.innerHTML = '<tr><td colspan="3" class="px-4 py-6 text-center text-red-400 text-sm">이력을 불러오지 못했습니다.</td></tr>'; return; }
        renderHistory(res.data);
    })
    .catch(() => { body.innerHTML = '<tr><td colspan="3" class="px-4 py-6 text-center text-red-400 text-sm">이력을 불러오지 못했습니다.</td></tr>'; });
}

function renderHistory(items) {
    const body = document.getElementById('historyBody');
    const countEl = document.getElementById('historyCount');
    countEl.textContent = items.length ? items.length + '건' : '';
    if (!items.length) {
        body.innerHTML = '<tr><td colspan="3" class="px-4 py-6 text-center text-gray-400 text-sm">입고 이력이 없습니다.</td></tr>';
        return;
    }
    const fmt = v => Number(v).toLocaleString('ko-KR', {minimumFractionDigits: 2});
    body.innerHTML = items.map(h => `
        <tr class="hover:bg-gray-50">
            <td class="px-3 py-2 text-xs text-gray-500 whitespace-nowrap">${escHtml(h.purchase_date)}</td>
            <td class="px-3 py-2">
                <div class="text-xs text-gray-500 whitespace-nowrap">${escHtml(h.vendor_name)}</div>
                <div class="text-sm text-gray-800 whitespace-nowrap">${escHtml(h.product_name)}</div>
            </td>
            <td class="px-3 py-2 text-xs text-right text-indigo-600 font-mono whitespace-nowrap">
                ${fmt(h.unit_price)}<span class="text-gray-400 ml-1">(${h.purchase_type === 'box' ? '박스' : '낱개'})</span>
            </td>
        </tr>
    `).join('');
}

function loadLogisticsStock(keyword) {
    const body = document.getElementById('logisticsBody');
    const countEl = document.getElementById('logisticsCount');
    if (!keyword) {
        body.innerHTML = '<tr><td colspan="4" class="px-4 py-6 text-center text-gray-400 text-sm">검색어를 입력하면 물류센터 재고가 표시됩니다.</td></tr>';
        countEl.textContent = '';
        return;
    }
    body.innerHTML = '<tr><td colspan="4" class="px-4 py-6 text-center text-gray-400 text-sm"><i class="fas fa-spinner fa-spin mr-1"></i>불러오는 중...</td></tr>';

    fetch(ORD_BASE + '/ajax/logistics_search.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({keyword, csrf_token: CSRF_TOKEN})
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) { body.innerHTML = '<tr><td colspan="4" class="px-4 py-6 text-center text-red-400 text-sm">재고를 불러오지 못했습니다.</td></tr>'; return; }
        renderLogistics(res.data);
    })
    .catch(() => { body.innerHTML = '<tr><td colspan="4" class="px-4 py-6 text-center text-red-400 text-sm">재고를 불러오지 못했습니다.</td></tr>'; });
}

function renderLogistics(items) {
    const body = document.getElementById('logisticsBody');
    const countEl = document.getElementById('logisticsCount');
    countEl.textContent = items.length ? items.length + '건' : '';
    if (!items.length) {
        body.innerHTML = '<tr><td colspan="4" class="px-4 py-6 text-center text-gray-400 text-sm">물류센터에 재고가 없습니다.</td></tr>';
        return;
    }
    body.innerHTML = items.map(item => {
        let expiryHtml = '<span class="text-gray-400">-</span>';
        if (item.earliest_expiry) {
            const days = item.days_left;
            let cls = 'text-gray-500';
            if (days !== null) {
                if (days < 0) cls = 'text-red-600 font-semibold';
                else if (days <= 30) cls = 'text-orange-500';
                else if (days <= 90) cls = 'text-yellow-600';
            }
            expiryHtml = `<span class="${cls}">${escHtml(item.earliest_expiry)}${days !== null ? ' (D-' + days + ')' : ''}</span>`;
        }
        return `
        <tr class="hover:bg-gray-50">
            <td class="px-3 py-2">
                <div class="text-xs text-gray-500 whitespace-nowrap">${escHtml(item.brand_name)}</div>
                <div class="text-sm text-gray-800 whitespace-nowrap">${escHtml(item.product_name)}</div>
            </td>
            <td class="px-3 py-2 text-xs text-right text-teal-700 font-mono whitespace-nowrap">${escHtml(item.stock_display)}</td>
            <td class="px-3 py-2 text-xs whitespace-nowrap">${expiryHtml}</td>
            <td class="px-3 py-2 text-center whitespace-nowrap">
                <button type="button" onclick="addToStoreOrder(${item.product_id}, this)"
                        class="inline-flex items-center px-2 py-1 text-xs font-medium text-teal-700 bg-teal-50 border border-teal-200 rounded-md hover:bg-teal-100 transition-colors">
                    <i class="fas fa-cart-plus mr-1"></i>담기
                </button>
            </td>
        </tr>
    `;
    }).join('');
}

// Design Ref: §7.1 확장 — 물류센터 재고 항목을 store/order.php의 주문 선택(장바구니)으로 전달
// store/order.php는 페이지 열람 시 localStorage 임시저장(order_draft_*)을 자동 복원하므로,
// 새 창을 열지 않고 같은 키에 직접 누적 저장해두면 다음에 store/order.php를 열었을 때 반영된다.
// (order/와 store/는 같은 도메인이라 localStorage를 공유하고, store_id도 같은 세션값을 씀)
const STORE_DRAFT_KEY = 'order_draft_<?php echo (int)ord_current_store_id(); ?>';
window.addToStoreOrder = function(productId, btn) {
    if (!productId) return;

    var draft = {};
    try { draft = JSON.parse(localStorage.getItem(STORE_DRAFT_KEY) || '{}'); } catch (e) { draft = {}; }
    var existingQty = (draft[productId] && draft[productId].qty) || 0;
    draft[productId] = { qty: existingQty + 1, unit: draft[productId] ? draft[productId].unit : undefined };
    localStorage.setItem(STORE_DRAFT_KEY, JSON.stringify(draft));

    var row  = btn.closest('tr');
    var name = row ? row.querySelector('.text-gray-800').textContent.trim() : '상품';

    var original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-check mr-1"></i>담김';
    setTimeout(function() { btn.disabled = false; btn.innerHTML = original; }, 1500);

    showFlash('success', name + ' — Store 주문 선택에 담았습니다.');
};

function renderResults(items) {
    const body = document.getElementById('resultBody');
    if (!items.length) {
        body.innerHTML = '<tr><td colspan="10" class="px-4 py-8 text-center text-gray-400 text-sm">검색 결과가 없습니다.</td></tr>';
        return;
    }
    const fmt = v => v ? Number(v).toLocaleString('ko-KR', {minimumFractionDigits:2}) : '';
    body.innerHTML = items.map(item => `
        <tr class="hover:bg-gray-50" id="result-${item.id}">
            <td class="px-3 py-2 text-xs text-gray-500 truncate" title="${escHtml(item.vendor_name)}">${escHtml(item.vendor_name)}</td>
            <td class="px-3 py-2 text-xs text-gray-500 truncate" title="${escHtml(item.brand ?? '')}">${escHtml(item.brand ?? '')}</td>
            <td class="px-3 py-2 overflow-hidden">
                <div class="text-xs font-medium text-gray-800 truncate" title="${escHtml(item.product_name)}">${escHtml(item.product_name)}</div>
                ${item.product_name_en ? `<div class="text-xs text-gray-400 truncate" title="${escHtml(item.product_name_en)}">${escHtml(item.product_name_en)}</div>` : ''}
            </td>
            <td class="px-3 py-2 text-xs text-gray-500 truncate" title="${escHtml(item.order_unit ?? '')}">${escHtml(item.order_unit ?? '')}</td>
            <td class="px-3 py-2 text-xs text-right text-gray-500 font-mono whitespace-nowrap">${item.unit_qty ? item.unit_qty : ''}</td>
            <td class="px-3 py-2 text-xs text-right text-teal-600 font-mono whitespace-nowrap">${fmt(item.unit_price_pcs)}</td>
            <td class="px-3 py-2 text-xs text-right text-indigo-600 font-mono whitespace-nowrap">${fmt(item.unit_price)}</td>
            <td class="px-3 py-2 text-xs text-gray-400 italic line-clamp-2 break-words" title="${escHtml(item.remark ?? '')}">${escHtml(item.remark ?? '')}</td>
            <td class="px-3 py-2 text-xs text-orange-500 whitespace-nowrap">${escHtml(item.expiry_date ?? '')}</td>
            <td class="px-3 py-2 whitespace-nowrap">
                <input type="number" min="0" step="1" value="${item.in_cart ? Math.round(item.cart_quantity) : ''}"
                       id="qty-${item.id}" data-item-id="${item.id}" data-vendor-id="${item.vendor_id}"
                       onclick="this.select()" class="qty-input w-14 px-2 py-1 border rounded text-xs text-center transition-colors ${item.in_cart ? 'border-green-400 bg-green-50' : 'border-gray-200'}">
            </td>
        </tr>
    `).join('');
}

function renderPagination(total, page, limit) {
    const pages = Math.ceil(total / limit);
    const pg = document.getElementById('pagination');
    if (pages <= 1) { pg.innerHTML = ''; return; }
    let html = '';
    for (let i = 1; i <= pages; i++) {
        html += `<button onclick="doSearch(${i})" class="px-3 py-1 text-xs rounded ${i === page ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'}">${i}</button>`;
    }
    pg.innerHTML = html;
}

function autoSaveCart(input) {
    const itemId  = parseInt(input.dataset.itemId);
    const vendorId = parseInt(input.dataset.vendorId);
    const qty     = parseInt(input.value);

    if (!qty || qty <= 0) {
        input.value = '';
        input.classList.remove('border-green-400', 'bg-green-50');
        input.classList.add('border-gray-200');
        postCart({action: 'remove', inventory_item_id: itemId})
            .then(() => { document.getElementById('cart-item-' + itemId)?.remove(); refreshCart(); });
    } else {
        postCart({action: 'add', inventory_item_id: itemId, vendor_id: vendorId, quantity: qty})
            .then(() => {
                input.classList.remove('border-gray-200');
                input.classList.add('border-green-400', 'bg-green-50');
                refreshCart();
            });
    }
}

// removeCart / clearVendorCart / postCart / refreshCart / renderCart / toggleVendorDetail /
// downloadOrder / escHtml / escJs 는 좌측 메뉴 주문 내역 패널과 함께 partials/header.php 에서 전역 정의됨.

document.getElementById('searchBtn').addEventListener('click', () => doSearch(1));
document.getElementById('searchInput').addEventListener('keydown', e => { if (e.key === 'Enter') doSearch(1); });
document.addEventListener('DOMContentLoaded', () => doSearch(1));

document.getElementById('resultBody').addEventListener('keydown', e => {
    if (!e.target.classList.contains('qty-input')) return;
    if (e.key === 'Enter' || e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        autoSaveCart(e.target);
        const inputs = Array.from(document.querySelectorAll('.qty-input'));
        const idx  = inputs.indexOf(e.target);
        const next = e.key === 'ArrowUp' ? inputs[idx - 1] : inputs[idx + 1];
        if (next) { next.focus(); next.select(); }
    }
});

document.getElementById('resultBody').addEventListener('focus', e => {
    if (e.target.classList.contains('qty-input')) {
        e.target.closest('tr')?.classList.add('row-focused');
    }
}, true);

document.getElementById('resultBody').addEventListener('blur', e => {
    if (e.target.classList.contains('qty-input')) {
        e.target.closest('tr')?.classList.remove('row-focused');
        autoSaveCart(e.target);
    }
}, true);
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
