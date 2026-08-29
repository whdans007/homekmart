<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/cart.php';

mall_require_login('/mall/login.php');
$member = mall_current_member();

$conn = get_db_connection();
$stmt = $conn->prepare(
    'SELECT id, order_number, channel, total_amount, status, created_at
     FROM mall_orders WHERE member_id = ? ORDER BY created_at DESC'
);
$stmt->bind_param('i', $member['id']);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 재주문(전체 담기) 버튼용 — 주문별 상품 목록을 함께 조회한다.
$items_by_order = [];
if (!empty($orders)) {
    $order_ids = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $items_stmt = $conn->prepare("SELECT order_id, product_id, quantity FROM mall_order_items WHERE order_id IN ({$placeholders})");
    $items_stmt->bind_param(str_repeat('i', count($order_ids)), ...$order_ids);
    $items_stmt->execute();
    foreach ($items_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $items_by_order[(int)$row['order_id']][] = ['product_id' => (int)$row['product_id'], 'quantity' => (int)$row['quantity']];
    }
    $items_stmt->close();
}
$conn->close();

$status_labels = ['pending' => '접수대기', 'confirmed' => '확인됨', 'preparing' => '준비중', 'ready' => '준비완료', 'completed' => '완료', 'cancelled' => '취소'];
$status_stage = ['pending' => 1, 'confirmed' => 2, 'preparing' => 2, 'ready' => 3, 'completed' => 4]; // cancelled은 트래커 없음

$mall_redesigned = true;
$mall_show_back = true;
$show_bottom_nav = true;
$active_nav = 'my';
$page_title = '주문내역';
require_once __DIR__ . '/../partials/header.php';
?>
<style>
.order-card { background: var(--bg-normal); border: 1px solid var(--line-alternative); border-radius: var(--radius-lg); margin: var(--space-4) var(--space-5) 0; overflow: hidden; }
.order-card .head { display: flex; justify-content: space-between; align-items: center; padding: var(--space-4); border-bottom: 1px solid var(--line-alternative); }
.order-card .num { font: 700 15px var(--font-sans); }
.order-card .date { font: var(--t-caption1) var(--font-sans); color: var(--label-assistive); }
.order-tracker { display: flex; align-items: center; padding: var(--space-4); gap: 4px; }
.order-tracker .step { flex: 1; text-align: center; }
.order-tracker .dot { width: 20px; height: 20px; border-radius: 50%; background: var(--fill-strong); color: var(--label-assistive); display: flex; align-items: center; justify-content: center; margin: 0 auto 4px; font: 700 11px var(--font-sans); }
.order-tracker .step.active .dot { background: var(--primary-normal); color: var(--static-white); }
.order-tracker .step.active ~ .step .line { background: var(--fill-strong); }
.order-tracker .label { font: var(--t-caption1) var(--font-sans); color: var(--label-assistive); }
.order-tracker .step.active .label { color: var(--label-normal); font-weight: 700; }
.order-card .foot { display: flex; align-items: center; justify-content: space-between; padding: var(--space-4); background: var(--bg-alternative); }
</style>

<?php if (empty($orders)): ?>
    <p class="empty-state">주문 내역이 없습니다.</p>
<?php else: ?>
<?php foreach ($orders as $o): ?>
    <?php $__stage = $status_stage[$o['status']] ?? null; ?>
    <div class="order-card">
        <a href="/mall/order_detail.php?id=<?php echo (int)$o['id']; ?>" class="head" style="color:inherit;">
            <div>
                <div class="num"><?php echo htmlspecialchars($o['order_number']); ?></div>
                <div class="date"><?php echo htmlspecialchars(substr($o['created_at'], 0, 16)); ?></div>
            </div>
            <span class="badge badge-blue"><?php echo $status_labels[$o['status']] ?? $o['status']; ?></span>
        </a>

        <?php if ($__stage): ?>
        <div class="order-tracker">
            <?php foreach (['접수' => 1, '준비중' => 2, '준비완료' => 3, '완료' => 4] as $label => $stage): ?>
                <div class="step <?php echo $__stage >= $stage ? 'active' : ''; ?>">
                    <div class="dot"><?php echo $__stage >= $stage ? '<svg style="width:12px;height:12px;"><use href="#i-check"></use></svg>' : $stage; ?></div>
                    <div class="label"><?php echo $label; ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="foot">
            <span style="font:700 16px var(--font-sans);"><?php echo number_format((float)$o['total_amount'], 2); ?></span>
            <?php if (!empty($items_by_order[$o['id']])): ?>
            <button type="button" class="reorder-btn quick-add-btn" data-items='<?php echo htmlspecialchars(json_encode($items_by_order[$o['id']]), ENT_QUOTES); ?>'>재주문(전체 담기)</button>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
<?php endif; ?>

<script>
document.querySelectorAll('.reorder-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
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
});
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
