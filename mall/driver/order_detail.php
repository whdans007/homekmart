<?php
/**
 * 배송기사 — 주문 상세 + 상태 버튼 + 위치 전송
 * Design Ref: mall-delivery-dispatch.design.md §5.4, §7 — IDOR 방지(본인 배정건만), 상태별 버튼 1개만 노출
 */
require_once __DIR__ . '/../lib/driver.php';
require_once __DIR__ . '/partials/i18n.php';
require_once __DIR__ . '/../lib/delivery.php';
require_once __DIR__ . '/../lib/csrf.php';

mall_driver_require_login('/mall/driver/login.php');
$driver = mall_driver_current();
if (!$driver) {
    header('Location: /mall/driver/login.php');
    exit;
}

$order_id = (int)($_GET['id'] ?? 0);
$assignment = mall_delivery_get_active_assignment($order_id);

// IDOR 방지: 본인에게 배정된 주문이 아니면 목록으로 되돌린다.
if (!$assignment || (int)$assignment['driver_id'] !== (int)$driver['id']) {
    header('Location: /mall/driver/index.php');
    exit;
}

$conn = get_db_connection();
$stmt = $conn->prepare(
    'SELECT o.id, o.order_number, o.status, o.total_amount, o.shipping_fee, o.memo, o.member_id, m.name AS member_name, m.phone AS member_phone,
            o.ship_recipient_name, o.ship_phone, o.ship_region, o.ship_city, o.ship_barangay, o.ship_detail_address, o.ship_landmark, o.ship_lat, o.ship_lng
     FROM mall_orders o INNER JOIN mall_members m ON m.id = o.member_id
     WHERE o.id = ?'
);
$stmt->bind_param('i', $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

$items_stmt = $conn->prepare('SELECT product_name_snapshot, quantity FROM mall_order_items WHERE order_id = ?');
$items_stmt->bind_param('i', $order_id);
$items_stmt->execute();
$items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$items_stmt->close();
$conn->close();
$fresh_items = mall_fresh_order_items_get_by_order($order_id);

// 주문 확정 시점에 mall_orders에 저장해둔 배송지 스냅샷을 사용한다(이후 회원이 기본 배송지를
// 바꿔도 과거 주문 표시는 영향받지 않는다). 스냅샷 도입 이전 주문은 ship_recipient_name이 NULL이다.
$address = $order['ship_recipient_name'] !== null ? [
    'recipient_name' => $order['ship_recipient_name'],
    'phone' => $order['ship_phone'],
    'region' => $order['ship_region'],
    'city' => $order['ship_city'],
    'barangay' => $order['ship_barangay'],
    'detail_address' => $order['ship_detail_address'],
    'landmark' => $order['ship_landmark'],
    'lat' => $order['ship_lat'],
    'lng' => $order['ship_lng'],
] : null;

$csrf_token = mall_csrf_token();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo htmlspecialchars($order['order_number']); ?> - 배송기사</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="/mall/css/wanted-tokens.css">
    <link rel="stylesheet" href="/mall/css/mall.css">
    <style>
        .driver-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: var(--space-4) var(--space-5); border-bottom: 1px solid var(--line-alternative); }
        .section-card { margin: var(--space-4) var(--space-5) 0; padding: var(--space-4); border: 1px solid var(--line-alternative); border-radius: var(--radius-lg); }
        .section-card h2 { font: var(--t-label1) var(--font-sans); margin: 0 0 8px; }
        .item-row { display: flex; justify-content: space-between; font: var(--t-label2) var(--font-sans); padding: 4px 0; }
        .maps-link { display: block; text-align: center; margin-top: 10px; padding: 10px; border-radius: var(--radius-md); background: var(--primary-bg); color: var(--primary-strong); font-weight: 700; text-decoration: none; }
        #fail-reason-box { display: none; margin: var(--space-4) var(--space-5) 0; }
        #fail-reason-box textarea { width: 100%; border: 1px solid var(--line-normal); border-radius: var(--radius-sm); padding: 8px; }
        .chat-bubble { max-width: 78%; padding: 8px 12px; border-radius: var(--radius-lg); font: var(--t-label2) var(--font-sans); line-height: 1.4; }
        .chat-bubble .time { display: block; margin-top: 3px; font-size: 10px; color: var(--label-assistive); }
        .chat-bubble .read-tag { display: block; margin-top: 1px; font-size: 10px; color: var(--primary-normal); text-align: right; }
        .chat-bubble.mine { align-self: flex-end; background: var(--primary-normal); color: #fff; }
        .chat-bubble.mine .time { color: rgba(255,255,255,0.75); }
        .chat-bubble.theirs { align-self: flex-start; background: var(--bg-alternative); color: var(--label-normal); }
        .chat-bubble.theirs .role-tag { display: block; font-size: 10px; font-weight: 700; color: var(--label-alternative); margin-bottom: 2px; }
    </style>
</head>
<body>
    <div class="driver-header">
        <div style="display:flex;align-items:center;gap:10px;min-width:0;">
            <a href="/mall/driver/index.php" style="color:var(--label-normal);"><i class="fas fa-arrow-left"></i></a>
            <span style="font:700 1rem var(--font-sans);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($order['order_number']); ?></span>
        </div>
        <div style="display:flex;align-items:center;gap:10px;white-space:nowrap;">
            <span style="font:var(--t-caption1) var(--font-sans);color:var(--label-alternative);"><?php echo htmlspecialchars($driver['name']); ?>님</span>
            <a href="/mall/driver/logout.php" style="font:var(--t-caption1) var(--font-sans);color:var(--label-alternative);">로그아웃</a>
        </div>
    </div>

    <div id="action-result"></div>

    <div class="section-card">
        <h2>고객 정보</h2>
        <div class="item-row"><span>이름</span><span><?php echo htmlspecialchars($address['recipient_name'] ?? $order['member_name']); ?></span></div>
        <div class="item-row"><span>연락처</span><span><?php echo htmlspecialchars($address['phone'] ?? $order['member_phone'] ?? ''); ?></span></div>
        <?php if ($address): ?>
        <div class="item-row"><span>배송지</span><span style="text-align:right;max-width:60%;">
            <?php echo htmlspecialchars(trim(implode(' ', array_filter([$address['detail_address'], $address['barangay'], $address['city'], $address['region']])))); ?>
        </span></div>
        <div class="item-row"><span>랜드마크</span><span><?php echo htmlspecialchars($address['landmark'] ?? ''); ?></span></div>
        <?php if (!empty($address['lat']) && !empty($address['lng'])): ?>
        <a class="maps-link" target="_blank" href="https://www.google.com/maps/dir/?api=1&destination=<?php echo urlencode($address['lat'] . ',' . $address['lng']); ?>">
            <i class="fas fa-diamond-turn-right"></i> Google 지도 길찾기
        </a>
        <?php endif; ?>
        <?php else: ?>
        <p style="color:var(--brand-red);font:var(--t-caption1) var(--font-sans);">등록된 배송지가 없습니다.</p>
        <?php endif; ?>
    </div>

    <?php if (!empty($order['memo'])): ?>
    <div class="section-card">
        <h2>요청사항</h2>
        <p style="font:var(--t-label2) var(--font-sans);margin:0;"><?php echo nl2br(htmlspecialchars($order['memo'])); ?></p>
    </div>
    <?php endif; ?>

    <div class="section-card">
        <h2>상품 목록</h2>
        <?php foreach ($items as $it): ?>
            <div class="item-row"><span><?php echo htmlspecialchars($it['product_name_snapshot']); ?></span><span>× <?php echo (int)$it['quantity']; ?></span></div>
        <?php endforeach; ?>
        <?php foreach ($fresh_items as $it): ?>
            <div class="item-row"><span><span class="badge badge-green">신선</span> <?php echo htmlspecialchars($it['product_name_snapshot']); ?></span><span>× <?php echo (int)$it['quantity']; ?></span></div>
        <?php endforeach; ?>
        <?php if ((float)$order['shipping_fee'] > 0): ?>
            <div class="item-row" style="border-top:1px solid var(--line-normal);margin-top:6px;padding-top:6px;color:var(--label-alternative);">
                <span>배달료</span><span><?php echo number_format((float)$order['shipping_fee'], 2); ?></span>
            </div>
        <?php endif; ?>
        <div class="item-row" style="<?php echo (float)$order['shipping_fee'] > 0 ? '' : 'border-top:1px solid var(--line-normal);margin-top:6px;padding-top:6px;'; ?>font-weight:700;">
            <span>합계</span><span><?php echo number_format((float)$order['total_amount'], 2); ?></span>
        </div>
    </div>

    <div class="section-card">
        <h2>주문톡</h2>
        <div id="chat-msg-list" style="max-height:220px;overflow-y:auto;display:flex;flex-direction:column;gap:6px;"></div>
        <div style="display:flex;gap:8px;margin-top:8px;">
            <input type="text" id="chat-input" maxlength="500" placeholder="메시지를 입력하세요" style="flex:1;border:1px solid var(--line-normal);border-radius:var(--radius-sm);padding:8px 10px;">
            <button type="button" id="chat-send-btn" class="btn btn-primary" style="padding:0 16px;">전송</button>
        </div>
    </div>

    <div id="fail-reason-box">
        <textarea id="fail-reason-input" rows="3" placeholder="배송 실패 사유를 입력해주세요"></textarea>
        <div class="sticky-cta">
            <button type="button" id="fail-cancel-btn" class="btn" style="flex:1;background:var(--fill-strong);color:var(--label-normal);">취소</button>
            <button type="button" id="fail-submit-btn" class="btn" style="flex:2;background:var(--brand-red);color:#fff;">배송실패 제출</button>
        </div>
    </div>

    <div class="sticky-cta">
        <?php if ($assignment['status'] === 'assigned'): ?>
            <button type="button" id="start-btn" class="btn btn-primary btn-block">배송시작</button>
        <?php elseif ($assignment['status'] === 'delivering'): ?>
            <button type="button" id="fail-open-btn" class="btn" style="flex:1;background:var(--fill-strong);color:var(--label-normal);">배송실패</button>
            <button type="button" id="arrived-btn" class="btn btn-primary" style="flex:2;">도착</button>
        <?php elseif ($assignment['status'] === 'arrived'): ?>
            <button type="button" id="complete-btn" class="btn btn-primary btn-block">배송완료</button>
        <?php endif; ?>
    </div>

<script>
var MALL_ORDER_ID = <?php echo (int)$order_id; ?>;
var MALL_CSRF_TOKEN = <?php echo json_encode($csrf_token); ?>;
var mallLocationTimer = null;

function showResult(message, isError) {
    document.getElementById('action-result').innerHTML =
        '<div style="margin:0 var(--space-5);padding:8px 12px;border-radius:8px;font:var(--t-caption1) var(--font-sans);' +
        (isError ? 'background:#fef2f2;color:#991b1b;' : 'background:#ecfdf5;color:#065f46;') + '">' + message + '</div>';
}

function postDriverAction(url, extraParams) {
    var params = new URLSearchParams(extraParams || {});
    params.set('order_id', MALL_ORDER_ID);
    params.set('csrf_token', MALL_CSRF_TOKEN);
    return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
        .then(function (r) { return r.json(); });
}

function mallSendLocation() {
    if (!navigator.geolocation) return;
    navigator.geolocation.getCurrentPosition(function (position) {
        postDriverAction('/mall/driver/ajax/update_location.php', {
            lat: position.coords.latitude,
            lng: position.coords.longitude
        });
    });
}

<?php if ($assignment['status'] === 'delivering'): ?>
mallSendLocation();
mallLocationTimer = setInterval(mallSendLocation, 30000);
<?php endif; ?>

var startBtn = document.getElementById('start-btn');
if (startBtn) {
    startBtn.addEventListener('click', function () {
        startBtn.disabled = true;
        postDriverAction('/mall/driver/ajax/start_delivery.php').then(function (data) {
            if (data.success) {
                window.location.reload();
            } else {
                startBtn.disabled = false;
                showResult(data.error?.message || '오류가 발생했습니다.', true);
            }
        });
    });
}

var arrivedBtn = document.getElementById('arrived-btn');
if (arrivedBtn) {
    arrivedBtn.addEventListener('click', function () {
        arrivedBtn.disabled = true;
        postDriverAction('/mall/driver/ajax/mark_arrived.php').then(function (data) {
            if (data.success) {
                window.location.reload();
            } else {
                arrivedBtn.disabled = false;
                showResult(data.error?.message || '오류가 발생했습니다.', true);
            }
        });
    });
}

var completeBtn = document.getElementById('complete-btn');
if (completeBtn) {
    completeBtn.addEventListener('click', function () {
        completeBtn.disabled = true;
        postDriverAction('/mall/driver/ajax/complete_delivery.php').then(function (data) {
            if (data.success) {
                window.location.href = '/mall/driver/index.php';
            } else {
                completeBtn.disabled = false;
                showResult(data.error?.message || '오류가 발생했습니다.', true);
            }
        });
    });
}

var failOpenBtn = document.getElementById('fail-open-btn');
if (failOpenBtn) {
    failOpenBtn.addEventListener('click', function () {
        document.getElementById('fail-reason-box').style.display = 'block';
    });
}
var failCancelBtn = document.getElementById('fail-cancel-btn');
if (failCancelBtn) {
    failCancelBtn.addEventListener('click', function () {
        document.getElementById('fail-reason-box').style.display = 'none';
    });
}
var failSubmitBtn = document.getElementById('fail-submit-btn');
if (failSubmitBtn) {
    failSubmitBtn.addEventListener('click', function () {
        var reason = document.getElementById('fail-reason-input').value.trim();
        if (!reason) { showResult('실패 사유를 입력해주세요.', true); return; }
        failSubmitBtn.disabled = true;
        postDriverAction('/mall/driver/ajax/fail_delivery.php', { reason: reason }).then(function (data) {
            if (data.success) {
                if (mallLocationTimer) clearInterval(mallLocationTimer);
                window.location.href = '/mall/driver/index.php';
            } else {
                failSubmitBtn.disabled = false;
                showResult(data.error?.message || '오류가 발생했습니다.', true);
            }
        });
    });
}

// ---- 주문톡 ----
var CHAT_LAST_ID = 0;
var CHAT_POLL_TIMER = null;
var CHAT_MY_LAST_ID = 0;

function chatEscapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
}

var ROLE_LABELS = { member: '고객', admin: '매장', driver: '나' };

function chatRenderReadTags(readUpto) {
    // 내(기사)가 보낸 마지막 메시지에만 "읽음" 표시를 갱신한다.
    if (CHAT_MY_LAST_ID && readUpto >= CHAT_MY_LAST_ID) {
        var bubble = document.querySelector('.chat-bubble[data-msg-id="' + CHAT_MY_LAST_ID + '"]');
        if (bubble && !bubble.querySelector('.read-tag')) {
            var tag = document.createElement('span');
            tag.className = 'read-tag';
            tag.textContent = '읽음';
            bubble.appendChild(tag);
        }
    }
}

function chatAppendBubble(m) {
    var list = document.getElementById('chat-msg-list');
    var mine = m.sender_type === 'driver';
    var bubble = document.createElement('div');
    bubble.className = 'chat-bubble ' + (mine ? 'mine' : 'theirs');
    bubble.dataset.msgId = m.id || '';
    var roleTag = mine ? '' : '<span class="role-tag">' + chatEscapeHtml(ROLE_LABELS[m.sender_type] || m.sender_type) + '</span>';
    bubble.innerHTML = roleTag + chatEscapeHtml(m.message).replace(/\n/g, '<br>') + '<span class="time">' + chatEscapeHtml((m.created_at || '').substring(0, 16)) + '</span>';
    list.appendChild(bubble);
    list.scrollTop = list.scrollHeight;
    if (mine && m.id) CHAT_MY_LAST_ID = m.id;
}

function chatPoll() {
    fetch('/mall/driver/ajax/get_order_chat_messages.php?order_id=' + MALL_ORDER_ID + '&after_id=' + CHAT_LAST_ID)
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                data.data.messages.forEach(function (m) { chatAppendBubble(m); CHAT_LAST_ID = m.id; });
                chatRenderReadTags(data.data.my_read_upto || 0);
            }
        });
}

fetch('/mall/driver/ajax/get_order_chat_messages.php?order_id=' + MALL_ORDER_ID + '&after_id=0')
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.success) {
            data.data.messages.forEach(function (m) { chatAppendBubble(m); CHAT_LAST_ID = m.id; });
            chatRenderReadTags(data.data.my_read_upto || 0);
        }
        CHAT_POLL_TIMER = setInterval(chatPoll, 5000);
    });

document.getElementById('chat-send-btn').addEventListener('click', function () {
    var input = document.getElementById('chat-input');
    var message = input.value.trim();
    if (!message) return;
    var btn = this;
    btn.disabled = true;
    postDriverAction('/mall/driver/ajax/send_order_chat_message.php', { message: message }).then(function (data) {
        btn.disabled = false;
        if (data.success) {
            chatAppendBubble({ sender_type: 'driver', message: message, created_at: data.data.created_at, id: data.data.id });
            CHAT_LAST_ID = data.data.id;
            input.value = '';
        } else {
            showResult(data.error?.message || '전송에 실패했습니다.', true);
        }
    });
});
document.getElementById('chat-input').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') document.getElementById('chat-send-btn').click();
});
</script>
<?php $active_driver_nav = 'list'; require __DIR__ . '/partials/nav.php'; ?>
<?php driver_i18n_ui(); ?>
</body>
</html>
