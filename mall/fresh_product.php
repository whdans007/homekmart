<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/cart.php';
require_once __DIR__ . '/lib/fresh_cart.php';
require_once __DIR__ . '/lib/csrf.php';

$mall_redesigned = true;
$product_id = (int)($_GET['id'] ?? 0);
$product = $product_id > 0 ? mall_fresh_product_get($product_id) : null;

if (!$product || $product['status'] !== 'active') {
    http_response_code(404);
    $page_title = '상품을 찾을 수 없습니다';
    $show_bottom_nav = true;
    require_once __DIR__ . '/partials/header.php';
    echo '<p class="empty-state">상품을 찾을 수 없습니다.</p>';
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

// 상세 조회 계약에는 이미지가 없으므로, 표시 전용 컬럼만 별도로 읽는다.
$conn = mall_get_db_connection();
$image_stmt = $conn->prepare('SELECT image_url FROM mall_fresh_products WHERE id = ?');
$image_stmt->bind_param('i', $product_id);
$image_stmt->execute();
$image_url = (string)($image_stmt->get_result()->fetch_assoc()['image_url'] ?? '');
$image_stmt->close();
$conn->close();

$page_title = $product['name_ko'];
$show_bottom_nav = true;
require_once __DIR__ . '/partials/header.php';

$display_name = ($mall_lang === 'en' && !empty($product['name_en'])) ? $product['name_en'] : $product['name_ko'];
$is_weight = $product['sale_type'] === 'weight';
$is_sold_out = (int)$product['is_sold_out'] === 1;
$unit_price = (float)$product['price_per_100g'];
$member_id = $member ? (int)$member['id'] : null;
$guest_token = $member ? null : mall_guest_token();
$cart_count = mall_cart_count($member_id, $guest_token) + mall_fresh_cart_count($member_id, $guest_token);
$csrf_token = mall_csrf_token();
$image_src = $image_url !== '' ? $image_url : '/logo/homekmart_logo.png';
?>
<style>
.fp-topbar { position:sticky;top:0;z-index:20;display:flex;align-items:center;justify-content:space-between;padding:calc(var(--space-3) + env(safe-area-inset-top,0px)) 6px var(--space-2);background:var(--sticky-bg);backdrop-filter:blur(12px); }
.fp-hero { position:relative;background:var(--fill-normal);margin-top:-52px; }
.fp-hero img { width:100%;aspect-ratio:1/1;object-fit:cover;display:block; }
.fp-body { padding:var(--space-5) var(--space-5) 120px; }
.fp-name { font:var(--t-heading1) var(--font-sans);margin:var(--space-3) 0 2px; }
.fp-name-en { font:500 13px/1.5 var(--font-sans);color:var(--label-alternative); }
.fp-price { font:var(--t-title2) var(--font-sans);margin-top:var(--space-4); }
.fp-help { font:var(--t-caption1) var(--font-sans);color:var(--label-alternative);margin-top:5px; }
.fp-estimate { display:flex;justify-content:space-between;align-items:center;padding:var(--space-3) var(--space-4);margin-top:var(--space-4);border-radius:var(--radius-lg);background:var(--primary-bg); }
</style>

<div class="fp-topbar">
    <a href="javascript:history.back()" class="icon-btn"><svg><use href="#i-chev-left"></use></svg></a>
    <a href="/mall/cart.php" class="icon-btn" style="position:relative;">
        <svg><use href="#i-bag"></use></svg>
        <?php if ($cart_count > 0): ?><span class="badge-count"><?php echo $cart_count > 99 ? '99+' : $cart_count; ?></span><?php endif; ?>
    </a>
</div>
<div class="fp-hero">
    <img src="<?php echo htmlspecialchars($image_src); ?>" alt="<?php echo htmlspecialchars($display_name); ?>" style="<?php echo $is_sold_out ? 'opacity:.55;' : ''; ?>">
</div>
<div class="fp-body">
    <div><span class="badge badge-green">신선</span><?php if ($is_sold_out): ?> <span class="badge-oos" style="position:static;">품절</span><?php endif; ?></div>
    <h1 class="fp-name"><?php echo htmlspecialchars($product['name_ko']); ?></h1>
    <?php if (!empty($product['name_en'])): ?><div class="fp-name-en"><?php echo htmlspecialchars($product['name_en']); ?></div><?php endif; ?>
    <div class="fp-price"><?php echo number_format($unit_price, 2); ?></div>
    <div class="fp-help"><?php echo $is_weight ? '100g당 가격' : '개당 가격'; ?></div>
    <?php if ($is_weight): ?>
    <div class="fp-estimate"><span>예상금액</span><strong id="fresh-estimated-price"><?php echo number_format($unit_price, 2); ?></strong></div>
    <p class="fp-help">예상금액이며 실제 무게에 따라 달라질 수 있습니다.</p>
    <?php endif; ?>
</div>

<div class="sticky-cta" style="flex-direction:column;align-items:stretch;gap:10px;">
    <div style="display:flex;align-items:center;justify-content:space-between;">
        <span style="font:600 14px/1 var(--font-sans);"><?php echo $is_weight ? '무게' : '수량'; ?></span>
        <div class="qty-stepper">
            <button type="button" id="fresh-minus" <?php echo $is_sold_out ? 'disabled' : ''; ?>><svg><use href="#i-minus"></use></svg></button>
            <span class="qty-value" id="fresh-value"><?php echo $is_weight ? '100g' : '1'; ?></span>
            <button type="button" id="fresh-plus" <?php echo $is_sold_out ? 'disabled' : ''; ?>><svg><use href="#i-plus"></use></svg></button>
        </div>
    </div>
    <button id="add-fresh-btn" class="btn btn-primary btn-block" <?php echo $is_sold_out ? 'disabled' : ''; ?>><?php echo $is_sold_out ? '품절된 상품입니다' : '장바구니 담기'; ?></button>
</div>
<?php if (!$is_sold_out): ?>
<script>
(function () {
    const isWeight = <?php echo $is_weight ? 'true' : 'false'; ?>;
    const unitPrice = <?php echo json_encode($unit_price); ?>;
    let value = isWeight ? 100 : 1;
    const valueEl = document.getElementById('fresh-value');
    const estimateEl = document.getElementById('fresh-estimated-price');
    function render() {
        valueEl.textContent = isWeight ? value + 'g' : value;
        if (estimateEl) estimateEl.textContent = (unitPrice * value / 100).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
    }
    document.getElementById('fresh-minus').addEventListener('click', function () { if (value > (isWeight ? 100 : 1)) { value -= isWeight ? 100 : 1; render(); } });
    document.getElementById('fresh-plus').addEventListener('click', function () { value += isWeight ? 100 : 1; render(); });
    document.getElementById('add-fresh-btn').addEventListener('click', function () {
        const btn = this;
        const params = new URLSearchParams();
        params.set('mall_fresh_product_id', '<?php echo $product_id; ?>');
        params.set(isWeight ? 'weight_g' : 'quantity', value);
        params.set('csrf_token', '<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>');
        btn.disabled = true;
        fetch('/mall/ajax/add_fresh_to_cart.php', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:params.toString()})
            .then(r => r.json()).then(data => {
                btn.disabled = false;
                if (data.success) { mallToast('장바구니에 담았습니다.', '/mall/cart.php', '보기'); mallUpdateCartBadge(data.data.cart_count); }
                else { mallToast(data.error?.message || '오류가 발생했습니다.'); }
            }).catch(() => { btn.disabled = false; mallToast('오류가 발생했습니다.'); });
    });
})();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
