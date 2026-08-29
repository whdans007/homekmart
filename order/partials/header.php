<?php
// Design Ref: §3.2 — 발주 전용 헤더, 인증만 공유, 좌측 사이드바 레이아웃 (logistics teal 디자인 통일)
ob_start();
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/order_helper.php';
ord_require_manager();

$_ord_page  = basename($_SERVER['PHP_SELF']);
$_ord_flash = ord_get_flash();
$_ord_cart_count = ord_cart_count(ord_current_store_id());
$_ord_cart_groups = ord_get_cart_grouped(ord_current_store_id());

// 점포명 / 등급(역할) 라벨 — office 사용자 정보 카드와 동일
$_ord_role_label = !empty($_SESSION['role']) ? get_role_label($_SESSION['role']) : '';
$_ord_store_name = '';
try {
    $_oc = get_ord_db();
    $_os = $_oc->prepare("SELECT name FROM stores WHERE id = ?");
    $_osid = ord_current_store_id();
    $_os->bind_param('i', $_osid); $_os->execute();
    $_ord_store_name = $_os->get_result()->fetch_row()[0] ?? '';
    $_os->close(); $_oc->close();
} catch (Exception $e) {}

$navItems = [
    ['file' => 'index.php',            'label' => '검색 / 발주',  'icon' => 'fa-search'],
];
$uploadItems = [
    ['file' => 'upload_inventory.php', 'label' => '재고리스트 업로드', 'icon' => 'fa-upload', 'also' => 'upload_excel.php'],
];
$adminItems = [];
if (ord_is_admin()) {
    $adminItems[] = ['file' => 'vendors.php', 'label' => '업체 관리', 'icon' => 'fa-building'];
    $adminItems[] = ['file' => 'users.php',   'label' => '사용자 관리', 'icon' => 'fa-users'];
}
?>
<!DOCTYPE html>
<html lang="ko" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title ?? '발주 관리'); ?> — HOME K MART</title>
    <link rel="icon" href="data:,">
    <link href="<?php echo ORD_WEB_ROOT; ?>/admin/css/style.css?v=20260619teal" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body class="bg-gray-50 h-full">

<div class="flex flex-col h-screen overflow-hidden">

    <!-- 상단바 (logistics teal 디자인, 좌측 사이드바 대체) -->
    <header class="flex-shrink-0 bg-white border-b border-teal-100">
        <div class="flex items-center gap-2 px-3 py-1.5 flex-wrap">

            <!-- 로고 -->
            <a href="<?php echo ORD_WEB_ROOT; ?>/" class="flex-shrink-0" title="MAIN">
                <img src="<?php echo ORD_WEB_ROOT; ?>/logo/homekmart_logo.png" alt="HOME K MART" style="height:28px;width:auto;display:block;">
            </a>
            <a href="<?php echo ORD_WEB_ROOT; ?>/"
               class="flex items-center gap-1.5 flex-shrink-0 px-2 py-1.5 text-xs font-semibold rounded-md transition-colors"
               style="background:#1e40af;color:#ffffff;"
               onmouseover="this.style.background='#1e3a8a'" onmouseout="this.style.background='#1e40af'">
                <i class="fa-solid fa-house"></i> MAIN
            </a>

            <span class="w-px h-5 bg-gray-200 mx-1 flex-shrink-0"></span>

            <!-- 발주 네비게이션 -->
            <nav class="flex items-center gap-1 flex-wrap">
                <?php foreach ($navItems as $nav): ?>
                <?php $active = $_ord_page === $nav['file'] || $_ord_page === ($nav['also'] ?? ''); ?>
                <a href="<?php echo ORD_BASE . '/' . $nav['file']; ?>"
                   class="<?php echo $active ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                    <i class="fas <?php echo $nav['icon']; ?> mr-1.5 text-xs w-4 text-center"></i>
                    <?php echo $nav['label']; ?>
                </a>
                <?php endforeach; ?>

                <?php foreach ($uploadItems as $nav): ?>
                <?php $active = $_ord_page === $nav['file'] || $_ord_page === ($nav['also'] ?? ''); ?>
                <a href="<?php echo ORD_BASE . '/' . $nav['file']; ?>"
                   class="<?php echo $active ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                    <i class="fas <?php echo $nav['icon']; ?> mr-1.5 text-xs w-4 text-center"></i>
                    <?php echo $nav['label']; ?>
                </a>
                <?php endforeach; ?>

                <?php if (!empty($adminItems)): ?>
                <span class="w-px h-5 bg-gray-200 mx-1 flex-shrink-0"></span>
                <?php foreach ($adminItems as $nav): ?>
                <?php $active = $_ord_page === $nav['file']; ?>
                <a href="<?php echo ORD_BASE . '/' . $nav['file']; ?>"
                   class="<?php echo $active ? 'bg-slate-200 text-slate-800' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
                    <i class="fas <?php echo $nav['icon']; ?> mr-1.5 text-xs w-4 text-center"></i>
                    <?php echo $nav['label']; ?>
                </a>
                <?php endforeach; ?>
                <?php endif; ?>
            </nav>

            <!-- 우측: 사용자정보 + 주문내역(카트) + 로그아웃 -->
            <div class="ml-auto flex items-center gap-2 flex-shrink-0">
                <div style="display:flex;align-items:center;gap:0.4rem;padding:0.3rem 0.6rem;border:1px solid #ccfbf1;border-radius:0.5rem;background:linear-gradient(135deg,#f0fdfa 0%,#ecfdf5 100%);">
                    <i class="fa-solid fa-store" style="color:#0d9488;font-size:0.75rem;"></i>
                    <span style="font-weight:600;font-size:0.75rem;color:#0f766e;white-space:nowrap;"><?php echo htmlspecialchars($_ord_store_name ?: 'Store'); ?></span>
                    <span class="text-gray-300">|</span>
                    <i class="fa-solid fa-circle-user" style="color:#0d9488;font-size:0.85rem;"></i>
                    <span style="font-weight:500;font-size:0.75rem;color:#334155;white-space:nowrap;"><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''); ?></span>
                    <?php if ($_ord_role_label !== ''): ?>
                    <span style="display:inline-flex;align-items:center;gap:0.2rem;padding:0.15rem 0.4rem;font-size:10px;font-weight:600;color:#0f766e;background:#ccfbf1;border-radius:0.4rem;white-space:nowrap;">
                        <i class="fa-solid fa-id-badge" style="font-size:0.58rem;"></i><?php echo htmlspecialchars($_ord_role_label); ?>
                    </span>
                    <?php endif; ?>
                </div>

                <!-- 주문 내역(카트) 드로어 토글 -->
                <button type="button" onclick="toggleCartDrawer()"
                        class="relative flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-semibold text-indigo-700 bg-indigo-50 border border-indigo-100 rounded-md hover:bg-indigo-100 transition-colors">
                    <i class="fas fa-cart-shopping"></i>장바구니
                    <span id="cartBadge" class="inline-flex items-center justify-center font-bold"
                          style="background:#4f46e5;color:#fff;min-width:1.15rem;height:1.15rem;padding:0 0.3rem;border-radius:9999px;font-size:0.65rem;line-height:1">
                        <?php echo array_sum(array_map(fn($g) => count($g['items']), $_ord_cart_groups)); ?>
                    </span>
                </button>

                <a href="<?php echo ORD_BASE; ?>/logout.php"
                   style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.4rem 0.6rem;font-size:11px;font-weight:600;color:#dc2626;background:#fef2f2;border-radius:0.4rem;text-decoration:none;transition:background 0.15s;"
                   onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fef2f2'">
                    <i class="fa-solid fa-right-from-bracket"></i>Logout
                </a>
            </div>
        </div>
    </header>

    <!-- 주문 내역(카트) 드로어: 우측 슬라이드 패널 -->
    <div id="cartDrawerOverlay" class="hidden fixed inset-0 bg-black/40 z-40" onclick="closeCartDrawer()"></div>
    <div id="cartDrawer"
         class="fixed top-0 right-0 h-full bg-white shadow-2xl z-50 flex flex-col transform translate-x-full transition-transform duration-200"
         style="width:22rem;max-width:90vw;">
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100 flex-shrink-0">
            <h2 class="text-sm font-semibold text-gray-700"><i class="fas fa-cart-shopping mr-1.5 text-indigo-500"></i>장바구니</h2>
            <button type="button" onclick="closeCartDrawer()" class="text-gray-400 hover:text-gray-700">
                <i class="fas fa-xmark"></i>
            </button>
        </div>
        <div id="cartBody" class="flex-1 overflow-y-auto bg-white">
            <?php if (empty($_ord_cart_groups)): ?>
            <div class="px-3 py-4 text-center text-gray-400 text-xs">장바구니가 비어있습니다.</div>
            <?php else: foreach ($_ord_cart_groups as $group):
                $vid         = $group['vendor_id'];
                $itemCount   = count($group['items']);
                $totalQty    = array_sum(array_column($group['items'], 'quantity'));
                $totalAmount = array_sum(array_map(fn($i) => $i['quantity'] * ($i['unit_price'] ?: $i['unit_price_pcs'] ?: 0), $group['items']));
            ?>
            <div class="border-b border-gray-100 last:border-0" data-vendor-id="<?php echo $vid; ?>">
                <!-- 업체 요약 카드 (클릭으로 펼침) -->
                <div class="px-3 py-2.5 cursor-pointer hover:bg-gray-50 transition-colors select-none"
                     onclick="toggleVendorDetail(<?php echo $vid; ?>)">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-gray-800 truncate flex-1 mr-2"><?php echo htmlspecialchars($group['vendor_name']); ?></span>
                        <i class="fas fa-chevron-down text-gray-400 text-xs transition-transform duration-200" id="chevron-<?php echo $vid; ?>"></i>
                    </div>
                    <div class="flex items-center gap-2 mt-1 text-xs text-gray-500">
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

    <script>
    // 발주 시스템 공통 상수/함수 (전 페이지 공유 — 좌측 메뉴의 주문 내역 패널용)
    const ORD_BASE = '<?php echo ORD_BASE; ?>';
    const CSRF_TOKEN = '<?php echo ord_csrf_token(); ?>';

    function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
    function escJs(s)   { return String(s).replace(/\\/g,'\\\\').replace(/'/g,"\\'"); }

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
            body.innerHTML = '<div class="px-3 py-4 text-center text-gray-400 text-xs">장바구니가 비어있습니다.</div>';
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
                <div class="px-3 py-2.5 cursor-pointer hover:bg-gray-50 transition-colors select-none"
                     onclick="toggleVendorDetail(${g.vendor_id})">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-gray-800 truncate flex-1 mr-2">${escHtml(g.vendor_name)}</span>
                        <i class="fas fa-chevron-down text-gray-400 text-xs transition-transform duration-200" id="chevron-${g.vendor_id}"
                           style="${isOpen ? 'transform:rotate(180deg)' : ''}"></i>
                    </div>
                    <div class="flex items-center gap-2 mt-1 text-xs text-gray-500">
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

    // ── 주문 내역 드로어 (우측 슬라이드) ─────────────────────────
    function openCartDrawer() {
        document.getElementById('cartDrawer').classList.remove('translate-x-full');
        document.getElementById('cartDrawerOverlay').classList.remove('hidden');
    }
    function closeCartDrawer() {
        document.getElementById('cartDrawer').classList.add('translate-x-full');
        document.getElementById('cartDrawerOverlay').classList.add('hidden');
    }
    function toggleCartDrawer() {
        const isOpen = !document.getElementById('cartDrawer').classList.contains('translate-x-full');
        if (isOpen) closeCartDrawer(); else openCartDrawer();
    }
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeCartDrawer();
    });

    function toggleVendorDetail(vendorId) {
        const detail  = document.getElementById('vendor-detail-' + vendorId);
        const chevron = document.getElementById('chevron-' + vendorId);
        const isHidden = detail.classList.toggle('hidden');
        chevron.style.transform = isHidden ? '' : 'rotate(180deg)';
    }

    // 다운로드를 fetch로 처리해 "성공 확인 후 장바구니 비우기" 순서를 보장한다.
    // (기존 form.submit() 방식은 다운로드 요청과 clear_vendor 요청이 동시에 날아가
    //  clear_vendor가 먼저 처리되면 다운로드 쪽에서 "장바구니가 비었음" 오류가 나던 문제 있었음)
    async function downloadOrder(vendorId, vendorName) {
        try {
            const res = await fetch(ORD_BASE + '/ajax/download_order.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({vendor_id: vendorId, save_history: 1, csrf_token: CSRF_TOKEN})
            });
            const ctype = res.headers.get('Content-Type') || '';
            if (!res.ok || ctype.includes('text/html')) {
                const text = await res.text();
                const m = text.match(/alert\((.*?)\)/);
                let msg = '발주서 다운로드에 실패했습니다.';
                if (m) { try { msg = JSON.parse(m[1]); } catch (e) {} }
                showFlash('error', msg);
                return;
            }

            const blob = await res.blob();
            const disposition = res.headers.get('Content-Disposition') || '';
            const m = disposition.match(/filename="?([^";]+)"?/i);
            let filename = vendorName + '_발주서.xlsx';
            if (m) { try { filename = decodeURIComponent(m[1]); } catch (e) {} }

            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url; a.download = filename;
            document.body.appendChild(a); a.click(); a.remove();
            URL.revokeObjectURL(url);

            // 다운로드 성공 후에만 해당 업체 장바구니를 비운다.
            await postCart({action: 'clear_vendor', vendor_id: vendorId});
            refreshCart();
        } catch (e) {
            showFlash('error', '다운로드 중 오류가 발생했습니다.');
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

    <!-- 메인 콘텐츠 영역 -->
    <div class="flex-1 flex flex-col overflow-hidden">

        <!-- 페이지 본문 -->
        <div class="flex-1 overflow-y-auto">
        <div class="px-6 py-5">

        <?php if ($_ord_flash): ?>
        <div class="mb-4 px-4 py-3 rounded-lg border text-sm flex items-center
                    <?php echo $_ord_flash['type'] === 'success'
                        ? 'bg-green-50 border-green-200 text-green-800'
                        : 'bg-red-50 border-red-200 text-red-800'; ?>">
            <i class="fas <?php echo $_ord_flash['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
            <?php echo htmlspecialchars($_ord_flash['message']); ?>
        </div>
        <?php endif; ?>
