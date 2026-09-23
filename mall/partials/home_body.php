<?php
/**
 * 홈 화면 본문 — mall/index.php(실제 고객 화면)와 mall/admin/preview_home.php(관리자 미리보기)가 공용으로 쓴다.
 * 상단바(로고+회원등급+장바구니)는 다른 화면과 동일하게 partials/header.php의 공용 .mall-topbar를 쓴다
 * (예전엔 홈 전용 .home-topbar를 따로 그렸으나, 화면마다 헤더가 달라 보인다는 피드백으로 통일했다).
 * 이 파일은 그 아래: 검색 진입 버튼 → 배송지 행 → 카테고리 8칸 → 배너 → 오늘의 특가(카운트다운)
 * → 다시 담을 시간(로그인 시에만) → 새로 들어온 한국 상품(별점 포함)을 그린다. 카드마다 담기/수량 컨트롤 포함.
 * include 전에 정의해야 하는 변수:
 *   $member, $mall_lang, $sections (mall_get_active_home_sections() 결과)
 * 선택:
 *   $show_section_placeholders (기본 false) — 관리자 미리보기에서 이미지 없는 배너도 자리표시자로 보여줄지
 *   $is_admin_preview (기본 false) — 섹션이 하나도 없을 때 "홈 레이아웃에서 추가하기" 링크를 보여줄지
 */
require_once __DIR__ . '/../lib/home_layout.php';
require_once __DIR__ . '/../lib/cart.php';

$show_wholesale_notice = $member && $member['member_type'] === 'wholesale' && $member['wholesale_status'] !== 'approved';
$__show_placeholders = $show_section_placeholders ?? false;
$__is_admin_preview = $is_admin_preview ?? false;

// 슬롯별로 바로 꺼내 쓸 수 있게 매핑(고정 순서: 배너 → 오늘의특가 → [다시담을시간] → 새상품).
$__sections_by_slot = [];
foreach ($sections as $__s) {
    $__sections_by_slot[$__s['slot_key']] = $__s;
}

// 현재 장바구니 담긴 수량(회원/게스트 공용) — 카드마다 "담기" 버튼을 보여줄지 수량 스테퍼를 보여줄지 결정.
$__home_member_id = $member ? $member['id'] : null;
$__home_guest_token = $member ? null : mall_guest_token();
$__cart_qty_map = mall_cart_get_quantities_by_product($__home_member_id, $__home_guest_token);

// 카테고리 그리드(2행 x 4열, 8개보다 많으면 옆으로 스크롤) — mark/tint 컬럼이 스키마에 없어
// 이름 첫 글자 + 고정 팔레트로 결정적으로 생성한다. 전체 대분류를 다 가져온다(8개 제한 없음).
$__tile_palette = ['#0066FF', '#00752E', '#C8102E', '#B87503', '#7C3AED', '#0891B2', '#DB2777', '#EA580C'];
$__conn = mall_get_db_connection();
$__home_categories = $__conn->query('SELECT id, name, name_en, image_url FROM categories WHERE parent_id IS NULL ORDER BY sort_order, name')->fetch_all(MYSQLI_ASSOC);

// 로그인 회원의 기본 배송지. 이전 데이터에 기본 플래그가 없더라도 최근 등록 주소를 대신 표시한다.
$__home_address = null;
if ($member) {
    $__addr_stmt = $__conn->prepare(
        'SELECT detail_address, barangay, city, region, landmark
         FROM mall_addresses
         WHERE member_id = ?
         ORDER BY is_default DESC, created_at DESC
         LIMIT 1'
    );
    $__addr_stmt->bind_param('i', $member['id']);
    $__addr_stmt->execute();
    $__home_address = $__addr_stmt->get_result()->fetch_assoc() ?: null;
    $__addr_stmt->close();
}

// 다시 담을 시간 — 관리자가 고르는 섹션이 아니라 회원별로 다른 개인화 데이터라 항상 고정으로 붙는다(비로그인이면 생략).
$__recent_cards = [];
if ($member) {
    $__rstmt = $__conn->prepare(
        'SELECT oi.product_id, MAX(o.created_at) AS last_ordered
         FROM mall_order_items oi
         INNER JOIN mall_orders o ON o.id = oi.order_id
         WHERE o.member_id = ?
         GROUP BY oi.product_id
         ORDER BY last_ordered DESC
         LIMIT 3'
    );
    $__rstmt->bind_param('i', $member['id']);
    $__rstmt->execute();
    $__recent_ids = array_column($__rstmt->get_result()->fetch_all(MYSQLI_ASSOC), 'product_id');
    $__rstmt->close();

    if (!empty($__recent_ids)) {
        $__channel = ($member['member_type'] === 'wholesale') ? 'wholesale' : 'retail';
        $__recent_rows = mall_get_products_by_ids($__recent_ids);
        $__recent_cards = mall_build_product_cards($__recent_rows, $member, $__channel, $mall_lang);
    }
}
$__conn->close();
?>

<div class="home-search-wrap">
    <a href="/mall/search.php" class="search-entry">
        <svg><use href="#i-search"></use></svg>
        <span>상품명으로 검색해보세요</span>
    </a>
</div>

<?php if ($show_wholesale_notice): ?>
<div class="wholesale-notice"><i class="fas fa-info-circle"></i> 승인 대기중 — 참고용 소매가로 표시됩니다. 관리자 승인 후 도매가가 노출됩니다.</div>
<?php endif; ?>

<a href="/mall/address.php" class="address-row">
    <svg class="pin"><use href="#i-location"></use></svg>
    <?php if ($__home_address): ?>
        <?php
        $__home_address_label = trim((string)($__home_address['detail_address'] ?? ''));
        if ($__home_address_label === '') {
            $__home_address_label = trim(implode(' ', array_filter([
                $__home_address['barangay'] ?? '',
                $__home_address['city'] ?? '',
                $__home_address['region'] ?? '',
            ])));
        }
        if ($__home_address_label === '') {
            $__home_address_label = trim((string)($__home_address['landmark'] ?? ''));
        }
        ?>
        <span class="short"><?php echo htmlspecialchars($__home_address_label ?: '등록된 배송지'); ?></span>
        <span class="change">배송지 변경</span>
    <?php else: ?>
        <span class="short">배송지 미설정</span>
        <span class="change">배송지 등록하기</span>
    <?php endif; ?>
    <svg class="chev"><use href="#i-chev-right"></use></svg>
</a>

<div class="section" style="padding-top:4px;">
    <div class="cat-grid">
        <?php foreach ($__home_categories as $cat): ?>
            <?php $__tint = $__tile_palette[$cat['id'] % count($__tile_palette)]; ?>
            <a class="cat-tile" href="/mall/category.php?id=<?php echo (int)$cat['id']; ?>">
                <?php if (!empty($cat['image_url'])): ?>
                    <span class="mark mark-image"><img src="/mall/<?php echo htmlspecialchars($cat['image_url']); ?>" alt=""></span>
                <?php else: ?>
                    <span class="mark" style="background:<?php echo $__tint; ?>;">
                        <?php echo htmlspecialchars(mb_substr(($mall_lang === 'en' && !empty($cat['name_en'])) ? $cat['name_en'] : $cat['name'], 0, 1)); ?>
                    </span>
                <?php endif; ?>
                <span class="label"><?php echo htmlspecialchars(($mall_lang === 'en' && !empty($cat['name_en'])) ? $cat['name_en'] : $cat['name']); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<?php
$__any_section = false;

if (isset($__sections_by_slot['promo_banner'])) {
    $__html = mall_render_home_section($__sections_by_slot['promo_banner'], $member, $mall_lang, $__cart_qty_map, $__show_placeholders);
    if ($__html !== '') { echo $__html; $__any_section = true; }
}
if (isset($__sections_by_slot['today_deals'])) {
    $__html = mall_render_home_section($__sections_by_slot['today_deals'], $member, $mall_lang, $__cart_qty_map, $__show_placeholders);
    if ($__html !== '') { echo $__html; $__any_section = true; }
}

if (!empty($__recent_cards)):
    $__any_section = true;
?>
<div class="section-band"></div>
<div class="section">
    <div class="section-title"><h2>다시 담을 시간</h2></div>
    <div>
        <?php foreach ($__recent_cards as $card): ?>
            <?php
            $__c = $card;
            $__price = $__c['price'];
            $__out_of_stock = $__c['stock'] <= 0;
            $__img = $__c['image_path'] ? '/mall/' . htmlspecialchars($__c['image_path']) : '/logo/homekmart_logo.png';
            $__cart = $__cart_qty_map[$__c['product_id']] ?? null;
            $__cart_qty = $__cart['quantity'] ?? 0;
            $__cart_item_id = $__cart['cart_item_id'] ?? '';
            ?>
            <div class="restock-row">
                <a href="/mall/product.php?id=<?php echo (int)$__c['product_id']; ?>" style="display:flex;align-items:center;gap:var(--space-3);flex:1;min-width:0;color:inherit;">
                    <img class="thumb" src="<?php echo $__img; ?>" alt="" style="<?php echo $__out_of_stock ? 'opacity:0.45;' : ''; ?>">
                    <div class="info">
                        <div class="name"><?php echo htmlspecialchars($__c['display_name']); ?></div>
                        <?php if ($__price['final_price'] !== null): ?>
                        <div class="price"><?php echo number_format($__price['final_price'], 2); ?></div>
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
                <?php else: ?>
                <span class="badge-oos" style="position:static;">품절</span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="section-band"></div>
<?php
if (isset($__sections_by_slot['new_arrivals'])) {
    $__html = mall_render_home_section($__sections_by_slot['new_arrivals'], $member, $mall_lang, $__cart_qty_map, $__show_placeholders);
    if ($__html !== '') { echo $__html; $__any_section = true; }
}
?>

<?php if (!$__any_section): ?>
    <p class="empty-state">
        등록된 섹션이 없습니다.
        <?php if ($__is_admin_preview): ?><br><a href="/mall/admin/home_layout.php" style="color:var(--primary-normal);font-weight:700;">홈 레이아웃에서 추가하기 →</a><?php endif; ?>
    </p>
<?php endif; ?>
