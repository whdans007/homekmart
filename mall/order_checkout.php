<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/cart.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/address.php';

mall_require_login('/mall/login.php');
$member = mall_current_member();
$csrf_token = mall_csrf_token();

$summary = mall_cart_get_summary($member['id'], null, $member);
$channel = ($member['member_type'] === 'wholesale') ? 'wholesale' : 'retail';

$addresses = mall_address_list($member['id']);
$default_address = $addresses[0] ?? null; // mall_address_list()가 is_default DESC로 정렬해 첫 번째가 기본 배송지

$shipping_fee = mall_calculate_shipping_fee($summary['subtotal']);
$total_with_shipping = round($summary['total'] + $shipping_fee, 2);

$mall_redesigned = true;
$mall_show_back = true;
$show_bottom_nav = true;
$page_title = '주문서 작성';
require_once __DIR__ . '/partials/header.php';
?>
<style>
.co-card { background: var(--bg-normal); border: 1px solid var(--line-alternative); border-radius: var(--radius-lg); padding: var(--space-4); margin: var(--space-4) var(--space-5) 0; }
.co-card h2 { font: var(--t-headline2) var(--font-sans); margin: 0 0 var(--space-3); }
.co-item-row { display: flex; justify-content: space-between; font: var(--t-label2) var(--font-sans); color: var(--label-neutral); padding: 6px 0; }
.co-radio-row { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--line-alternative); font: var(--t-label1) var(--font-sans); }
.co-radio-row:last-child { border-bottom: none; }
.co-radio-row input[type="radio"] { width: 18px; height: 18px; accent-color: var(--primary-normal); }
.co-toggle-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 0; }
</style>

<h1 style="font:var(--t-heading2) var(--font-sans);padding:var(--space-4) var(--space-5) 0;">주문서 작성</h1>

<?php if (empty($summary['items'])): ?>
    <p class="empty-state">장바구니가 비어있습니다.<br><a href="/mall/index.php" style="color:var(--primary-normal);font-weight:700;">쇼핑하러 가기</a></p>
<?php else: ?>

<div id="checkout-result"></div>

<div id="checkout-form-area">
    <div class="co-card">
        <h2>배송지</h2>
        <?php if ($default_address): ?>
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
            <div style="font:var(--t-label2) var(--font-sans);color:var(--label-normal);">
                <div style="font-weight:700;"><?php echo htmlspecialchars($default_address['recipient_name']); ?> · <?php echo htmlspecialchars($default_address['phone']); ?></div>
                <div style="color:var(--label-alternative);margin-top:2px;">
                    <?php echo htmlspecialchars(trim(implode(' ', array_filter([$default_address['region'], $default_address['city'], $default_address['barangay'], $default_address['detail_address']])))); ?><br>
                    랜드마크: <?php echo htmlspecialchars($default_address['landmark']); ?>
                </div>
            </div>
            <a href="/mall/address.php" style="font:700 13px var(--font-sans);color:var(--primary-normal);flex-shrink:0;">변경</a>
        </div>
        <?php else: ?>
        <div style="display:flex;align-items:center;justify-content:space-between;">
            <div style="font:var(--t-label2) var(--font-sans);color:var(--label-assistive);">등록된 배송지가 없습니다</div>
            <a href="/mall/address.php?add=1" style="font:700 13px var(--font-sans);color:var(--primary-normal);">배송지 추가</a>
        </div>
        <?php endif; ?>
    </div>

    <div class="co-card">
        <h2>배송 방법</h2>
        <label class="co-radio-row"><input type="radio" name="ship" checked> 일반배송 (기본 배송비 <?php echo number_format(MALL_BASE_SHIPPING_FEE); ?>, <?php echo number_format(MALL_FREE_SHIPPING_THRESHOLD); ?> 이상 무료)</label>
        <label class="co-radio-row disabled-option"><input type="radio" name="ship" disabled> 당일배송 (Makati·BGC·Ortigas) <span class="tag-soon">준비중</span></label>
        <label class="co-radio-row disabled-option"><input type="radio" name="ship" disabled> 픽업 <span class="tag-soon">준비중</span></label>
    </div>

    <div class="co-card">
        <h2>결제 수단</h2>
        <label class="co-radio-row"><input type="radio" name="pay" checked> 착불(현장결제, COD)</label>
        <label class="co-radio-row disabled-option"><input type="radio" name="pay" disabled> GCash <span class="tag-soon">준비중</span></label>
        <label class="co-radio-row disabled-option"><input type="radio" name="pay" disabled> Maya <span class="tag-soon">준비중</span></label>
        <label class="co-radio-row disabled-option"><input type="radio" name="pay" disabled> 카드 <span class="tag-soon">준비중</span></label>
        <label class="co-radio-row disabled-option"><input type="radio" name="pay" disabled> 계좌이체 <span class="tag-soon">준비중</span></label>
    </div>

    <div class="co-card">
        <div class="co-toggle-row">
            <span style="font:var(--t-label1) var(--font-sans);">포인트 사용 <span class="tag-soon">준비중</span></span>
            <input type="checkbox" disabled>
        </div>
    </div>

    <div class="co-card">
        <h2>주문 요약</h2>
        <?php foreach ($summary['items'] as $item): ?>
            <div class="co-item-row"><span><?php echo htmlspecialchars($item['display_name']); ?> × <?php echo (int)$item['quantity']; ?></span><span><?php echo number_format($item['line_total'], 2); ?></span></div>
        <?php endforeach; ?>
        <div style="border-top:1px solid var(--line-normal);margin-top:8px;padding-top:8px;text-align:right;">
            <div style="font:var(--t-caption1) var(--font-sans);color:var(--label-alternative);">소계 <?php echo number_format($summary['subtotal'], 2); ?></div>
            <div style="font:var(--t-caption1) var(--font-sans);color:var(--brand-red);">할인 -<?php echo number_format($summary['discount_amount'], 2); ?></div>
            <div style="font:var(--t-caption1) var(--font-sans);color:var(--label-alternative);">배송비 <?php echo $shipping_fee > 0 ? number_format($shipping_fee, 2) : '무료'; ?></div>
            <div style="font:700 18px var(--font-sans);margin-top:4px;">합계 <?php echo number_format($total_with_shipping, 2); ?></div>
            <div style="font:var(--t-caption1) var(--font-sans);color:var(--label-assistive);margin-top:4px;">표시 가격은 VAT 12% 포함가입니다.</div>
        </div>
    </div>

    <div class="co-card">
        <h2 style="font:var(--t-label1) var(--font-sans);">요청사항 (선택)</h2>
        <textarea id="order-memo" rows="3" style="width:100%;border:1px solid var(--line-normal);border-radius:var(--radius-sm);padding:8px;font:var(--t-label2) var(--font-sans);"></textarea>
    </div>
</div>

<div class="sticky-cta">
    <button id="submit-order-btn" class="btn btn-primary btn-block">주문 확정 (착불)</button>
</div>

<script>
document.getElementById('submit-order-btn').addEventListener('click', function () {
    const btn = this;
    btn.disabled = true;
    btn.textContent = '처리중...';

    const params = new URLSearchParams();
    params.set('channel', '<?php echo $channel; ?>');
    params.set('memo', document.getElementById('order-memo').value);
    params.set('csrf_token', '<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>');

    fetch('/mall/ajax/submit_order.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.href = '/mall/order_done.php?id=' + data.data.order_id;
            } else {
                document.getElementById('checkout-result').innerHTML = '<div class="wholesale-notice" style="margin:0 var(--space-5) var(--space-3);color:var(--brand-red);">' + (data.error?.message || '주문 처리 중 오류가 발생했습니다') + '</div>';
                btn.disabled = false;
                btn.textContent = '주문 확정 (착불)';
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.textContent = '주문 확정 (착불)';
        });
});
</script>

<?php endif; ?>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
