<?php
/**
 * 새로 들어온 한국 상품 2열 그리드 카드 — 디자인 원본(App.dc.html isHome §freshGrid) 그대로:
 * 이미지 위 영문명 플레이트, 이름 2줄, 할인율+가격, 별점, 담기 버튼/수량 스테퍼.
 * 포함하는 쪽에서 $card(review_avg/review_count 포함 — mall_render_new_arrivals_section() 참고)와
 * $__cart(mall_cart_get_quantities_by_product()의 해당 상품 항목, 없으면 null)를 미리 정의해야 한다.
 */
$__c = $card;
$__price = $__c['price'];
$__out_of_stock = $__c['stock'] <= 0;
$__img = $__c['image_path'] ? '/mall/' . htmlspecialchars($__c['image_path']) : '/logo/homekmart_logo.png';
$__cart_qty = $__cart['quantity'] ?? 0;
$__cart_item_id = $__cart['cart_item_id'] ?? '';
?>
<div class="new-arrival-card">
    <a href="/mall/product.php?id=<?php echo (int)$__c['product_id']; ?>" style="display:block;">
        <div class="pcard-thumb">
            <img src="<?php echo $__img; ?>" alt="<?php echo htmlspecialchars($__c['display_name']); ?>" style="<?php echo $__out_of_stock ? 'opacity:0.45;' : ''; ?>">
            <?php if (!empty($__c['display_name_en'])): ?><span class="en-plate-sm"><?php echo htmlspecialchars($__c['display_name_en']); ?></span><?php endif; ?>
            <?php if ($__out_of_stock): ?><span class="badge-oos">품절</span><?php endif; ?>
        </div>
        <div class="name"><?php echo htmlspecialchars($__c['display_name']); ?></div>
        <?php if ($__price['final_price'] === null): ?>
            <div class="no-price">가격 정보 없음</div>
        <?php else: ?>
            <div class="price-row">
                <?php if ($__price['discount_rate'] > 0): ?>
                    <span class="discount">-<?php echo rtrim(rtrim(number_format($__price['discount_rate'], 2), '0'), '.'); ?>%</span>
                <?php endif; ?>
                <span class="final"><?php echo number_format($__price['final_price'], 2); ?></span>
            </div>
        <?php endif; ?>
        <?php if (!empty($__c['review_count'])): ?>
        <div class="rating-row">
            <svg><use href="#i-star"></use></svg>
            <span class="avg"><?php echo $__c['review_avg']; ?></span>
            <span class="cnt">리뷰 <?php echo $__c['review_count']; ?></span>
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
</div>
