<?php
require_once __DIR__ . '/lib/auth.php';

mall_require_login('/mall/login.php');
$member = mall_current_member();

$order_id = (int)($_GET['id'] ?? 0);

$conn = get_db_connection();
$stmt = $conn->prepare(
    'SELECT id, order_number, channel, subtotal, discount_amount, shipping_fee, total_amount, status, estimated_ready_at, payment_method, memo, cancel_reason, created_at
     FROM mall_orders WHERE id = ? AND member_id = ?'
);
$stmt->bind_param('ii', $order_id, $member['id']);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    $conn->close();
    http_response_code(404);
    $mall_redesigned = true;
    $show_bottom_nav = true;
    $page_title = '주문을 찾을 수 없습니다';
    require_once __DIR__ . '/partials/header.php';
    echo '<p class="empty-state">주문을 찾을 수 없습니다.</p>';
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

$items_stmt = $conn->prepare(
    'SELECT product_id, product_name_snapshot, unit_price_snapshot, discount_rate_snapshot, quantity, line_total
     FROM mall_order_items WHERE order_id = ?'
);
$items_stmt->bind_param('i', $order_id);
$items_stmt->execute();
$items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$items_stmt->close();
$conn->close();

$status_labels = [
    'pending' => '접수대기', 'confirmed' => '확인됨', 'preparing' => '상품준비중', 'ready' => '준비완료',
    'assigned' => '배송기사 배정됨', 'delivering' => '배송중', 'arrived' => '도착', 'completed' => '완료',
    'cancelled' => '취소', 'delivery_failed' => '배송 지연(재배정 중)',
];
$payment_labels = ['cod' => '착불(현장결제)', 'offline' => '오프라인 결제'];
$mall_show_map = in_array($order['status'], ['delivering', 'arrived'], true);

$mall_redesigned = true;
$show_bottom_nav = true;
$page_title = '주문 상세';
require_once __DIR__ . '/partials/header.php';
?>

<div style="padding:var(--space-4) var(--space-5) 0;">
    <h1 style="font:var(--t-heading2) var(--font-sans);margin:0 0 4px;"><?php echo htmlspecialchars($order['order_number']); ?></h1>
    <p style="font:var(--t-caption1) var(--font-sans);color:var(--label-assistive);margin:0;">
        <?php echo htmlspecialchars(substr($order['created_at'], 0, 16)); ?> ·
        <span class="badge badge-blue"><?php echo $status_labels[$order['status']] ?? $order['status']; ?></span>
    </p>
    <?php if (!empty($order['estimated_ready_at']) && in_array($order['status'], ['preparing', 'ready'], true)): ?>
    <p style="font:var(--t-label2) var(--font-sans);color:var(--primary-strong);margin:6px 0 0;">
        <i class="fas fa-clock"></i> 예상 준비완료 시각: <?php echo htmlspecialchars(date('m/d H:i', strtotime($order['estimated_ready_at']))); ?>
    </p>
    <?php endif; ?>
    <p id="delivery-status-banner" style="font:700 var(--t-label2) var(--font-sans);margin:6px 0 0;<?php echo $order['status'] === 'arrived' ? 'color:var(--brand-green);' : 'color:var(--primary-strong);'; ?>" <?php echo in_array($order['status'], ['assigned', 'delivering', 'arrived'], true) ? '' : 'hidden'; ?>>
        <?php if ($order['status'] === 'assigned'): ?>
            <i class="fas fa-box"></i> 배송기사가 배정되었습니다. 곧 출발할 예정입니다.
        <?php elseif ($order['status'] === 'delivering'): ?>
            <i class="fas fa-truck"></i> 배송 중입니다.
        <?php elseif ($order['status'] === 'arrived'): ?>
            <i class="fas fa-circle-check"></i> 기사님이 도착했습니다!
        <?php endif; ?>
    </p>
    <?php if ($order['status'] === 'cancelled'): ?>
    <p style="font:700 var(--t-label2) var(--font-sans);color:var(--brand-red);margin:6px 0 0;">
        <i class="fas fa-circle-xmark"></i> 이 주문은 취소되었습니다<?php echo !empty($order['cancel_reason']) ? ': ' . htmlspecialchars($order['cancel_reason']) : '.'; ?>
    </p>
    <?php endif; ?>
</div>

<?php if ($mall_show_map): ?>
<div id="delivery-map" style="height:220px;margin:var(--space-4) var(--space-5) 0;border-radius:var(--radius-lg);background:var(--fill-normal);"></div>
<?php endif; ?>

<div class="card" style="margin:var(--space-4) var(--space-5) 0;overflow:hidden;">
    <?php foreach ($items as $it): ?>
    <div style="display:flex;justify-content:space-between;padding:12px var(--space-4);border-bottom:1px solid var(--line-alternative);font:var(--t-label1) var(--font-sans);">
        <div>
            <?php echo htmlspecialchars($it['product_name_snapshot']); ?> × <?php echo (int)$it['quantity']; ?>
            <?php if ($it['discount_rate_snapshot'] > 0): ?>
                <span style="color:var(--brand-red);font:var(--t-caption1) var(--font-sans);">(-<?php echo number_format((float)$it['discount_rate_snapshot'], 2); ?>%)</span>
            <?php endif; ?>
        </div>
        <div style="font-weight:700;"><?php echo number_format((float)$it['line_total'], 2); ?></div>
    </div>
    <?php endforeach; ?>
</div>

<div style="text-align:right;padding:var(--space-3) var(--space-5) 0;font:var(--t-label2) var(--font-sans);">
    <div style="color:var(--label-alternative);">소계 <?php echo number_format((float)$order['subtotal'], 2); ?></div>
    <div style="color:var(--brand-red);">할인 -<?php echo number_format((float)$order['discount_amount'], 2); ?></div>
    <div style="color:var(--label-alternative);">배송비 <?php echo (float)$order['shipping_fee'] > 0 ? number_format((float)$order['shipping_fee'], 2) : '무료'; ?></div>
    <div style="font:700 20px var(--font-sans);margin-top:4px;">합계 <?php echo number_format((float)$order['total_amount'], 2); ?></div>
    <div style="color:var(--label-alternative);margin-top:4px;">결제수단: <?php echo htmlspecialchars($payment_labels[$order['payment_method']] ?? $order['payment_method']); ?></div>
</div>

<?php if (!empty($order['memo'])): ?>
<div class="card" style="margin:var(--space-4) var(--space-5) 0;padding:var(--space-3) var(--space-4);">
    <strong style="font:var(--t-label2) var(--font-sans);">요청사항</strong>
    <p style="font:var(--t-label2) var(--font-sans);color:var(--label-neutral);margin:4px 0 0;"><?php echo nl2br(htmlspecialchars($order['memo'])); ?></p>
</div>
<?php endif; ?>

<div class="section" style="padding-bottom:var(--space-6);">
    <button type="button" id="reorder-btn" class="btn btn-primary btn-block"
            data-items='<?php echo htmlspecialchars(json_encode(array_map(fn($it) => ['product_id' => (int)$it['product_id'], 'quantity' => (int)$it['quantity']], $items)), ENT_QUOTES); ?>'>
        재주문(전체 담기)
    </button>
    <a href="/mall/mypage/orders.php" style="display:block;text-align:center;margin-top:var(--space-3);font:var(--t-caption1) var(--font-sans);color:var(--label-alternative);">← 주문내역으로</a>
</div>

<script>
document.getElementById('reorder-btn').addEventListener('click', function () {
    const btn = this;
    if (btn.disabled) return;
    btn.disabled = true;
    const items = JSON.parse(btn.dataset.items);
    Promise.all(items.map(function (it) {
        const params = new URLSearchParams();
        params.set('product_id', it.product_id);
        params.set('quantity', it.quantity);
        return fetch('/mall/ajax/add_to_cart.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() }).then(r => r.json());
    })).then(function (results) {
        btn.disabled = false;
        const ok = results.filter(r => r.success).length;
        mallToast(ok + '개 상품을 장바구니에 담았습니다.', '/mall/cart.php', '보기');
        const last = results[results.length - 1];
        if (last && last.success) { mallUpdateCartBadge(last.data.cart_count); }
    });
});
</script>

<?php $mall_live_tracking = in_array($order['status'], ['assigned', 'delivering', 'arrived'], true); ?>
<?php if ($mall_live_tracking): ?>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo urlencode(MALL_GOOGLE_MAPS_API_KEY); ?>&callback=mallInitTrackingMap" async defer></script>
<script>
var MALL_ORDER_ID = <?php echo (int)$order_id; ?>;
var MALL_INITIAL_STATUS = <?php echo json_encode($order['status']); ?>;
var mallTrackingMap = null;
var mallTrackingMarker = null;

function mallInitTrackingMap() {
    var mapEl = document.getElementById('delivery-map');
    if (!mapEl) return;
    mallTrackingMap = new google.maps.Map(mapEl, { center: { lat: 14.5995, lng: 120.9842 }, zoom: 13 });
}

function mallPollOrderTracking() {
    fetch('/mall/ajax/get_order_tracking.php?order_id=' + MALL_ORDER_ID)
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.success) return;

            // 배송중→도착처럼 "추적 상태 집합 안에서의" 전이도 놓치지 않도록, 집합을 벗어났는지가
            // 아니라 최초 렌더된 상태와 달라졌는지로 판단한다.
            if (data.data.status !== MALL_INITIAL_STATUS) {
                window.location.reload();
                return;
            }

            var loc = data.data.driver_location;
            if (loc && mallTrackingMap) {
                var pos = { lat: loc.lat, lng: loc.lng };
                if (mallTrackingMarker) {
                    mallTrackingMarker.setPosition(pos);
                } else {
                    mallTrackingMarker = new google.maps.Marker({ position: pos, map: mallTrackingMap });
                }
                mallTrackingMap.setCenter(pos);
            }
        });
}

setInterval(mallPollOrderTracking, 15000);
mallPollOrderTracking();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
