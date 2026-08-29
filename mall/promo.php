<?php
/**
 * 기획전 페이지 — 디자인 원본(design_handoff_home_k_mart/HOME K MART App.dc.html §isPromo) 그대로.
 * 홈 화면 배너를 "기획전 페이지"로 연결하면(admin/home_layout.php에서 클릭 시 이동 = 기획전 페이지) 여기로 온다.
 * 상품 목록은 관리자가 홈 레이아웃 화면의 "기획전 상품" 패널에서 고른다(slot_key=promo_products,
 * mall_home_sections 재사용 — 홈에는 안 보이고 이 페이지 전용).
 */
require_once __DIR__ . '/lib/catalog.php';
require_once __DIR__ . '/lib/cart.php';

$mall_redesigned = true;
$mall_hide_topbar = true;
$show_bottom_nav = true;
$page_title = '기획전';
require_once __DIR__ . '/partials/header.php';

$requested_channel = ($member && $member['member_type'] === 'wholesale') ? 'wholesale' : 'retail';

// 고객 화면은 항상 발행본만 본다.
$promo_slot = mall_get_home_slot('promo_products', 'published');
$promo_config = [];
if ($promo_slot && $promo_slot['is_active'] && !empty($promo_slot['config'])) {
    $decoded = json_decode($promo_slot['config'], true);
    $promo_config = is_array($decoded) ? $decoded : [];
}
$product_ids = $promo_config['product_ids'] ?? [];

$banner_slot = mall_get_home_slot('promo_banner', 'published');
$hero_title = $banner_slot['title'] ?? '';
$hero_subtitle = $banner_slot['subtitle'] ?? '';

$cards = [];
$category_by_product = [];
$tabs = [];
if (!empty($product_ids)) {
    $rows = mall_get_products_by_ids($product_ids);
    $cards = mall_build_product_cards($rows, $member, $requested_channel, $mall_lang);

    // 탭 필터용 — 이 기획전에 포함된 상품들의 대분류 카테고리만 모은다(전체 카테고리 목록이 아니라).
    $conn = mall_get_db_connection();
    $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
    $types = str_repeat('i', count($product_ids));
    $stmt = $conn->prepare(
        "SELECT p.id AS product_id, COALESCE(c.parent_id, c.id) AS top_category_id
         FROM products p LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.id IN ({$placeholders})"
    );
    $stmt->bind_param($types, ...$product_ids);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $category_by_product[(int)$row['product_id']] = $row['top_category_id'] !== null ? (int)$row['top_category_id'] : null;
    }
    $stmt->close();

    $top_category_ids = array_values(array_unique(array_filter(array_values($category_by_product))));
    if (!empty($top_category_ids)) {
        $cat_placeholders = implode(',', array_fill(0, count($top_category_ids), '?'));
        $cat_types = str_repeat('i', count($top_category_ids));
        $cat_stmt = $conn->prepare("SELECT id, name, name_en FROM categories WHERE id IN ({$cat_placeholders}) ORDER BY sort_order, name");
        $cat_stmt->bind_param($cat_types, ...$top_category_ids);
        $cat_stmt->execute();
        $tabs = $cat_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $cat_stmt->close();
    }
}

$__member_id = $member ? $member['id'] : null;
$__guest_token = $member ? null : mall_guest_token();
$__cart_qty_map = mall_cart_get_quantities_by_product($__member_id, $__guest_token);
?>

<div class="promo-hero">
    <a href="javascript:history.back()" class="icon-btn back-btn"><svg><use href="#i-chev-left"></use></svg></a>
    <div class="inner">
        <span class="eyebrow">기획전</span>
        <div class="title"><?php echo $hero_title !== '' ? nl2br(htmlspecialchars($hero_title)) : '이번 주<br>기획전'; ?></div>
        <?php if ($hero_subtitle !== ''): ?><div class="sub"><?php echo htmlspecialchars($hero_subtitle); ?></div><?php endif; ?>
    </div>
</div>

<?php if (!empty($tabs)): ?>
<div class="promo-tabs" id="promo-tabs">
    <button type="button" class="chip active" data-tab-category="">전체</button>
    <?php foreach ($tabs as $t): ?>
        <button type="button" class="chip" data-tab-category="<?php echo (int)$t['id']; ?>">
            <?php echo htmlspecialchars(($mall_lang === 'en' && !empty($t['name_en'])) ? $t['name_en'] : $t['name']); ?>
        </button>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="section" style="padding-top:4px;">
    <?php if (empty($cards)): ?>
        <p class="empty-state">아직 등록된 기획전 상품이 없습니다.</p>
    <?php else: ?>
        <?php foreach ($cards as $card): ?>
            <?php
            $__c = $card;
            $__price = $__c['price'];
            $__out_of_stock = $__c['stock'] <= 0;
            $__img = $__c['image_path'] ? '/mall/' . htmlspecialchars($__c['image_path']) : '/logo/homekmart_logo.png';
            $__cart = $__cart_qty_map[$__c['product_id']] ?? null;
            $__cart_qty = $__cart['quantity'] ?? 0;
            $__cart_item_id = $__cart['cart_item_id'] ?? '';
            $__cat_id = $category_by_product[$__c['product_id']] ?? '';
            ?>
            <div class="promo-row" data-category-id="<?php echo htmlspecialchars((string)$__cat_id); ?>">
                <a href="/mall/product.php?id=<?php echo (int)$__c['product_id']; ?>" style="display:flex;gap:13px;flex:1;min-width:0;color:inherit;">
                    <div class="pcard-thumb">
                        <img src="<?php echo $__img; ?>" alt="" style="<?php echo $__out_of_stock ? 'opacity:0.45;' : ''; ?>">
                        <?php if ($__price['final_price'] !== null && $__price['discount_rate'] > 0): ?>
                            <span class="disc-flag">-<?php echo rtrim(rtrim(number_format($__price['discount_rate'], 2), '0'), '.'); ?>%</span>
                        <?php endif; ?>
                        <?php if ($__out_of_stock): ?><span class="badge-oos">품절</span><?php endif; ?>
                    </div>
                    <div class="info">
                        <div class="name"><?php echo htmlspecialchars($__c['display_name']); ?></div>
                        <?php if (!empty($__c['display_name_en'])): ?><div class="origin"><?php echo htmlspecialchars($__c['display_name_en']); ?></div><?php endif; ?>
                        <?php if ($__price['final_price'] === null): ?>
                            <div class="no-price">가격 정보 없음</div>
                        <?php else: ?>
                            <div class="price-row">
                                <span class="final"><?php echo number_format($__price['final_price'], 2); ?></span>
                                <?php if ($__price['discount_rate'] > 0): ?>
                                    <span class="was"><?php echo number_format($__price['base_price'], 2); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </a>
                <?php if (!$__out_of_stock): ?>
                <div class="action-col">
                    <div class="pcard-action" data-pcard-action data-product-id="<?php echo (int)$__c['product_id']; ?>" data-stock="<?php echo (int)$__c['stock']; ?>" style="margin-top:0;">
                        <button type="button" class="pcard-add-btn" <?php echo $__cart_qty > 0 ? 'style="display:none;"' : ''; ?>>
                            <svg><use href="#i-plus"></use></svg> 담기
                        </button>
                        <div class="pcard-stepper" data-cart-item-id="<?php echo htmlspecialchars((string)$__cart_item_id); ?>" <?php echo $__cart_qty > 0 ? '' : 'style="display:none;"'; ?>>
                            <button type="button" class="pcard-dec"><svg><use href="#i-minus"></use></svg></button>
                            <span class="qty"><?php echo (int)$__cart_qty; ?></span>
                            <button type="button" class="pcard-inc"><svg><use href="#i-plus"></use></svg></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
document.querySelectorAll('#promo-tabs .chip').forEach(function (tab) {
    tab.addEventListener('click', function () {
        document.querySelectorAll('#promo-tabs .chip').forEach(function (t) { t.classList.remove('active'); });
        tab.classList.add('active');
        const catId = tab.dataset.tabCategory;
        document.querySelectorAll('.promo-row').forEach(function (row) {
            row.style.display = (catId === '' || row.dataset.categoryId === catId) ? 'flex' : 'none';
        });
    });
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
