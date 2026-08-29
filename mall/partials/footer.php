    </main>
    <?php
    $__lang_qs_ko = array_merge($_GET, ['lang' => 'ko']);
    $__lang_qs_en = array_merge($_GET, ['lang' => 'en']);
    ?>
    <footer style="text-align:center;padding:var(--space-5) var(--space-5) var(--space-6);color:var(--label-assistive);font:var(--t-caption1) var(--font-sans);">
        &copy; <?php echo date('Y'); ?> HOME K MART ·
        <a href="?<?php echo htmlspecialchars(http_build_query($__lang_qs_ko)); ?>" style="color:<?php echo ($mall_lang ?? 'en') === 'ko' ? 'var(--label-normal)' : 'var(--label-assistive)'; ?>;font-weight:<?php echo ($mall_lang ?? 'en') === 'ko' ? '700' : '500'; ?>;">한국어</a>
        ·
        <a href="?<?php echo htmlspecialchars(http_build_query($__lang_qs_en)); ?>" style="color:<?php echo ($mall_lang ?? 'en') === 'en' ? 'var(--label-normal)' : 'var(--label-assistive)'; ?>;font-weight:<?php echo ($mall_lang ?? 'en') === 'en' ? '700' : '500'; ?>;">English</a>
    </footer>
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
</script>
</body>
</html>
