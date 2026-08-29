<?php
require_once __DIR__ . '/lib/catalog.php';

$page_title = '검색';
$mall_redesigned = true;
$show_bottom_nav = true;
$active_nav = 'search';
require_once __DIR__ . '/partials/header.php';

$q = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'default'; // default(관련도) | price_asc(낮은가격순) | newest(최신순)
$channel = ($member && $member['member_type'] === 'wholesale') ? 'wholesale' : 'retail';

$cards = [];
$popular_cards = [];

if ($q !== '') {
    $order_by = ($sort === 'newest') ? 'newest' : 'default';
    $products = mall_get_eligible_products($channel, null, $q, 60, $order_by);
    $cards = mall_build_product_cards($products, $member, $channel, $mall_lang);

    if ($sort === 'price_asc') {
        // 최종가(할인 반영)는 SQL이 아니라 mall_calculate_price()에서만 계산되므로 카드로 만든 뒤 여기서 정렬한다.
        usort($cards, function ($a, $b) {
            $pa = $a['price']['final_price']; $pb = $b['price']['final_price'];
            if ($pa === null) return 1;
            if ($pb === null) return -1;
            return $pa <=> $pb;
        });
    }
} else {
    // 초기 상태: 판매랭킹 통계가 없어 "지금 많이 찾는 상품"은 최신 등록 상품으로 대체한다.
    $popular_products = mall_get_eligible_products($channel, null, '', 5, 'newest');
    $popular_cards = mall_build_product_cards($popular_products, $member, $channel, $mall_lang);
}

$conn = mall_get_db_connection();
$suggested_terms = $conn->query('SELECT name, name_en FROM categories WHERE parent_id IS NULL ORDER BY sort_order, name LIMIT 6')->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>

<div class="section" style="padding-top:var(--space-4);">
    <form method="get" style="display:flex;align-items:center;gap:var(--space-2);height:44px;padding:0 var(--space-4);border-radius:var(--radius-md);background:var(--fill-normal);">
        <svg style="width:18px;height:18px;color:var(--label-alternative);flex-shrink:0;"><use href="#i-search"></use></svg>
        <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="상품명/SKU 검색" autofocus
               style="flex:1;border:none;background:none;outline:none;font:var(--t-body2) var(--font-sans);color:var(--label-normal);">
        <button type="submit" style="border:none;background:none;color:var(--primary-normal);font:700 13px var(--font-sans);cursor:pointer;">검색</button>
    </form>
</div>

<?php if ($q === ''): ?>

<div class="section">
    <div class="section-title"><h2 class="t-label1" style="font:var(--t-label1) var(--font-sans);">추천 검색어</h2></div>
    <div class="chip-row">
        <?php foreach ($suggested_terms as $t): ?>
            <?php $__label = ($mall_lang === 'en' && !empty($t['name_en'])) ? $t['name_en'] : $t['name']; ?>
            <a class="chip" href="/mall/search.php?q=<?php echo urlencode($__label); ?>"><?php echo htmlspecialchars($__label); ?></a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (!empty($popular_cards)): ?>
<div class="section">
    <div class="section-title"><h2>지금 많이 찾는 상품</h2></div>
    <div>
        <?php foreach ($popular_cards as $__i => $card): ?>
            <div style="display:flex;align-items:center;gap:var(--space-2);">
                <div style="width:20px;flex-shrink:0;text-align:center;font:700 15px var(--font-sans);color:var(--primary-normal);"><?php echo $__i + 1; ?></div>
                <div style="flex:1;min-width:0;"><?php include __DIR__ . '/partials/product_row.php'; ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php else: ?>

<div class="section">
    <div class="chip-row">
        <a class="chip <?php echo $sort === 'default' ? 'active' : ''; ?>" href="?q=<?php echo urlencode($q); ?>&sort=default">관련도순</a>
        <a class="chip <?php echo $sort === 'price_asc' ? 'active' : ''; ?>" href="?q=<?php echo urlencode($q); ?>&sort=price_asc">낮은가격순</a>
        <a class="chip <?php echo $sort === 'newest' ? 'active' : ''; ?>" href="?q=<?php echo urlencode($q); ?>&sort=newest">최신순</a>
    </div>

    <?php if (empty($cards)): ?>
        <p class="empty-state">"<?php echo htmlspecialchars($q); ?>"에 대한 검색 결과가 없습니다.<br>다른 검색어로 시도해보세요.</p>
    <?php else: ?>
        <div class="product-grid" style="margin-top:var(--space-4);">
            <?php foreach ($cards as $card): ?>
                <?php include __DIR__ . '/partials/product_card.php'; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
