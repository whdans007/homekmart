<?php
require_once __DIR__ . '/lib/catalog.php';

$page_title = '카테고리';
$mall_redesigned = true;
$show_bottom_nav = true;
$active_nav = 'category';
require_once __DIR__ . '/partials/header.php';

$category_id = isset($_GET['id']) && $_GET['id'] !== '' ? (int)$_GET['id'] : null;
$sub_id = isset($_GET['sub']) && $_GET['sub'] !== '' ? (int)$_GET['sub'] : null;
$search = trim($_GET['q'] ?? '');

$requested_channel = ($member && $member['member_type'] === 'wholesale') ? 'wholesale' : 'retail';
$show_wholesale_notice = $member && $member['member_type'] === 'wholesale' && $member['wholesale_status'] !== 'approved';

$conn = mall_get_db_connection();
$rail_categories = $conn->query('SELECT id, name, name_en FROM categories WHERE parent_id IS NULL ORDER BY sort_order, name')->fetch_all(MYSQLI_ASSOC);
$sub_categories = [];
if ($category_id) {
    $sub_stmt = $conn->prepare('SELECT id, name, name_en FROM categories WHERE parent_id = ? ORDER BY sort_order, name');
    $sub_stmt->bind_param('i', $category_id);
    $sub_stmt->execute();
    $sub_categories = $sub_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sub_stmt->close();
}
$conn->close();

// "전체" 탭을 없앴으니 카테고리를 안 고르고 들어온 경우 첫 번째 대분류를 기본으로 보여준다.
if ($category_id === null && !empty($rail_categories)) {
    $category_id = (int)$rail_categories[0]['id'];
    if ($category_id) {
        $conn2 = mall_get_db_connection();
        $sub_stmt2 = $conn2->prepare('SELECT id, name, name_en FROM categories WHERE parent_id = ? ORDER BY sort_order, name');
        $sub_stmt2->bind_param('i', $category_id);
        $sub_stmt2->execute();
        $sub_categories = $sub_stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
        $sub_stmt2->close();
        $conn2->close();
    }
}

$effective_category_id = $sub_id ?: $category_id;
$products = mall_get_eligible_products($requested_channel, $effective_category_id, $search);
$cards = mall_build_product_cards($products, $member, $requested_channel, $mall_lang);
$fresh_products = [];
try {
    $fresh_products = mall_get_eligible_fresh_products($effective_category_id, $search);
} catch (Throwable $e) {
    // 신선상품 스키마/조회에 문제가 생겨도 기존 카테고리 상품 화면 전체가 중단되지 않게 한다.
    error_log(sprintf(
        '[mall/category.php] Failed to load fresh products: %s in %s:%d',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
}

function mall_cat_label($cat, $mall_lang) {
    return ($mall_lang === 'en' && !empty($cat['name_en'])) ? $cat['name_en'] : $cat['name'];
}
?>
<style>
.cat-layout { display: flex; align-items: flex-start; }
.cat-rail { width: 96px; flex-shrink: 0; background: var(--bg-alternative); }
.cat-rail a {
    display: block; position: relative; padding: 14px 8px; text-align: center;
    font: var(--t-caption1) var(--font-sans); color: var(--label-alternative);
}
.cat-rail a.active { background: var(--bg-normal); color: var(--label-normal); font-weight: 700; }
.cat-rail a.active::before { content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: var(--primary-normal); }
.cat-content { flex: 1; min-width: 0; padding: var(--space-4) var(--space-4) 0; }
</style>

<?php if ($show_wholesale_notice): ?>
<div class="wholesale-notice"><i class="fas fa-info-circle"></i> 승인 대기중 — 참고용 소매가로 표시됩니다. 관리자 승인 후 도매가가 노출됩니다.</div>
<?php endif; ?>

<div class="cat-layout">
    <nav class="cat-rail">
        <?php foreach ($rail_categories as $cat): ?>
            <a href="/mall/category.php?id=<?php echo (int)$cat['id']; ?>" class="<?php echo $category_id === (int)$cat['id'] ? 'active' : ''; ?>">
                <?php echo htmlspecialchars(mall_cat_label($cat, $mall_lang)); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="cat-content">
        <?php if (!empty($sub_categories)): ?>
        <div class="chip-row" style="margin-bottom:var(--space-3);">
            <a class="chip <?php echo $sub_id === null ? 'active' : ''; ?>" href="/mall/category.php?id=<?php echo (int)$category_id; ?>">전체</a>
            <?php foreach ($sub_categories as $sub): ?>
                <a class="chip <?php echo $sub_id === (int)$sub['id'] ? 'active' : ''; ?>" href="/mall/category.php?id=<?php echo (int)$category_id; ?>&sub=<?php echo (int)$sub['id']; ?>">
                    <?php echo htmlspecialchars(mall_cat_label($sub, $mall_lang)); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (empty($cards) && empty($fresh_products)): ?>
            <p class="empty-state">등록된 상품이 없습니다.</p>
        <?php else: ?>
            <div>
                <?php foreach ($cards as $card): ?>
                    <?php include __DIR__ . '/partials/product_row.php'; ?>
                <?php endforeach; ?>
                <?php foreach ($fresh_products as $fresh): ?>
                    <?php
                    $fresh_name = ($mall_lang === 'en' && !empty($fresh['name_en'])) ? $fresh['name_en'] : $fresh['name_ko'];
                    $fresh_image = $fresh['image_url'] ?: '/logo/homekmart_logo.png';
                    ?>
                    <div class="product-row-item">
                        <a href="/mall/fresh_product.php?id=<?php echo (int)$fresh['id']; ?>">
                            <img class="thumb" src="<?php echo htmlspecialchars($fresh_image); ?>" alt="<?php echo htmlspecialchars($fresh_name); ?>">
                        </a>
                        <div class="info">
                            <a href="/mall/fresh_product.php?id=<?php echo (int)$fresh['id']; ?>" style="color:inherit;">
                                <div class="name"><span class="badge badge-green">신선</span> <?php echo htmlspecialchars($fresh_name); ?></div>
                            </a>
                            <div class="bottom-row">
                                <div class="price-row" style="margin-top:0;">
                                    <span class="final"><?php echo number_format((float)$fresh['price_per_100g'], 2); ?></span>
                                    <span style="font:var(--t-caption1) var(--font-sans);color:var(--label-alternative);">/ <?php echo $fresh['sale_type'] === 'weight' ? '100g' : '개'; ?></span>
                                </div>
                                <a class="quick-add-btn" href="/mall/fresh_product.php?id=<?php echo (int)$fresh['id']; ?>">담기</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
