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

$cartGroups = ord_get_cart_grouped(ord_current_store_id());
?>

<div style="display:flex; flex-direction:row; gap:1.5rem; align-items:flex-start;">

    <!-- 검색 패널 -->
    <div style="flex:1; min-width:0;">
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
        <div id="searchResults" class="bg-white rounded-xl shadow-sm border border-gray-200 min-h-48 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
                <span class="text-sm font-medium text-gray-600">검색 결과 <span id="resultCount" class="text-indigo-600">—</span></span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 border-b border-gray-100">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 whitespace-nowrap">업체명</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 whitespace-nowrap">브랜드</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 whitespace-nowrap">상품명(한)</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 whitespace-nowrap">상품명(영)</th>
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
                        <tr><td colspan="11" class="px-4 py-8 text-center text-gray-400"><i class="fas fa-spinner fa-spin mr-1"></i>불러오는 중...</td></tr>
                    </tbody>
                </table>
            </div>
            <div id="pagination" class="px-4 py-3 border-t border-gray-100 flex justify-center gap-2"></div>
        </div>
    </div>

    <!-- 주문 내역 패널 -->
    <div style="width:260px; flex-shrink:0;" id="cart">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200" style="position:sticky; top:1rem; max-height:calc(100vh - 6rem); display:flex; flex-direction:column;">
            <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between flex-shrink-0">
                <h2 class="text-sm font-semibold text-gray-700"><i class="fas fa-clipboard-list mr-2 text-indigo-600"></i>주문 내역</h2>
                <span id="cartBadge" class="text-xs bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded-full font-medium">
                    <?php echo array_sum(array_map(fn($g) => count($g['items']), $cartGroups)); ?>
                </span>
            </div>
            <div id="cartBody" class="overflow-y-auto flex-1">
                <?php if (empty($cartGroups)): ?>
                <div class="px-4 py-8 text-center text-gray-400 text-sm">주문 내역이 없습니다.</div>
                <?php else: foreach ($cartGroups as $group):
                    $vid         = $group['vendor_id'];
                    $itemCount   = count($group['items']);
                    $totalQty    = array_sum(array_column($group['items'], 'quantity'));
                    $totalAmount = array_sum(array_map(fn($i) => $i['quantity'] * ($i['unit_price'] ?: $i['unit_price_pcs'] ?: 0), $group['items']));
                ?>
                <div class="border-b border-gray-100 last:border-0" data-vendor-id="<?php echo $vid; ?>">
                    <!-- 업체 요약 카드 (클릭으로 펼침) -->
                    <div class="px-3 py-3 cursor-pointer hover:bg-gray-50 transition-colors select-none"
                         onclick="toggleVendorDetail(<?php echo $vid; ?>)">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold text-gray-800 truncate flex-1 mr-2"><?php echo htmlspecialchars($group['vendor_name']); ?></span>
                            <i class="fas fa-chevron-down text-gray-400 text-xs transition-transform duration-200" id="chevron-<?php echo $vid; ?>"></i>
                        </div>
                        <div class="flex items-center gap-2 mt-1.5 text-xs text-gray-500">
                            <span class="font-semibold text-gray-700"><?php echo $itemCount; ?>종</span>
                            <span class="text-gray-300">|</span>
                            <span><?php echo number_format($totalQty); ?>개</span>
                            <?php if ($totalAmount > 0): ?>
                            <span class="text-gray-300">|</span>
                            <span class="text-indigo-600 font-semibold"><?php echo number_format($totalAmount, 2); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <!-- 상세 내역 (기본 접힘) -->
                    <div class="hidden" id="vendor-detail-<?php echo $vid; ?>">
                        <div class="divide-y divide-gray-50">
                            <?php foreach ($group['items'] as $item): ?>
                            <div class="px-3 py-1.5 flex items-center gap-2" id="cart-item-<?php echo $item['inventory_item_id']; ?>">
                                <div class="flex-1 min-w-0">
                                    <div class="text-xs text-gray-600 truncate" title="<?php echo htmlspecialchars($item['product_name']); ?>">
                                        <?php echo htmlspecialchars($item['product_name']); ?>
                                    </div>
                                    <?php if (!empty($item['added_by_name'])): ?>
                                    <div class="text-gray-400 truncate" style="font-size:9px"><?php echo htmlspecialchars($item['added_by_name']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <span class="text-xs font-semibold text-gray-800 w-8 text-right flex-shrink-0"><?php echo (int)$item['quantity']; ?></span>
                                <button onclick="removeCart(<?php echo $item['inventory_item_id']; ?>)" class="text-gray-300 hover:text-red-500 text-xs flex-shrink-0">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="px-3 py-2 flex gap-2 border-t border-gray-100 bg-gray-50">
                            <button onclick="clearVendorCart(<?php echo $vid; ?>)"
                                    class="flex-1 text-xs text-red-400 hover:text-red-600 py-1.5 border border-red-200 rounded-lg hover:border-red-400 transition-colors">
                                전체삭제
                            </button>
                            <button onclick="downloadOrder(<?php echo $vid; ?>, '<?php echo htmlspecialchars(addslashes($group['vendor_name'])); ?>')"
                                    class="flex-1 inline-flex items-center justify-center text-xs bg-indigo-600 text-white py-1.5 px-2 rounded-lg hover:bg-indigo-700 transition-colors">
                                <i class="fas fa-download mr-1"></i>발주서
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
const ORD_BASE = '<?php echo ORD_BASE; ?>';
const CSRF_TOKEN = '<?php echo ord_csrf_token(); ?>';
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
}

function renderResults(items) {
    const body = document.getElementById('resultBody');
    if (!items.length) {
        body.innerHTML = '<tr><td colspan="11" class="px-4 py-8 text-center text-gray-400 text-sm">검색 결과가 없습니다.</td></tr>';
        return;
    }
    const fmt = v => v ? Number(v).toLocaleString('ko-KR', {minimumFractionDigits:2}) : '';
    body.innerHTML = items.map(item => `
        <tr class="hover:bg-gray-50" id="result-${item.id}">
            <td class="px-3 py-2 text-xs text-gray-500 whitespace-nowrap">${escHtml(item.vendor_name)}</td>
            <td class="px-3 py-2 text-xs text-gray-500 whitespace-nowrap">${escHtml(item.brand ?? '')}</td>
            <td class="px-3 py-2 text-sm font-medium text-gray-800">${escHtml(item.product_name)}</td>
            <td class="px-3 py-2 text-xs text-gray-400">${escHtml(item.product_name_en ?? '')}</td>
            <td class="px-3 py-2 text-xs text-gray-500 whitespace-nowrap">${escHtml(item.order_unit ?? '')}</td>
            <td class="px-3 py-2 text-xs text-right text-gray-500 font-mono whitespace-nowrap">${item.unit_qty ? item.unit_qty : ''}</td>
            <td class="px-3 py-2 text-xs text-right text-teal-600 font-mono whitespace-nowrap">${fmt(item.unit_price_pcs)}</td>
            <td class="px-3 py-2 text-xs text-right text-indigo-600 font-mono whitespace-nowrap">${fmt(item.unit_price)}</td>
            <td class="px-3 py-2 text-xs text-gray-400 italic">${escHtml(item.remark ?? '')}</td>
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

function removeCart(itemId) {
    const input = document.getElementById('qty-' + itemId);
    if (input) { input.value = ''; input.classList.remove('border-green-400', 'bg-green-50'); input.classList.add('border-gray-200'); }
    postCart({action: 'remove', inventory_item_id: itemId})
        .then(() => { document.getElementById('cart-item-' + itemId)?.remove(); refreshCart(); });
}

function clearVendorCart(vendorId) {
    if (!confirm('이 업체 장바구니를 모두 비우시겠습니까?')) return;
    postCart({action: 'clear_vendor', vendor_id: vendorId})
        .then(() => { location.reload(); });
}

function postCart(data) {
    return fetch(ORD_BASE + '/ajax/cart_action.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({...data, csrf_token: CSRF_TOKEN})
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) throw new Error(res.error);
        document.getElementById('cartBadge').textContent = res.cart_count;
        return res;
    });
}

function refreshCart() {
    fetch(ORD_BASE + '/ajax/cart_summary.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({csrf_token: CSRF_TOKEN})
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) return;
        document.getElementById('cartBadge').textContent = res.total_items;
        renderCart(res.groups);
    });
}

function renderCart(groups) {
    const body = document.getElementById('cartBody');
    if (!groups.length) {
        body.innerHTML = '<div class="px-4 py-8 text-center text-gray-400 text-sm">주문 내역이 없습니다.</div>';
        return;
    }
    const fmt = n => Number(n).toLocaleString('ko-KR', {minimumFractionDigits: 2});

    // 현재 열린 업체 기억
    const openVendors = new Set(
        [...document.querySelectorAll('[id^="vendor-detail-"]')]
            .filter(el => !el.classList.contains('hidden'))
            .map(el => el.id.replace('vendor-detail-', ''))
    );

    body.innerHTML = groups.map(g => {
        const isOpen = openVendors.has(String(g.vendor_id));
        return `
        <div class="border-b border-gray-100 last:border-0" data-vendor-id="${g.vendor_id}">
            <div class="px-3 py-3 cursor-pointer hover:bg-gray-50 transition-colors select-none"
                 onclick="toggleVendorDetail(${g.vendor_id})">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-gray-800 truncate flex-1 mr-2">${escHtml(g.vendor_name)}</span>
                    <i class="fas fa-chevron-down text-gray-400 text-xs transition-transform duration-200" id="chevron-${g.vendor_id}"
                       style="${isOpen ? 'transform:rotate(180deg)' : ''}"></i>
                </div>
                <div class="flex items-center gap-2 mt-1.5 text-xs text-gray-500">
                    <span class="font-semibold text-gray-700">${g.item_count}종</span>
                    <span class="text-gray-300">|</span>
                    <span>${g.total_quantity}개</span>
                    ${g.total_amount > 0 ? `<span class="text-gray-300">|</span><span class="text-indigo-600 font-semibold">${fmt(g.total_amount)}</span>` : ''}
                </div>
            </div>
            <div class="${isOpen ? '' : 'hidden'}" id="vendor-detail-${g.vendor_id}">
                <div class="divide-y divide-gray-50">
                    ${g.items.map(item => {
                        const byLine = item.added_by_name
                            ? `<div class="text-gray-400 truncate" style="font-size:9px">${escHtml(item.added_by_name)}</div>`
                            : '';
                        return `
                    <div class="px-3 py-1.5 flex items-center gap-2" id="cart-item-${item.id}">
                        <div class="flex-1 min-w-0">
                            <div class="text-xs text-gray-600 truncate" title="${escHtml(item.product_name)}">${escHtml(item.product_name)}</div>
                            ${byLine}
                        </div>
                        <span class="text-xs font-semibold text-gray-800 w-8 text-right flex-shrink-0">${item.quantity}</span>
                        <button onclick="removeCart(${item.id})" class="text-gray-300 hover:text-red-500 text-xs flex-shrink-0">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>`;
                    }).join('')}
                </div>
                <div class="px-3 py-2 flex gap-2 border-t border-gray-100 bg-gray-50">
                    <button onclick="clearVendorCart(${g.vendor_id})"
                            class="flex-1 text-xs text-red-400 hover:text-red-600 py-1.5 border border-red-200 rounded-lg hover:border-red-400 transition-colors">
                        전체삭제
                    </button>
                    <button onclick="downloadOrder(${g.vendor_id}, '${escJs(g.vendor_name)}')"
                            class="flex-1 inline-flex items-center justify-center text-xs bg-indigo-600 text-white py-1.5 px-2 rounded-lg hover:bg-indigo-700 transition-colors">
                        <i class="fas fa-download mr-1"></i>발주서
                    </button>
                </div>
            </div>
        </div>`;
    }).join('');
}

function toggleVendorDetail(vendorId) {
    const detail  = document.getElementById('vendor-detail-' + vendorId);
    const chevron = document.getElementById('chevron-' + vendorId);
    const isHidden = detail.classList.toggle('hidden');
    chevron.style.transform = isHidden ? '' : 'rotate(180deg)';
}

function downloadOrder(vendorId, vendorName) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = ORD_BASE + '/ajax/download_order.php';
    const fields = {vendor_id: vendorId, save_history: 1, csrf_token: CSRF_TOKEN};
    for (const [k, v] of Object.entries(fields)) {
        const i = document.createElement('input');
        i.type = 'hidden'; i.name = k; i.value = v;
        form.appendChild(i);
    }
    document.body.appendChild(form);
    form.submit();
    form.remove();
}

function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function escJs(s)   { return String(s).replace(/\\/g,'\\\\').replace(/'/g,"\\'"); }

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

document.getElementById('resultBody').addEventListener('blur', e => {
    if (e.target.classList.contains('qty-input')) autoSaveCart(e.target);
}, true);

// 다른 admin이 담은 변경사항 자동 동기화 (15초마다)
let _cartVersion = null;
async function syncCartPoll() {
    try {
        const res = await fetch(ORD_BASE + '/ajax/cart_summary.php').then(r => r.json());
        if (!res.success) return;
        document.getElementById('cartBadge').textContent = res.total_items;
        if (_cartVersion !== null && _cartVersion !== res.version) {
            renderCart(res.groups);
        }
        _cartVersion = res.version;
    } catch(e) {}
}
syncCartPoll();
setInterval(syncCartPoll, 15000);
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
