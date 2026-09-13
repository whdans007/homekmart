<?php
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../lib/lang_helper.php';
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
    'pending' => t('mall_admin.order_status.pending'), 'confirmed' => t('mall_admin.order_status.confirmed'),
    'preparing' => t('mall_admin.order_status.preparing'), 'ready' => t('mall_admin.order_status.ready'),
    'assigned' => t('mall_admin.order_status.assigned'), 'delivering' => t('mall_admin.order_status.delivering'),
    'arrived' => t('mall_admin.order_status.arrived'), 'completed' => t('mall_admin.order_status.completed'),
    'cancelled' => t('mall_admin.order_status.cancelled'), 'delivery_failed' => t('mall_admin.order_status.delivery_failed'),
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
<html lang="<?php echo get_language(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('mall_admin.nav.orders'); ?> - HOME K MART <?php echo t('mall_admin.title'); ?></title>
    <link rel="icon" href="data:,">
    <link href="../../admin/css/style.css" rel="stylesheet">
    <link href="../../admin/css/design-system.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        .order-row { cursor: pointer; }
        .order-row:hover { background: #f9fafb; }
        #order-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: none; align-items: center; justify-content: center; z-index: 50; padding: 16px; }
        #order-modal { background: #fff; border-radius: 10px; max-width: 760px; width: 100%; max-height: 90vh; overflow-y: auto; }
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
        #fresh-price-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.4); display: none; align-items: center; justify-content: center; z-index: 70; padding: 16px; }
        #fresh-price-modal { background: #fff; border-radius: 10px; width: 100%; max-width: 360px; padding: 18px; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<main class="p-6">
    <h1 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-receipt mr-2"></i><?php echo t('mall_admin.nav.orders'); ?></h1>
    <div id="flash-area"></div>

    <form method="get" class="flex gap-2 mb-4">
        <select name="channel" class="border border-gray-300 rounded-md px-2 py-1 text-xs">
            <option value=""><?php echo t('mall_admin.orders.all_channels'); ?></option>
            <option value="retail" <?php echo $channel_filter === 'retail' ? 'selected' : ''; ?>><?php echo t('mall_admin.orders.channel_retail'); ?></option>
            <option value="wholesale" <?php echo $channel_filter === 'wholesale' ? 'selected' : ''; ?>><?php echo t('mall_admin.orders.channel_wholesale'); ?></option>
        </select>
        <select name="status" class="border border-gray-300 rounded-md px-2 py-1 text-xs">
            <option value=""><?php echo t('mall_admin.orders.all_statuses'); ?></option>
            <?php foreach ($status_labels as $key => $label): ?>
                <option value="<?php echo $key; ?>" <?php echo $status_filter === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="px-3 py-1 text-xs font-semibold bg-gray-700 text-white rounded-md"><?php echo t('mall_admin.orders.apply_filter'); ?></button>
    </form>

    <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-xs">
            <thead class="bg-gray-100 text-gray-600">
                <tr>
                    <th class="px-3 py-2 text-left"><?php echo t('mall_admin.orders.order_number'); ?></th>
                    <th class="px-3 py-2 text-left"><?php echo t('mall_admin.orders.member'); ?></th>
                    <th class="px-3 py-2 text-left"><?php echo t('mall_admin.orders.channel'); ?></th>
                    <th class="px-3 py-2 text-left"><?php echo t('mall_admin.orders.total'); ?></th>
                    <th class="px-3 py-2 text-left"><?php echo t('mall_admin.orders.order_datetime'); ?></th>
                    <th class="px-3 py-2 text-left"><?php echo t('common.status'); ?></th>
                    <th class="px-3 py-2 text-left"><?php echo t('mall_admin.orders.driver'); ?></th>
                    <th class="px-3 py-2 text-center"><?php echo t('mall_admin.nav.order_chat'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($orders)): ?>
                <tr><td colspan="8" class="px-3 py-6 text-center text-gray-400"><?php echo t('mall_admin.dashboard.no_orders'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($orders as $o): ?>
                <?php $__unread = $chat_unread_map[(int)$o['id']] ?? 0; ?>
                <tr class="order-row border-t border-gray-100" data-order-id="<?php echo (int)$o['id']; ?>">
                    <td class="px-3 py-2">
                        <div class="font-mono"><?php echo htmlspecialchars($o['order_number']); ?></div>
                        <div class="text-gray-600 text-[11px]"><?php echo htmlspecialchars(mall_driver_address_line($o)); ?></div>
                    </td>
                    <td class="px-3 py-2"><?php echo htmlspecialchars($o['member_name']); ?> <span class="text-gray-400">(<?php echo htmlspecialchars($o['email']); ?>)</span></td>
                    <td class="px-3 py-2"><?php echo $o['channel'] === 'wholesale' ? '<span class="text-purple-700 font-semibold">' . t('mall_admin.orders.channel_wholesale') . '</span>' : '<span class="text-teal-700 font-semibold">' . t('mall_admin.orders.channel_retail') . '</span>'; ?></td>
                    <td class="px-3 py-2"><?php echo number_format((float)$o['total_amount'], 2); ?></td>
                    <td class="px-3 py-2"><?php echo htmlspecialchars($o['created_at']); ?></td>
                    <td class="px-3 py-2"><span class="px-2 py-1 rounded-md font-semibold <?php echo $status_color[$o['status']] ?? 'bg-gray-100 text-gray-600'; ?>"><?php echo htmlspecialchars($status_labels[$o['status']] ?? $o['status']); ?></span></td>
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
        <div id="modal-loading" class="p-6 text-center text-gray-400 text-xs"><?php echo t('mall_admin.order_chat.loading'); ?></div>
        <div id="modal-content" style="display:none;">
            <section class="modal-section">
                <h3><?php echo t('mall_admin.orders.progress'); ?></h3>
                <div id="modal-progress"></div>
                <div id="modal-action-area" class="mt-3"></div>
            </section>
            <section class="modal-section">
                <h3><?php echo t('mall_admin.orders.customer_info'); ?></h3>
                <div id="modal-customer"></div>
            </section>
            <section class="modal-section">
                <h3><?php echo t('mall_admin.orders.order_items'); ?></h3>
                <div id="modal-items"></div>
                <button type="button" id="modal-print-btn" class="inline-block mt-3 px-3 py-1.5 text-xs font-semibold bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200">
                    <i class="fas fa-print mr-1"></i><?php echo t('mall_admin.orders.print_picking_slip'); ?>
                </button>
                <button type="button" id="modal-receipt-btn" class="inline-block mt-3 ml-2 px-3 py-1.5 text-xs font-semibold bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200">
                    <i class="fas fa-receipt mr-1"></i><?php echo t('mall_admin.orders.print_receipt'); ?>
                </button>
                <a id="modal-chat-link" href="order_chat.php" class="inline-block mt-3 ml-2 px-3 py-1.5 text-xs font-semibold bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200">
                    <i class="fas fa-comments mr-1"></i><?php echo t('mall_admin.orders.view_in_chat'); ?>
                </a>
            </section>
        </div>
    </div>
</div>

<div id="print-modal-backdrop">
    <div id="print-modal">
        <div class="modal-header">
            <h2 id="print-modal-title" class="font-bold text-sm"><?php echo t('mall_admin.picking_slip.title'); ?></h2>
            <div class="flex items-center gap-2">
                <button type="button" id="print-modal-print-btn" class="px-3 py-1.5 text-xs font-semibold bg-blue-600 text-white rounded-md hover:bg-blue-700">
                    <i class="fas fa-print mr-1"></i><?php echo t('common.print'); ?>
                </button>
                <button type="button" id="print-modal-close-btn" class="text-gray-400 hover:text-gray-700" style="font-size:20px;line-height:1;">&times;</button>
            </div>
        </div>
        <iframe id="print-modal-iframe" src="about:blank"></iframe>
    </div>
</div>

<div id="fresh-price-modal-backdrop">
    <div id="fresh-price-modal" role="dialog" aria-modal="true" aria-labelledby="fresh-price-modal-title">
        <div class="flex items-center justify-between mb-3">
            <h2 id="fresh-price-modal-title" class="font-bold text-sm">신선상품 가격 변경</h2>
            <button type="button" id="fresh-price-modal-close" class="text-gray-400 hover:text-gray-700" style="font-size:20px;line-height:1;">&times;</button>
        </div>
        <div id="fresh-price-modal-product" class="text-xs text-gray-500 mb-2"></div>
        <label class="block text-xs text-gray-600">포장 1개 판매가
            <input type="number" id="fresh-price-modal-input" min="0" step="0.01" class="mt-1 w-full border border-gray-300 rounded px-2 py-1.5 text-sm text-right">
        </label>
        <div class="flex justify-end gap-2 mt-4">
            <button type="button" id="fresh-price-modal-cancel" class="px-3 py-1.5 text-xs bg-gray-100 text-gray-700 rounded-md">취소</button>
            <button type="button" id="fresh-price-modal-save" class="px-3 py-1.5 text-xs font-semibold bg-blue-600 text-white rounded-md">저장</button>
        </div>
    </div>
</div>

<script>
var MALL_STATUS_LABELS = <?php echo json_encode($status_labels, JSON_UNESCAPED_UNICODE); ?>;
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
    document.getElementById('print-modal-title').textContent = type === 'receipt' ? '<?php echo addslashes(t('mall_admin.receipt.title')); ?>' : '<?php echo addslashes(t('mall_admin.picking_slip.title')); ?>';
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
                showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.orders.load_failed')); ?>', 'error');
                closeOrderModal();
                return;
            }
            renderOrderModal(data.data);
            document.getElementById('modal-loading').style.display = 'none';
            document.getElementById('modal-content').style.display = 'block';
        })
        .catch(() => {
            showFlash('<?php echo addslashes(t('mall_admin.orders.load_failed')); ?>', 'error');
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
    renderActionArea(order, data.active_drivers, data.default_prep_minutes, data.fresh_items || []);
    renderCustomer(order);
    renderItems(order, data.items, data.fresh_items || []);
}

function renderProgress(order) {
    const container = document.getElementById('modal-progress');
    if (order.status === 'cancelled' || order.status === 'delivery_failed') {
        const msg = order.status === 'cancelled'
            ? '<?php echo addslashes(t('mall_admin.orders.order_cancelled')); ?>' + (order.cancel_reason ? ': ' + escapeHtml(order.cancel_reason) : '.')
            : '<?php echo addslashes(t('mall_admin.orders.delivery_failed_msg')); ?>: ' + escapeHtml(order.failed_reason || '<?php echo addslashes(t('mall_admin.orders.no_reason')); ?>');
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

function renderActionArea(order, activeDrivers, defaultPrepMinutes, freshItems) {
    const area = document.getElementById('modal-action-area');
    area.innerHTML = '';
    // Design Ref: mall-fresh-products.design.md §5.4, §6.2 — weight 타입 신선 라인이 아직 실측
    // 입력 전이면 준비완료 버튼을 아예 비활성화한다(서버도 mark_ready.php에서 최종 방어).
    const hasUnconfirmedWeight = (freshItems || []).some(function (fi) {
        return fi.sale_type_snapshot === 'weight' && Number(fi.is_sold_out) !== 1 && fi.actual_weight_g === null;
    });

    // 배송기사 배정 전(접수대기~준비완료) 단계에서만, 고객 요청 등으로 주문을 취소할 수 있다.
    const CANCELLABLE_STATUSES = ['pending', 'confirmed', 'preparing', 'ready'];
    const cancelBtnHtml = CANCELLABLE_STATUSES.includes(order.status)
        ? '<button type="button" class="act-cancel-order-btn px-3 py-1.5 text-xs font-semibold bg-red-50 text-red-600 rounded-md hover:bg-red-100 ml-1"><?php echo addslashes(t('mall_admin.orders.cancel_by_customer')); ?></button>'
        : '';

    if (order.status === 'pending') {
        area.innerHTML =
            '<div id="act-confirm-form" class="flex items-center gap-1 flex-wrap">' +
                '<input type="number" id="act-prep-minutes" class="border border-gray-300 rounded px-1 py-0.5 w-20 text-xs" min="0" step="1" value="' + defaultPrepMinutes + '">' +
                '<span class="text-xs text-gray-500"><?php echo addslashes(t('mall_admin.orders.minutes_unit')); ?></span>' +
                '<button type="button" id="act-confirm-submit" class="px-2 py-1 text-xs font-semibold bg-green-600 text-white rounded-md hover:bg-green-700"><?php echo addslashes(t('mall_admin.orders.confirm_order')); ?></button>' +
                cancelBtnHtml +
            '</div>';
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
                    if (!data.success) { showFlash(data.error?.message || '<?php echo addslashes(t('common.error_occurred')); ?>', 'error'); return; }
                    showFlash('<?php echo addslashes(t('mall_admin.orders.confirmed_msg')); ?>', 'success');
                    loadOrderDetail(order.id);
                    updateRowSummary(order.id, 'preparing', order.current_driver_name);
                });
        });
    } else if (order.status === 'preparing') {
        area.innerHTML =
            (hasUnconfirmedWeight
                ? '<button type="button" id="act-ready-btn" class="px-3 py-1.5 text-xs font-semibold bg-gray-300 text-gray-500 rounded-md cursor-not-allowed" disabled><?php echo addslashes(t('mall_admin.order_status.ready')); ?></button> ' +
                  '<div class="text-xs text-amber-600 mt-1"><?php echo addslashes(t('mall_admin.orders.fresh_weight_pending_hint')); ?></div>'
                : '<button type="button" id="act-ready-btn" class="px-3 py-1.5 text-xs font-semibold bg-blue-600 text-white rounded-md hover:bg-blue-700"><?php echo addslashes(t('mall_admin.order_status.ready')); ?></button> ') +
            '<button type="button" id="act-cancel-btn" class="px-3 py-1.5 text-xs font-semibold bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300"><?php echo addslashes(t('mall_admin.orders.revert_to_pending')); ?></button>' +
            cancelBtnHtml;
        if (!hasUnconfirmedWeight) {
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
                    if (!data.success) { showFlash(data.error?.message || '<?php echo addslashes(t('common.error_occurred')); ?>', 'error'); return; }
                    showFlash('<?php echo addslashes(t('mall_admin.orders.ready_msg')); ?>', 'success');
                    loadOrderDetail(order.id);
                    updateRowSummary(order.id, 'ready', order.current_driver_name);
                });
        });
        }
        document.getElementById('act-cancel-btn').addEventListener('click', function () {
            if (!confirm('<?php echo addslashes(t('mall_admin.orders.revert_confirm')); ?>')) return;
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
                    if (!data.success) { showFlash(data.error?.message || '<?php echo addslashes(t('common.error_occurred')); ?>', 'error'); return; }
                    showFlash('<?php echo addslashes(t('mall_admin.orders.reverted_msg')); ?>', 'success');
                    loadOrderDetail(order.id);
                    updateRowSummary(order.id, 'pending', order.current_driver_name);
                });
        });
    } else if (order.status === 'ready') {
        if (!activeDrivers.length) {
            area.innerHTML = '<span class="text-gray-400 text-xs"><?php echo addslashes(t('mall_admin.orders.no_active_driver')); ?></span> ' + cancelBtnHtml;
            return;
        }
        area.innerHTML =
            '<div class="flex items-center gap-1">' +
                '<select id="act-assign-select" class="border border-gray-300 rounded px-1 py-0.5 text-xs">' +
                    activeDrivers.map(d => '<option value="' + d.id + '">' + escapeHtml(d.name) + ' (<?php echo addslashes(t('mall_admin.orders.in_progress')); ?> ' + d.active_count + ')</option>').join('') +
                '</select>' +
                '<button type="button" id="act-assign-btn" class="px-2 py-1 text-xs font-semibold bg-blue-600 text-white rounded-md hover:bg-blue-700"><?php echo addslashes(t('mall_admin.orders.assign')); ?></button>' +
                cancelBtnHtml +
            '</div>';
        document.getElementById('act-assign-btn').addEventListener('click', function () {
            const btn = this;
            btn.disabled = true;
            const select = document.getElementById('act-assign-select');
            const driverId = select.value;
            const driverName = select.options[select.selectedIndex].text.replace(/\s*\(<?php echo addslashes(t('mall_admin.orders.in_progress')); ?>.*\)$/, '');
            const params = new URLSearchParams();
            params.set('order_id', order.id);
            params.set('driver_id', driverId);
            params.set('csrf_token', window.MALL_CSRF_TOKEN);
            fetch('ajax/assign_driver.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    if (!data.success) { showFlash(data.error?.message || '<?php echo addslashes(t('common.error_occurred')); ?>', 'error'); return; }
                    showFlash('<?php echo addslashes(t('mall_admin.orders.assigned_msg')); ?>', 'success');
                    loadOrderDetail(order.id);
                    updateRowSummary(order.id, 'assigned', driverName);
                });
        });
    } else if (order.status === 'delivery_failed') {
        if (!activeDrivers.length) {
            area.innerHTML = '<span class="text-gray-400 text-xs"><?php echo addslashes(t('mall_admin.orders.no_active_driver')); ?></span>';
            return;
        }
        area.innerHTML =
            '<div class="flex items-center gap-1">' +
                '<select id="act-reassign-select" class="border border-gray-300 rounded px-1 py-0.5 text-xs">' +
                    activeDrivers.map(d => '<option value="' + d.id + '">' + escapeHtml(d.name) + ' (<?php echo addslashes(t('mall_admin.orders.in_progress')); ?> ' + d.active_count + ')</option>').join('') +
                '</select>' +
                '<button type="button" id="act-reassign-btn" class="px-2 py-1 text-xs font-semibold bg-blue-600 text-white rounded-md hover:bg-blue-700"><?php echo addslashes(t('mall_admin.orders.reassign')); ?></button>' +
            '</div>';
        document.getElementById('act-reassign-btn').addEventListener('click', function () {
            const btn = this;
            btn.disabled = true;
            const select = document.getElementById('act-reassign-select');
            const driverId = select.value;
            const driverName = select.options[select.selectedIndex].text.replace(/\s*\(<?php echo addslashes(t('mall_admin.orders.in_progress')); ?>.*\)$/, '');
            const params = new URLSearchParams();
            params.set('order_id', order.id);
            params.set('driver_id', driverId);
            params.set('csrf_token', window.MALL_CSRF_TOKEN);
            fetch('ajax/reassign_driver.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    if (!data.success) { showFlash(data.error?.message || '<?php echo addslashes(t('common.error_occurred')); ?>', 'error'); return; }
                    showFlash('<?php echo addslashes(t('mall_admin.orders.reassigned_msg')); ?>', 'success');
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
    html += '<div class="info-row"><span><?php echo addslashes(t('mall_admin.orders.member')); ?></span><span>' + escapeHtml(order.member_name) + ' (' + escapeHtml(order.email) + ')</span></div>';
    if (hasSnapshot) {
        html += '<div class="info-row"><span><?php echo addslashes(t('mall_admin.orders.recipient')); ?></span><span>' + escapeHtml(order.ship_recipient_name) + '</span></div>';
        html += '<div class="info-row"><span><?php echo addslashes(t('mall_admin.orders.contact')); ?></span><span>' + escapeHtml(order.ship_phone) + '</span></div>';
        const addr = [order.ship_detail_address, order.ship_barangay, order.ship_city, order.ship_region].filter(Boolean).join(' ');
        html += '<div class="info-row"><span><?php echo addslashes(t('mall_admin.orders.shipping_address')); ?></span><span style="text-align:right;max-width:65%;">' + escapeHtml(addr) + '</span></div>';
        html += '<div class="info-row"><span><?php echo addslashes(t('mall_admin.orders.landmark')); ?></span><span>' + escapeHtml(order.ship_landmark) + '</span></div>';
    } else {
        html += '<div class="info-row"><span><?php echo addslashes(t('mall_admin.orders.shipping_address')); ?></span><span class="text-gray-400"><?php echo addslashes(t('mall_admin.orders.no_snapshot')); ?></span></div>';
    }
    document.getElementById('modal-customer').innerHTML = html;
}

function renderItems(order, items, freshItems) {
    freshItems = freshItems || [];
    let html = '<table class="min-w-full text-xs"><thead><tr class="text-gray-500">' +
        '<th class="px-2 py-1 text-center">구분</th><th class="px-2 py-1 text-left"><?php echo addslashes(t('mall_admin.picking_slip.product')); ?></th><th class="px-2 py-1 text-right"><?php echo addslashes(t('mall_admin.receipt.unit_price')); ?></th>' +
        '<th class="px-2 py-1 text-right"><?php echo addslashes(t('mall_admin.discount_rules.discount_rate')); ?></th><th class="px-2 py-1 text-right"><?php echo addslashes(t('common.quantity')); ?></th>' +
        '<th class="px-2 py-1 text-right"><?php echo addslashes(t('mall_admin.receipt.amount')); ?></th><th class="px-2 py-1 text-center"><?php echo addslashes(t('mall_admin.orders.stock')); ?></th></tr></thead><tbody>';
    if (!items.length && !freshItems.length) {
        html += '<tr><td colspan="7" class="px-2 py-3 text-center text-gray-400"><?php echo addslashes(t('mall_admin.orders.no_items')); ?></td></tr>';
    } else {
        items.forEach(function (it) {
            const soldOut = Number(it.is_sold_out) === 1;
            const strike = soldOut ? 'text-decoration:line-through;color:#9ca3af;' : '';
            html += '<tr class="border-t border-gray-200">' +
                '<td class="px-2 py-1 text-center">-</td>' +
                '<td class="px-2 py-1" style="' + strike + '">' + escapeHtml(it.product_name_snapshot) + '</td>' +
                '<td class="px-2 py-1 text-right" style="' + strike + '">' + Number(it.unit_price_snapshot).toFixed(2) + '</td>' +
                '<td class="px-2 py-1 text-right" style="' + strike + '">' + Number(it.discount_rate_snapshot).toFixed(2) + '%</td>' +
                '<td class="px-2 py-1 text-right" style="' + strike + '">' + it.quantity + '</td>' +
                '<td class="px-2 py-1 text-right font-semibold" style="' + strike + '">' + Number(it.line_total).toFixed(2) + '</td>' +
                '<td class="px-2 py-1 text-center">' +
                (soldOut
                    ? '<span class="px-2 py-1 rounded-md bg-red-100 text-red-700 font-semibold mr-1"><?php echo addslashes(t('mall_admin.orders.sold_out_tag')); ?></span>' +
                      '<button type="button" class="mark-sold-out-btn px-2 py-1 rounded-md bg-gray-100 text-gray-700 hover:bg-blue-100 hover:text-blue-700 font-semibold" data-order-item-id="' + it.order_item_id + '" data-sold-out="0"><?php echo addslashes(t('mall_admin.orders.undo_sold_out')); ?></button>'
                    : '<button type="button" class="mark-sold-out-btn px-2 py-1 rounded-md bg-gray-100 text-gray-700 hover:bg-red-100 hover:text-red-700 font-semibold" data-order-item-id="' + it.order_item_id + '" data-sold-out="1"><?php echo addslashes(t('mall_admin.orders.mark_sold_out')); ?></button>') +
                '</td>' +
                '</tr>';
        });
        // 신선상품 라인(mall_fresh_order_items) — 정가상품과 완전히 분리된 테이블이라 별도로 렌더링한다.
        // Design Ref: mall-fresh-products.design.md §5.4.
        freshItems.forEach(function (fi) {
            const soldOut = Number(fi.is_sold_out) === 1;
            const strike = soldOut ? 'text-decoration:line-through;color:#9ca3af;' : '';
            const isWeight = fi.sale_type_snapshot === 'weight';
            const confirmed = fi.actual_weight_g !== null;
            const amount = confirmed ? fi.confirmed_price : fi.estimated_price;
            let qtyCell;
            if (isWeight) {
                qtyCell = confirmed ? ('확정 ' + fi.actual_weight_g + 'g') : ('예상 ' + fi.weight_g + 'g');
            } else {
                qtyCell = fi.quantity;
            }
            let actionCell;
            if (soldOut) {
                actionCell = '<span class="px-2 py-1 rounded-md bg-red-100 text-red-700 font-semibold mr-1">품절됨</span>' +
                    '<button type="button" class="mark-fresh-sold-out-btn px-2 py-1 rounded-md bg-gray-100 text-gray-700 hover:bg-blue-100 font-semibold" data-fresh-order-item-id="' + fi.id + '" data-sold-out="0">품절 해제</button>';
            } else {
                actionCell =
                    '<button type="button" class="mark-fresh-sold-out-btn px-2 py-1 rounded-md bg-gray-100 text-gray-700 hover:bg-red-100 font-semibold ml-1" data-fresh-order-item-id="' + fi.id + '" data-sold-out="1">품절 처리</button>';
            }
            html += '<tr class="border-t border-gray-200 fresh-price-row" data-fresh-order-item-id="' + fi.id + '" data-product-name="' + escapeHtml(fi.product_name_snapshot) + '" data-unit-price="' + Number(fi.unit_price_snapshot).toFixed(2) + '" style="cursor:pointer;">' +
                '<td class="px-2 py-1 text-center"><span class="px-1.5 py-0.5 rounded text-xs bg-emerald-100 text-emerald-700">신선</span></td>' +
                '<td class="px-2 py-1" style="' + strike + '"><div>' + escapeHtml(fi.product_name_snapshot) + '</div>' + (fi.product_name_en ? '<div class="text-gray-500 text-[11px]">' + escapeHtml(fi.product_name_en) + '</div>' : '') + '</td>' +
                '<td class="px-2 py-1 text-right" style="' + strike + '">' + Number(fi.unit_price_snapshot).toFixed(2) + '</td>' +
                '<td class="px-2 py-1 text-right" style="' + strike + '">-</td>' +
                '<td class="px-2 py-1 text-right" style="' + strike + '">' + qtyCell + '</td>' +
                '<td class="px-2 py-1 text-right font-semibold" style="' + strike + '">' + Number(amount).toFixed(2) + '</td>' +
                '<td class="px-2 py-1 text-center">' + actionCell + '</td>' +
                '</tr>';
        });
    }
    html += '</tbody></table>';
    html += '<div style="text-align:right;margin-top:8px;font-size:12px;">' +
        '<div class="text-gray-500"><?php echo addslashes(t('mall_admin.receipt.subtotal')); ?> ' + Number(order.subtotal).toFixed(2) + '</div>' +
        '<div class="text-red-600"><?php echo addslashes(t('mall_admin.receipt.discount')); ?> -' + Number(order.discount_amount).toFixed(2) + '</div>' +
        '<div class="text-gray-500"><?php echo addslashes(t('mall_admin.receipt.shipping_fee')); ?> ' + Number(order.shipping_fee).toFixed(2) + '</div>' +
        '<div style="font-weight:700;font-size:14px;margin-top:4px;"><?php echo addslashes(t('mall_admin.orders.total')); ?> ' + Number(order.total_amount).toFixed(2) + '</div>' +
        '</div>';
    document.getElementById('modal-items').innerHTML = html;
}

document.getElementById('modal-items').addEventListener('click', function (e) {
    const freshPriceRow = e.target.closest('.fresh-price-row');
    if (freshPriceRow && !e.target.closest('button')) {
        document.getElementById('fresh-price-modal-product').textContent = freshPriceRow.dataset.productName;
        document.getElementById('fresh-price-modal-input').value = freshPriceRow.dataset.unitPrice;
        document.getElementById('fresh-price-modal-save').dataset.freshOrderItemId = freshPriceRow.dataset.freshOrderItemId;
        document.getElementById('fresh-price-modal-backdrop').style.display = 'flex';
        document.getElementById('fresh-price-modal-input').focus();
        return;
    }
    const freshSoldBtn = e.target.closest('.mark-fresh-sold-out-btn');
    if (freshSoldBtn) {
        const soldOut = freshSoldBtn.dataset.soldOut === '1';
        if (!confirm(soldOut ? '이 신선상품을 품절 처리하시겠습니까?' : '품절 처리를 해제하시겠습니까?')) return;
        const params = new URLSearchParams({ mall_fresh_order_item_id: freshSoldBtn.dataset.freshOrderItemId, sold_out: soldOut ? '1' : '0', csrf_token: window.MALL_CSRF_TOKEN });
        freshSoldBtn.disabled = true;
        fetch('ajax/mark_fresh_sold_out.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() }).then(r => r.json()).then(data => {
            if (!data.success) { freshSoldBtn.disabled = false; showFlash(data.error?.message || '품절 처리에 실패했습니다.', 'error'); return; }
            showFlash(soldOut ? '신선상품을 품절 처리했습니다.' : '품절 처리를 해제했습니다.', 'success'); loadOrderDetail(data.data.order_id);
        }).catch(() => { freshSoldBtn.disabled = false; showFlash('품절 처리에 실패했습니다.', 'error'); });
        return;
    }
    const btn = e.target.closest('.mark-sold-out-btn');
    if (!btn) return;
    const soldOut = btn.dataset.soldOut === '1';
    if (!confirm(soldOut ? '<?php echo addslashes(t('mall_admin.orders.sold_out_confirm')); ?>' : '<?php echo addslashes(t('mall_admin.orders.undo_sold_out_confirm')); ?>')) return;
    btn.disabled = true;
    const params = new URLSearchParams();
    params.set('order_item_id', btn.dataset.orderItemId);
    params.set('sold_out', soldOut ? '1' : '0');
    params.set('csrf_token', window.MALL_CSRF_TOKEN);
    fetch('ajax/mark_sold_out.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showFlash(soldOut ? '<?php echo addslashes(t('mall_admin.orders.sold_out_msg')); ?>' : '<?php echo addslashes(t('mall_admin.orders.undo_sold_out_msg')); ?>', 'success');
                loadOrderDetail(data.data.order_id);
            } else {
                btn.disabled = false;
                showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.order_chat.process_failed')); ?>', 'error');
            }
        });
});

function closeFreshPriceModal() {
    document.getElementById('fresh-price-modal-backdrop').style.display = 'none';
}
document.getElementById('fresh-price-modal-close').addEventListener('click', closeFreshPriceModal);
document.getElementById('fresh-price-modal-cancel').addEventListener('click', closeFreshPriceModal);
document.getElementById('fresh-price-modal-backdrop').addEventListener('click', function (e) {
    if (e.target === this) closeFreshPriceModal();
});
document.getElementById('fresh-price-modal-save').addEventListener('click', function () {
    const btn = this;
    const input = document.getElementById('fresh-price-modal-input');
    const price = Number(input.value);
    if (!Number.isFinite(price) || price < 0) { showFlash('판매가를 확인해주세요.', 'error'); return; }
    btn.disabled = true;
    const params = new URLSearchParams({ mall_fresh_order_item_id: btn.dataset.freshOrderItemId, unit_price: price.toFixed(2), csrf_token: window.MALL_CSRF_TOKEN });
    fetch('ajax/update_fresh_order_item_price.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { showFlash(data.error?.message || '가격 변경에 실패했습니다.', 'error'); return; }
            closeFreshPriceModal();
            showFlash('신선상품 가격을 변경했습니다.', 'success');
            loadOrderDetail(data.data.order_id);
        })
        .catch(() => showFlash('가격 변경에 실패했습니다.', 'error'))
        .finally(() => { btn.disabled = false; });
});

// 신선상품 weight 타입 라인 실측 확정. Design Ref: mall-fresh-products.design.md §4.2, §5.4.
document.getElementById('modal-items').addEventListener('click', function (e) {
    const btn = e.target.closest('.confirm-fresh-weight-btn');
    if (!btn) return;
    const row = btn.closest('tr');
    const input = row.querySelector('.confirm-fresh-weight-input');
    const actualWeightG = parseInt(input.value, 10);
    if (!actualWeightG || actualWeightG <= 0) {
        showFlash('실측 무게를 확인해주세요.', 'error');
        return;
    }
    btn.disabled = true;
    const params = new URLSearchParams();
    params.set('mall_fresh_order_item_id', btn.dataset.mallFreshOrderItemId);
    params.set('actual_weight_g', actualWeightG);
    params.set('csrf_token', window.MALL_CSRF_TOKEN);
    fetch('ajax/confirm_fresh_weight.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showFlash('실측 무게가 확정되었습니다.', 'success');
                loadOrderDetail(MALL_CURRENT_ORDER_ID);
            } else {
                btn.disabled = false;
                showFlash(data.error?.message || '처리에 실패했습니다.', 'error');
            }
        });
});

document.getElementById('modal-action-area').addEventListener('click', function (e) {
    const btn = e.target.closest('.act-cancel-order-btn');
    if (!btn || !MALL_CURRENT_ORDER_ID) return;
    const orderId = MALL_CURRENT_ORDER_ID;
    const reason = prompt('<?php echo addslashes(t('mall_admin.orders.cancel_reason_prompt')); ?>');
    if (reason === null) return;
    const trimmed = reason.trim();
    if (!trimmed) { showFlash('<?php echo addslashes(t('mall_admin.orders.cancel_reason_required')); ?>', 'error'); return; }
    btn.disabled = true;
    const params = new URLSearchParams();
    params.set('order_id', orderId);
    params.set('reason', trimmed);
    params.set('csrf_token', window.MALL_CSRF_TOKEN);
    fetch('ajax/cancel_order.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showFlash('<?php echo addslashes(t('mall_admin.orders.order_cancelled_msg')); ?>', 'success');
                loadOrderDetail(data.data.order_id);
                updateRowSummary(data.data.order_id, 'cancelled', '-');
            } else {
                btn.disabled = false;
                showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.order_chat.process_failed')); ?>', 'error');
            }
        })
        .catch(function () {
            btn.disabled = false;
            showFlash('<?php echo addslashes(t('mall_admin.order_chat.process_failed')); ?>', 'error');
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
