<?php
/**
 * 기사 앱 하단 탭바 — 배달목록/채팅 2개.
 * 포함하는 쪽에서 $driver(로그인 기사 정보)와 $active_driver_nav('list'|'chat')를 미리 정의해야 한다.
 */
require_once __DIR__ . '/../../lib/order_chat.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/i18n.php';
$__driver_chat_unread = mall_order_chat_unread_total_for_driver($driver['id']);
$__driver_push_csrf = mall_csrf_token();
?>
<style>body{width:100%;max-width:480px;margin:0 auto;}</style>
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
    <a href="/mall/driver/settings.php" class="<?php echo ($active_driver_nav ?? '') === 'settings' ? 'active' : ''; ?>">
        <span class="bottom-nav-icon-wrap"><i class="fas fa-gear" style="font-size:20px;"></i></span>
        <span>설정</span>
    </a>
</nav>
<script>
(function () {
    if (!window.Capacitor || !window.Capacitor.isNativePlatform || !window.Capacitor.isNativePlatform()) return;
    var Push = window.Capacitor.Plugins && window.Capacitor.Plugins.PushNotifications;
    if (!Push) return;
    Push.addListener('registration', function (token) {
        if (!token || !token.value) return;
        var p = new URLSearchParams();
        p.set('token', token.value); p.set('csrf_token', <?= json_encode($__driver_push_csrf) ?>);
        fetch('/mall/driver/ajax/register_device_token.php', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:p.toString()});
    });
    Push.addListener('pushNotificationActionPerformed', function (action) {
        var data = action && action.notification && action.notification.data;
        if (data && data.order_id) location.href = '/mall/driver/index.php';
    });
    Push.addListener('pushNotificationReceived', function (notification) {
        var data = notification && notification.data;
        if (data && data.order_id) {
            location.href = '/mall/driver/index.php';
        }
    });
    Push.requestPermissions().then(function (r) { if (r.receive === 'granted') Push.register(); });
})();
</script>
<?php driver_i18n_ui(); ?>
