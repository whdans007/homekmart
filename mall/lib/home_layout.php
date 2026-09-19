<?php
/**
 * 홈 화면 고정 슬롯 — 디자인 목업(design_handoff_home_k_mart)에 정해진 구성 그대로
 * 기획전 배너/오늘의 특가/새로 들어온 한국 상품, 딱 3개만 존재한다(자유 추가/삭제 불가).
 * 각 슬롯의 "내용"(배너 이미지·문구·링크, 상품 리스트에 어떤 상품이 들어갈지)만 관리자가 편집한다.
 * mall_home_sections 테이블을 그대로 쓰되 slot_key로 고정 슬롯을 식별하고,
 * 타입별 세부 설정은 기존처럼 config(JSON)에 저장한다.
 * 초안(draft)/발행(published) 워크플로우: 관리자는 항상 draft를 편집하고, 고객 화면은 published만 본다.
 */
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/review.php';
require_once __DIR__ . '/fresh_pricing.php';

function mall_render_fresh_home_cards(array $rows, string $class = 'h-scroll'): string {
    if (empty($rows)) return '';
    ob_start();
    ?><style>.fresh-home-card{display:block;position:relative;min-width:160px;max-width:220px;padding:10px;border:1px solid #e5e7eb;border-radius:12px;background:#fff;color:inherit}.fresh-home-card img{display:block;width:100%;height:140px;object-fit:cover;border-radius:8px;margin-bottom:8px}.fresh-home-card strong,.fresh-home-card small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.fresh-home-card small{color:#6b7280;margin-top:3px}.fresh-home-badge{display:inline-block;color:#047857;background:#d1fae5;border-radius:999px;font-size:10px;padding:2px 6px;margin-bottom:5px}.fresh-home-price{display:block;margin-top:7px;font-weight:700}</style><?php
    foreach ($rows as $fresh) {
        $price = mall_fresh_sale_unit_price($fresh);
        $name = $fresh['display_name'] ?: '';
        $image = $fresh['image_url'] ?: '/logo/homekmart_logo.png';
        ?>
        <a class="fresh-home-card" href="/mall/fresh_product.php?id=<?php echo (int)$fresh['id']; ?>">
            <img src="<?php echo htmlspecialchars($image); ?>" alt="<?php echo htmlspecialchars($name); ?>">
            <span class="fresh-home-badge">신선</span>
            <strong><?php echo htmlspecialchars($name); ?></strong>
            <?php if (!empty($fresh['display_name_en'])): ?><small><?php echo htmlspecialchars($fresh['display_name_en']); ?></small><?php endif; ?>
            <span class="fresh-home-price"><?php echo number_format($price, 2); ?></span>
        </a>
        <?php
    }
    return ob_get_clean();
}

// 디자인 목업 순서 그대로 — 새 슬롯을 추가하려면 이 배열 + 관리자 화면 패널을 함께 늘려야 한다.
const MALL_HOME_SLOTS = ['promo_banner', 'today_deals', 'new_arrivals'];

// 홈 화면에는 안 보이지만 같은 관리자 저장/발행 파이프라인(mall_home_sections)을 재사용하는 슬롯 —
// 기획전 페이지(mall/promo.php)에 노출할 상품 큐레이션. MALL_HOME_SLOTS와 분리해 홈 표시 순서 로직과
// 섞이지 않게 한다.
const MALL_PROMO_PAGE_SLOTS = ['promo_products'];

/**
 * 홈 화면 고정 슬롯 목록 조회(슬롯 순서 고정).
 * @param bool $customer_facing true면 고객 화면(발행본 + 노출 설정된 것만), false면 관리자 화면(숨김 상태도 봐야 토글 가능)
 * @param string $status 'draft'|'published'
 */
function mall_get_active_home_sections($customer_facing, $status) {
    $conn = mall_get_db_connection();
    $store_id = MALL_STORE_ID;

    // slot_key를 홈 화면 슬롯(MALL_HOME_SLOTS)으로만 한정한다 — 기획전 상품(promo_products)은
    // 같은 테이블을 쓰지만 홈이 아니라 /mall/promo.php 전용이라 여기 섞이면 안 된다.
    $home_slot_placeholders = implode(',', array_fill(0, count(MALL_HOME_SLOTS), '?'));
    $where = ['store_id = ?', 'status = ?', "slot_key IN ({$home_slot_placeholders})"];
    $params = array_merge([$store_id, $status], MALL_HOME_SLOTS);
    $types = 'is' . str_repeat('s', count(MALL_HOME_SLOTS));
    if ($customer_facing) {
        $where[] = 'is_active = 1';
    }

    $slot_order = "FIELD(slot_key, '" . implode("','", MALL_HOME_SLOTS) . "')";
    $stmt = $conn->prepare(
        "SELECT id, store_id, section_type, slot_key, title, subtitle, config, sort_order, is_active, status, published_at
         FROM mall_home_sections
         WHERE " . implode(' AND ', $where) . "
         ORDER BY {$slot_order}"
    );
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    return $rows;
}

/**
 * 고정 슬롯 하나의 현재(초안) 데이터를 가져온다 — 관리자 설정 화면에서 슬롯별 폼을 채울 때 쓴다.
 * 아직 한 번도 저장한 적 없는 슬롯이면 null(화면에서는 빈 폼으로 표시).
 */
function mall_get_home_slot($slot_key, $status = 'draft') {
    $conn = mall_get_db_connection();
    $store_id = MALL_STORE_ID;
    $stmt = $conn->prepare(
        'SELECT id, section_type, slot_key, title, subtitle, config, is_active
         FROM mall_home_sections WHERE store_id = ? AND slot_key = ? AND status = ?'
    );
    $stmt->bind_param('iss', $store_id, $slot_key, $status);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $row ?: null;
}

/**
 * 최근 발행 정보(발행일시 + 발행자 이름) — 관리자 화면 상단 안내 배너용.
 */
function mall_get_last_publish_info() {
    $conn = mall_get_db_connection();
    $store_id = MALL_STORE_ID;
    $stmt = $conn->prepare(
        "SELECT s.published_at, u.full_name AS published_by_name
         FROM mall_home_sections s
         LEFT JOIN users u ON u.id = s.published_by
         WHERE s.store_id = ? AND s.status = 'published' AND s.published_at IS NOT NULL
         ORDER BY s.published_at DESC LIMIT 1"
    );
    $stmt->bind_param('i', $store_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $row ?: null;
}

/**
 * link_type/link_value(배너 클릭 시 이동 대상)를 실제 href로 변환.
 * @param string $link_type url|category|product|promo
 */
function mall_home_section_link_href($link_type, $link_value) {
    switch ($link_type) {
        case 'category':
            return '/mall/category.php?id=' . (int)$link_value;
        case 'product':
            return '/mall/product.php?id=' . (int)$link_value;
        case 'promo':
            return '/mall/promo.php';
        case 'url':
        default:
            return $link_value !== '' ? $link_value : '#';
    }
}

function mall_render_banner_section($section, $config, $show_placeholder) {
    $image_path = $config['image_path'] ?? null;
    // 이미지 없는 배너는 고객 화면에 그대로 노출하면 어색하므로 감춘다. 관리자 미리보기에서만 자리표시자로 보여준다.
    if (!$image_path && !$show_placeholder) {
        return '';
    }
    $link_type = $config['link_type'] ?? 'url';
    $link_value = $config['link_value'] ?? '';
    $href = mall_home_section_link_href($link_type, $link_value);

    ob_start();
    ?>
    <div class="section">
        <a href="<?php echo htmlspecialchars($href); ?>" class="promo-banner" style="display:block;<?php echo $image_path ? "background-image:url('/mall/" . htmlspecialchars($image_path) . "');background-size:cover;background-position:center;" : ''; ?>">
            <div class="inner">
                <span class="eyebrow">기획전</span>
                <?php if (!empty($section['title'])): ?><p class="title"><?php echo htmlspecialchars($section['title']); ?></p><?php endif; ?>
                <?php if (!empty($section['subtitle'])): ?><p class="sub"><?php echo htmlspecialchars($section['subtitle']); ?></p><?php endif; ?>
                <?php if (!$image_path && $show_placeholder): ?><p class="sub" style="opacity:0.75;">(이미지 없음 — 고객 화면에는 표시되지 않습니다)</p><?php endif; ?>
            </div>
        </a>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * 오늘의 특가 — 가로 스크롤 딜 카드(할인뱃지/영문명 플레이트/담기·수량 컨트롤 포함).
 * @param array $cart_qty_map mall_cart_get_quantities_by_product() 결과 — 카드별 초기 담기/수량 상태 결정용
 */
function mall_render_today_deals_section($section, $config, $member, $mall_lang, $cart_qty_map) {
    $product_ids = $config['product_ids'] ?? [];
    if (empty($product_ids)) {
        return '';
    }
    $channel = ($member && $member['member_type'] === 'wholesale') ? 'wholesale' : 'retail';
    $rows = mall_get_products_by_ids($product_ids);
    $fresh_rows = mall_get_fresh_products_by_ids($config['fresh_product_ids'] ?? []);
    if (empty($rows) && empty($fresh_rows)) {
        return '';
    }
    $cards = mall_build_product_cards($rows, $member, $channel, $mall_lang);

    // 상품별 프로모(1+1/퍼센트할인/원가세일) — mall_products에 직접 저장되어 있다(상품 큐레이션 화면에서
    // 설정, ajax/save_today_deal_promo.php 참고). 판매가처럼 저장 즉시 반영되도록 draft/발행 구조를 안 거친다.
    $promos = [];
    $promo_conn = mall_get_db_connection();
    $promo_placeholders = implode(',', array_fill(0, count($product_ids), '?'));
    $promo_stmt = $promo_conn->prepare(
        "SELECT product_id, promo_type, promo_value, promo_was
         FROM mall_products
         WHERE store_id = ? AND product_id IN ({$promo_placeholders}) AND promo_type IS NOT NULL"
    );
    $promo_types = 'i' . str_repeat('i', count($product_ids));
    $promo_params = array_merge([MALL_STORE_ID], $product_ids);
    $promo_stmt->bind_param($promo_types, ...$promo_params);
    $promo_stmt->execute();
    foreach ($promo_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $__row) {
        $promos[(string)$__row['product_id']] = [
            'type' => $__row['promo_type'],
            'value' => $__row['promo_value'] !== null ? (float)$__row['promo_value'] : null,
            'was' => $__row['promo_was'] !== null ? (float)$__row['promo_was'] : null,
        ];
    }
    $promo_stmt->close();

    ob_start();
    ?>
    <div class="section">
        <div class="section-title" style="align-items:flex-end;">
            <?php if (!empty($section['title'])): ?><h2><?php echo htmlspecialchars($section['title']); ?></h2><?php endif; ?>
            <span class="deal-timer" data-deal-timer>
                <span class="lbl">오늘마감</span>
                <span class="val" data-deal-timer-value>--:--:--</span>
            </span>
        </div>
        <div class="h-scroll">
            <?php foreach ($cards as $card): ?>
                <?php $__cart = $cart_qty_map[$card['product_id']] ?? null; ?>
                <?php $__promo = $promos[(string)$card['product_id']] ?? null; ?>
                <div class="deal-card"><?php include __DIR__ . '/../partials/deal_card.php'; ?></div>
            <?php endforeach; ?>
            <?php echo mall_render_fresh_home_cards($fresh_rows); ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * 새로 들어온 한국 상품 — 2열 그리드(별점/담기·수량 컨트롤 포함).
 * @param array $cart_qty_map mall_cart_get_quantities_by_product() 결과
 */
function mall_render_new_arrivals_section($section, $config, $member, $mall_lang, $cart_qty_map) {
    $product_ids = $config['product_ids'] ?? [];
    if (empty($product_ids)) {
        return '';
    }
    $channel = ($member && $member['member_type'] === 'wholesale') ? 'wholesale' : 'retail';
    $rows = mall_get_products_by_ids($product_ids);
    $fresh_rows = mall_get_fresh_products_by_ids($config['fresh_product_ids'] ?? []);
    if (empty($rows) && empty($fresh_rows)) {
        return '';
    }
    $cards = mall_build_product_cards($rows, $member, $channel, $mall_lang);
    foreach ($cards as &$__c) {
        $stats = mall_get_product_review_stats($__c['product_id']);
        $__c['review_avg'] = $stats['avg'];
        $__c['review_count'] = $stats['count'];
    }
    unset($__c);

    ob_start();
    ?>
    <div class="section">
        <?php if (!empty($section['title'])): ?>
        <div class="section-title"><h2><?php echo htmlspecialchars($section['title']); ?></h2></div>
        <?php endif; ?>
        <div class="new-arrival-grid">
            <?php foreach ($cards as $card): ?>
                <?php $__cart = $cart_qty_map[$card['product_id']] ?? null; ?>
                <?php include __DIR__ . '/../partials/new_arrival_card.php'; ?>
            <?php endforeach; ?>
            <?php echo mall_render_fresh_home_cards($fresh_rows, 'new-arrival-grid'); ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * 섹션 1개를 HTML로 렌더링(slot_key 기준 분기 — 슬롯마다 카드 스타일이 달라 section_type만으로는 부족하다).
 * @param array $cart_qty_map mall_cart_get_quantities_by_product() 결과
 * @param bool $show_placeholder 관리자 미리보기 전용 — 이미지 없는 배너도 자리표시자로 보여줄지
 */
function mall_render_home_section($section, $member, $mall_lang, $cart_qty_map, $show_placeholder = false) {
    $config = [];
    if (!empty($section['config'])) {
        $decoded = json_decode($section['config'], true);
        if (is_array($decoded)) {
            $config = $decoded;
        }
    }

    switch ($section['slot_key'] ?? '') {
        case 'promo_banner':
            return mall_render_banner_section($section, $config, $show_placeholder);
        case 'today_deals':
            return mall_render_today_deals_section($section, $config, $member, $mall_lang, $cart_qty_map);
        case 'new_arrivals':
            return mall_render_new_arrivals_section($section, $config, $member, $mall_lang, $cart_qty_map);
        default:
            return '';
    }
}
