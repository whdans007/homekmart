<?php
/**
 * 주문톡 관리 — 주문자(고객) 단위로 채팅을 모아보고 응답하는 인박스.
 * Design Ref: mall-order-chat.design.md 확장 — 관리자 요청으로 "주문 코드" 단위가 아니라
 * "주문자" 단위로 재구성. 진행중 주문은 대화 유무와 무관하게 항상 노출하고(먼저 말 걸기 가능),
 * 진행중/완료는 배송상태가 아니라 관리자가 누르는 "채팅종료" 버튼으로만 결정된다 — 종료 후에도
 * 고객/관리자/기사 누구든 새 메시지를 보내면 자동으로 "진행중"으로 돌아간다.
 * 고객을 선택하면 그 고객의 주문코드 목록(칩)이 나타나고, 칩을 눌러 주문별 대화로 전환한다.
 */
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../lib/lang_helper.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../config/mall_config.php';
require_once __DIR__ . '/../lib/order_chat.php';
require_once __DIR__ . '/../lib/delivery.php';

ensure_logged_in();
require_mall_permission('mall_management', '../../admin/index.php');

$current_page = 'order_chat.php';

$status_labels = [
    'pending' => t('mall_admin.order_status.pending'), 'confirmed' => t('mall_admin.order_status.confirmed'),
    'preparing' => t('mall_admin.order_status.preparing'), 'ready' => t('mall_admin.order_status.ready'),
    'assigned' => t('mall_admin.order_status.assigned'), 'delivering' => t('mall_admin.order_status.delivering'),
    'arrived' => t('mall_admin.order_status.arrived'), 'completed' => t('mall_admin.order_status.completed'),
    'cancelled' => t('mall_admin.order_status.cancelled'), 'delivery_failed' => t('mall_admin.order_status.delivery_failed'),
];
$rows = mall_order_chat_admin_working_orders(500);

// 원본 주문 행을 주문자(member) 단위로 묶는다.
$customers = [];
foreach ($rows as $r) {
    $mid = (int)$r['member_id'];
    if (!isset($customers[$mid])) {
        $customers[$mid] = [
            'member_id' => $mid,
            'name' => $r['member_name'],
            'orders' => [],
            'active_unread' => 0,
            'done_unread' => 0,
            'has_active' => false,
            'has_done' => false,
            'last_active_at' => null,
            'last_done_at' => null,
            'active_address' => null,
            'done_address' => null,
        ];
    }

    $is_done = (bool)$r['is_closed'];
    $activity_at = $r['last_message_at'] ?: $r['order_created_at'];
    $unread = (int)$r['unread_count'];
    $address = mall_driver_address_line($r);

    $customers[$mid]['orders'][] = [
        'id' => (int)$r['order_id'],
        'order_number' => $r['order_number'],
        'status' => $r['status'],
        'status_label' => $status_labels[$r['status']] ?? $r['status'],
        'is_done' => $is_done,
        'unread_count' => $unread,
        'last_message' => $r['last_message'],
        'address' => $address,
    ];

    if (!$is_done) {
        $customers[$mid]['has_active'] = true;
        $customers[$mid]['active_unread'] += $unread;
        if ($customers[$mid]['last_active_at'] === null || $activity_at > $customers[$mid]['last_active_at']) {
            $customers[$mid]['last_active_at'] = $activity_at;
            $customers[$mid]['active_address'] = $address;
        }
    } else {
        $customers[$mid]['has_done'] = true;
        $customers[$mid]['done_unread'] += $unread;
        if ($customers[$mid]['last_done_at'] === null || $activity_at > $customers[$mid]['last_done_at']) {
            $customers[$mid]['last_done_at'] = $activity_at;
            $customers[$mid]['done_address'] = $address;
        }
    }
}

$active_customers = array_values(array_filter($customers, function ($c) { return $c['has_active']; }));
usort($active_customers, function ($a, $b) {
    if (($a['active_unread'] > 0) !== ($b['active_unread'] > 0)) {
        return ($a['active_unread'] > 0) ? -1 : 1;
    }
    return strcmp($b['last_active_at'] ?? '', $a['last_active_at'] ?? '');
});

$done_customers = array_values(array_filter($customers, function ($c) { return $c['has_done']; }));
usort($done_customers, function ($a, $b) {
    if (($a['done_unread'] > 0) !== ($b['done_unread'] > 0)) {
        return ($a['done_unread'] > 0) ? -1 : 1;
    }
    return strcmp($b['last_done_at'] ?? '', $a['last_done_at'] ?? '');
});
?>
<!DOCTYPE html>
<html lang="<?php echo get_language(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('mall_admin.nav.order_chat'); ?> - HOME K MART <?php echo t('mall_admin.title'); ?></title>
    <link rel="icon" href="data:,">
    <link href="../../admin/css/style.css" rel="stylesheet">
    <link href="../../admin/css/design-system.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        .chat-shell { display: flex; height: calc(100vh - 110px); background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; }
        .conv-pane { width: 300px; flex-shrink: 0; border-right: 1px solid #e5e7eb; display: flex; flex-direction: column; }
        .conv-tabs { display: flex; border-bottom: 1px solid #e5e7eb; }
        .conv-tab-btn { flex: 1; padding: 10px 0; text-align: center; font-size: 12px; font-weight: 700; color: #9ca3af; background: none; border: none; border-bottom: 2px solid transparent; cursor: pointer; }
        .conv-tab-btn.active { color: #2563eb; border-bottom-color: #2563eb; }
        .conv-list { flex: 1; overflow-y: auto; }
        .customer-row { display: block; width: 100%; text-align: left; padding: 12px 14px; border-bottom: 1px solid #f3f4f6; cursor: pointer; background: none; border-left: none; border-right: none; border-top: none; }
        .customer-row:hover { background: #f9fafb; }
        .customer-row.active { background: #eff6ff; }
        .customer-row .top-line { display: flex; justify-content: space-between; align-items: center; gap: 6px; }
        .customer-row .name { font-size: 13px; font-weight: 700; color: #111; }
        .customer-row .address { font-size: 12px; color: #4b5563; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .customer-row .order-count { font-size: 11px; color: #9ca3af; margin-top: 2px; }
        .customer-row .unread-badge { flex-shrink: 0; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: #dc2626; color: #fff; font-size: 10px; font-weight: 700; line-height: 18px; text-align: center; }
        .empty-hint { padding: 24px 14px; text-align: center; color: #9ca3af; font-size: 12px; }
        .chat-pane { flex: 1; display: flex; flex-direction: column; min-width: 0; }
        .chat-pane-header { padding: 12px 16px; border-bottom: 1px solid #e5e7eb; }
        .chat-pane-header .cust-name { font-size: 13px; font-weight: 700; color: #111; }
        .order-chip-row { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
        .order-chip { padding: 4px 10px; border-radius: 999px; border: 1px solid #d1d5db; font-size: 11px; font-family: monospace; cursor: pointer; background: #fff; color: #374151; position: relative; }
        .order-chip.active { background: #2563eb; border-color: #2563eb; color: #fff; }
        .order-chip .status-tag { font-family: -apple-system, sans-serif; margin-left: 4px; opacity: .8; }
        .order-chip .dot { display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: #dc2626; margin-left: 4px; }
        #chat-msg-list { flex: 1; overflow-y: auto; padding: 14px 16px; display: flex; flex-direction: column; gap: 8px; }
        .chat-bubble { max-width: 70%; padding: 8px 12px; border-radius: 12px; font-size: 12px; line-height: 1.45; }
        .chat-bubble .time { display: block; margin-top: 3px; font-size: 10px; opacity: .7; }
        .chat-bubble .read-tag { display: block; margin-top: 1px; font-size: 10px; color: #93c5fd; text-align: right; }
        .chat-bubble .role-tag { display: block; font-size: 10px; font-weight: 700; color: #6b7280; margin-bottom: 2px; }
        .chat-bubble.admin { align-self: flex-end; background: #2563eb; color: #fff; }
        .chat-bubble.member { align-self: flex-start; background: #f3f4f6; color: #111; }
        .chat-bubble.driver { align-self: flex-start; background: #fef3c7; color: #111; }
        .chat-empty-state { margin: auto; text-align: center; color: #9ca3af; font-size: 12px; }
        .chat-input-row { display: flex; gap: 8px; padding: 12px 16px; border-top: 1px solid #e5e7eb; }
        .chat-input-row input { flex: 1; border: 1px solid #d1d5db; border-radius: 8px; padding: 8px 12px; font-size: 12px; }
        .chat-input-row button:disabled { opacity: .5; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<main class="p-6">
    <h1 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-comments mr-2"></i><?php echo t('mall_admin.nav.order_chat'); ?></h1>

    <div class="chat-shell">
        <div class="conv-pane">
            <div class="conv-tabs">
                <button type="button" class="conv-tab-btn active" data-tab="active"><?php echo t('mall_admin.order_chat.active'); ?></button>
                <button type="button" class="conv-tab-btn" data-tab="done"><?php echo t('mall_admin.order_chat.done'); ?></button>
            </div>
            <div class="conv-list" id="conv-list-active">
                <?php if (empty($active_customers)): ?>
                    <div class="empty-hint"><?php echo t('mall_admin.order_chat.no_active'); ?></div>
                <?php else: ?>
                    <?php foreach ($active_customers as $c): ?>
                        <button type="button" class="customer-row" data-member-id="<?php echo $c['member_id']; ?>" data-orders='<?php echo htmlspecialchars(json_encode($c['orders']), ENT_QUOTES); ?>' data-name="<?php echo htmlspecialchars($c['name'], ENT_QUOTES); ?>">
                            <div class="top-line">
                                <span class="name"><?php echo htmlspecialchars($c['name']); ?></span>
                                <?php if ($c['active_unread'] > 0): ?>
                                    <span class="unread-badge"><?php echo $c['active_unread'] > 99 ? '99+' : $c['active_unread']; ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="address"><?php echo htmlspecialchars($c['active_address'] ?? ''); ?></div>
                            <div class="order-count"><?php echo t('mall_admin.order_chat.active_order_count', ['count' => count(array_filter($c['orders'], function ($o) { return !$o['is_done']; }))]); ?></div>
                        </button>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="conv-list" id="conv-list-done" style="display:none;">
                <?php if (empty($done_customers)): ?>
                    <div class="empty-hint"><?php echo t('mall_admin.order_chat.no_done'); ?></div>
                <?php else: ?>
                    <?php foreach ($done_customers as $c): ?>
                        <button type="button" class="customer-row" data-member-id="<?php echo $c['member_id']; ?>" data-orders='<?php echo htmlspecialchars(json_encode($c['orders']), ENT_QUOTES); ?>' data-name="<?php echo htmlspecialchars($c['name'], ENT_QUOTES); ?>">
                            <div class="top-line">
                                <span class="name"><?php echo htmlspecialchars($c['name']); ?></span>
                                <?php if ($c['done_unread'] > 0): ?>
                                    <span class="unread-badge"><?php echo $c['done_unread'] > 99 ? '99+' : $c['done_unread']; ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="address"><?php echo htmlspecialchars($c['done_address'] ?? ''); ?></div>
                            <div class="order-count"><?php echo t('mall_admin.order_chat.done_order_count', ['count' => count(array_filter($c['orders'], function ($o) { return $o['is_done']; }))]); ?></div>
                        </button>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="chat-pane">
            <div class="chat-pane-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;">
                <div style="min-width:0;">
                    <div class="cust-name" id="chat-cust-name"><?php echo t('mall_admin.order_chat.select_customer_hint'); ?></div>
                    <div class="order-chip-row" id="order-chip-row"></div>
                </div>
                <button type="button" id="close-chat-btn" class="px-2.5 py-1 text-xs font-semibold bg-gray-100 text-gray-600 rounded-md hover:bg-gray-200 flex-shrink-0" disabled><?php echo t('mall_admin.order_chat.close_chat'); ?></button>
            </div>
            <div id="chat-msg-list"><div class="chat-empty-state"><?php echo t('mall_admin.order_chat.select_conversation_hint'); ?></div></div>
            <div class="chat-input-row">
                <input type="text" id="chat-input" maxlength="500" placeholder="<?php echo htmlspecialchars(t('mall_admin.order_chat.message_placeholder')); ?>" disabled>
                <button type="button" id="chat-send-btn" class="px-3 py-1.5 text-xs font-semibold bg-blue-600 text-white rounded-md hover:bg-blue-700" disabled><?php echo t('mall_admin.order_chat.send'); ?></button>
            </div>
        </div>
    </div>
</main>

<script>
var CURRENT_ORDER_ID = null;
var CURRENT_ORDERS = [];
var LAST_MSG_ID = 0;
var POLL_TIMER = null;

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
}

// ---- 탭 전환 ----
document.querySelectorAll('.conv-tab-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.conv-tab-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('conv-list-active').style.display = btn.dataset.tab === 'active' ? 'block' : 'none';
        document.getElementById('conv-list-done').style.display = btn.dataset.tab === 'done' ? 'block' : 'none';
    });
});

// ---- 채팅 메시지 ----
var CHAT_MY_LAST_ID = 0;
var CHAT_ROLE_LABELS = { member: '<?php echo addslashes(t('mall_admin.order_chat.role_member')); ?>', driver: '<?php echo addslashes(t('mall_admin.order_chat.role_driver')); ?>' };

function appendBubble(m) {
    const list = document.getElementById('chat-msg-list');
    const empty = list.querySelector('.chat-empty-state');
    if (empty) empty.remove();
    const mine = m.sender_type === 'admin';
    const bubble = document.createElement('div');
    bubble.className = 'chat-bubble ' + m.sender_type;
    bubble.dataset.msgId = m.id || '';
    const roleTag = mine ? '' : '<span class="role-tag">' + escapeHtml(CHAT_ROLE_LABELS[m.sender_type] || m.sender_type) + '</span>';
    bubble.innerHTML = roleTag + escapeHtml(m.message).replace(/\n/g, '<br>') + '<span class="time">' + escapeHtml((m.created_at || '').substring(0, 16)) + '</span>';
    list.appendChild(bubble);
    list.scrollTop = list.scrollHeight;
    if (mine && m.id) CHAT_MY_LAST_ID = m.id;
}

// 내(관리자)가 보낸 마지막 메시지에만 "읽음" 표시를 갱신한다(고객이 읽었을 때).
function renderReadTag(readUpto) {
    if (!CHAT_MY_LAST_ID || readUpto < CHAT_MY_LAST_ID) return;
    const bubble = document.querySelector('#chat-msg-list .chat-bubble[data-msg-id="' + CHAT_MY_LAST_ID + '"]');
    if (bubble && !bubble.querySelector('.read-tag')) {
        const tag = document.createElement('span');
        tag.className = 'read-tag';
        tag.textContent = '<?php echo addslashes(t('mall_admin.order_chat.read_tag')); ?>';
        bubble.appendChild(tag);
    }
}

function stopPolling() {
    if (POLL_TIMER) { clearInterval(POLL_TIMER); POLL_TIMER = null; }
}

function poll() {
    if (!CURRENT_ORDER_ID) return;
    fetch('ajax/get_order_chat_messages.php?order_id=' + CURRENT_ORDER_ID + '&after_id=' + LAST_MSG_ID)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                data.data.messages.forEach(function (m) { appendBubble(m); LAST_MSG_ID = m.id; });
                renderReadTag(data.data.my_read_upto || 0);
            }
            clearOrderUnread(CURRENT_ORDER_ID);
        });
}

function clearOrderUnread(orderId) {
    document.querySelectorAll('.customer-row').forEach(function (row) {
        if (!row.dataset.orders) return;
        try {
            const orders = JSON.parse(row.dataset.orders);
            if (orders.some(o => String(o.id) === String(orderId))) {
                const badge = row.querySelector('.unread-badge');
                if (badge) badge.remove();
            }
        } catch (e) {}
    });
    const chip = document.querySelector('.order-chip[data-order-id="' + orderId + '"]');
    if (chip) { const dot = chip.querySelector('.dot'); if (dot) dot.remove(); }
}

// ---- 칩(주문코드) 렌더 + 선택 ----
function renderOrderChips(orders, selectedOrderId) {
    const row = document.getElementById('order-chip-row');
    row.innerHTML = '';
    orders.forEach(function (o) {
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'order-chip' + (String(o.id) === String(selectedOrderId) ? ' active' : '');
        chip.dataset.orderId = o.id;
        chip.innerHTML = escapeHtml(o.order_number) + '<span class="status-tag">' + escapeHtml(o.status_label) + '</span>' + (o.unread_count > 0 ? '<span class="dot"></span>' : '');
        chip.addEventListener('click', function () { selectOrder(o.id, orders); });
        row.appendChild(chip);
    });
}

function selectOrder(orderId, orders) {
    CURRENT_ORDER_ID = orderId;
    LAST_MSG_ID = 0;
    CHAT_MY_LAST_ID = 0;
    stopPolling();
    renderOrderChips(orders, orderId);

    document.getElementById('chat-input').disabled = false;
    document.getElementById('chat-send-btn').disabled = false;
    document.getElementById('close-chat-btn').disabled = false;

    const list = document.getElementById('chat-msg-list');
    list.innerHTML = '<div class="chat-empty-state"><?php echo addslashes(t('mall_admin.order_chat.loading')); ?></div>';

    fetch('ajax/get_order_chat_messages.php?order_id=' + orderId + '&after_id=0')
        .then(r => r.json())
        .then(data => {
            list.innerHTML = '';
            if (!data.success || !data.data.messages.length) {
                list.innerHTML = '<div class="chat-empty-state"><?php echo addslashes(t('mall_admin.order_chat.no_history')); ?></div>';
            } else {
                data.data.messages.forEach(function (m) { appendBubble(m); LAST_MSG_ID = m.id; });
                if (data.success) renderReadTag(data.data.my_read_upto || 0);
            }
            clearOrderUnread(orderId);
            POLL_TIMER = setInterval(poll, 5000);
        });
}

// ---- 주문자(고객) 선택 ----
function selectCustomer(row) {
    document.querySelectorAll('.customer-row.active').forEach(r => r.classList.remove('active'));
    row.classList.add('active');

    const orders = JSON.parse(row.dataset.orders);
    CURRENT_ORDERS = orders;
    document.getElementById('chat-cust-name').textContent = row.dataset.name;

    // 안읽음이 있는 주문을 우선 선택하고, 없으면 가장 최근(목록 첫 번째) 주문을 선택한다.
    const preferred = orders.find(o => o.unread_count > 0) || orders[0];
    if (preferred) selectOrder(preferred.id, orders);
}

document.querySelectorAll('.customer-row').forEach(function (row) {
    row.addEventListener('click', function () { selectCustomer(row); });
});

document.getElementById('chat-send-btn').addEventListener('click', function () {
    const input = document.getElementById('chat-input');
    const message = input.value.trim();
    if (!message || !CURRENT_ORDER_ID) return;
    const btn = this;
    btn.disabled = true;
    const params = new URLSearchParams();
    params.set('order_id', CURRENT_ORDER_ID);
    params.set('message', message);
    params.set('csrf_token', window.MALL_CSRF_TOKEN);
    fetch('ajax/send_order_chat_reply.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            if (data.success) {
                appendBubble({ sender_type: 'admin', message: message, created_at: data.data.created_at, id: data.data.id });
                LAST_MSG_ID = data.data.id;
                input.value = '';
            } else {
                alert(data.error && data.error.message ? data.error.message : '<?php echo addslashes(t('mall_admin.order_chat.send_failed')); ?>');
            }
        })
        .catch(function () { btn.disabled = false; });
});
document.getElementById('chat-input').addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !document.getElementById('chat-send-btn').disabled) {
        document.getElementById('chat-send-btn').click();
    }
});

document.getElementById('close-chat-btn').addEventListener('click', function () {
    if (!CURRENT_ORDER_ID) return;
    if (!confirm('<?php echo addslashes(t('mall_admin.order_chat.close_confirm')); ?>')) return;
    const btn = this;
    btn.disabled = true;
    const params = new URLSearchParams();
    params.set('order_id', CURRENT_ORDER_ID);
    params.set('csrf_token', window.MALL_CSRF_TOKEN);
    fetch('ajax/close_order_chat.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.href = 'order_chat.php';
            } else {
                btn.disabled = false;
                alert(data.error && data.error.message ? data.error.message : '<?php echo addslashes(t('mall_admin.order_chat.process_failed')); ?>');
            }
        })
        .catch(function () { btn.disabled = false; });
});

// orders.php 목록의 안읽음 배지에서 ?order_id=로 들어온 경우 해당 주문자를 자동 선택한다
// (진행중/완료 두 탭을 모두 뒤져서 찾는다).
(function () {
    const params = new URLSearchParams(window.location.search);
    const deepLinkOrderId = params.get('order_id');
    if (!deepLinkOrderId) return;
    const rows = document.querySelectorAll('.customer-row');
    for (const row of rows) {
        let orders;
        try { orders = JSON.parse(row.dataset.orders); } catch (e) { continue; }
        if (orders.some(o => String(o.id) === String(deepLinkOrderId))) {
            const isDone = row.closest('#conv-list-done') !== null;
            document.querySelector('.conv-tab-btn[data-tab="' + (isDone ? 'done' : 'active') + '"]').click();
            document.querySelectorAll('.customer-row.active').forEach(r => r.classList.remove('active'));
            row.classList.add('active');
            document.getElementById('chat-cust-name').textContent = row.dataset.name;
            CURRENT_ORDERS = orders;
            selectOrder(deepLinkOrderId, orders);
            break;
        }
    }
})();
</script>
</body>
</html>
