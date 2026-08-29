<?php
/**
 * 헤더 내 장바구니 개수 표시 위젯 — 회원/게스트(비로그인) 모두 지원한다.
 * 포함하는 쪽에서 $member를 미리 정의해야 한다(비로그인이면 null).
 */
require_once __DIR__ . '/../lib/cart.php';
$__mall_member_id = (isset($member) && $member !== null) ? $member['id'] : null;
$__mall_guest_token = $__mall_member_id ? null : mall_guest_token();
$__mall_cart_count = mall_cart_count($__mall_member_id, $__mall_guest_token);
?>
<a href="/mall/cart.php" class="icon-btn" title="장바구니">
    <svg><use href="#i-bag"></use></svg>
    <?php if ($__mall_cart_count > 0): ?>
    <span class="badge-count"><?php echo $__mall_cart_count > 99 ? '99+' : $__mall_cart_count; ?></span>
    <?php endif; ?>
</a>
