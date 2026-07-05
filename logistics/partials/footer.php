        </main>
    </div><!-- /.flex-1 -->
</div><!-- /.flex.h-screen -->

<!-- Design Ref: store-order-popup §3 — 점포 신규 주문 전역 팝업 알림 (화면 중앙, 빨강 배경) -->
<div id="newOrderToast" style="display:none;position:fixed;inset:0;z-index:60;align-items:center;justify-content:center;background:rgba(0,0,0,0.5)">
    <div style="width:30rem;max-width:90vw;border-radius:0.75rem;overflow:hidden;border:2px solid #991b1b;box-shadow:0 20px 45px rgba(0,0,0,0.4)">
        <div style="background:#b91c1c;color:#fff;padding:0.85rem 1.25rem;display:flex;align-items:center;justify-content:space-between">
            <span style="font-size:1.05rem;font-weight:700"><i class="fas fa-bell" style="margin-right:0.5rem"></i><span id="noToastTitle">새 점포 주문</span></span>
            <button type="button" onclick="lcCloseNewOrderToast()" style="color:#fff;opacity:0.85;background:none;border:none;cursor:pointer;font-size:1rem"><i class="fas fa-times"></i></button>
        </div>
        <div style="background:#dc2626;color:#fff;padding:1.25rem">
            <p id="noToastStore" style="font-size:1.25rem;font-weight:700;margin:0"></p>
            <p id="noToastMeta" style="font-size:0.9rem;color:#fee2e2;margin:0.35rem 0 0"></p>
        </div>
        <div style="background:#b91c1c;padding:0.85rem 1.25rem;display:flex;gap:0.5rem">
            <a id="noToastViewLink" href="#" onclick="lcAckNewOrderToast()"
               style="flex:1;text-align:center;padding:0.55rem 0;background:#fff;color:#b91c1c;font-size:0.9rem;font-weight:700;border-radius:0.375rem;text-decoration:none">주문 보기</a>
            <button type="button" onclick="lcCloseNewOrderToast()"
                    style="padding:0.55rem 1rem;background:#7f1d1d;color:#fff;font-size:0.9rem;border:none;border-radius:0.375rem;cursor:pointer">닫기</button>
        </div>
    </div>
</div>
<script>
(function() {
    var LC_BASE = '<?php echo LC_BASE; ?>';
    var BEEP_KEY = 'lc_last_beeped_order_id'; // 알림음 중복 방지용(시각적 팝업 차단 아님)
    var SNOOZE_KEY       = 'lc_popup_snooze_until';  // 이 시각(ms)까지는 재표시 안 함
    var SNOOZE_MAXID_KEY = 'lc_popup_snooze_maxid';  // 스누즈 당시 본 최대 주문 id
    var SNOOZE_MS = 5 * 60 * 1000;       // 닫기/주문보기 후 5분간 멈춤
    var POLL_MS = 60000;                 // 1분 폴링
    var audioUnlocked = false;
    var lastShownMaxId = 0;              // 현재 팝업이 가리키는 최대 주문 id

    // 알림음 자동재생 정책 대응 — 첫 사용자 상호작용 후 unlock (§3.4)
    function unlock() { audioUnlocked = true; document.removeEventListener('click', unlock); document.removeEventListener('keydown', unlock); }
    document.addEventListener('click', unlock);
    document.addEventListener('keydown', unlock);

    function beep() {
        if (!audioUnlocked) return;
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            var ctx = new Ctx();
            var osc = ctx.createOscillator(), gain = ctx.createGain();
            osc.type = 'sine'; osc.frequency.value = 880;
            gain.gain.setValueAtTime(0.12, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.18);
            osc.connect(gain); gain.connect(ctx.destination);
            osc.start(); osc.stop(ctx.currentTime + 0.18);
            osc.onended = function() { try { ctx.close(); } catch (e) {} };
        } catch (e) { /* 알림음 실패해도 팝업은 정상 (SC-5 best-effort) */ }
    }

    function getBeepWatermark() { return parseInt(localStorage.getItem(BEEP_KEY), 10) || 0; }
    function setBeepWatermark(id) { localStorage.setItem(BEEP_KEY, String(id)); }

    function hidePopup() { document.getElementById('newOrderToast').style.display = 'none'; }

    // pending 주문이 있는 한 계속 표시. 알림음만 진짜 신규(최대 id 증가)일 때 1회.
    function showPopup(orders, isNew) {
        var first = orders[0];           // 서버가 id DESC 정렬 → 최신
        lastShownMaxId = first.id;
        var n = orders.length;
        document.getElementById('noToastTitle').textContent = (isNew ? '🔔 새 점포 주문 ' : '🔔 미확인 점포 주문 ') + n + '건';
        document.getElementById('noToastStore').textContent = first.store_name + (n > 1 ? ' 외 ' + (n - 1) + '건' : '');
        var amount = Math.round(first.total_amount).toLocaleString();
        document.getElementById('noToastMeta').textContent = first.order_no + ' · ' + amount + '원 · ' + first.item_count + '개 품목';
        document.getElementById('noToastViewLink').href = LC_BASE + '/orders.php?status=pending';
        document.getElementById('newOrderToast').style.display = 'flex';
        if (isNew) beep();
    }

    // 닫기/주문보기 시 일정 시간(SNOOZE_MS) 멈춤. 그 사이 새 주문이 오면 즉시 다시 알림(poll에서 처리).
    function snooze() {
        if (lastShownMaxId > 0) localStorage.setItem(SNOOZE_MAXID_KEY, String(lastShownMaxId));
        localStorage.setItem(SNOOZE_KEY, String(Date.now() + SNOOZE_MS));
    }
    // 닫기: 한동안 숨김. 스누즈 만료 후에도 pending 남아있으면 다시 표시.
    window.lcCloseNewOrderToast = function() { snooze(); hidePopup(); };
    // 주문 보기: 한동안 스누즈 후 orders.php 로 이동(기본 a href 진행).
    window.lcAckNewOrderToast = function() { snooze(); };

    function poll() {
        // since=0 → 현재 pending 주문 전체 조회. 닫아도 pending 남아있으면 계속 다시 띄운다.
        var url = LC_BASE + '/ajax/check_new_orders.php?since=0';
        fetch(url, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || !data.success) return;
                var orders = data.orders || [];
                if (orders.length === 0) { hidePopup(); return; }  // 모두 처리됨 → 팝업 종료
                var maxId  = orders[0].id;
                var isNew  = maxId > getBeepWatermark();

                // 스누즈: 닫기/주문보기 후 SNOOZE_MS 동안은 재표시 안 함.
                // 단, 스누즈 당시보다 새로운 주문(maxId 증가)이 들어오면 즉시 다시 알림.
                var snoozeUntil  = parseInt(localStorage.getItem(SNOOZE_KEY), 10) || 0;
                var snoozedMaxId = parseInt(localStorage.getItem(SNOOZE_MAXID_KEY), 10) || 0;
                if (Date.now() < snoozeUntil && maxId <= snoozedMaxId) { hidePopup(); return; }

                if (isNew) setBeepWatermark(maxId);
                showPopup(orders, isNew);
            })
            .catch(function() { /* 네트워크/세션오류 무시, 다음 주기 재시도 (NFR-3) */ });
    }

    poll();                              // 진입 즉시 1회
    setInterval(poll, POLL_MS);
})();
</script>
</body>
</html>
<?php ob_end_flush(); ?>
