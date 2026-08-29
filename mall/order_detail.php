<?php
require_once __DIR__ . '/lib/auth.php';

mall_require_login('/mall/login.php');
$member = mall_current_member();

$order_id = (int)($_GET['id'] ?? 0);

$conn = get_db_connection();
$stmt = $conn->prepare(
    'SELECT id, order_number, channel, subtotal, discount_amount, total_amount, status, payment_method, memo, created_at
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

$status_labels = ['pending' => '접수대기', 'confirmed' => '확인됨', 'preparing' => '준비중', 'ready' => '준비완료', 'completed' => '완료', 'cancelled' => '취소'];
$payment_labels = ['cod' => '착불(현장결제)', 'offline' => '오프라인 결제'];

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
</div>

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

<?php require_once __DIR__ . '/partials/footer.php'; ?>
