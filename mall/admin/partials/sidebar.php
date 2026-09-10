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
require_once __DIR__ . '/../../../lib/lang_helper.php';
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
    ['href' => 'dashboard.php', 'icon' => 'fa-gauge', 'label' => t('mall_admin.nav.dashboard')],
    ['href' => 'home_layout.php', 'icon' => 'fa-swatchbook', 'label' => t('mall_admin.nav.home_layout')],
    ['href' => 'products.php', 'icon' => 'fa-box', 'label' => t('mall_admin.nav.products')],
    // 도매 상품 노출(wholesale_products.php)은 메뉴에서만 숨김 — 기능/페이지는 그대로 남아있어
    // 필요해지면 이 줄만 되살리면 된다(URL 직접 접근으로는 여전히 사용 가능).
    ['href' => 'members.php', 'icon' => 'fa-users', 'label' => t('mall_admin.nav.members')],
    ['href' => 'discount_rules.php', 'icon' => 'fa-percent', 'label' => t('mall_admin.nav.settings')],
    ['href' => 'orders.php', 'icon' => 'fa-receipt', 'label' => t('mall_admin.nav.orders'), 'badge' => $__mall_pending_orders],
    ['href' => 'order_chat.php', 'icon' => 'fa-comments', 'label' => t('mall_admin.nav.order_chat'), 'badge' => $__mall_chat_unread_total],
    ['href' => 'deliveries.php', 'icon' => 'fa-truck-fast', 'label' => t('mall_admin.nav.deliveries'), 'badge' => $__mall_delivering_orders],
    ['href' => 'drivers.php', 'icon' => 'fa-motorcycle', 'label' => t('mall_admin.nav.drivers')],
    ['href' => 'delivery_map.php', 'icon' => 'fa-map-location-dot', 'label' => t('mall_admin.nav.delivery_map')],
];
$__mall_current_lang = get_language();
?>
<div class="bg-white border-b border-gray-200">
    <div class="flex items-center justify-between gap-4 px-4 py-2 flex-wrap">
        <div class="flex items-center gap-4 flex-wrap">
            <div class="font-bold text-sm text-gray-800 flex-shrink-0 whitespace-nowrap"><i class="fas fa-store mr-1.5"></i><?php echo t('mall_admin.title'); ?></div>
            <nav class="flex items-center gap-1 flex-wrap">
                <?php foreach ($__mall_nav_items as $__item): ?>
                    <a href="<?php echo $__item['href']; ?>"
                       class="flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium rounded-md whitespace-nowrap transition-colors <?php echo ($current_page ?? '') === $__item['href'] ? 'bg-blue-100 text-blue-800' : 'text-gray-600 hover:bg-gray-100'; ?>">
                        <i class="fas <?php echo $__item['icon']; ?>"></i><?php echo htmlspecialchars($__item['label']); ?>
                        <?php if (isset($__item['badge'])): ?>
                            <span class="mall-nav-badge px-1.5 py-0.5 rounded-full bg-red-600 text-white text-[10px] font-bold leading-none" data-badge-key="<?php echo htmlspecialchars($__item['href']); ?>" style="<?php echo $__item['badge'] > 0 ? '' : 'display:none;'; ?>"><?php echo $__item['badge'] > 99 ? '99+' : $__item['badge']; ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
        <div class="flex items-center gap-3 flex-shrink-0">
            <div id="mall-lang-toggle" class="flex items-center rounded-md border border-gray-200 overflow-hidden flex-shrink-0" role="group" aria-label="Language">
                <button type="button" class="mall-lang-btn px-2 py-1 text-[11px] font-semibold whitespace-nowrap <?php echo $__mall_current_lang === 'ko' ? 'bg-blue-600 text-white' : 'bg-white text-gray-500 hover:bg-gray-100'; ?>" data-lang="ko">한국어</button>
                <button type="button" class="mall-lang-btn px-2 py-1 text-[11px] font-semibold whitespace-nowrap <?php echo $__mall_current_lang === 'en' ? 'bg-blue-600 text-white' : 'bg-white text-gray-500 hover:bg-gray-100'; ?>" data-lang="en">English</button>
            </div>
            <a href="https://homekmart.net/" class="text-xs text-gray-500 hover:text-gray-700 flex-shrink-0 whitespace-nowrap"><i class="fas fa-arrow-left mr-1"></i><?php echo t('mall_admin.back_to_home'); ?></a>
        </div>
    </div>
</div>
<script>
window.MALL_CSRF_TOKEN = <?php echo json_encode($__mall_csrf_token); ?>;

// 상단 메뉴 끝쪽 한/영 전환 토글 — admin/ajax_set_language.php(기존 admin 언어 스위처와 동일 세션/엔드포인트)를
// 그대로 재사용한다. mall/admin은 lib/session_helper.php로 admin과 같은 세션을 쓰므로 언어 설정도 공유된다.
document.querySelectorAll('.mall-lang-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var lang = btn.dataset.lang;
        if (btn.classList.contains('bg-blue-600')) return;
        fetch('../../admin/ajax_set_language.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'language=' + encodeURIComponent(lang)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) { window.location.reload(); }
        })
        .catch(function () {});
    });
});

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
    var lastChatMessageId = null;
    var audioContext = null;
    var voiceAlerts = {
        '고객님 메시지': new Audio('/mall/assets/audio/message.wav'),
        '주문이 접수되었습니다': new Audio('/mall/assets/audio/order_received.wav')
    };
    Object.keys(voiceAlerts).forEach(function (key) {
        voiceAlerts[key].preload = 'auto';
    });

    // Browsers allow sound/notification permission only after a user gesture.
    // The first click in the admin screen unlocks audio and asks once for permission.
    function enableRealtimeAlerts() {
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (Ctx && !audioContext) audioContext = new Ctx();
            if (audioContext && audioContext.state === 'suspended') audioContext.resume().catch(function () {});
        } catch (e) {}
        Object.keys(voiceAlerts).forEach(function (key) {
            var audio = voiceAlerts[key];
            audio.volume = 0;
            var unlocked = audio.play();
            if (unlocked && unlocked.then) {
                unlocked.then(function () {
                    audio.pause();
                    audio.currentTime = 0;
                    audio.volume = 1;
                }).catch(function () { audio.volume = 1; });
            }
        });
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission().catch(function () {});
        }
    }
    document.addEventListener('pointerdown', enableRealtimeAlerts, { once: true });

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
            var ctx = audioContext || new Ctx();
            audioContext = ctx;
            var play = function () { playTone(ctx, 0); playTone(ctx, 0.4); };
            if (ctx.state === 'suspended' && ctx.resume) {
                ctx.resume().then(play).catch(function () {});
            } else {
                play();
            }
        } catch (e) {}
    }

    function speakAlert(text) {
        var recorded = voiceAlerts[text];
        if (recorded) {
            recorded.pause();
            recorded.currentTime = 0;
            recorded.volume = 1;
            var playback = recorded.play();
            if (playback && playback.catch) playback.catch(function () {});
            return;
        }
        try {
            if (!('speechSynthesis' in window) || !('SpeechSynthesisUtterance' in window)) return;
            var utterance = new SpeechSynthesisUtterance(text);
            utterance.lang = 'ko-KR';
            utterance.rate = 0.92;
            utterance.pitch = 1.05;
            utterance.volume = 1;
            var koreanVoice = window.speechSynthesis.getVoices().find(function (voice) {
                return /^ko(?:-|_)/i.test(voice.lang || '');
            });
            if (koreanVoice) utterance.voice = koreanVoice;
            window.speechSynthesis.speak(utterance);
        } catch (e) {}
    }

    function notifyChat(chat) {
        if (!chat) return;
        beep();
        window.setTimeout(function () { speakAlert('고객님 메시지'); }, 550);
        if (!('Notification' in window) || Notification.permission !== 'granted') return;
        var sender = chat.sender_type === 'driver' ? '배송기사' : (chat.member_name || '고객');
        var notification = new Notification('새 주문톡 · ' + sender, {
            body: (chat.order_number ? chat.order_number + '\n' : '') + (chat.message || '새 메시지가 도착했습니다.'),
            icon: '/logo/homekmart_logo.png',
            tag: 'mall-order-chat-' + chat.id
        });
        notification.onclick = function () {
            window.focus();
            window.location.href = 'order_chat.php?order_id=' + encodeURIComponent(chat.order_id);
            notification.close();
        };
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
                var orderIncreased = counts['orders.php'] > (lastCounts['orders.php'] || 0);
                var increased = false;
                Object.keys(counts).forEach(function (key) {
                    if (ALERT_KEYS.indexOf(key) !== -1 && counts[key] > (lastCounts[key] || 0)) increased = true;
                    updateBadge(key, counts[key]);
                });
                var latestChat = data.data.latest_chat || null;
                var chatAlerted = false;
                if (lastChatMessageId === null) {
                    // Establish a baseline so old unread messages do not alert on page load.
                    lastChatMessageId = latestChat ? Number(latestChat.id) : 0;
                } else if (latestChat && Number(latestChat.id) > lastChatMessageId) {
                    lastChatMessageId = Number(latestChat.id);
                    notifyChat(latestChat);
                    chatAlerted = true;
                }
                if (orderIncreased) {
                    beep();
                    window.setTimeout(function () { speakAlert('주문이 접수되었습니다'); }, 550);
                } else if (increased && !chatAlerted) {
                    beep();
                }
                lastCounts = counts;
            })
            .catch(function () {});
    }

    pollBadges();
    setInterval(pollBadges, 5000);
})();
</script>
