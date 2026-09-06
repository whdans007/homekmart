<?php
/**
 * 하단 탭바 — 홈/카테고리/검색/장바구니/마이 5개 탭.
 * 포함하는 쪽(footer.php)에서 $active_nav('home'|'category'|'search'|'cart'|'my')를 미리 정의해야 한다.
 */
require_once __DIR__ . '/../lib/cart.php';
require_once __DIR__ . '/../lib/fresh_cart.php';
require_once __DIR__ . '/../lib/order_chat.php';

// 아이콘 스프라이트에 line→fill 변형이 있는 것만(home/person/chat) 활성 시 fill로 바꾸고,
// 나머지(grid/search/bag)는 활성 색상(--primary-normal)만으로 구분한다.
// "주문톡"은 장바구니 바로 앞에 배치한다(요청: "장바구니 옆에").
$__nav_items = [
    ['key' => 'home',     'href' => '/mall/index.php',          'label' => '홈',      'icon' => 'home',   'has_fill' => true],
    ['key' => 'category', 'href' => '/mall/category.php',       'label' => '카테고리', 'icon' => 'grid',   'has_fill' => false],
    ['key' => 'search',   'href' => '/mall/search.php',         'label' => '검색',    'icon' => 'search', 'has_fill' => false],
    ['key' => 'chat',     'href' => '/mall/order_chat.php',     'label' => '주문톡',  'icon' => 'chat',   'has_fill' => true],
    ['key' => 'cart',     'href' => '/mall/cart.php',           'label' => '장바구니', 'icon' => 'bag',    'has_fill' => false],
    ['key' => 'my',       'href' => '/mall/my.php',             'label' => '마이',    'icon' => 'person', 'has_fill' => true],
];

// header.php에서 이미 계산해둔 $member를 그대로 쓴다(footer.php를 거쳐 여기까지 같은 스코프로 내려온다) —
// 굳이 mall_current_member()를 또 호출해 쿼리를 중복시키지 않는다.
$__nav_member = $member ?? null;
$__nav_member_id = $__nav_member ? $__nav_member['id'] : null;
$__nav_guest_token = $__nav_member ? null : mall_guest_token();
$__nav_cart_count = mall_cart_count($__nav_member_id, $__nav_guest_token)
    + mall_fresh_cart_count($__nav_member_id, $__nav_guest_token);
// 안읽음 채팅 배지는 로그인 회원에게만 표시한다(게스트는 주문톡 자체를 쓸 수 없음 — 클릭 시 로그인 유도).
$__nav_chat_unread = $__nav_member_id ? mall_order_chat_unread_count_for_member($__nav_member_id) : 0;
?>
<nav class="bottom-nav">
    <?php foreach ($__nav_items as $__item): ?>
        <?php $__active = ($active_nav ?? '') === $__item['key']; ?>
        <a href="<?php echo $__item['href']; ?>" class="<?php echo $__active ? 'active' : ''; ?><?php echo $__item['key'] === 'cart' ? ' bottom-nav-cart' : ''; ?>">
            <span class="bottom-nav-icon-wrap">
                <svg><use href="#i-<?php echo $__item['icon'] . ($__active && $__item['has_fill'] ? '-fill' : ''); ?>"></use></svg>
                <?php if ($__item['key'] === 'cart' && $__nav_cart_count > 0): ?>
                    <span class="badge-count"><?php echo $__nav_cart_count > 99 ? '99+' : $__nav_cart_count; ?></span>
                <?php endif; ?>
                <?php if ($__item['key'] === 'chat' && $__nav_chat_unread > 0): ?>
                    <span class="badge-count"><?php echo $__nav_chat_unread > 99 ? '99+' : $__nav_chat_unread; ?></span>
                <?php endif; ?>
            </span>
            <span><?php echo $__item['label']; ?></span>
        </a>
    <?php endforeach; ?>
</nav>
