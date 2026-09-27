<?php
/**
 * 상품 카드(그리드용) — 등급별 가격·품절뱃지 표시
 * 포함하는 쪽에서 $card(아래 형태의 배열)를 미리 정의해야 한다:
 * ['product_id', 'display_name', 'image_path'(nullable), 'price'(mall_calculate_price 반환값), 'stock']
 */
$__c = $card;
$__price = $__c['price'];
$__out_of_stock = $__c['stock'] <= 0;

// 모바일 앱에서는 썸네일 사용 (로딩 속도 개선)
$is_app = isset($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'HOMEKMART-ANDROID-APP') !== false;
$__img_path = $__c['image_path'];
if ($is_app && $__img_path) {
    // detail_xxx.jpg → thumb_xxx.jpg 변환
    $__img_path = preg_replace('/detail_/', 'thumb_', $__img_path, 1);
}
$__img = $__img_path ? '/mall/' . htmlspecialchars($__img_path) : '/logo/homekmart_logo.png';
?>
<a href="/mall/product.php?id=<?php echo (int)$__c['product_id']; ?>" class="product-card">
    <div class="thumb-wrap<?php echo $__out_of_stock ? ' out-of-stock' : ''; ?>">
        <?php if ($__out_of_stock): ?><span class="badge-oos">품절</span><?php endif; ?>
        <img class="thumb" src="<?php echo $__img; ?>" alt="<?php echo htmlspecialchars($__c['display_name']); ?>">
    </div>
    <div class="name"><?php echo htmlspecialchars($__c['display_name']); ?></div>
    <div class="price-row">
        <?php if ($__price['final_price'] === null): ?>
            <span class="no-price">가격 정보 없음</span>
        <?php else: ?>
            <?php if ($__price['discount_rate'] > 0): ?>
                <div class="was"><?php echo number_format($__price['base_price'], 2); ?></div>
            <?php endif; ?>
            <span class="final"><?php echo number_format($__price['final_price'], 2); ?></span>
            <?php if ($__price['discount_rate'] > 0): ?>
                <span class="discount">-<?php echo rtrim(rtrim(number_format($__price['discount_rate'], 2), '0'), '.'); ?>%</span>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</a>
