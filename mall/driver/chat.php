<?php
/**
 * 기사 앱 채팅 — 대화 목록(order_id 없이 접근) + 주문별 채팅창(?order_id=N)
 * Design Ref: mall-order-chat.design.md 확장 — 기사도 배정된 주문의 채팅에 참여
 */
require_once __DIR__ . '/../lib/driver.php';
require_once __DIR__ . '/../lib/delivery.php';
require_once __DIR__ . '/../lib/order_chat.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/partials/i18n.php';

mall_driver_require_login('/mall/driver/login.php');
$driver = mall_driver_current();
if (!$driver) {
    header('Location: /mall/driver/login.php');
    exit;
}
$csrf_token = mall_csrf_token();

$order_id = (int)($_GET['order_id'] ?? 0);
$active_driver_nav = 'chat';

if ($order_id > 0) {
    // ---- 채팅창 모드 ----
    // 배송 종료 시 채팅 차단: 현재 활성 배정이 아니면(완료/실패 등) 목록으로 되돌린다.
    $assignment = mall_delivery_get_active_assignment($order_id);
    if (!$assignment || (int)$assignment['driver_id'] !== (int)$driver['id']) {
        header('Location: /mall/driver/chat.php');
        exit;
    }

    $conn = get_db_connection();
    $stmt = $conn->prepare(
        'SELECT o.order_number, o.ship_detail_address, o.ship_barangay, o.ship_city, m.name AS member_name
         FROM mall_orders o INNER JOIN mall_members m ON m.id = o.member_id WHERE o.id = ?'
    );
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    $messages = [];
    try {
        $messages = mall_order_chat_list($order_id, 0);
        mall_order_chat_mark_read($order_id, 'driver');
    } catch (Throwable $e) {
        error_log('driver chat.php list error: ' . $e->getMessage());
    }
    ?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo htmlspecialchars($order ? mall_driver_address_line($order) : '채팅'); ?> - 배송기사</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="/mall/css/wanted-tokens.css">
    <link rel="stylesheet" href="/mall/css/mall.css">
    <style>
        body { display: flex; flex-direction: column; width:100%; max-width:480px; height:100vh; margin:0 auto; padding-bottom:0; }
        .driver-header { display: flex; align-items: center; gap: 10px; padding: var(--space-4) var(--space-5); border-bottom: 1px solid var(--line-alternative); flex-shrink: 0; }
        #chat-list { flex: 1; overflow-y: auto; padding: var(--space-4) var(--space-5); display: flex; flex-direction: column; gap: 10px; }
        .chat-bubble { max-width: 78%; padding: 10px 14px; border-radius: var(--radius-lg); font: var(--t-label2) var(--font-sans); line-height: 1.45; }
        .chat-bubble .time { display: block; margin-top: 4px; font-size: 11px; color: var(--label-assistive); }
        .chat-bubble .read-tag { display: block; margin-top: 1px; font-size: 10px; color: var(--primary-normal); text-align: right; }
        .chat-bubble .role-tag { display: block; font-size: 10px; font-weight: 700; color: var(--label-alternative); margin-bottom: 2px; }
        .chat-bubble.mine { align-self: flex-end; background: var(--primary-normal); color: #fff; }
        .chat-bubble.mine .time { color: rgba(255,255,255,0.75); }
        .chat-bubble.theirs { align-self: flex-start; background: var(--bg-alternative); color: var(--label-normal); }
        .chat-empty { margin: auto; text-align: center; color: var(--label-assistive); font: var(--t-label2) var(--font-sans); }
        .chat-input-bar { flex-shrink: 0; display: flex; gap: 8px; padding: var(--space-3) var(--space-5); border-top: 1px solid var(--line-alternative); }
        .chat-input-bar input { flex: 1; border: 1px solid var(--line-normal); border-radius: var(--radius-md); padding: 10px 14px; }
        .chat-input-bar button { padding: 0 18px; border-radius: var(--radius-md); background: var(--primary-normal); color: #fff; font-weight: 700; border: none; }
    </style>
</head>
<body>
    <div class="driver-header">
        <a href="/mall/driver/chat.php" style="color:var(--label-normal);"><i class="fas fa-arrow-left"></i></a>
        <div>
            <div style="font:700 1rem var(--font-sans);"><?php echo htmlspecialchars($order ? mall_driver_address_line($order) : ''); ?></div>
            <div style="font:var(--t-caption1) var(--font-sans);color:var(--label-alternative);"><?php echo htmlspecialchars($order['member_name'] ?? ''); ?> · <?php echo htmlspecialchars($order['order_number'] ?? ''); ?></div>
        </div>
    </div>

    <div id="chat-list">
        <?php if (empty($messages)): ?>
            <div class="chat-empty" id="chat-empty">아직 대화가 없습니다.</div>
        <?php else: ?>
            <?php $__role_labels = ['admin' => '매장', 'member' => '고객']; ?>
            <?php foreach ($messages as $m): ?>
                <div class="chat-bubble <?php echo $m['sender_type'] === 'driver' ? 'mine' : 'theirs'; ?>" data-msg-id="<?php echo (int)$m['id']; ?>">
                    <?php if ($m['sender_type'] !== 'driver'): ?><span class="role-tag"><?php echo $__role_labels[$m['sender_type']] ?? $m['sender_type']; ?></span><?php endif; ?>
                    <?php echo nl2br(htmlspecialchars($m['message'])); ?>
                    <span class="time"><?php echo htmlspecialchars(substr($m['created_at'], 0, 16)); ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="chat-input-bar">
        <input type="text" id="chat-input" maxlength="500" placeholder="메시지를 입력하세요">
        <button type="button" id="chat-send-btn">전송</button>
    </div>

<script>
(function () {
    var ORDER_ID = <?php echo (int)$order_id; ?>;
    var CSRF_TOKEN = <?php echo json_encode($csrf_token); ?>;
    var lastId = <?php echo empty($messages) ? 0 : (int)end($messages)['id']; ?>;
    var myLastId = <?php
        $__mine = array_values(array_filter($messages, function ($m) { return $m['sender_type'] === 'driver'; }));
        echo empty($__mine) ? 0 : (int)end($__mine)['id'];
    ?>;
    var roleLabels = { admin: '매장', member: '고객' };
    var list = document.getElementById('chat-list');
    var input = document.getElementById('chat-input');
    var sendBtn = document.getElementById('chat-send-btn');

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function appendMessage(m) {
        var empty = document.getElementById('chat-empty');
        if (empty) empty.remove();
        var mine = m.sender_type === 'driver';
        var bubble = document.createElement('div');
        bubble.className = 'chat-bubble ' + (mine ? 'mine' : 'theirs');
        bubble.dataset.msgId = m.id || '';
        var roleTag = mine ? '' : '<span class="role-tag">' + escapeHtml(roleLabels[m.sender_type] || m.sender_type) + '</span>';
        bubble.innerHTML = roleTag + escapeHtml(m.message).replace(/\n/g, '<br>') + '<span class="time">' + m.created_at.substring(0, 16) + '</span>';
        list.appendChild(bubble);
        list.scrollTop = list.scrollHeight;
        if (mine && m.id) myLastId = m.id;
    }

    function renderReadTag(readUpto) {
        if (!myLastId || readUpto < myLastId) return;
        var bubble = list.querySelector('.chat-bubble[data-msg-id="' + myLastId + '"]');
        if (bubble && !bubble.querySelector('.read-tag')) {
            var tag = document.createElement('span');
            tag.className = 'read-tag';
            tag.textContent = '읽음';
            bubble.appendChild(tag);
        }
    }

    function poll() {
        fetch('/mall/driver/ajax/get_order_chat_messages.php?order_id=' + ORDER_ID + '&after_id=' + lastId)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    data.data.messages.forEach(function (m) { appendMessage(m); lastId = m.id; });
                    renderReadTag(data.data.my_read_upto || 0);
                }
            });
    }
    setInterval(poll, 5000);

    function send() {
        var message = input.value.trim();
        if (!message) return;
        sendBtn.disabled = true;
        var params = new URLSearchParams();
        params.set('order_id', ORDER_ID);
        params.set('message', message);
        params.set('csrf_token', CSRF_TOKEN);
        fetch('/mall/driver/ajax/send_order_chat_message.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                sendBtn.disabled = false;
                if (data.success) {
                    appendMessage({ sender_type: 'driver', message: message, created_at: data.data.created_at, id: data.data.id });
                    lastId = data.data.id;
                    input.value = '';
                } else {
                    alert(data.error && data.error.message ? data.error.message : '오류가 발생했습니다.');
                }
            })
            .catch(function () { sendBtn.disabled = false; });
    }
    sendBtn.addEventListener('click', send);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') send(); });

    list.scrollTop = list.scrollHeight;
})();
</script>
<?php $active_driver_nav = 'chat'; require __DIR__ . '/partials/nav.php'; ?>
<?php driver_i18n_ui(); ?>
</body>
</html>
<?php
    exit;
}

// ---- 대화 목록 모드 ----
$conversations = mall_order_chat_driver_conversations($driver['id']);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>채팅 - 배송기사</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="/mall/css/wanted-tokens.css">
    <link rel="stylesheet" href="/mall/css/mall.css">
    <style>
        body { padding-bottom: 76px; }
        .driver-header { display: flex; align-items: center; justify-content: space-between; padding: var(--space-4) var(--space-5); border-bottom: 1px solid var(--line-alternative); }
        .conv-row { display: flex; align-items: center; gap: 10px; padding: var(--space-4) var(--space-5); border-bottom: 1px solid var(--line-alternative); color: inherit; text-decoration: none; }
        .conv-row .info { flex: 1; min-width: 0; }
        .conv-row .num { font: 700 14px var(--font-sans); }
        .conv-row .preview { font: var(--t-caption1) var(--font-sans); color: var(--label-assistive); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px; }
        .conv-row .unread-badge { flex-shrink: 0; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: var(--brand-red); color: #fff; font: 700 11px/18px var(--font-sans); text-align: center; }
    </style>
</head>
<body>
    <div class="driver-header">
        <div style="font:700 1rem var(--font-sans);"><i class="fas fa-comments"></i> 채팅</div>
    </div>

    <?php if (empty($conversations)): ?>
        <p class="empty-state">배정된 주문이 없습니다.</p>
    <?php else: ?>
        <?php foreach ($conversations as $c): ?>
            <a class="conv-row" href="/mall/driver/chat.php?order_id=<?php echo (int)$c['order_id']; ?>">
                <div class="info">
                    <div class="num"><?php echo htmlspecialchars(mall_driver_address_line($c)); ?></div>
                    <div class="preview"><?php echo htmlspecialchars($c['member_name']); ?><?php echo $c['last_message'] ? ' · ' . htmlspecialchars($c['last_message']) : ' · 대화를 시작해보세요'; ?></div>
                </div>
                <?php if ((int)$c['unread_count'] > 0): ?>
                    <span class="unread-badge"><?php echo (int)$c['unread_count'] > 99 ? '99+' : (int)$c['unread_count']; ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php require __DIR__ . '/partials/nav.php'; ?>
</body>
</html>
