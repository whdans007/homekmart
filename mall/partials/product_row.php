<?php
/**
 * 상품 리스트 행(카테고리 화면) — 66px 썸네일, 상품명 좌측, 가격+담기 버튼 하단.
 * 포함하는 쪽에서 $card(product_card.php와 동일한 형태)를 미리 정의해야 한다.
 */
$__c = $card;
$__price = $__c['price'];
$__out_of_stock = $__c['stock'] <= 0;
$__img = $__c['image_path'] ? '/mall/' . htmlspecialchars($__c['image_path']) : '/logo/homekmart_logo.png';
$__cart_state = ($cart_quantities ?? [])[$__c['product_id']] ?? null;
$__cart_qty = $__cart_state ? (int)$__cart_state['quantity'] : 0;
$__cart_item_id = $__cart_state ? (int)$__cart_state['cart_item_id'] : '';
?>
<div class="product-row-item">
    <a href="/mall/product.php?id=<?php echo (int)$__c['product_id']; ?>">
        <img class="thumb" src="<?php echo $__img; ?>" alt="<?php echo htmlspecialchars($__c['display_name']); ?>" style="<?php echo $__out_of_stock ? 'opacity:0.45;' : ''; ?>">
    </a>
    <div class="info">
        <a href="/mall/product.php?id=<?php echo (int)$__c['product_id']; ?>" style="color:inherit;">
            <div class="name"><?php echo htmlspecialchars($__c['display_name']); ?></div>
        </a>
        <div class="bottom-row">
            <div class="price-row" style="margin-top:0;">
                <?php if ($__price['final_price'] === null): ?>
                    <span class="no-price">가격 정보 없음</span>
                <?php else: ?>
                    <span class="final"><?php echo number_format($__price['final_price'], 2); ?></span>
                    <?php if ($__price['discount_rate'] > 0): ?>
                        <span class="discount">-<?php echo rtrim(rtrim(number_format($__price['discount_rate'], 2), '0'), '.'); ?>%</span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php if ($__out_of_stock): ?>
                <span class="badge-oos" style="position:static;">품절</span>
            <?php else: ?>
                <div class="pcard-action product-row-cart-action" data-pcard-action data-product-id="<?php echo (int)$__c['product_id']; ?>" data-stock="<?php echo (int)$__c['stock']; ?>">
                    <button type="button" class="pcard-add-btn quick-add-btn" <?php echo $__cart_qty > 0 ? 'style="display:none;"' : ''; ?>>담기</button>
                    <div class="pcard-stepper qty-stepper qty-stepper-sm" data-cart-item-id="<?php echo htmlspecialchars((string)$__cart_item_id); ?>" <?php echo $__cart_qty > 0 ? '' : 'style="display:none;"'; ?>>
                        <button type="button" class="pcard-dec" aria-label="수량 줄이기"><svg><use href="#i-minus"></use></svg></button>
                        <span class="qty qty-value"><?php echo $__cart_qty; ?></span>
                        <button type="button" class="pcard-inc" aria-label="수량 늘리기"><svg><use href="#i-plus"></use></svg></button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
