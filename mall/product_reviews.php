<?php
require_once __DIR__ . '/lib/review.php';

$mall_redesigned = true;
$product_id = (int)($_GET['id'] ?? 0);
$sort = ($_GET['sort'] ?? 'recent') === 'rating_desc' ? 'rating_desc' : 'recent';

$conn = get_db_connection();
$stmt = $conn->prepare(
    'SELECT mp.display_name, mp.display_name_en
     FROM mall_products mp WHERE mp.product_id = ? AND mp.store_id = ? AND mp.is_active = 1'
);
$store_id = MALL_STORE_ID;
$stmt->bind_param('ii', $product_id, $store_id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if (!$product) {
    http_response_code(404);
    $page_title = '상품을 찾을 수 없습니다';
    require_once __DIR__ . '/partials/header.php';
    echo '<p class="empty-state">상품을 찾을 수 없습니다.</p>';
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

$page_title = '리뷰';
require_once __DIR__ . '/partials/header.php';

$display_name = ($mall_lang === 'en' && !empty($product['display_name_en'])) ? $product['display_name_en'] : $product['display_name'];

$stats = mall_get_product_review_stats($product_id);
$reviews = mall_get_product_reviews($product_id, $sort, 100);

$reviewable_order_id = $member ? mall_get_reviewable_order_id($member['id'], $product_id) : null;
?>
<style>
.review-header { padding: var(--space-4) var(--space-5) 0; }
.review-header .back { display: inline-flex; align-items: center; gap: 4px; color: var(--label-alternative); font: var(--t-caption1) var(--font-sans); margin-bottom: var(--space-2); }
.rating-summary { display: flex; gap: var(--space-6); align-items: center; padding: var(--space-4) 0; }
.rating-summary .avg { font: 700 40px/1 var(--font-sans); }
.rating-bars { flex: 1; }
.rating-bar-row { display: flex; align-items: center; gap: 8px; font: var(--t-caption1) var(--font-sans); color: var(--label-alternative); margin-bottom: 4px; }
.rating-bar-track { flex: 1; height: 6px; border-radius: 999px; background: var(--fill-normal); overflow: hidden; }
.rating-bar-fill { height: 100%; background: var(--brand-star); }
.review-card { padding: var(--space-4) 0; border-bottom: 1px solid var(--line-alternative); }
.review-card .head { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
.review-avatar { width: 28px; height: 28px; border-radius: 50%; background: var(--primary-bg); color: var(--primary-strong); display: flex; align-items: center; justify-content: center; font: 700 12px var(--font-sans); flex-shrink: 0; }
</style>

<div class="review-header">
    <a href="/mall/product.php?id=<?php echo (int)$product_id; ?>" class="back"><svg style="width:14px;height:14px;"><use href="#i-chev-left"></use></svg> <?php echo htmlspecialchars($display_name); ?></a>

    <?php if ($stats['count'] > 0): ?>
    <div class="rating-summary">
        <div style="text-align:center;">
            <div class="avg"><?php echo $stats['avg']; ?></div>
            <div class="stars" style="justify-content:center;"><svg><use href="#i-star"></use></svg></div>
        </div>
        <div class="rating-bars">
            <?php for ($n = 5; $n >= 1; $n--): ?>
                <?php $__pct = $stats['count'] > 0 ? round($stats['breakdown'][$n] / $stats['count'] * 100) : 0; ?>
                <div class="rating-bar-row">
                    <span style="width:12px;"><?php echo $n; ?></span>
                    <div class="rating-bar-track"><div class="rating-bar-fill" style="width:<?php echo $__pct; ?>%;"></div></div>
                    <span style="width:24px;text-align:right;"><?php echo $stats['breakdown'][$n]; ?></span>
                </div>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="section" style="padding-top:0;">
    <div class="chip-row" style="margin-bottom:var(--space-3);">
        <a class="chip <?php echo $sort === 'recent' ? 'active' : ''; ?>" href="?id=<?php echo (int)$product_id; ?>&sort=recent">최근순</a>
        <a class="chip <?php echo $sort === 'rating_desc' ? 'active' : ''; ?>" href="?id=<?php echo (int)$product_id; ?>&sort=rating_desc">평점높은순</a>
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

    <?php if (empty($reviews)): ?>
        <p class="empty-state">아직 등록된 리뷰가 없습니다.</p>
    <?php else: ?>
        <?php foreach ($reviews as $rv): ?>
        <div class="review-card">
            <div class="head">
                <div class="review-avatar"><?php echo htmlspecialchars(mb_substr($rv['member_name'], 0, 1)); ?></div>
                <div>
                    <div class="stars">
                        <?php for ($i = 1; $i <= 5; $i++): ?><svg style="<?php echo $i > $rv['rating'] ? 'color:var(--line-normal);' : ''; ?>"><use href="#i-star"></use></svg><?php endfor; ?>
                    </div>
                    <div style="font:var(--t-caption1) var(--font-sans);color:var(--label-assistive);"><?php echo htmlspecialchars(mall_mask_reviewer_name($rv['member_name'])); ?> · <?php echo htmlspecialchars(substr($rv['created_at'], 0, 10)); ?></div>
                </div>
            </div>
            <?php if ($rv['comment']): ?><p style="font:var(--t-label1) var(--font-sans);color:var(--label-neutral);margin:0;"><?php echo htmlspecialchars($rv['comment']); ?></p><?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
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
