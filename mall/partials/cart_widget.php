<?php
/**
 * 헤더 내 장바구니 개수 표시 위젯 — 회원/게스트(비로그인) 모두 지원한다.
 * 포함하는 쪽에서 $member를 미리 정의해야 한다(비로그인이면 null).
 */
require_once __DIR__ . '/../lib/cart.php';
require_once __DIR__ . '/../lib/fresh_cart.php';
$__mall_member_id = (isset($member) && $member !== null) ? $member['id'] : null;
$__mall_guest_token = $__mall_member_id ? null : mall_guest_token();
// 신선상품 장바구니(mall_fresh_cart_items)는 완전히 분리된 테이블이라 개수도 따로 더한다.
// Design Ref: mall-fresh-products.design.md §4.3.
$__mall_cart_count = mall_cart_count($__mall_member_id, $__mall_guest_token)
    + mall_fresh_cart_count($__mall_member_id, $__mall_guest_token);
?>
<a href="/mall/cart.php" class="icon-btn" title="장바구니">
    <svg><use href="#i-bag"></use></svg>
    <?php if ($__mall_cart_count > 0): ?>
    <span class="badge-count"><?php echo $__mall_cart_count > 99 ? '99+' : $__mall_cart_count; ?></span>
    <?php endif; ?>
</a>
