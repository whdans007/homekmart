<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/cart.php';
require_once __DIR__ . '/lib/fresh_cart.php';
require_once __DIR__ . '/lib/csrf.php';

// 장바구니는 회원가입 없이도 볼 수 있다(주문 시에만 로그인 필요) — 하단 "주문하기" CTA에서만 로그인을 요구한다.
$member = mall_current_member();
$member_id = $member ? $member['id'] : null;
$guest_token = $member ? null : mall_guest_token();

$summary = mall_cart_get_summary($member_id, $guest_token, $member);
$fresh_summary = mall_fresh_cart_get_summary($member_id, $guest_token);
$combined_subtotal = round($summary['subtotal'] + $fresh_summary['subtotal'], 2);
$combined_total = round($summary['total'] + $fresh_summary['subtotal'], 2);
$csrf_token = mall_csrf_token();
$channel = ($member && $member['member_type'] === 'wholesale') ? 'wholesale' : 'retail';

$applied_instant_rate = 0.0;
$next_instant_tier = null;
if ($channel === 'wholesale' && $summary['subtotal'] > 0) {
    $applied_instant_rate = mall_get_wholesale_instant_discount_rate($summary['subtotal']);
    $next_instant_tier = mall_get_next_wholesale_instant_tier($summary['subtotal']);
}

// 무료배송 진행바(참고용 안내) — retail 채널에서만 표시
$free_shipping_progress = null;
if ($channel === 'retail') {
    $remaining = MALL_FREE_SHIPPING_THRESHOLD - $combined_subtotal;
    $free_shipping_progress = [
        'pct' => max(0, min(100, round($combined_subtotal / MALL_FREE_SHIPPING_THRESHOLD * 100))),
        'remaining' => max(0, $remaining),
        'reached' => $remaining <= 0,
    ];
}

$page_title = '장바구니';
$mall_redesigned = true;
$show_bottom_nav = true;
$active_nav = 'cart';
require_once __DIR__ . '/partials/header.php';
?>
<style>
.cart-row { display: flex; align-items: center; gap: var(--space-3); padding: var(--space-4) 0; border-bottom: 1px solid var(--line-alternative); }
.cart-row .thumb { width: 76px; height: 76px; border-radius: var(--radius-md); object-fit: cover; background: var(--fill-normal); border: 1px dotted var(--line-normal); flex-shrink: 0; }
.cart-row .info { flex: 1; min-width: 0; }
.cart-row .name { font: 600 15px/1.4 var(--font-sans); margin-bottom: 4px; }
.cart-row .unit-price { font: var(--t-caption1) var(--font-sans); color: var(--label-alternative); }
.cart-row .row-bottom { display: flex; align-items: center; justify-content: space-between; margin-top: 8px; }
.cart-row .line-total { font: 700 15px var(--font-sans); }
.cart-row .remove-btn { background: none; border: none; color: var(--label-assistive); cursor: pointer; padding: 4px; }
.shipping-progress { margin: var(--space-4) var(--space-5) 0; padding: var(--space-3) var(--space-4); border-radius: var(--radius-md); background: var(--bg-alternative); }
.shipping-progress .track { height: 6px; border-radius: 999px; background: var(--fill-strong); overflow: hidden; margin-top: 8px; }
.shipping-progress .fill { height: 100%; background: var(--primary-normal); }
.summary-card { margin: var(--space-4) var(--space-5) 0; }
.summary-row { display: flex; justify-content: space-between; font: var(--t-label1) var(--font-sans); color: var(--label-neutral); padding: 4px 0; }
.summary-row.total { border-top: 1px solid var(--line-normal); margin-top: 8px; padding-top: 12px; font: 700 18px var(--font-sans); color: var(--label-normal); }
</style>

<h1 style="font:var(--t-heading2) var(--font-sans);padding:var(--space-4) var(--space-5) 0;">장바구니</h1>

<?php if (empty($summary['items']) && empty($fresh_summary['items'])): ?>
    <p class="empty-state">장바구니가 비어있습니다.<br><a href="/mall/index.php" style="color:var(--primary-normal);font-weight:700;">쇼핑하러 가기</a></p>
<?php else: ?>

<?php if ($free_shipping_progress): ?>
<div class="shipping-progress">
    <?php if ($free_shipping_progress['reached']): ?>
        <span style="font:700 13px var(--font-sans);color:var(--brand-green);"><i class="fas fa-check-circle"></i> 무료배송 조건을 달성했어요!</span>
    <?php else: ?>
        <span style="font:var(--t-label2) var(--font-sans);color:var(--label-neutral);"><strong style="color:var(--primary-normal);"><?php echo number_format($free_shipping_progress['remaining'], 2); ?></strong> 더 담으면 무료배송(기본 배송비 <?php echo number_format(MALL_BASE_SHIPPING_FEE); ?>)</span>
    <?php endif; ?>
    <div class="track"><div class="fill" style="width:<?php echo $free_shipping_progress['pct']; ?>%;"></div></div>
</div>
<?php endif; ?>

<div class="section">
<div id="cart-body">
<?php foreach ($summary['items'] as $item): ?>
    <div class="cart-row" data-cart-item-id="<?php echo (int)$item['cart_item_id']; ?>" data-qty="<?php echo (int)$item['quantity']; ?>" data-stock="<?php echo (int)$item['stock']; ?>">
        <img class="thumb" src="<?php echo $item['image_path'] ? '/mall/' . htmlspecialchars($item['image_path']) : '/logo/homekmart_logo.png'; ?>" alt="">
        <div class="info">
            <div class="name"><?php echo htmlspecialchars($item['display_name']); ?></div>
            <?php if ($item['out_of_stock']): ?>
                <div style="color:var(--brand-red);font:var(--t-caption1) var(--font-sans);">재고 부족(재고 <?php echo (int)$item['stock']; ?>개) — 수량을 조정해주세요</div>
            <?php else: ?>
                <div class="unit-price"><?php echo number_format($item['price']['final_price'] ?? 0, 2); ?> / 개</div>
            <?php endif; ?>
            <div class="row-bottom">
                <div class="qty-stepper qty-stepper-sm">
                    <button type="button" class="qty-minus"><svg><use href="#i-minus"></use></svg></button>
                    <input type="number" class="qty-value" inputmode="numeric" min="1" <?php echo ((int)$item['stock'] > 0) ? 'max="' . (int)$item['stock'] . '"' : ''; ?> value="<?php echo (int)$item['quantity']; ?>">
                    <button type="button" class="qty-plus"><svg><use href="#i-plus"></use></svg></button>
                </div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <div class="line-total"><?php echo number_format($item['line_total'], 2); ?></div>
                    <button class="remove-btn" title="삭제"><svg style="width:18px;height:18px;"><use href="#i-close"></use></svg></button>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<?php if (!empty($fresh_summary['items'])): ?>
    <h2 style="font:var(--t-headline2) var(--font-sans);margin:var(--space-5) 0 0;">신선상품</h2>
    <?php foreach ($fresh_summary['items'] as $item): ?>
    <div class="cart-row fresh-cart-row" data-cart-item-id="<?php echo (int)$item['cart_item_id']; ?>">
        <img class="thumb" src="<?php echo htmlspecialchars($item['image_url'] ?: '/logo/homekmart_logo.png'); ?>" alt="">
        <div class="info">
            <div class="name"><span class="badge badge-green">신선</span> <?php echo htmlspecialchars(($mall_lang === 'en' && !empty($item['name_en'])) ? $item['name_en'] : $item['name_ko']); ?></div>
            <?php if ($item['sold_out']): ?>
                <div style="color:var(--brand-red);font:var(--t-caption1) var(--font-sans);">품절 — 삭제 후 주문해주세요</div>
            <?php elseif ($item['sale_type'] === 'weight'): ?>
                <div class="unit-price"><?php echo number_format($item['unit_price'], 2); ?> / 100g · <?php echo (int)$item['weight_g']; ?>g</div>
                <div class="unit-price">예상금액이며 실제 무게에 따라 달라질 수 있습니다.</div>
            <?php else: ?>
                <div class="unit-price"><?php echo number_format($item['unit_price'], 2); ?> / 개 · <?php echo (int)$item['quantity']; ?>개</div>
            <?php endif; ?>
            <div class="row-bottom">
                <span class="badge <?php echo $item['sale_type'] === 'weight' ? 'badge-blue' : 'badge-green'; ?>"><?php echo $item['sale_type'] === 'weight' ? '무게상품' : '낱개상품'; ?></span>
                <div style="display:flex;align-items:center;gap:10px;">
                    <div class="line-total"><?php echo $item['sale_type'] === 'weight' ? '예상 ' : ''; ?><?php echo number_format($item['estimated_price'], 2); ?></div>
                    <button class="remove-btn fresh-remove-btn" title="삭제"><svg style="width:18px;height:18px;"><use href="#i-close"></use></svg></button>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
</div>
</div>

<?php if ($channel === 'wholesale'): ?>
<div class="notice-banner" id="instant-discount-notice">
    <?php if ($applied_instant_rate > 0): ?>
        현재 <strong><?php echo rtrim(rtrim(number_format($applied_instant_rate, 2), '0'), '.'); ?>%</strong> 즉석할인이 적용중입니다.
    <?php else: ?>
        현재 적용 중인 즉석할인이 없습니다.
    <?php endif; ?>
    <?php if ($next_instant_tier): ?>
        <?php echo number_format($next_instant_tier['amount_remaining'], 2); ?>원 더 담으면
        <strong><?php echo rtrim(rtrim(number_format($next_instant_tier['discount_rate'], 2), '0'), '.'); ?>%</strong> 할인 구간에 도달합니다.
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="summary-card">
    <div class="summary-row"><span>소계</span><span id="cart-subtotal"><?php echo number_format($combined_subtotal, 2); ?></span></div>
    <div class="summary-row" style="color:var(--brand-red);"><span>할인</span><span>-<span id="cart-discount"><?php echo number_format($summary['discount_amount'], 2); ?></span></span></div>
    <div class="summary-row total"><span>예상 합계</span><span id="cart-total"><?php echo number_format($combined_total, 2); ?></span></div>
</div>

<div class="sticky-cta">
    <a href="/mall/order_checkout.php" class="btn btn-primary btn-block">주문하기</a>
</div>
<?php endif; ?>

<script>
function mallUpdateCartQty(row, qty) {
    const stock = parseInt(row.dataset.stock, 10);
    qty = Math.max(1, qty || 1);
    if (stock > 0 && qty > stock) qty = stock;
    const params = new URLSearchParams();
    params.set('cart_item_id', row.dataset.cartItemId);
    params.set('quantity', qty);
    fetch('/mall/ajax/update_cart_item.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
        .then(r => r.json())
        .then(() => window.location.reload());
}

document.querySelectorAll('.cart-row:not(.fresh-cart-row) .qty-minus, .cart-row:not(.fresh-cart-row) .qty-plus').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const row = btn.closest('.cart-row');
        let qty = parseInt(row.dataset.qty, 10);
        qty = btn.classList.contains('qty-plus') ? qty + 1 : qty - 1;
        if (qty < 1) return;
        mallUpdateCartQty(row, qty);
    });
});

document.querySelectorAll('.cart-row:not(.fresh-cart-row) .qty-value').forEach(function (input) {
    input.addEventListener('change', function () {
        const row = input.closest('.cart-row');
        const qty = parseInt(input.value, 10);
        if (!qty || qty === parseInt(row.dataset.qty, 10)) {
            input.value = row.dataset.qty;
            return;
        }
        mallUpdateCartQty(row, qty);
    });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') input.blur();
    });
});

document.querySelectorAll('.cart-row:not(.fresh-cart-row) .remove-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const row = btn.closest('.cart-row');
        const params = new URLSearchParams();
        params.set('cart_item_id', row.dataset.cartItemId);
        fetch('/mall/ajax/remove_cart_item.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(r => r.json())
            .then(() => window.location.reload());
    });
});

document.querySelectorAll('.fresh-cart-row .fresh-remove-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const row = btn.closest('.fresh-cart-row');
        const params = new URLSearchParams();
        params.set('cart_item_id', row.dataset.cartItemId);
        params.set('csrf_token', '<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>');
        fetch('/mall/ajax/remove_fresh_cart_item.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(r => r.json()).then(data => data.success ? window.location.reload() : mallToast(data.error?.message || '삭제하지 못했습니다.'));
    });
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
