<?php
/**
 * 기사 앱 하단 탭바 — 배달목록/채팅 2개.
 * 포함하는 쪽에서 $driver(로그인 기사 정보)와 $active_driver_nav('list'|'chat')를 미리 정의해야 한다.
 */
require_once __DIR__ . '/../../lib/order_chat.php';
$__driver_chat_unread = mall_order_chat_unread_total_for_driver($driver['id']);
?>
<nav class="bottom-nav">
    <a href="/mall/driver/index.php" class="<?php echo ($active_driver_nav ?? '') === 'list' ? 'active' : ''; ?>">
        <span class="bottom-nav-icon-wrap"><i class="fas fa-truck-fast" style="font-size:20px;"></i></span>
        <span>배달목록</span>
    </a>
    <a href="/mall/driver/chat.php" class="<?php echo ($active_driver_nav ?? '') === 'chat' ? 'active' : ''; ?>">
        <span class="bottom-nav-icon-wrap">
            <i class="fas fa-comments" style="font-size:20px;"></i>
            <?php if ($__driver_chat_unread > 0): ?>
                <span class="badge-count"><?php echo $__driver_chat_unread > 99 ? '99+' : $__driver_chat_unread; ?></span>
            <?php endif; ?>
        </span>
        <span>채팅</span>
    </a>
</nav>
