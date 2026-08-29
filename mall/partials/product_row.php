<?php
/**
 * 상품 리스트 행(카테고리 화면) — 66px 썸네일, 상품명 좌측, 가격+담기 버튼 하단.
 * 포함하는 쪽에서 $card(product_card.php와 동일한 형태)를 미리 정의해야 한다.
 */
$__c = $card;
$__price = $__c['price'];
$__out_of_stock = $__c['stock'] <= 0;
$__img = $__c['image_path'] ? '/mall/' . htmlspecialchars($__c['image_path']) : '/logo/homekmart_logo.png';
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
                <button type="button" class="quick-add-btn add-to-cart-quick" data-product-id="<?php echo (int)$__c['product_id']; ?>">담기</button>
            <?php endif; ?>
        </div>
    </div>
</div>
