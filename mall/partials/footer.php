    </main>
    <?php
    // 주문톡 푸시(FCM) 기기토큰 등록용 — 로그인된 페이지에서만 CSRF 토큰을 노출한다(비로그인 상태는
    // register_device_token.php가 401로 거부하므로 등록 시도 자체를 만들지 않는다).
    // require_once이므로 header.php가 이미 auth.php/csrf.php를 불러왔어도 중복 부작용 없음.
    require_once __DIR__ . '/../lib/auth.php';
    require_once __DIR__ . '/../lib/csrf.php';
    $__mall_push_csrf_token = mall_is_logged_in() ? mall_csrf_token() : null;
    ?>
    <?php if (empty($hide_mall_footer)): ?>
    <footer style="text-align:center;padding:var(--space-5) var(--space-5) var(--space-6);color:var(--label-assistive);font:var(--t-caption1) var(--font-sans);">
        &copy; <?php echo date('Y'); ?> HOME K MART
    </footer>
    <?php endif; ?>
</div>
<?php if (!empty($show_bottom_nav)): ?>
<?php include __DIR__ . '/bottom_nav.php'; ?>
<?php endif; ?>
<script>
// 공용 토스트 + "빠른 담기"(.add-to-cart-quick[data-product-id]) — 페이지 전체 공용.
function mallToast(message, linkHref, linkLabel) {
    document.querySelectorAll('.toast').forEach(function (t) { t.remove(); });
    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.innerHTML = '<span>' + message + '</span>' + (linkHref ? '<a href="' + linkHref + '">' + linkLabel + '</a>' : '');
    document.body.appendChild(toast);
    setTimeout(function () { toast.remove(); }, 2600);
}

function mallUpdateCartBadge(count) {
    // 상단(mall-topbar/pd-topbar)과 하단 탭바(bottom-nav) 장바구니 아이콘을 전부 갱신한다.
    // (href만으로 고르면 토스트 안의 "보기" 링크도 걸려서 .icon-btn/.bottom-nav-cart로 한정한다.)
    document.querySelectorAll('a[href="/mall/cart.php"].icon-btn, a[href="/mall/cart.php"].bottom-nav-cart').forEach(function (cartLink) {
        // 하단 탭바는 뱃지를 아이콘 감싸는 span(.bottom-nav-icon-wrap) 안에 붙이고,
        // 그 외(topbar)는 아이콘 버튼 자신에 바로 붙인다 — 위치 기준(position:relative)이 다르다.
        const badgeHost = cartLink.querySelector('.bottom-nav-icon-wrap') || cartLink;
        let badge = badgeHost.querySelector('.badge-count');
        if (count > 0) {
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'badge-count';
                badgeHost.appendChild(badge);
            }
            badge.textContent = count > 99 ? '99+' : count;
        } else if (badge) {
            badge.remove();
        }
    });
}

document.querySelectorAll('.add-to-cart-quick[data-product-id]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
        e.preventDefault();
        if (btn.disabled) return;
        btn.disabled = true;
        const params = new URLSearchParams();
        params.set('product_id', btn.dataset.productId);
        params.set('quantity', '1');
        fetch('/mall/ajax/add_to_cart.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.disabled = false;
                if (data.success) {
                    mallToast('장바구니에 담았습니다.', '/mall/cart.php', '보기');
                    mallUpdateCartBadge(data.data.cart_count);
                } else {
                    mallToast(data.error?.message || '오류가 발생했습니다.');
                }
            })
            .catch(function () { btn.disabled = false; mallToast('오류가 발생했습니다.'); });
    });
});

// 홈 화면 카드/재구매 행의 "담기" 버튼 ↔ 수량 스테퍼(오늘의특가/새상품/다시담을시간 공용, [data-pcard-action]).
document.querySelectorAll('[data-pcard-action]').forEach(function (box) {
    const addBtn = box.querySelector('.pcard-add-btn');
    const stepper = box.querySelector('.pcard-stepper');
    const qtyEl = stepper.querySelector('.qty');
    const productId = box.dataset.productId;
    const stock = parseInt(box.dataset.stock, 10) || 0;

    function showStepper(cartItemId, qty) {
        stepper.dataset.cartItemId = cartItemId;
        qtyEl.textContent = qty;
        addBtn.style.display = 'none';
        stepper.style.display = 'flex';
    }
    function showAddBtn() {
        addBtn.style.display = 'flex';
        stepper.style.display = 'none';
    }
    function updateQty(newQty) {
        const cartItemId = stepper.dataset.cartItemId;
        const params = new URLSearchParams();
        params.set('cart_item_id', cartItemId);
        params.set('quantity', newQty);
        fetch('/mall/ajax/update_cart_item.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) { mallToast(data.error?.message || '오류가 발생했습니다'); return; }
                if (newQty <= 0) { showAddBtn(); } else { qtyEl.textContent = newQty; }
                mallUpdateCartBadge(data.data.cart_count);
            });
    }

    addBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (addBtn.disabled) return;
        addBtn.disabled = true;
        const params = new URLSearchParams();
        params.set('product_id', productId);
        params.set('quantity', '1');
        fetch('/mall/ajax/add_to_cart.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                addBtn.disabled = false;
                if (data.success) {
                    showStepper(data.data.cart_item_id, data.data.quantity);
                    mallUpdateCartBadge(data.data.cart_count);
                } else {
                    mallToast(data.error?.message || '오류가 발생했습니다');
                }
            })
            .catch(function () { addBtn.disabled = false; });
    });
    stepper.querySelector('.pcard-inc').addEventListener('click', function (e) {
        e.preventDefault();
        const qty = parseInt(qtyEl.textContent, 10);
        if (stock > 0 && qty >= stock) return;
        updateQty(qty + 1);
    });
    stepper.querySelector('.pcard-dec').addEventListener('click', function (e) {
        e.preventDefault();
        const qty = parseInt(qtyEl.textContent, 10);
        updateQty(qty - 1);
    });
});

// 오늘의 특가 "오늘마감" 카운트다운(장식용 — 자정까지 남은 시간을 그냥 보여준다, 실제 마감 데이터는 없음).
document.querySelectorAll('[data-deal-timer-value]').forEach(function (el) {
    function tick() {
        const now = new Date();
        const midnight = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1, 0, 0, 0);
        const diff = Math.max(0, midnight - now);
        const h = String(Math.floor(diff / 3600000)).padStart(2, '0');
        const m = String(Math.floor((diff % 3600000) / 60000)).padStart(2, '0');
        const s = String(Math.floor((diff % 60000) / 1000)).padStart(2, '0');
        el.textContent = h + ':' + m + ':' + s;
    }
    tick();
    setInterval(tick, 1000);
});

// 안드로이드 앱(Capacitor) 하드웨어 뒤로가기 버튼 — 기본값은 웹뷰 히스토리가 없으면
// 앱 자체가 종료돼버려서, 갈 곳이 없을 때는 종료 대신 앱을 백그라운드로 내린다.
(function () {
    if (!window.Capacitor || !window.Capacitor.isNativePlatform || !window.Capacitor.isNativePlatform()) return;
    var CapApp = window.Capacitor.Plugins && window.Capacitor.Plugins.App;
    if (!CapApp) return;
    CapApp.addListener('backButton', function (event) {
        if (event && event.canGoBack) {
            window.history.back();
        } else {
            CapApp.minimizeApp();
        }
    });
})();

// 주문톡 푸시(FCM) — 등록/수신/탭 딥링크
// Design Ref: mall-order-chat-push.design.md §6.2
(function () {
    if (!window.Capacitor || !window.Capacitor.isNativePlatform || !window.Capacitor.isNativePlatform()) return;
    var Push = window.Capacitor.Plugins && window.Capacitor.Plugins.PushNotifications;
    if (!Push) return;

    // 푸시 수신 후 앱이 이미 열려 있으면 서버의 읽지 않은 메시지 수를 다시
    // 조회해야 주문톡 배지가 즉시 갱신됩니다.
    var mallPushRefreshPending = false;
    function mallPlayDeliveryArrivalAlert() {
        var startArrivalMusic = null;
        try {
            var recorded = window.__mallArrivalAudio || (window.__mallArrivalAudio = new Audio('/mall/assets/delivery_arrived.wav?v=1'));
            recorded.currentTime = 0;
            recorded.onended = function () { if (startArrivalMusic) startArrivalMusic(); };
            var playback = recorded.play();
            if (playback && playback.catch) playback.catch(function () { if (startArrivalMusic) startArrivalMusic(); });
        } catch (e) {}
        window.mallStopDeliveryArrivalAlert = function () {
            if (window.__mallArrivalTimer) { clearInterval(window.__mallArrivalTimer); window.__mallArrivalTimer = null; }
            if (window.__mallArrivalContext) { window.__mallArrivalContext.close().catch(function () {}); window.__mallArrivalContext = null; }
        };
        window.mallStopDeliveryArrivalAlert();
        try {
            var MusicCtx = window.AudioContext || window.webkitAudioContext;
            if (MusicCtx) {
                var music = new MusicCtx();
                window.__mallArrivalContext = music;
                var notes = [659.25, 783.99, 987.77, 783.99];
                var playNote = function () {
                    var osc = music.createOscillator(), gain = music.createGain(), now = music.currentTime;
                    osc.frequency.value = notes[Math.floor(Math.random() * notes.length)];
                    gain.gain.setValueAtTime(0.08, now); gain.gain.exponentialRampToValueAtTime(0.001, now + 0.35);
                    osc.connect(gain).connect(music.destination); osc.start(now); osc.stop(now + 0.35);
                };
                startArrivalMusic = function () {
                    if (window.__mallArrivalTimer) return;
                    playNote(); window.__mallArrivalTimer = setInterval(playNote, 900);
                };
            }
        } catch (e) {}
        try {
            var AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (AudioCtx) {
                var ctx = new AudioCtx(), osc = ctx.createOscillator(), gain = ctx.createGain();
                osc.frequency.value = 880; gain.gain.setValueAtTime(0.18, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.6);
                osc.connect(gain).connect(ctx.destination); osc.start(); osc.stop(ctx.currentTime + 0.6);
            }
            if ('speechSynthesis' in window) {
                window.speechSynthesis.cancel();
                var utterance = new SpeechSynthesisUtterance('배달이 도착했습니다.');
                utterance.lang = 'ko-KR'; utterance.rate = 0.95;
                utterance.onend = function () { if (startArrivalMusic) startArrivalMusic(); };
                window.speechSynthesis.speak(utterance);
            }
        } catch (e) { /* 기기에서 음성 기능을 지원하지 않아도 알림 자체는 유지 */ }
    }
    window.mallPlayDeliveryArrivalAlert = mallPlayDeliveryArrivalAlert;
    // 배송완료 알림: 완료 전용 한국어 음성을 앱에 포함하고, 음성 재생이 차단된
    // 환경에서는 Web Speech API를 대체 수단으로 사용합니다.
    function mallPlayDeliveryCompletionAlert() {
        try {
            var recorded = window.__mallCompletionAudio || (window.__mallCompletionAudio = new Audio('/mall/assets/delivery_completed.wav?v=1'));
            recorded.currentTime = 0;
            var playback = recorded.play();
            if (playback && playback.catch) playback.catch(function () {});
        } catch (e) {}
        try {
            if ('speechSynthesis' in window) {
                window.speechSynthesis.cancel();
                var utterance = new SpeechSynthesisUtterance('배송이 완료되었습니다. 이용해 주셔서 감사합니다.');
                utterance.lang = 'ko-KR'; utterance.rate = 0.95;
                window.speechSynthesis.speak(utterance);
            }
        } catch (e) { /* 음성 기능 미지원 시 푸시 알림은 그대로 표시 */ }
    }
    window.mallPlayDeliveryCompletionAlert = mallPlayDeliveryCompletionAlert;
    // 배송시작 알림 음성
    function mallPlayDeliveryStartAlert() {
        try {
            var recorded = window.__mallStartAudio || (window.__mallStartAudio = new Audio('/mall/assets/delivery_started.wav?v=1'));
            recorded.currentTime = 0;
            var playback = recorded.play();
            if (playback && playback.catch) playback.catch(function () {});
        } catch (e) {}
        try {
            if ('speechSynthesis' in window) {
                window.speechSynthesis.cancel();
                var utterance = new SpeechSynthesisUtterance('배송이 시작되었습니다.');
                utterance.lang = 'ko-KR'; utterance.rate = 0.95;
                window.speechSynthesis.speak(utterance);
            }
        } catch (e) { /* 음성 기능 미지원 시 푸시 알림은 그대로 표시 */ }
    }
    window.mallPlayDeliveryStartAlert = mallPlayDeliveryStartAlert;
    var PushApp = window.Capacitor.Plugins && window.Capacitor.Plugins.App;
    if (PushApp) {
        PushApp.addListener('resume', function () {
            if (!mallPushRefreshPending) return;
            mallPushRefreshPending = false;
            window.location.reload();
        });
    }

    var MALL_PUSH_CSRF_TOKEN = <?php echo json_encode($__mall_push_csrf_token); ?>;
    // 비로그인 상태(로그인/회원가입 화면 등)에서는 등록/리스너를 아예 걸지 않는다 —
    // register_device_token.php가 401로 거부할 뿐 아니라, 로그인 전 권한 팝업을 띄우는 것도
    // 사용자 경험상 바람직하지 않다.
    if (!MALL_PUSH_CSRF_TOKEN) return;

    function mallRegisterDeviceToken(token) {
        // server.url 방식이라 페이지 이동마다 웹뷰 문서 전체가 새로 로드되어 이 리스너도 매번 다시
        // 걸린다 — 토큰이 지난번과 같으면 굳이 다시 POST하지 않는다(서버 upsert 자체는 멱등이지만
        // 불필요한 요청/쓰기를 줄임). localStorage 접근 실패(사생활 보호 모드 등)는 무시하고 등록은
        // 계속 진행한다(최악의 경우 중복 등록일 뿐 기능 손실은 없음).
        try {
            // Always re-register: the server-side token row can be removed by a
            // migration/restore or must be reassigned after a member change.
        } catch (e) { /* localStorage 접근 불가 — 매번 등록해도 안전(서버 upsert) */ }

        var params = new URLSearchParams();
        params.set('token', token);
        params.set('platform', 'android');
        params.set('csrf_token', MALL_PUSH_CSRF_TOKEN);
        fetch('/mall/ajax/register_device_token.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data && data.success) {
                try { window.localStorage && localStorage.setItem('mall_fcm_token', token); } catch (e) { /* 무시 */ }
            }
        }).catch(function () { /* 네트워크 실패는 무시 — 다음 페이지 로드/토큰 갱신 시 재시도됨 */ });
    }

    Push.addListener('registration', function (token) {
        if (token && token.value) mallRegisterDeviceToken(token.value);
    });
    Push.addListener('registrationError', function (err) {
        console.error('mall push registrationError', err);
    });

    // 포그라운드 수신 — 트레이 알림은 백그라운드/종료 상태에서만 OS가 자동 표시하므로(Android 표준
    // 동작), 여기서는 현재 열려있는 주문톡 채팅창과 같은 주문이면 조용히 새로고침만 한다.
    Push.addListener('pushNotificationReceived', function (notification) {
        mallPushRefreshPending = true;
        var orderId = notification && notification.data && notification.data.order_id;
        if (orderId && notification.data.type === 'delivery_status') {
            var notificationBody = (notification && (notification.body || notification.title)) || '';
            if (notification && notification.data) {
                notificationBody = notificationBody || notification.data.message || notification.data.body || '';
            }
            var deliveryMessages = ['배달시작', '배송이 시작되었습니다.', '배달도착', '배달이 도착했습니다.', '배송이 완료되었습니다. 이용해 주셔서 감사합니다.'];
            var message = deliveryMessages.indexOf(notificationBody) !== -1 ? notificationBody : '배송 상태가 변경되었습니다.';
            mallToast(message, '/mall/order_detail.php?id=' + encodeURIComponent(orderId), '주문 보기');
            if (notificationBody === '배달이 도착했습니다.' || notificationBody === '배달도착') mallPlayDeliveryArrivalAlert();
            if (notificationBody === '배송이 완료되었습니다. 이용해 주셔서 감사합니다.') mallPlayDeliveryCompletionAlert();
            if (notificationBody === '배달시작' || notificationBody === '배송이 시작되었습니다.') mallPlayDeliveryStartAlert();
        }
        if (orderId && window.ORDER_ID && Number(orderId) === Number(window.ORDER_ID)
            && typeof window.mallOrderChatRefresh === 'function') {
            window.mallOrderChatRefresh();
        }
    });

    // 알림 탭(백그라운드/종료 상태에서 눌러 앱 진입) — 해당 주문 채팅창으로 딥링크.
    // order_chat.php는 로그인 필수(mall_require_login)라 세션 만료 시에는 로그인 화면으로 안전하게 빠진다.
    Push.addListener('pushNotificationActionPerformed', function (action) {
        var orderId = action && action.notification && action.notification.data && action.notification.data.order_id;
        if (orderId) {
            var isDelivery = action.notification.data.type === 'delivery_status';
            var actionBody = (action.notification.body || (action.notification.data && (action.notification.data.message || action.notification.data.body)) || '');
            var arrival = isDelivery && (actionBody === '배달이 도착했습니다.' || actionBody === '배달도착');
            var completion = isDelivery && actionBody === '배송이 완료되었습니다. 이용해 주셔서 감사합니다.';
            var started = isDelivery && (actionBody === '배달시작' || actionBody === '배송이 시작되었습니다.');
            var alertQuery = arrival ? '&arrival_alert=1' : (completion ? '&completion_alert=1' : (started ? '&start_alert=1' : ''));
            window.location.href = (isDelivery ? '/mall/order_detail.php?id=' : '/mall/order_chat.php?order_id=') + encodeURIComponent(orderId) + alertQuery;
        }
    });

    Push.requestPermissions().then(function (res) {
        if (res && res.receive === 'granted') {
            Push.register();
        }
    }).catch(function (err) {
        console.error('mall push requestPermissions error', err);
    });
})();
</script>
</body>
</html>
