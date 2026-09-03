<?php
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../config/mall_config.php';
require_once __DIR__ . '/../lib/order_chat.php';
require_once __DIR__ . '/../lib/delivery.php';

ensure_logged_in();
require_permission('mall_management', '../../admin/index.php');

$current_page = 'orders.php';

$channel_filter = $_GET['channel'] ?? '';
$status_filter = $_GET['status'] ?? '';

$where = ['o.store_id = ?'];
$params = [MALL_STORE_ID];
$types = 'i';

$all_statuses = ['pending', 'confirmed', 'preparing', 'ready', 'assigned', 'delivering', 'arrived', 'completed', 'cancelled', 'delivery_failed'];

if (in_array($channel_filter, ['retail', 'wholesale'], true)) {
    $where[] = 'o.channel = ?';
    $params[] = $channel_filter;
    $types .= 's';
}
if (in_array($status_filter, $all_statuses, true)) {
    $where[] = 'o.status = ?';
    $params[] = $status_filter;
    $types .= 's';
}

$conn = get_db_connection();
$stmt = $conn->prepare(
    "SELECT o.id, o.order_number, o.channel, o.total_amount, o.status, o.created_at,
            o.ship_detail_address, o.ship_barangay, o.ship_city,
            m.name AS member_name, m.email,
            cd.name AS current_driver_name
     FROM mall_orders o
     INNER JOIN mall_members m ON m.id = o.member_id
     LEFT JOIN mall_drivers cd ON cd.id = o.current_driver_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY o.created_at DESC LIMIT 200"
);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

$chat_unread_map = mall_order_chat_unread_map_for_admin(array_column($orders, 'id'));

$status_labels = [
    'pending' => '접수대기', 'confirmed' => '확인됨', 'preparing' => '상품준비중', 'ready' => '준비완료',
    'assigned' => '배정됨', 'delivering' => '배송중', 'arrived' => '도착', 'completed' => '완료',
    'cancelled' => '취소', 'delivery_failed' => '배송실패',
];
$status_color = [
    'pending' => 'bg-gray-100 text-gray-600', 'confirmed' => 'bg-gray-100 text-gray-600',
    'preparing' => 'bg-yellow-100 text-yellow-800', 'ready' => 'bg-blue-100 text-blue-800',
    'assigned' => 'bg-indigo-100 text-indigo-800', 'delivering' => 'bg-indigo-100 text-indigo-800',
    'arrived' => 'bg-green-100 text-green-800', 'completed' => 'bg-green-100 text-green-800',
    'cancelled' => 'bg-gray-100 text-gray-500', 'delivery_failed' => 'bg-red-100 text-red-700',
];
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>주문 관리 - HOME K MART 쇼핑몰</title>
    <link rel="icon" href="data:,">
    <link href="../../admin/css/style.css" rel="stylesheet">
    <link href="../../admin/css/design-system.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        .order-row { cursor: pointer; }
        .order-row:hover { background: #f9fafb; }
        #order-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: none; align-items: center; justify-content: center; z-index: 50; padding: 16px; }
        #order-modal { background: #fff; border-radius: 10px; max-width: 640px; width: 100%; max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 16px; border-bottom: 1px solid #e5e7eb; }
        .modal-section { padding: 16px; border-bottom: 1px solid #f3f4f6; }
        .modal-section:last-child { border-bottom: none; }
        .modal-section h3 { font-size: 12px; font-weight: 700; color: #6b7280; margin: 0 0 10px; text-transform: uppercase; letter-spacing: .03em; }
        .stepper { display: flex; align-items: flex-start; }
        .step-item { display: flex; flex-direction: column; align-items: center; min-width: 56px; font-size: 11px; color: #9ca3af; }
        .step-dot { width: 30px; height: 30px; border-radius: 50%; background: #e5e7eb; color: #9ca3af; display: flex; align-items: center; justify-content: center; margin-bottom: 6px; font-size: 12px; font-weight: 700; }
        .step-item.done .step-dot { background: #059669; color: #fff; }
        .step-item.current .step-dot { background: #2563eb; color: #fff; }
        .step-item.current { color: #2563eb; font-weight: 700; }
        .step-time { font-size: 10px; color: #9ca3af; margin-top: 2px; }
        .step-line { flex: 1; height: 2px; background: #e5e7eb; margin-top: 15px; min-width: 12px; }
        .step-line.done { background: #059669; }
        .status-confirm-line { font-size: 12px; color: #059669; font-weight: 700; margin: 6px 0 0; }
        .info-row { display: flex; justify-content: space-between; font-size: 12px; padding: 3px 0; }
        .info-row span:first-child { color: #6b7280; }
        #print-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 60; padding: 16px; }
        #print-modal { background: #fff; border-radius: 10px; max-width: 900px; width: 100%; height: 85vh; display: flex; flex-direction: column; }
        #print-modal-iframe { flex: 1; width: 100%; border: none; border-radius: 0 0 10px 10px; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<main class="p-6">
    <h1 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-receipt mr-2"></i>주문 관리</h1>
    <div id="flash-area"></div>

    <form method="get" class="flex gap-2 mb-4">
        <select name="channel" class="border border-gray-300 rounded-md px-2 py-1 text-xs">
            <option value="">전체 채널</option>
            <option value="retail" <?php echo $channel_filter === 'retail' ? 'selected' : ''; ?>>소매</option>
            <option value="wholesale" <?php echo $channel_filter === 'wholesale' ? 'selected' : ''; ?>>도매</option>
        </select>
        <select name="status" class="border border-gray-300 rounded-md px-2 py-1 text-xs">
            <option value="">전체 상태</option>
            <?php foreach ($status_labels as $key => $label): ?>
                <option value="<?php echo $key; ?>" <?php echo $status_filter === $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="px-3 py-1 text-xs font-semibold bg-gray-700 text-white rounded-md">필터 적용</button>
    </form>

    <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-xs">
            <thead class="bg-gray-100 text-gray-600">
                <tr>
                    <th class="px-3 py-2 text-left">주문번호</th>
                    <th class="px-3 py-2 text-left">회원</th>
                    <th class="px-3 py-2 text-left">채널</th>
                    <th class="px-3 py-2 text-left">합계</th>
                    <th class="px-3 py-2 text-left">주문일시</th>
                    <th class="px-3 py-2 text-left">상태</th>
                    <th class="px-3 py-2 text-left">배송기사</th>
                    <th class="px-3 py-2 text-center">주문톡</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($orders)): ?>
                <tr><td colspan="8" class="px-3 py-6 text-center text-gray-400">주문이 없습니다.</td></tr>
            <?php endif; ?>
            <?php foreach ($orders as $o): ?>
                <?php $__unread = $chat_unread_map[(int)$o['id']] ?? 0; ?>
                <tr class="order-row border-t border-gray-100" data-order-id="<?php echo (int)$o['id']; ?>">
                    <td class="px-3 py-2">
                        <div class="font-mono"><?php echo htmlspecialchars($o['order_number']); ?></div>
                        <div class="text-gray-600 text-[11px]"><?php echo htmlspecialchars(mall_driver_address_line($o)); ?></div>
                    </td>
                    <td class="px-3 py-2"><?php echo htmlspecialchars($o['member_name']); ?> <span class="text-gray-400">(<?php echo htmlspecialchars($o['email']); ?>)</span></td>
                    <td class="px-3 py-2"><?php echo $o['channel'] === 'wholesale' ? '<span class="text-purple-700 font-semibold">도매</span>' : '<span class="text-teal-700 font-semibold">소매</span>'; ?></td>
                    <td class="px-3 py-2"><?php echo number_format((float)$o['total_amount'], 2); ?></td>
                    <td class="px-3 py-2"><?php echo htmlspecialchars($o['created_at']); ?></td>
                    <td class="px-3 py-2"><span class="px-2 py-1 rounded-md font-semibold <?php echo $status_color[$o['status']] ?? 'bg-gray-100 text-gray-600'; ?>"><?php echo $status_labels[$o['status']] ?? $o['status']; ?></span></td>
                    <td class="px-3 py-2"><?php echo htmlspecialchars($o['current_driver_name'] ?? '-'); ?></td>
                    <td class="px-3 py-2 text-center">
                        <?php if ($__unread > 0): ?>
                            <a href="order_chat.php?order_id=<?php echo (int)$o['id']; ?>" class="chat-badge-link px-2 py-1 rounded-full bg-red-100 text-red-700 font-bold hover:bg-red-200"><?php echo $__unread > 99 ? '99+' : $__unread; ?></a>
                        <?php else: ?>
                            <span class="text-gray-300">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</main>

<div id="order-modal-backdrop">
    <div id="order-modal">
        <div class="modal-header">
            <div>
                <h2 id="modal-order-number" class="font-bold text-sm"></h2>
                <div id="modal-status-confirm" class="status-confirm-line"></div>
            </div>
            <button type="button" id="modal-close-btn" class="text-gray-400 hover:text-gray-700" style="font-size:20px;line-height:1;">&times;</button>
        </div>
        <div id="modal-loading" class="p-6 text-center text-gray-400 text-xs">불러오는 중...</div>
        <div id="modal-content" style="display:none;">
            <section class="modal-section">
                <h3>진행 상황</h3>
                <div id="modal-progress"></div>
                <div id="modal-action-area" class="mt-3"></div>
            </section>
            <section class="modal-section">
                <h3>고객 정보</h3>
                <div id="modal-customer"></div>
            </section>
            <section class="modal-section">
                <h3>주문 내역</h3>
                <div id="modal-items"></div>
                <button type="button" id="modal-print-btn" class="inline-block mt-3 px-3 py-1.5 text-xs font-semibold bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200">
                    <i class="fas fa-print mr-1"></i>피킹슬립 인쇄
                </button>
                <button type="button" id="modal-receipt-btn" class="inline-block mt-3 ml-2 px-3 py-1.5 text-xs font-semibold bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200">
                    <i class="fas fa-receipt mr-1"></i>영수증 인쇄
                </button>
                <a id="modal-chat-link" href="order_chat.php" class="inline-block mt-3 ml-2 px-3 py-1.5 text-xs font-semibold bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200">
                    <i class="fas fa-comments mr-1"></i>주문톡에서 보기
                </a>
            </section>
        </div>
    </div>
</div>

<div id="print-modal-backdrop">
    <div id="print-modal">
        <div class="modal-header">
            <h2 id="print-modal-title" class="font-bold text-sm">피킹슬립</h2>
            <div class="flex items-center gap-2">
                <button type="button" id="print-modal-print-btn" class="px-3 py-1.5 text-xs font-semibold bg-blue-600 text-white rounded-md hover:bg-blue-700">
                    <i class="fas fa-print mr-1"></i>인쇄
                </button>
                <button type="button" id="print-modal-close-btn" class="text-gray-400 hover:text-gray-700" style="font-size:20px;line-height:1;">&times;</button>
            </div>
        </div>
        <iframe id="print-modal-iframe" src="about:blank"></iframe>
    </div>
</div>

<script>
var MALL_STATUS_LABELS = {
    pending: '접수대기', confirmed: '확인됨', preparing: '상품준비중', ready: '준비완료',
    assigned: '배정됨', delivering: '배송중', arrived: '도착', completed: '완료',
    cancelled: '취소', delivery_failed: '배송실패'
};
var MALL_STATUS_COLORS = {
    pending: 'bg-gray-100 text-gray-600', confirmed: 'bg-gray-100 text-gray-600',
    preparing: 'bg-yellow-100 text-yellow-800', ready: 'bg-blue-100 text-blue-800',
    assigned: 'bg-indigo-100 text-indigo-800', delivering: 'bg-indigo-100 text-indigo-800',
    arrived: 'bg-green-100 text-green-800', completed: 'bg-green-100 text-green-800',
    cancelled: 'bg-gray-100 text-gray-500', delivery_failed: 'bg-red-100 text-red-700'
};
var MALL_HAPPY_PATH = ['pending', 'preparing', 'ready', 'assigned', 'delivering', 'arrived', 'completed'];
var MALL_CURRENT_ORDER_ID = null;

function showFlash(message, type) {
    const area = document.getElementById('flash-area');
    const color = type === 'error' ? 'bg-red-100 text-red-700 border-red-300' : 'bg-green-100 text-green-700 border-green-300';
    area.innerHTML = '<div class="mb-3 px-3 py-2 text-xs rounded border ' + color + '">' + message + '</div>';
    setTimeout(() => { area.innerHTML = ''; }, 4000);
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
}

function updateRowSummary(orderId, status, driverName) {
    const row = document.querySelector('.order-row[data-order-id="' + orderId + '"]');
    if (!row) return;
    const cells = row.querySelectorAll('td');
    cells[5].innerHTML = '<span class="px-2 py-1 rounded-md font-semibold ' + (MALL_STATUS_COLORS[status] || '') + '">' + (MALL_STATUS_LABELS[status] || status) + '</span>';
    cells[6].textContent = driverName || '-';
}

function openOrderModal(orderId) {
    MALL_CURRENT_ORDER_ID = orderId;
    document.getElementById('order-modal-backdrop').style.display = 'flex';
    document.getElementById('modal-loading').style.display = 'block';
    document.getElementById('modal-content').style.display = 'none';
    loadOrderDetail(orderId);
}

function closeOrderModal() {
    document.getElementById('order-modal-backdrop').style.display = 'none';
    MALL_CURRENT_ORDER_ID = null;
}

function openPrintModal(orderId, type) {
    const src = type === 'receipt' ? 'order_receipt.php?id=' : 'order_print.php?id=';
    document.getElementById('print-modal-title').textContent = type === 'receipt' ? '영수증' : '피킹슬립';
    document.getElementById('print-modal-iframe').src = src + encodeURIComponent(orderId);
    document.getElementById('print-modal-backdrop').style.display = 'flex';
}

function closePrintModal() {
    document.getElementById('print-modal-backdrop').style.display = 'none';
    document.getElementById('print-modal-iframe').src = 'about:blank';
}

function loadOrderDetail(orderId) {
    fetch('ajax/get_order_detail.php?order_id=' + encodeURIComponent(orderId))
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                showFlash(data.error?.message || '주문 정보를 불러오지 못했습니다.', 'error');
                closeOrderModal();
                return;
            }
            renderOrderModal(data.data);
            document.getElementById('modal-loading').style.display = 'none';
            document.getElementById('modal-content').style.display = 'block';
        })
        .catch(() => {
            showFlash('주문 정보를 불러오지 못했습니다.', 'error');
            closeOrderModal();
        });
}

// 각 단계 키에 해당하는 mall_orders/최신 배정 타임스탬프 필드명
var MALL_STEP_TIME_FIELD = {
    pending: 'created_at', preparing: 'confirmed_at', ready: 'ready_at',
    assigned: 'assigned_at', delivering: 'delivering_at', arrived: 'arrived_at', completed: 'completed_at'
};

function mallFormatDateTime(value) {
    if (!value) return '';
    const d = new Date(value.replace(' ', 'T'));
    if (isNaN(d.getTime())) return '';
    const pad = n => String(n).padStart(2, '0');
    return pad(d.getMonth() + 1) + '/' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
}

function renderOrderModal(data) {
    const order = data.order;
    document.getElementById('modal-order-number').innerHTML = escapeHtml(order.order_number) +
        ' <span class="px-2 py-1 rounded-md font-semibold text-xs ' + (MALL_STATUS_COLORS[order.status] || '') + '">' + (MALL_STATUS_LABELS[order.status] || order.status) + '</span>';
    document.getElementById('modal-print-btn').dataset.orderId = order.id;
    document.getElementById('modal-receipt-btn').dataset.orderId = order.id;
    document.getElementById('modal-chat-link').href = 'order_chat.php?order_id=' + order.id;

    const confirmLine = document.getElementById('modal-status-confirm');
    const timeField = MALL_STEP_TIME_FIELD[order.status];
    if (timeField && order[timeField]) {
        confirmLine.innerHTML = '<i class="fas fa-circle-check"></i> ' + (MALL_STATUS_LABELS[order.status]) + ': ' + mallFormatDateTime(order[timeField]);
        confirmLine.style.display = '';
    } else {
        confirmLine.style.display = 'none';
    }

    renderProgress(order);
    renderActionArea(order, data.active_drivers, data.default_prep_minutes);
    renderCustomer(order);
    renderItems(order, data.items);
}

function renderProgress(order) {
    const container = document.getElementById('modal-progress');
    if (order.status === 'cancelled' || order.status === 'delivery_failed') {
        const msg = order.status === 'cancelled'
            ? '이 주문은 취소되었습니다' + (order.cancel_reason ? ': ' + escapeHtml(order.cancel_reason) : '.')
            : '배송에 실패했습니다: ' + escapeHtml(order.failed_reason || '사유 없음');
        container.innerHTML = '<div class="px-3 py-2 rounded-md text-xs font-semibold ' + (MALL_STATUS_COLORS[order.status]) + '">' + msg + '</div>';
        return;
    }

    const stepIndex = MALL_HAPPY_PATH.indexOf(order.status);
    let html = '<div class="stepper">';
    MALL_HAPPY_PATH.forEach(function (key, i) {
        if (i > 0) {
            html += '<div class="step-line' + (i <= stepIndex ? ' done' : '') + '"></div>';
        }
        let cls = 'step-item';
        let dotContent = String(i + 1);
        let time = '';
        if (i < stepIndex) {
            cls += ' done';
            dotContent = '<i class="fas fa-check"></i>';
            time = mallFormatDateTime(order[MALL_STEP_TIME_FIELD[key]]);
        } else if (i === stepIndex) {
            cls += ' current';
            time = mallFormatDateTime(order[MALL_STEP_TIME_FIELD[key]]);
        }
        html += '<div class="' + cls + '"><div class="step-dot">' + dotContent + '</div>' + MALL_STATUS_LABELS[key] +
            (time ? '<div class="step-time">' + time + '</div>' : '') + '</div>';
    });
    html += '</div>';
    container.innerHTML = html;
}

function renderActionArea(order, activeDrivers, defaultPrepMinutes) {
    const area = document.getElementById('modal-action-area');
    area.innerHTML = '';

    // 배송기사 배정 전(접수대기~준비완료) 단계에서만, 고객 요청 등으로 주문을 취소할 수 있다.
    const CANCELLABLE_STATUSES = ['pending', 'confirmed', 'preparing', 'ready'];
    const cancelBtnHtml = CANCELLABLE_STATUSES.includes(order.status)
        ? '<button type="button" class="act-cancel-order-btn px-3 py-1.5 text-xs font-semibold bg-red-50 text-red-600 rounded-md hover:bg-red-100 ml-1">주문취소(고객요청)</button>'
        : '';

    if (order.status === 'pending') {
        area.innerHTML =
            '<button type="button" id="act-confirm-btn" class="px-3 py-1.5 text-xs font-semibold bg-green-600 text-white rounded-md hover:bg-green-700">접수확인</button>' +
            cancelBtnHtml +
            '<div id="act-confirm-form" class="flex items-center gap-1 mt-2" hidden>' +
                '<input type="number" id="act-prep-minutes" class="border border-gray-300 rounded px-1 py-0.5 w-20 text-xs" min="0" step="1" value="' + defaultPrepMinutes + '">' +
                '<span class="text-xs text-gray-500">분</span>' +
                '<button type="button" id="act-confirm-submit" class="px-2 py-1 text-xs font-semibold bg-green-600 text-white rounded-md hover:bg-green-700">확인</button>' +
            '</div>';
        document.getElementById('act-confirm-btn').addEventListener('click', function () {
            document.getElementById('act-confirm-btn').hidden = true;
            document.getElementById('act-confirm-form').hidden = false;
        });
        document.getElementById('act-confirm-submit').addEventListener('click', function () {
            const btn = this;
            btn.disabled = true;
            const params = new URLSearchParams();
            params.set('order_id', order.id);
            params.set('prep_minutes', document.getElementById('act-prep-minutes').value);
            params.set('csrf_token', window.MALL_CSRF_TOKEN);
            fetch('ajax/confirm_order.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    if (!data.success) { showFlash(data.error?.message || '오류가 발생했습니다.', 'error'); return; }
                    showFlash('접수확인 처리되었습니다.', 'success');
                    loadOrderDetail(order.id);
                    updateRowSummary(order.id, 'preparing', order.current_driver_name);
                });
        });
    } else if (order.status === 'preparing') {
        area.innerHTML =
            '<button type="button" id="act-ready-btn" class="px-3 py-1.5 text-xs font-semibold bg-blue-600 text-white rounded-md hover:bg-blue-700">준비완료</button> ' +
            '<button type="button" id="act-cancel-btn" class="px-3 py-1.5 text-xs font-semibold bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">접수 취소(접수대기로)</button>' +
            cancelBtnHtml;
        document.getElementById('act-ready-btn').addEventListener('click', function () {
            const btn = this;
            btn.disabled = true;
            const params = new URLSearchParams();
            params.set('order_id', order.id);
            params.set('csrf_token', window.MALL_CSRF_TOKEN);
            fetch('ajax/mark_ready.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    if (!data.success) { showFlash(data.error?.message || '오류가 발생했습니다.', 'error'); return; }
                    showFlash('준비완료 처리되었습니다.', 'success');
                    loadOrderDetail(order.id);
                    updateRowSummary(order.id, 'ready', order.current_driver_name);
                });
        });
        document.getElementById('act-cancel-btn').addEventListener('click', function () {
            if (!confirm('접수를 취소하고 접수대기 상태로 되돌릴까요?')) return;
            const btn = this;
            btn.disabled = true;
            const params = new URLSearchParams();
            params.set('order_id', order.id);
            params.set('status', 'pending');
            params.set('csrf_token', window.MALL_CSRF_TOKEN);
            fetch('ajax/update_order_status.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    if (!data.success) { showFlash(data.error?.message || '오류가 발생했습니다.', 'error'); return; }
                    showFlash('접수대기로 되돌렸습니다.', 'success');
                    loadOrderDetail(order.id);
                    updateRowSummary(order.id, 'pending', order.current_driver_name);
                });
        });
    } else if (order.status === 'ready') {
        if (!activeDrivers.length) {
            area.innerHTML = '<span class="text-gray-400 text-xs">활성 기사 없음</span> ' + cancelBtnHtml;
            return;
        }
        area.innerHTML =
            '<div class="flex items-center gap-1">' +
                '<select id="act-assign-select" class="border border-gray-300 rounded px-1 py-0.5 text-xs">' +
                    activeDrivers.map(d => '<option value="' + d.id + '">' + escapeHtml(d.name) + ' (진행중 ' + d.active_count + ')</option>').join('') +
                '</select>' +
                '<button type="button" id="act-assign-btn" class="px-2 py-1 text-xs font-semibold bg-blue-600 text-white rounded-md hover:bg-blue-700">배정</button>' +
                cancelBtnHtml +
            '</div>';
        document.getElementById('act-assign-btn').addEventListener('click', function () {
            const btn = this;
            btn.disabled = true;
            const select = document.getElementById('act-assign-select');
            const driverId = select.value;
            const driverName = select.options[select.selectedIndex].text.replace(/\s*\(진행중.*\)$/, '');
            const params = new URLSearchParams();
            params.set('order_id', order.id);
            params.set('driver_id', driverId);
            params.set('csrf_token', window.MALL_CSRF_TOKEN);
            fetch('ajax/assign_driver.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    if (!data.success) { showFlash(data.error?.message || '오류가 발생했습니다.', 'error'); return; }
                    showFlash('배송기사가 배정되었습니다.', 'success');
                    loadOrderDetail(order.id);
                    updateRowSummary(order.id, 'assigned', driverName);
                });
        });
    } else if (order.status === 'delivery_failed') {
        if (!activeDrivers.length) {
            area.innerHTML = '<span class="text-gray-400 text-xs">활성 기사 없음</span>';
            return;
        }
        area.innerHTML =
            '<div class="flex items-center gap-1">' +
                '<select id="act-reassign-select" class="border border-gray-300 rounded px-1 py-0.5 text-xs">' +
                    activeDrivers.map(d => '<option value="' + d.id + '">' + escapeHtml(d.name) + ' (진행중 ' + d.active_count + ')</option>').join('') +
                '</select>' +
                '<button type="button" id="act-reassign-btn" class="px-2 py-1 text-xs font-semibold bg-blue-600 text-white rounded-md hover:bg-blue-700">재배정</button>' +
            '</div>';
        document.getElementById('act-reassign-btn').addEventListener('click', function () {
            const btn = this;
            btn.disabled = true;
            const select = document.getElementById('act-reassign-select');
            const driverId = select.value;
            const driverName = select.options[select.selectedIndex].text.replace(/\s*\(진행중.*\)$/, '');
            const params = new URLSearchParams();
            params.set('order_id', order.id);
            params.set('driver_id', driverId);
            params.set('csrf_token', window.MALL_CSRF_TOKEN);
            fetch('ajax/reassign_driver.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    if (!data.success) { showFlash(data.error?.message || '오류가 발생했습니다.', 'error'); return; }
                    showFlash('재배정되었습니다.', 'success');
                    loadOrderDetail(order.id);
                    updateRowSummary(order.id, 'assigned', driverName);
                });
        });
    }
    // assigned/delivering/arrived/completed/cancelled: 배송기사 앱이 처리 — 관리자 액션 없음
}

function renderCustomer(order) {
    const hasSnapshot = !!order.ship_recipient_name;
    let html = '';
    html += '<div class="info-row"><span>회원</span><span>' + escapeHtml(order.member_name) + ' (' + escapeHtml(order.email) + ')</span></div>';
    if (hasSnapshot) {
        html += '<div class="info-row"><span>수령인</span><span>' + escapeHtml(order.ship_recipient_name) + '</span></div>';
        html += '<div class="info-row"><span>연락처</span><span>' + escapeHtml(order.ship_phone) + '</span></div>';
        const addr = [order.ship_detail_address, order.ship_barangay, order.ship_city, order.ship_region].filter(Boolean).join(' ');
        html += '<div class="info-row"><span>배송지</span><span style="text-align:right;max-width:65%;">' + escapeHtml(addr) + '</span></div>';
        html += '<div class="info-row"><span>랜드마크</span><span>' + escapeHtml(order.ship_landmark) + '</span></div>';
    } else {
        html += '<div class="info-row"><span>배송지</span><span class="text-gray-400">스냅샷 없음(구주문)</span></div>';
    }
    document.getElementById('modal-customer').innerHTML = html;
}

function renderItems(order, items) {
    let html = '<table class="min-w-full text-xs"><thead><tr class="text-gray-500">' +
        '<th class="px-2 py-1 text-left">상품</th><th class="px-2 py-1 text-right">단가</th>' +
        '<th class="px-2 py-1 text-right">할인율</th><th class="px-2 py-1 text-right">수량</th>' +
        '<th class="px-2 py-1 text-right">금액</th><th class="px-2 py-1 text-center">재고</th></tr></thead><tbody>';
    if (!items.length) {
        html += '<tr><td colspan="6" class="px-2 py-3 text-center text-gray-400">담긴 상품이 없습니다.</td></tr>';
    } else {
        items.forEach(function (it) {
            const soldOut = Number(it.is_sold_out) === 1;
            const strike = soldOut ? 'text-decoration:line-through;color:#9ca3af;' : '';
            html += '<tr class="border-t border-gray-200">' +
                '<td class="px-2 py-1" style="' + strike + '">' + escapeHtml(it.product_name_snapshot) + '</td>' +
                '<td class="px-2 py-1 text-right" style="' + strike + '">' + Number(it.unit_price_snapshot).toFixed(2) + '</td>' +
                '<td class="px-2 py-1 text-right" style="' + strike + '">' + Number(it.discount_rate_snapshot).toFixed(2) + '%</td>' +
                '<td class="px-2 py-1 text-right" style="' + strike + '">' + it.quantity + '</td>' +
                '<td class="px-2 py-1 text-right font-semibold" style="' + strike + '">' + Number(it.line_total).toFixed(2) + '</td>' +
                '<td class="px-2 py-1 text-center">' +
                (soldOut
                    ? '<span class="px-2 py-1 rounded-md bg-red-100 text-red-700 font-semibold mr-1">품절됨</span>' +
                      '<button type="button" class="mark-sold-out-btn px-2 py-1 rounded-md bg-gray-100 text-gray-700 hover:bg-blue-100 hover:text-blue-700 font-semibold" data-order-item-id="' + it.order_item_id + '" data-sold-out="0">품절취소</button>'
                    : '<button type="button" class="mark-sold-out-btn px-2 py-1 rounded-md bg-gray-100 text-gray-700 hover:bg-red-100 hover:text-red-700 font-semibold" data-order-item-id="' + it.order_item_id + '" data-sold-out="1">품절표시</button>') +
                '</td>' +
                '</tr>';
        });
    }
    html += '</tbody></table>';
    html += '<div style="text-align:right;margin-top:8px;font-size:12px;">' +
        '<div class="text-gray-500">소계 ' + Number(order.subtotal).toFixed(2) + '</div>' +
        '<div class="text-red-600">할인 -' + Number(order.discount_amount).toFixed(2) + '</div>' +
        '<div class="text-gray-500">배송비 ' + Number(order.shipping_fee).toFixed(2) + '</div>' +
        '<div style="font-weight:700;font-size:14px;margin-top:4px;">합계 ' + Number(order.total_amount).toFixed(2) + '</div>' +
        '</div>';
    document.getElementById('modal-items').innerHTML = html;
}

document.getElementById('modal-items').addEventListener('click', function (e) {
    const btn = e.target.closest('.mark-sold-out-btn');
    if (!btn) return;
    const soldOut = btn.dataset.soldOut === '1';
    if (!confirm(soldOut ? '이 상품을 품절 처리하시겠습니까? 이 주문의 금액이 다시 계산됩니다.' : '이 상품의 품절을 취소하시겠습니까? 이 주문의 금액이 다시 계산됩니다.')) return;
    btn.disabled = true;
    const params = new URLSearchParams();
    params.set('order_item_id', btn.dataset.orderItemId);
    params.set('sold_out', soldOut ? '1' : '0');
    params.set('csrf_token', window.MALL_CSRF_TOKEN);
    fetch('ajax/mark_sold_out.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showFlash(soldOut ? '품절 처리되었습니다.' : '품절이 취소되었습니다.', 'success');
                loadOrderDetail(data.data.order_id);
            } else {
                btn.disabled = false;
                showFlash(data.error?.message || '처리에 실패했습니다.', 'error');
            }
        });
});

document.getElementById('modal-action-area').addEventListener('click', function (e) {
    const btn = e.target.closest('.act-cancel-order-btn');
    if (!btn || !MALL_CURRENT_ORDER_ID) return;
    const reason = prompt('취소 사유를 입력해주세요(예: 고객 요청):');
    if (reason === null) return;
    const trimmed = reason.trim();
    if (!trimmed) { showFlash('취소 사유를 입력해주세요.', 'error'); return; }
    btn.disabled = true;
    const params = new URLSearchParams();
    params.set('order_id', MALL_CURRENT_ORDER_ID);
    params.set('reason', trimmed);
    params.set('csrf_token', window.MALL_CSRF_TOKEN);
    fetch('ajax/cancel_order.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showFlash('주문이 취소되었습니다.', 'success');
                loadOrderDetail(data.data.order_id);
                updateRowSummary(data.data.order_id, 'cancelled', '-');
            } else {
                btn.disabled = false;
                showFlash(data.error?.message || '처리에 실패했습니다.', 'error');
            }
        });
});

document.querySelectorAll('.order-row').forEach(function (row) {
    row.addEventListener('click', function () {
        openOrderModal(row.dataset.orderId);
    });
});
document.querySelectorAll('.chat-badge-link').forEach(function (link) {
    link.addEventListener('click', function (e) { e.stopPropagation(); });
});
document.getElementById('modal-close-btn').addEventListener('click', closeOrderModal);
document.getElementById('order-modal-backdrop').addEventListener('click', function (e) {
    if (e.target === this) closeOrderModal();
});

document.getElementById('modal-print-btn').addEventListener('click', function () {
    openPrintModal(this.dataset.orderId, 'picking');
});
document.getElementById('modal-receipt-btn').addEventListener('click', function () {
    openPrintModal(this.dataset.orderId, 'receipt');
});
document.getElementById('print-modal-close-btn').addEventListener('click', closePrintModal);
document.getElementById('print-modal-backdrop').addEventListener('click', function (e) {
    if (e.target === this) closePrintModal();
});
document.getElementById('print-modal-print-btn').addEventListener('click', function () {
    const iframe = document.getElementById('print-modal-iframe');
    iframe.contentWindow.focus();
    iframe.contentWindow.print();
});

// 배달 관리(deliveries.php) 등 다른 화면에서 ?open=<order_id>로 들어오면 해당 주문 상세를 바로 연다.
(function () {
    const params = new URLSearchParams(window.location.search);
    const openId = params.get('open');
    if (openId) openOrderModal(openId);
})();
</script>
</body>
</html>
