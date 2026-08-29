<?php
require_once __DIR__ . '/lib/pricing.php';
require_once __DIR__ . '/lib/catalog.php';
require_once __DIR__ . '/lib/cart.php';
require_once __DIR__ . '/lib/review.php';

$mall_redesigned = true;
$product_id = (int)($_GET['id'] ?? 0);

$conn = mall_get_db_connection();
$stmt = $conn->prepare(
    "SELECT mp.product_id, mp.display_name, mp.display_name_en, p.description, p.category_id, p.pieces_per_box
     FROM mall_products mp
     INNER JOIN products p ON p.id = mp.product_id
     WHERE mp.product_id = ? AND mp.store_id = ? AND mp.is_active = 1"
);
$store_id = MALL_STORE_ID;
$stmt->bind_param('ii', $product_id, $store_id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    http_response_code(404);
    $page_title = '상품을 찾을 수 없습니다';
    $show_bottom_nav = true;
    require_once __DIR__ . '/partials/header.php';
    echo '<p class="empty-state">상품을 찾을 수 없습니다.</p>';
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

$images_stmt = $conn->prepare('SELECT image_path FROM mall_product_images WHERE product_id = ? ORDER BY sort_order');
$images_stmt->bind_param('i', $product_id);
$images_stmt->execute();
$images = array_column($images_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'image_path');
$images_stmt->close();
$conn->close();

$page_title = $product['display_name'];
$show_bottom_nav = true;
require_once __DIR__ . '/partials/header.php';

// 상세페이지는 디자인 원본처럼 한글명(주)+영문명(부제) 둘 다 항상 보여준다(목록/카드류는 언어 설정에 따라 하나만 표시).
$display_name = ($mall_lang === 'en' && !empty($product['display_name_en'])) ? $product['display_name_en'] : $product['display_name'];
$display_name_ko = $product['display_name'];
$display_name_en = $product['display_name_en'] ?: '';

$requested_channel = ($member && $member['member_type'] === 'wholesale') ? 'wholesale' : 'retail';
$price = mall_calculate_price($product_id, $member, $requested_channel, 0.0);
$stock = mall_get_stock_quantity($product_id);
$out_of_stock = $stock <= 0;

$instant_tiers = [];
if ($price['channel_used'] === 'wholesale') {
    $pconn = mall_get_db_connection();
    $instant_tiers = $pconn->query(
        'SELECT min_order_amount, discount_rate FROM mall_wholesale_instant_discount_tiers WHERE is_active = 1 ORDER BY min_order_amount'
    )->fetch_all(MYSQLI_ASSOC);
}

// 위시리스트 상태 + 리뷰 작성 가능 여부
$is_wishlisted = false;
$reviewable_order_id = null;
if ($member) {
    $wconn = mall_get_db_connection();
    $wstmt = $wconn->prepare('SELECT id FROM mall_wishlist WHERE member_id = ? AND product_id = ?');
    $wstmt->bind_param('ii', $member['id'], $product_id);
    $wstmt->execute();
    $is_wishlisted = (bool)$wstmt->get_result()->fetch_assoc();
    $wstmt->close();

    $reviewable_order_id = mall_get_reviewable_order_id($member['id'], $product_id);
}

$__pd_member_id = $member ? $member['id'] : null;
$__pd_guest_token = $member ? null : mall_guest_token();
$pd_cart_count = mall_cart_count($__pd_member_id, $__pd_guest_token);

$review_stats = mall_get_product_review_stats($product_id);
$review_preview = mall_get_product_reviews($product_id, 'recent', 3);

// 함께 담으면 좋아요 — 실시간 추천이 아니라 같은 카테고리 다른 상품으로 단순 대체
$related_cards = [];
if ($product['category_id']) {
    $related_products = mall_get_eligible_products($requested_channel, (int)$product['category_id'], '', 8);
    $related_products = array_values(array_filter($related_products, fn($p) => (int)$p['product_id'] !== $product_id));
    $related_products = array_slice($related_products, 0, 4);
    $related_cards = mall_build_product_cards($related_products, $member, $requested_channel, $mall_lang);
}
?>
<style>
/* 디자인 원본(App.dc.html isProduct 화면) 그대로 — 상단바는 스크롤에 고정된 블러 바, 히어로 이미지가 그 뒤로
   음수 마진으로 파고드는 구조. 썸네일 스트립은 원본에 없어 뺐다(이미지가 여러 장이어도 히어로만 보여준다). */
.pd-topbar {
    position: sticky; top: 0; z-index: 20;
    display: flex; align-items: center; justify-content: space-between;
    padding: calc(var(--space-3) + env(safe-area-inset-top, 0px)) 6px var(--space-2);
    background: var(--sticky-bg); -webkit-backdrop-filter: blur(12px); backdrop-filter: blur(12px);
}
.pd-topbar .icon-btn { width: 44px; height: 44px; }
.pd-hero { position: relative; background: var(--fill-normal); margin-top: -52px; }
.pd-hero img { width: 100%; aspect-ratio: 1/1; object-fit: cover; display: block; }
.pd-hero .en-plate {
    position: absolute; left: var(--space-4); bottom: var(--space-3);
    font: 600 12px/1.3 var(--font-sans); color: var(--label-normal);
    background: var(--plate); -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px);
    padding: 5px 9px; border-radius: var(--radius-xs);
}
.pd-body { padding: var(--space-5) var(--space-5) 0; }
.pd-badges { display: flex; gap: 6px; margin-bottom: var(--space-3); }
.pd-name-ko { font: var(--t-heading1) var(--font-sans); letter-spacing: var(--ls-heading1); color: var(--label-normal); margin: 0; }
.pd-name-en { font: 500 13px/1.5 var(--font-sans); color: var(--label-alternative); margin-top: 4px; }
.pd-price-row { display: flex; align-items: baseline; gap: 8px; margin: var(--space-4) 0 4px; }
.pd-price-discount { font: var(--t-title2) var(--font-sans); letter-spacing: var(--ls-title2); color: var(--brand-red); }
.pd-price-final { font: var(--t-title2) var(--font-sans); letter-spacing: var(--ls-title2); color: var(--label-normal); }
.pd-price-was { font: 500 15px/1.2 var(--font-sans); color: var(--label-assistive); text-decoration: line-through; }
.pd-rating { display: flex; align-items: center; gap: 6px; border: none; background: none; padding: var(--space-2) 0 0; cursor: pointer; }
.pd-rating .avg { font: 700 14px/1 var(--font-sans); color: var(--label-normal); }
.pd-rating .line { font: 500 14px/1 var(--font-sans); color: var(--label-alternative); text-decoration: underline; }
.pd-spec-box { margin: var(--space-5) 0 0; border: 1px solid var(--line-normal); border-radius: var(--radius-lg); overflow: hidden; }
.pd-spec-box .row { display: flex; gap: var(--space-3); padding: 13px 15px; border-bottom: 1px solid var(--line-alternative); }
.pd-spec-box .row:last-child { border-bottom: none; }
.pd-spec-box .row .k { width: 76px; flex-shrink: 0; font: 500 13px/1.5 var(--font-sans); color: var(--label-alternative); }
.pd-spec-box .row .v { flex: 1; font: 500 13px/1.5 var(--font-sans); color: var(--label-neutral); }
.pd-ship-banner {
    margin: var(--space-4) 0 0; background: var(--primary-bg); border-radius: var(--radius-lg);
    padding: var(--space-4); display: flex; gap: 11px; align-items: flex-start;
}
.pd-ship-banner svg { width: 20px; height: 20px; color: var(--primary-normal); flex-shrink: 0; margin-top: 1px; }
.pd-ship-banner .title { font: 700 14px/1.4 var(--font-sans); color: var(--primary-strong); }
.pd-ship-banner .sub { font: 500 12px/1.5 var(--font-sans); color: var(--primary-strong); opacity: 0.85; margin-top: 3px; }
.pd-desc { font: var(--t-body2) var(--font-sans); color: var(--label-neutral); white-space: pre-line; margin: var(--space-4) 0; }
</style>

<div class="pd-topbar">
    <a href="javascript:history.back()" class="icon-btn"><svg><use href="#i-chev-left"></use></svg></a>
    <div style="display:flex;">
        <?php if ($member): ?>
        <button id="wishlist-btn" class="icon-btn" data-product-id="<?php echo (int)$product_id; ?>" data-active="<?php echo $is_wishlisted ? '1' : '0'; ?>" style="<?php echo $is_wishlisted ? 'color:var(--brand-red);' : ''; ?>">
            <svg><use href="#i-heart<?php echo $is_wishlisted ? '-fill' : ''; ?>"></use></svg>
        </button>
        <?php endif; ?>
        <a href="/mall/cart.php" class="icon-btn" style="position:relative;">
            <svg><use href="#i-bag"></use></svg>
            <?php if ($pd_cart_count > 0): ?><span class="badge-count"><?php echo $pd_cart_count > 99 ? '99+' : $pd_cart_count; ?></span><?php endif; ?>
        </a>
    </div>
</div>

<div class="pd-hero">
    <?php $main_img = $images[0] ?? null; ?>
    <img src="<?php echo $main_img ? '/mall/' . htmlspecialchars($main_img) : '/logo/homekmart_logo.png'; ?>" alt="<?php echo htmlspecialchars($display_name); ?>" style="<?php echo $out_of_stock ? 'opacity:0.6;' : ''; ?>">
    <?php if ($display_name_en !== ''): ?><div class="en-plate"><?php echo htmlspecialchars($display_name_en); ?></div><?php endif; ?>
</div>

<div class="pd-body">
    <div class="pd-badges">
        <span class="badge badge-blue">정품 직소싱</span>
        <span class="badge badge-green">익일배송</span>
    </div>
    <h1 class="pd-name-ko"><?php echo htmlspecialchars($display_name_ko); ?></h1>
    <?php if ($display_name_en !== ''): ?><div class="pd-name-en"><?php echo htmlspecialchars($display_name_en); ?></div><?php endif; ?>

    <?php if ($price['final_price'] === null): ?>
        <p class="no-price" style="margin-top:var(--space-4);">가격 정보가 없습니다.</p>
    <?php else: ?>
        <div class="pd-price-row">
            <?php if ($price['discount_rate'] > 0): ?>
                <span class="pd-price-discount">-<?php echo rtrim(rtrim(number_format($price['discount_rate'], 2), '0'), '.'); ?>%</span>
            <?php endif; ?>
            <span class="pd-price-final"><?php echo number_format($price['final_price'], 2); ?></span>
            <?php if ($price['discount_rate'] > 0): ?>
                <span class="pd-price-was"><?php echo number_format($price['base_price'], 2); ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($review_stats['count'] > 0): ?>
    <a href="/mall/product_reviews.php?id=<?php echo (int)$product_id; ?>" class="pd-rating">
        <svg style="width:15px;height:15px;color:var(--brand-star);"><use href="#i-star"></use></svg>
        <span class="avg"><?php echo $review_stats['avg']; ?></span>
        <span class="line">리뷰 <?php echo $review_stats['count']; ?>건 보기</span>
    </a>
    <?php endif; ?>

    <?php if ($price['wholesale_pending_notice']): ?>
        <div class="wholesale-notice" style="margin-left:0;margin-right:0;">승인 대기중 — 참고용 소매가입니다. 관리자 승인 후 도매가가 노출됩니다.</div>
    <?php endif; ?>

    <?php if (!empty($instant_tiers)): ?>
    <div class="card" style="padding:var(--space-3) var(--space-4);margin-top:var(--space-3);">
        <strong class="t-label1" style="font:var(--t-label1) var(--font-sans);">즉석할인 구간</strong>
        <ul style="margin:6px 0 0;padding-left:1.1rem;font:var(--t-caption1) var(--font-sans);color:var(--label-neutral);">
            <?php foreach ($instant_tiers as $t): ?>
            <li><?php echo number_format((float)$t['min_order_amount'], 2); ?> 이상 — <?php echo number_format((float)$t['discount_rate'], 2); ?>%</li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="pd-spec-box">
        <?php if (!empty($product['pieces_per_box'])): ?>
        <div class="row"><span class="k">판매단위</span><span class="v"><?php echo (int)$product['pieces_per_box']; ?>개입</span></div>
        <?php endif; ?>
        <div class="row"><span class="k">배송</span><span class="v">필리핀 메트로 마닐라 기준 익일배송(지역별 상이)</span></div>
        <!-- 원산지/보관방법/유통기한: products 테이블에 해당 컬럼이 없어 이번 범위에서는 표시하지 않음 -->
    </div>

    <div class="pd-ship-banner">
        <svg><use href="#i-send"></use></svg>
        <div>
            <div class="title">오후 8시 이전 주문 시 익일 도착</div>
            <div class="sub">메트로 마닐라 기준(지역별 상이)</div>
        </div>
    </div>

    <?php if (!empty($product['description'])): ?>
    <p class="pd-desc"><?php echo htmlspecialchars($product['description']); ?></p>
    <?php endif; ?>
</div>

<?php if (!empty($related_cards)): ?>
<div class="section">
    <div class="section-title"><h2>함께 담으면 좋아요</h2></div>
    <div class="h-scroll">
        <?php foreach ($related_cards as $card): ?>
            <div class="deal-card"><?php include __DIR__ . '/partials/product_card.php'; ?></div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="section" style="padding-bottom:var(--space-6);">
    <div class="section-title">
        <h2>리뷰<?php if ($review_stats['count'] > 0): ?> (<?php echo $review_stats['avg']; ?> · <?php echo $review_stats['count']; ?>건)<?php endif; ?></h2>
        <a href="/mall/product_reviews.php?id=<?php echo (int)$product_id; ?>" class="action">전체보기 →</a>
    </div>

    <?php if ($reviewable_order_id): ?>
    <div class="card" style="padding:var(--space-3) var(--space-4);margin-bottom:var(--space-3);">
        <div id="review-select" style="margin-bottom:8px;">
            <?php foreach ([1, 2, 3, 4, 5] as $n): ?>
                <svg class="review-star" data-value="<?php echo $n; ?>" style="width:20px;height:20px;cursor:pointer;color:var(--line-normal);"><use href="#i-star"></use></svg>
            <?php endforeach; ?>
        </div>
        <textarea id="review-comment" rows="2" placeholder="상품 후기를 남겨주세요" style="width:100%;border:1px solid var(--line-normal);border-radius:var(--radius-sm);padding:8px;font:var(--t-label2) var(--font-sans);"></textarea>
        <button id="review-submit-btn" data-product-id="<?php echo (int)$product_id; ?>" data-order-id="<?php echo (int)$reviewable_order_id; ?>" class="btn btn-primary" style="height:36px;margin-top:8px;font-size:13px;">리뷰 등록</button>
    </div>
    <?php endif; ?>

    <?php if (empty($review_preview)): ?>
        <p class="empty-state" style="padding:var(--space-4) 0;">아직 등록된 리뷰가 없습니다.</p>
    <?php else: ?>
        <?php foreach ($review_preview as $rv): ?>
        <div style="border-bottom:1px solid var(--line-alternative);padding:10px 0;">
            <div class="stars">
                <?php for ($i = 1; $i <= 5; $i++): ?><svg style="<?php echo $i > $rv['rating'] ? 'color:var(--line-normal);' : ''; ?>"><use href="#i-star"></use></svg><?php endfor; ?>
                <span style="color:var(--label-assistive);margin-left:6px;font:var(--t-caption1) var(--font-sans);"><?php echo htmlspecialchars(mall_mask_reviewer_name($rv['member_name'])); ?></span>
            </div>
            <?php if ($rv['comment']): ?><p style="font:var(--t-label2) var(--font-sans);color:var(--label-neutral);margin:4px 0 0;"><?php echo htmlspecialchars($rv['comment']); ?></p><?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if ($out_of_stock): ?>
<div class="sticky-cta">
    <button class="btn btn-primary btn-block" disabled>품절된 상품입니다</button>
</div>
<?php else: ?>
<div class="sticky-cta" style="flex-direction:column;align-items:stretch;gap:10px;">
    <div style="display:flex;align-items:center;justify-content:space-between;">
        <span style="font:600 14px/1 var(--font-sans);color:var(--label-neutral);">수량</span>
        <div class="qty-stepper" id="pd-qty-stepper">
            <button type="button" id="pd-qty-minus"><svg><use href="#i-minus"></use></svg></button>
            <span class="qty-value" id="pd-qty-value">1</span>
            <button type="button" id="pd-qty-plus"><svg><use href="#i-plus"></use></svg></button>
        </div>
    </div>
    <div style="display:flex;gap:10px;">
        <?php if ($member): ?>
        <button id="wishlist-btn-bottom" class="icon-btn" data-product-id="<?php echo (int)$product_id; ?>" data-active="<?php echo $is_wishlisted ? '1' : '0'; ?>" style="width:48px;height:48px;flex-shrink:0;border:1px solid var(--line-normal);border-radius:var(--radius-md);<?php echo $is_wishlisted ? 'color:var(--brand-red);' : ''; ?>">
            <svg><use href="#i-heart<?php echo $is_wishlisted ? '-fill' : ''; ?>"></use></svg>
        </button>
        <?php endif; ?>
        <button id="add-to-cart-btn" data-product-id="<?php echo (int)$product_id; ?>" class="btn btn-primary" style="flex:1;">
            <svg style="width:18px;height:18px;"><use href="#i-bag"></use></svg> 장바구니 담기
        </button>
    </div>
</div>
<script>
(function () {
    const maxQty = <?php echo (int)$stock; ?>;
    let qty = 1;
    const qtyEl = document.getElementById('pd-qty-value');
    document.getElementById('pd-qty-minus').addEventListener('click', function () {
        if (qty > 1) { qty--; qtyEl.textContent = qty; }
    });
    document.getElementById('pd-qty-plus').addEventListener('click', function () {
        if (qty < maxQty) { qty++; qtyEl.textContent = qty; }
    });
    document.getElementById('add-to-cart-btn').addEventListener('click', function () {
        const btn = this;
        const params = new URLSearchParams();
        params.set('product_id', btn.dataset.productId);
        params.set('quantity', qty);
        btn.disabled = true;
        fetch('/mall/ajax/add_to_cart.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                if (data.success) {
                    mallToast('장바구니에 담았습니다.', '/mall/cart.php', '보기');
                    mallUpdateCartBadge(data.data.cart_count);
                } else {
                    mallToast(data.error?.message || '오류가 발생했습니다');
                }
            });
    });
})();
</script>
<?php endif; ?>

<script>
// 상단(pd-topbar)/하단(sticky-cta) 두 곳에 찜 버튼이 있어 하나를 누르면 둘 다 같이 갱신한다.
const wishlistBtns = [document.getElementById('wishlist-btn'), document.getElementById('wishlist-btn-bottom')].filter(Boolean);
function setWishlistBtnsState(active) {
    wishlistBtns.forEach(function (btn) {
        btn.dataset.active = active ? '1' : '0';
        btn.style.color = active ? 'var(--brand-red)' : '';
        btn.querySelector('use').setAttribute('href', active ? '#i-heart-fill' : '#i-heart');
    });
}
wishlistBtns.forEach(function (wishlistBtn) {
    wishlistBtn.addEventListener('click', function () {
        const params = new URLSearchParams();
        params.set('product_id', wishlistBtn.dataset.productId);
        fetch('/mall/ajax/toggle_wishlist.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    setWishlistBtnsState(data.data.wishlisted);
                }
            });
    });
});

let selectedRating = 0;
document.querySelectorAll('.review-star').forEach(function (star) {
    star.addEventListener('click', function () {
        selectedRating = parseInt(star.dataset.value, 10);
        document.querySelectorAll('.review-star').forEach(function (s) {
            const v = parseInt(s.dataset.value, 10);
            s.style.color = v <= selectedRating ? 'var(--brand-star)' : 'var(--line-normal)';
        });
    });
});

const reviewSubmitBtn = document.getElementById('review-submit-btn');
if (reviewSubmitBtn) {
    reviewSubmitBtn.addEventListener('click', function () {
        if (selectedRating < 1) { alert('별점을 선택해주세요.'); return; }
        const params = new URLSearchParams();
        params.set('product_id', reviewSubmitBtn.dataset.productId);
        params.set('order_id', reviewSubmitBtn.dataset.orderId);
        params.set('rating', selectedRating);
        params.set('comment', document.getElementById('review-comment').value);
        fetch('/mall/ajax/submit_review.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.error?.message || '오류가 발생했습니다');
                }
            });
    });
}
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
