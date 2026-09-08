<?php
/**
 * 주문톡 — 주문 목록(order_id 없이 접근) + 주문별 채팅창(?order_id=N)
 * Design Ref: mall-order-chat.design.md §5.1, §5.4
 */
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/order_chat.php';
require_once __DIR__ . '/lib/csrf.php';

mall_require_login('/mall/login.php');
$member = mall_current_member();
$csrf_token = mall_csrf_token();

$order_id = (int)($_GET['order_id'] ?? 0);

$mall_redesigned = true;
$show_bottom_nav = true;
$active_nav = 'chat';

if ($order_id > 0) {
    // ---- 채팅창 모드 ---- 특정 주문의 대화방이므로 목록으로 돌아갈 수 있게 뒤로가기 헤더를 쓴다.
    $mall_show_back = true;
    $conn = get_db_connection();
    $stmt = $conn->prepare('SELECT id, order_number, created_at FROM mall_orders WHERE id = ? AND member_id = ?');
    $stmt->bind_param('ii', $order_id, $member['id']);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$order) {
        http_response_code(404);
        $page_title = '주문톡';
        require_once __DIR__ . '/partials/header.php';
        echo '<p class="empty-state">대상 주문을 찾을 수 없습니다.</p>';
        require_once __DIR__ . '/partials/footer.php';
        exit;
    }

    // DB 오류(예: 마이그레이션 미실행)로 화면 전체가 하얗게 죽는 걸 막는다 — 채팅만 비어있게 표시.
    try {
        $messages = mall_order_chat_list($order_id, 0);
        mall_order_chat_mark_read($order_id, 'member');
    } catch (Throwable $e) {
        error_log('order_chat.php (member) error: ' . $e->getMessage());
        $messages = [];
    }

    $page_title = '주문톡 — ' . $order['order_number'];
    require_once __DIR__ . '/partials/header.php';
?>
<style>
.chat-header { padding: var(--space-4) var(--space-5); border-bottom: 1px solid var(--line-alternative); font: 700 15px var(--font-sans); }
.chat-list { padding: var(--space-4) var(--space-5); display: flex; flex-direction: column; gap: 10px; min-height: 50vh; }
.chat-bubble { max-width: 78%; padding: 10px 14px; border-radius: var(--radius-lg); font: var(--t-label2) var(--font-sans); line-height: 1.45; }
.chat-bubble .time { display: block; margin-top: 4px; font-size: 11px; color: var(--label-assistive); }
.chat-bubble .read-tag { display: block; margin-top: 1px; font-size: 10px; color: var(--primary-normal); text-align: right; }
.chat-bubble .role-tag { display: block; font-size: 10px; font-weight: 700; color: var(--label-alternative); margin-bottom: 2px; }
.chat-bubble.mine { align-self: flex-end; background: var(--primary-normal); color: var(--static-white); border-bottom-right-radius: 4px; }
.chat-bubble.mine .time { color: rgba(255,255,255,0.75); }
.chat-bubble.theirs { align-self: flex-start; background: var(--bg-alternative); color: var(--label-normal); border-bottom-left-radius: 4px; }
.chat-empty { padding: var(--space-6) var(--space-5); text-align: center; color: var(--label-assistive); font: var(--t-label2) var(--font-sans); }
.chat-input-bar { position: sticky; bottom: 0; display: flex; gap: 8px; padding: var(--space-3) var(--space-5); background: var(--bg-normal); border-top: 1px solid var(--line-alternative); }
.chat-input-bar input[type="text"] { flex: 1; border: 1px solid var(--line-normal); border-radius: var(--radius-md); padding: 10px 14px; font: var(--t-label2) var(--font-sans); }
.chat-input-bar button { padding: 0 18px; border-radius: var(--radius-md); background: var(--primary-normal); color: var(--static-white); font-weight: 700; border: none; }
.chat-input-bar button:disabled { opacity: 0.5; }
</style>

<div class="chat-header"><?php echo htmlspecialchars($order['order_number']); ?></div>

<div class="chat-list" id="chat-list">
    <?php if (empty($messages)): ?>
        <div class="chat-empty" id="chat-empty">이 주문에 대해 문의할 내용을 남겨주세요.</div>
    <?php else: ?>
        <?php $__role_labels = ['admin' => '매장', 'driver' => '배송기사']; ?>
        <?php foreach ($messages as $m): ?>
            <div class="chat-bubble <?php echo $m['sender_type'] === 'member' ? 'mine' : 'theirs'; ?>" data-msg-id="<?php echo (int)$m['id']; ?>">
                <?php if ($m['sender_type'] !== 'member'): ?><span class="role-tag"><?php echo $__role_labels[$m['sender_type']] ?? $m['sender_type']; ?></span><?php endif; ?>
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
    var list = document.getElementById('chat-list');
    var input = document.getElementById('chat-input');
    var sendBtn = document.getElementById('chat-send-btn');
    var polling = true;
    var roleLabels = { admin: '매장', driver: '배송기사' };
    var myLastId = <?php
        $__mine = array_values(array_filter($messages, function ($m) { return $m['sender_type'] === 'member'; }));
        echo empty($__mine) ? 0 : (int)end($__mine)['id'];
    ?>;

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function appendMessage(m) {
        var empty = document.getElementById('chat-empty');
        if (empty) empty.remove();
        var mine = m.sender_type === 'member';
        var bubble = document.createElement('div');
        bubble.className = 'chat-bubble ' + (mine ? 'mine' : 'theirs');
        bubble.dataset.msgId = m.id || '';
        var roleTag = mine ? '' : '<span class="role-tag">' + escapeHtml(roleLabels[m.sender_type] || m.sender_type) + '</span>';
        bubble.innerHTML = roleTag + escapeHtml(m.message).replace(/\n/g, '<br>') + '<span class="time">' + m.created_at.substring(0, 16) + '</span>';
        list.appendChild(bubble);
        list.scrollTop = list.scrollHeight;
        if (mine && m.id) myLastId = m.id;
    }

    // 내(고객)가 보낸 마지막 메시지에만 "읽음" 표시를 갱신한다(매장이 읽었을 때).
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
        if (!polling) return;
        fetch('/mall/ajax/get_order_chat_messages.php?order_id=' + ORDER_ID + '&after_id=' + lastId)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    data.data.messages.forEach(function (m) {
                        appendMessage(m);
                        lastId = m.id;
                    });
                    renderReadTag(data.data.my_read_upto || 0);
                }
            })
            .finally(function () { if (polling) setTimeout(poll, 5000); });
    }
    setTimeout(poll, 5000);

    document.addEventListener('visibilitychange', function () {
        polling = document.visibilityState === 'visible';
        if (polling) poll();
    });

    function send() {
        var message = input.value.trim();
        if (!message) return;
        sendBtn.disabled = true;
        var params = new URLSearchParams();
        params.set('order_id', ORDER_ID);
        params.set('message', message);
        params.set('csrf_token', CSRF_TOKEN);
        fetch('/mall/ajax/send_order_chat_message.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                sendBtn.disabled = false;
                if (data.success) {
                    appendMessage({ sender_type: 'member', message: message, created_at: data.data.created_at, id: data.data.id });
                    lastId = data.data.id;
                    input.value = '';
                } else {
                    mallToast(data.error && data.error.message ? data.error.message : '오류가 발생했습니다.');
                }
            })
            .catch(function () { sendBtn.disabled = false; });
    }
    sendBtn.addEventListener('click', send);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') send(); });

    list.scrollTop = list.scrollHeight;
})();
</script>
<?php
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

// ---- 주문 목록 모드 ----
$conn = get_db_connection();
$stmt = $conn->prepare('SELECT id, order_number, created_at FROM mall_orders WHERE member_id = ? ORDER BY created_at DESC');
$stmt->bind_param('i', $member['id']);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$last_message_by_order = [];
// DB 오류(예: 마이그레이션 미실행)로 목록 화면 전체가 하얗게 죽는 걸 막는다 — 미리보기만 비어있게 표시.
try {
    if (!empty($orders)) {
        $order_ids = array_column($orders, 'id');
        $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
        $msg_stmt = $conn->prepare(
            "SELECT m.order_id, m.message, m.created_at, m.sender_type,
                    (SELECT COUNT(*) FROM mall_order_messages m2 WHERE m2.order_id = m.order_id AND m2.sender_type IN ('admin', 'driver') AND m2.is_read_by_member = 0) AS unread_count
             FROM mall_order_messages m
             INNER JOIN (
                 SELECT order_id, MAX(id) AS max_id FROM mall_order_messages WHERE order_id IN ($placeholders) GROUP BY order_id
             ) latest ON latest.order_id = m.order_id AND latest.max_id = m.id"
        );
        $msg_stmt->bind_param(str_repeat('i', count($order_ids)), ...$order_ids);
        $msg_stmt->execute();
        foreach ($msg_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $last_message_by_order[(int)$row['order_id']] = $row;
        }
        $msg_stmt->close();
    }
} catch (Throwable $e) {
    error_log('order_chat.php (list) error: ' . $e->getMessage());
}
$conn->close();

$page_title = '주문톡';
require_once __DIR__ . '/partials/header.php';
?>
<style>
.chat-order-row { display: flex; align-items: center; gap: 10px; padding: var(--space-4) var(--space-5); border-bottom: 1px solid var(--line-alternative); color: inherit; }
.chat-order-row .info { flex: 1; min-width: 0; }
.chat-order-row .num { font: 700 14px var(--font-sans); }
.chat-order-row .preview { font: var(--t-caption1) var(--font-sans); color: var(--label-assistive); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px; }
.chat-order-row .meta { text-align: right; flex-shrink: 0; }
.chat-order-row .time { font: var(--t-caption1) var(--font-sans); color: var(--label-assistive); }
.chat-order-row .unread-badge { display: inline-block; margin-top: 4px; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: var(--brand-red); color: var(--static-white); font: 700 11px/18px var(--font-sans); text-align: center; }
</style>

<?php if (empty($orders)): ?>
    <p class="empty-state">주문 후 이용 가능합니다.<br><a href="/mall/index.php" style="color:var(--primary-normal);font-weight:700;">쇼핑하러 가기</a></p>
<?php else: ?>
<?php foreach ($orders as $o): ?>
    <?php $last = $last_message_by_order[$o['id']] ?? null; ?>
    <a class="chat-order-row" href="/mall/order_chat.php?order_id=<?php echo (int)$o['id']; ?>">
        <div class="info">
            <div class="num"><?php echo htmlspecialchars($o['order_number']); ?></div>
            <div class="preview"><?php echo $last ? htmlspecialchars($last['message']) : '대화를 시작해보세요'; ?></div>
        </div>
        <div class="meta">
            <div class="time"><?php echo htmlspecialchars(substr($last['created_at'] ?? $o['created_at'], 0, 10)); ?></div>
            <?php if ($last && (int)$last['unread_count'] > 0): ?>
                <span class="unread-badge"><?php echo (int)$last['unread_count'] > 99 ? '99+' : (int)$last['unread_count']; ?></span>
            <?php endif; ?>
        </div>
    </a>
<?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
