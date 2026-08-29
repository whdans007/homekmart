<?php
/**
 * 오늘의 특가 가로 스크롤 카드 — 디자인 원본(App.dc.html isHome §deals) 그대로:
 * 이미지 위 할인뱃지(좌상단)+영문명 플레이트(좌하단), 이름 2줄, 가격+정가취소선 한 줄,
 * 담기 버튼(미담김) / 수량 스테퍼(담김) 컨트롤.
 * 포함하는 쪽에서 $card(product_card.php와 동일한 형태), $__cart(mall_cart_get_quantities_by_product()의
 * 해당 상품 항목, 없으면 null), $__promo(상품별 프로모 설정 — 1plus1/percent/cost_sale, 없으면 null)를
 * 미리 정의해야 한다.
 */
$__c = $card;
$__price = $__c['price'];
$__out_of_stock = $__c['stock'] <= 0;
$__img = $__c['image_path'] ? '/mall/' . htmlspecialchars($__c['image_path']) : '/logo/homekmart_logo.png';
$__cart_qty = $__cart['quantity'] ?? 0;
$__cart_item_id = $__cart['cart_item_id'] ?? '';

// 프로모 뱃지 문구 — 1+1은 가격 변동 없이 문구만, 퍼센트/원가세일은 실제 판매가(selling_price_override)에
// 이미 반영되어 있어 $__price['final_price']가 곧 세일가다. "정가" 취소선은 프로모 적용 당시 저장해둔
// was(원래가)를 그대로 쓴다(등급 할인율로 다시 계산하지 않음 — 프로모와 등급 할인은 별개 개념).
$__promo_badge = null;
$__promo_was = null;
if ($__promo && !empty($__promo['type'])) {
    if ($__promo['type'] === '1plus1') {
        $__promo_badge = '1+1';
    } elseif ($__promo['type'] === 'percent' && !empty($__promo['value'])) {
        $__promo_badge = '-' . rtrim(rtrim(number_format((float)$__promo['value'], 2), '0'), '.') . '%';
        $__promo_was = $__promo['was'] ?? null;
    } elseif ($__promo['type'] === 'cost_sale') {
        $__promo_badge = '원가세일';
        $__promo_was = $__promo['was'] ?? null;
    }
}
?>
<a href="/mall/product.php?id=<?php echo (int)$__c['product_id']; ?>" style="display:block;">
    <div class="pcard-thumb">
        <img src="<?php echo $__img; ?>" alt="<?php echo htmlspecialchars($__c['display_name']); ?>" style="<?php echo $__out_of_stock ? 'opacity:0.45;' : ''; ?>">
        <?php if (!empty($__c['display_name_en'])): ?><span class="en-plate-sm"><?php echo htmlspecialchars($__c['display_name_en']); ?></span><?php endif; ?>
        <?php if ($__promo_badge): ?>
            <span class="disc-flag"><?php echo htmlspecialchars($__promo_badge); ?></span>
        <?php elseif ($__price['final_price'] !== null && $__price['discount_rate'] > 0): ?>
            <span class="disc-flag">-<?php echo rtrim(rtrim(number_format($__price['discount_rate'], 2), '0'), '.'); ?>%</span>
        <?php endif; ?>
        <?php if ($__out_of_stock): ?><span class="badge-oos">품절</span><?php endif; ?>
    </div>
    <div class="name">
        <?php if ($__promo_badge): ?><span class="promo-name-tag"><?php echo htmlspecialchars($__promo_badge); ?></span><?php endif; ?>
        <?php echo htmlspecialchars($__c['display_name']); ?>
    </div>
    <?php if ($__price['final_price'] === null): ?>
        <div class="no-price"><?php echo '가격 정보 없음'; ?></div>
    <?php else: ?>
        <div style="display:flex;align-items:baseline;gap:6px;margin-top:3px;">
            <span style="font:700 16px/1.2 var(--font-sans);color:var(--label-normal);"><?php echo number_format($__price['final_price'], 2); ?></span>
            <?php if ($__promo_was !== null): ?>
                <span style="font:500 12px/1.2 var(--font-sans);color:var(--label-assistive);text-decoration:line-through;"><?php echo number_format((float)$__promo_was, 2); ?></span>
            <?php elseif ($__price['discount_rate'] > 0): ?>
                <span style="font:500 12px/1.2 var(--font-sans);color:var(--label-assistive);text-decoration:line-through;"><?php echo number_format($__price['base_price'], 2); ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</a>
<?php if (!$__out_of_stock): ?>
<div class="pcard-action" data-pcard-action data-product-id="<?php echo (int)$__c['product_id']; ?>" data-stock="<?php echo (int)$__c['stock']; ?>">
    <button type="button" class="pcard-add-btn" <?php echo $__cart_qty > 0 ? 'style="display:none;"' : ''; ?>>
        <svg><use href="#i-plus"></use></svg> 담기
    </button>
    <div class="pcard-stepper" data-cart-item-id="<?php echo htmlspecialchars((string)$__cart_item_id); ?>" <?php echo $__cart_qty > 0 ? '' : 'style="display:none;"'; ?>>
        <button type="button" class="pcard-dec"><svg><use href="#i-minus"></use></svg></button>
        <span class="qty"><?php echo (int)$__cart_qty; ?></span>
        <button type="button" class="pcard-inc"><svg><use href="#i-plus"></use></svg></button>
    </div>
</div>
<?php endif; ?>
