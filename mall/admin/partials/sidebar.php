<?php
/**
 * mall/admin 공용 상단 메뉴바
 * Design Ref: shopping-mall.design.md §5.3 Component List, §7 CSRF 토큰
 * 포함하는 쪽에서 $current_page(basename)를 미리 정의해야 활성 메뉴가 표시된다.
 * CSRF 토큰을 window.MALL_CSRF_TOKEN으로 노출해 각 페이지의 fetch() 호출에서 재사용한다.
 * 좌측 고정 사이드바 대신 상단 가로 메뉴로 배치해 본문 영역을 더 넓게 쓴다.
 */
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../lib/order_chat.php';
require_once __DIR__ . '/../../config/mall_config.php';
$__mall_csrf_token = mall_csrf_token();
$__mall_chat_unread_total = mall_order_chat_unread_total_for_admin();

// 접수대기(신규 주문) 건수 — 모든 관리자 페이지 로드 시마다 호출되므로 예외가 나도 화면이
// 죽지 않도록 방어한다(dashboard.php의 동일 카운트 쿼리와 같은 기준).
$__mall_pending_orders = 0;
try {
    $__pending_result = mall_get_db_connection()->query(
        "SELECT COUNT(*) AS cnt FROM mall_orders WHERE store_id = " . (int)MALL_STORE_ID . " AND status = 'pending'"
    );
    $__mall_pending_orders = (int)($__pending_result->fetch_assoc()['cnt'] ?? 0);
} catch (Throwable $e) {
    $__mall_pending_orders = 0;
}

// 배송중 건수 — "배달 관리" 메뉴 배지(지금 몇 건이 배송중인지 한눈에).
$__mall_delivering_orders = 0;
try {
    $__delivering_result = mall_get_db_connection()->query(
        "SELECT COUNT(*) AS cnt FROM mall_orders WHERE store_id = " . (int)MALL_STORE_ID . " AND status = 'delivering'"
    );
    $__mall_delivering_orders = (int)($__delivering_result->fetch_assoc()['cnt'] ?? 0);
} catch (Throwable $e) {
    $__mall_delivering_orders = 0;
}

$__mall_nav_items = [
    ['href' => 'dashboard.php', 'icon' => 'fa-gauge', 'label' => '대시보드'],
    ['href' => 'home_layout.php', 'icon' => 'fa-swatchbook', 'label' => '홈 레이아웃'],
    ['href' => 'products.php', 'icon' => 'fa-box', 'label' => '상품 큐레이션'],
    // 도매 상품 노출(wholesale_products.php)은 메뉴에서만 숨김 — 기능/페이지는 그대로 남아있어
    // 필요해지면 이 줄만 되살리면 된다(URL 직접 접근으로는 여전히 사용 가능).
    ['href' => 'members.php', 'icon' => 'fa-users', 'label' => '회원 관리'],
    ['href' => 'discount_rules.php', 'icon' => 'fa-percent', 'label' => '환경설정'],
    ['href' => 'orders.php', 'icon' => 'fa-receipt', 'label' => '주문 관리', 'badge' => $__mall_pending_orders],
    ['href' => 'order_chat.php', 'icon' => 'fa-comments', 'label' => '주문톡', 'badge' => $__mall_chat_unread_total],
    ['href' => 'deliveries.php', 'icon' => 'fa-truck-fast', 'label' => '배달 관리', 'badge' => $__mall_delivering_orders],
    ['href' => 'drivers.php', 'icon' => 'fa-motorcycle', 'label' => '배송기사 관리'],
    ['href' => 'delivery_map.php', 'icon' => 'fa-map-location-dot', 'label' => '실시간 배송 지도'],
];
?>
<div class="bg-white border-b border-gray-200">
    <div class="flex items-center justify-between gap-4 px-4 py-2 flex-wrap">
        <div class="flex items-center gap-4 flex-wrap">
            <div class="font-bold text-sm text-gray-800 flex-shrink-0 whitespace-nowrap"><i class="fas fa-store mr-1.5"></i>쇼핑몰 관리</div>
            <nav class="flex items-center gap-1 flex-wrap">
                <?php foreach ($__mall_nav_items as $__item): ?>
                    <a href="<?php echo $__item['href']; ?>"
                       class="flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium rounded-md whitespace-nowrap transition-colors <?php echo ($current_page ?? '') === $__item['href'] ? 'bg-blue-100 text-blue-800' : 'text-gray-600 hover:bg-gray-100'; ?>">
                        <i class="fas <?php echo $__item['icon']; ?>"></i><?php echo $__item['label']; ?>
                        <?php if (isset($__item['badge'])): ?>
                            <span class="mall-nav-badge px-1.5 py-0.5 rounded-full bg-red-600 text-white text-[10px] font-bold leading-none" data-badge-key="<?php echo htmlspecialchars($__item['href']); ?>" style="<?php echo $__item['badge'] > 0 ? '' : 'display:none;'; ?>"><?php echo $__item['badge'] > 99 ? '99+' : $__item['badge']; ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
        <a href="../../admin/index.php" class="text-xs text-gray-500 hover:text-gray-700 flex-shrink-0 whitespace-nowrap"><i class="fas fa-arrow-left mr-1"></i>관리자 메인으로</a>
    </div>
</div>
<script>
window.MALL_CSRF_TOKEN = <?php echo json_encode($__mall_csrf_token); ?>;

// 상단 메뉴 배지(접수대기 주문/주문톡 안읽음) 30초 폴링 + 늘어나면 알림음.
// sidebar.php가 모든 관리자 페이지에 포함되므로, 어느 화면에 있든 새 주문/새 메시지를 놓치지 않는다.
(function () {
    var lastCounts = {
        'orders.php': <?php echo (int)$__mall_pending_orders; ?>,
        'order_chat.php': <?php echo (int)$__mall_chat_unread_total; ?>,
        'deliveries.php': <?php echo (int)$__mall_delivering_orders; ?>
    };
    // 배달 관리는 "지금 몇 건 배송중인지" 참고용 숫자라 늘어나도 알림음은 울리지 않는다
    // (접수대기 주문/주문톡 안읽음만 응대가 필요한 알림 대상).
    var ALERT_KEYS = ['orders.php', 'order_chat.php'];

    function playTone(ctx, delaySec) {
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.value = 880;
        var start = ctx.currentTime + delaySec;
        gain.gain.setValueAtTime(0.2, start);
        gain.gain.exponentialRampToValueAtTime(0.001, start + 0.3);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start(start);
        osc.stop(start + 0.3);
    }

    function beep() {
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            var ctx = new Ctx();
            var play = function () { playTone(ctx, 0); playTone(ctx, 0.4); };
            if (ctx.state === 'suspended' && ctx.resume) {
                ctx.resume().then(play).catch(function () {});
            } else {
                play();
            }
        } catch (e) {}
    }

    function updateBadge(key, count) {
        var el = document.querySelector('.mall-nav-badge[data-badge-key="' + key + '"]');
        if (!el) return;
        if (count > 0) {
            el.textContent = count > 99 ? '99+' : count;
            el.style.display = '';
        } else {
            el.style.display = 'none';
        }
    }

    function pollBadges() {
        fetch('ajax/get_nav_badges.php')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;
                var counts = {
                    'orders.php': data.data.pending_orders,
                    'order_chat.php': data.data.chat_unread,
                    'deliveries.php': data.data.delivering_orders
                };
                var increased = false;
                Object.keys(counts).forEach(function (key) {
                    if (ALERT_KEYS.indexOf(key) !== -1 && counts[key] > (lastCounts[key] || 0)) increased = true;
                    updateBadge(key, counts[key]);
                });
                if (increased) beep();
                lastCounts = counts;
            })
            .catch(function () {});
    }

    setInterval(pollBadges, 30000);
})();
</script>
