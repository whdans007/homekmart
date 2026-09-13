<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/fresh_order.php';

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
    $page_title = '二쇰Ц??李얠쓣 ???놁뒿?덈떎';
    require_once __DIR__ . '/partials/header.php';
    echo '<p class="empty-state">二쇰Ц??李얠쓣 ???놁뒿?덈떎.</p>';
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
$fresh_items = mall_fresh_order_items_get_by_order($order_id);

$status_labels = [
    'pending' => '?묒닔?湲?, 'confirmed' => '?뺤씤??, 'preparing' => '?곹뭹以鍮꾩쨷', 'ready' => '以鍮꾩셿猷?,
    'assigned' => '諛곗넚湲곗궗 諛곗젙??, 'delivering' => '諛곗넚以?, 'arrived' => '?꾩갑', 'completed' => '?꾨즺',
    'cancelled' => '痍⑥냼', 'delivery_failed' => '諛곗넚 吏???щ같??以?',
];
$payment_labels = ['cod' => '李⑸텋(?꾩옣寃곗젣)', 'offline' => '?ㅽ봽?쇱씤 寃곗젣'];
$mall_show_map = in_array($order['status'], ['delivering', 'arrived'], true);

$mall_redesigned = true;
$show_bottom_nav = true;
$page_title = '二쇰Ц ?곸꽭';
require_once __DIR__ . '/partials/header.php';
?>

<div style="padding:var(--space-4) var(--space-5) 0;">
    <h1 style="font:var(--t-heading2) var(--font-sans);margin:0 0 4px;"><?php echo htmlspecialchars($order['order_number']); ?></h1>
    <p style="font:var(--t-caption1) var(--font-sans);color:var(--label-assistive);margin:0;">
        <?php echo htmlspecialchars(substr($order['created_at'], 0, 16)); ?> 쨌
        <span class="badge badge-blue"><?php echo $status_labels[$order['status']] ?? $order['status']; ?></span>
    </p>
    <?php if (!empty($order['estimated_ready_at']) && in_array($order['status'], ['preparing', 'ready'], true)): ?>
    <p style="font:var(--t-label2) var(--font-sans);color:var(--primary-strong);margin:6px 0 0;">
        <i class="fas fa-clock"></i> ?덉긽 以鍮꾩셿猷??쒓컖: <?php echo htmlspecialchars(date('m/d H:i', strtotime($order['estimated_ready_at']))); ?>
    </p>
    <?php endif; ?>
    <p id="delivery-status-banner" style="font:700 var(--t-label2) var(--font-sans);margin:6px 0 0;<?php echo $order['status'] === 'arrived' ? 'color:var(--brand-green);' : 'color:var(--primary-strong);'; ?>" <?php echo in_array($order['status'], ['assigned', 'delivering', 'arrived'], true) ? '' : 'hidden'; ?>>
            <i class="fas fa-circle-check"></i> 배달도착
            <i class="fas fa-box"></i> 諛곗넚湲곗궗媛 諛곗젙?섏뿀?듬땲?? 怨?異쒕컻???덉젙?낅땲??
        <?php elseif ($order['status'] === 'delivering'): ?>
            <i class="fas fa-truck"></i> 배달이 시작되었습니다.
        <?php elseif ($order['status'] === 'arrived'): ?>
            <i class="fas fa-circle-check"></i> 배달도착
        <?php endif; ?>
    </p>
    <?php if ($order['status'] === 'cancelled'): ?>
    <p style="font:700 var(--t-label2) var(--font-sans);color:var(--brand-red);margin:6px 0 0;">
        <i class="fas fa-circle-xmark"></i> ??二쇰Ц? 痍⑥냼?섏뿀?듬땲???php echo !empty($order['cancel_reason']) ? ': ' . htmlspecialchars($order['cancel_reason']) : '.'; ?>
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
            <?php echo htmlspecialchars($it['product_name_snapshot']); ?> 횞 <?php echo (int)$it['quantity']; ?>
            <?php if ($it['discount_rate_snapshot'] > 0): ?>
                <span style="color:var(--brand-red);font:var(--t-caption1) var(--font-sans);">(-<?php echo number_format((float)$it['discount_rate_snapshot'], 2); ?>%)</span>
            <?php endif; ?>
        </div>
        <div style="font-weight:700;"><?php echo number_format((float)$it['line_total'], 2); ?></div>
    </div>
    <?php endforeach; ?>
    <?php foreach ($fresh_items as $it): ?>
    <?php
    $is_weight = $it['sale_type_snapshot'] === 'weight';
    $is_confirmed = $is_weight && $it['actual_weight_g'] !== null;
    $display_price = $is_weight
        ? ($is_confirmed ? (float)$it['confirmed_price'] : (float)$it['estimated_price'])
        : (float)$it['confirmed_price'];
    ?>
    <div style="display:flex;justify-content:space-between;gap:12px;padding:12px var(--space-4);border-bottom:1px solid var(--line-alternative);font:var(--t-label1) var(--font-sans);">
        <div>
            <div><span class="badge badge-green">?좎꽑</span> <?php echo htmlspecialchars($it['product_name_snapshot']); ?><?php if (!$is_weight): ?> 횞 <?php echo (int)$it['quantity']; ?><?php endif; ?></div>
            <?php if ($is_weight && !$is_confirmed): ?>
                <span class="badge badge-blue" style="margin-top:5px;">?덉긽 <?php echo (int)$it['weight_g']; ?>g 쨌 ?덉긽湲덉븸 <?php echo number_format((float)$it['estimated_price'], 2); ?></span>
            <?php elseif ($is_weight): ?>
                <span class="badge badge-green" style="margin-top:5px;">?뺤젙 <?php echo (int)$it['actual_weight_g']; ?>g 쨌 ?뺤젙湲덉븸 <?php echo number_format((float)$it['confirmed_price'], 2); ?></span>
            <?php else: ?>
                <span class="badge badge-green" style="margin-top:5px;">?뺤젙湲덉븸 <?php echo number_format((float)$it['confirmed_price'], 2); ?></span>
            <?php endif; ?>
        </div>
        <div style="font-weight:700;white-space:nowrap;"><?php echo number_format($display_price, 2); ?></div>
    </div>
    <?php endforeach; ?>
</div>

<div style="text-align:right;padding:var(--space-3) var(--space-5) 0;font:var(--t-label2) var(--font-sans);">
    <div style="color:var(--label-alternative);">?뚭퀎 <?php echo number_format((float)$order['subtotal'], 2); ?></div>
    <div style="color:var(--brand-red);">?좎씤 -<?php echo number_format((float)$order['discount_amount'], 2); ?></div>
    <div style="color:var(--label-alternative);">諛곗넚鍮?<?php echo (float)$order['shipping_fee'] > 0 ? number_format((float)$order['shipping_fee'], 2) : '臾대즺'; ?></div>
    <div style="font:700 20px var(--font-sans);margin-top:4px;">?⑷퀎 <?php echo number_format((float)$order['total_amount'], 2); ?></div>
    <div style="color:var(--label-alternative);margin-top:4px;">寃곗젣?섎떒: <?php echo htmlspecialchars($payment_labels[$order['payment_method']] ?? $order['payment_method']); ?></div>
</div>

<?php if (!empty($order['memo'])): ?>
<div class="card" style="margin:var(--space-4) var(--space-5) 0;padding:var(--space-3) var(--space-4);">
    <strong style="font:var(--t-label2) var(--font-sans);">?붿껌?ы빆</strong>
    <p style="font:var(--t-label2) var(--font-sans);color:var(--label-neutral);margin:4px 0 0;"><?php echo nl2br(htmlspecialchars($order['memo'])); ?></p>
</div>
<?php endif; ?>

<div class="section" style="padding-bottom:var(--space-6);">
    <button type="button" id="reorder-btn" class="btn btn-primary btn-block"
            data-items='<?php echo htmlspecialchars(json_encode(array_map(fn($it) => ['product_id' => (int)$it['product_id'], 'quantity' => (int)$it['quantity']], $items)), ENT_QUOTES); ?>'>
        ?ъ＜臾??꾩껜 ?닿린)
    </button>
    <a href="/mall/mypage/orders.php" style="display:block;text-align:center;margin-top:var(--space-3);font:var(--t-caption1) var(--font-sans);color:var(--label-alternative);">??二쇰Ц?댁뿭?쇰줈</a>
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
        mallToast(ok + '媛??곹뭹???λ컮援щ땲???댁븯?듬땲??', '/mall/cart.php', '蹂닿린');
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

            // 諛곗넚以묅넂?꾩갑泥섎읆 "異붿쟻 ?곹깭 吏묓빀 ?덉뿉?쒖쓽" ?꾩씠???볦튂吏 ?딅룄濡? 吏묓빀??踰쀬뼱?щ뒗吏媛
            // ?꾨땲??理쒖큹 ?뚮뜑???곹깭? ?щ씪議뚮뒗吏濡??먮떒?쒕떎.
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
