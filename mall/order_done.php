<?php
require_once __DIR__ . '/lib/auth.php';

mall_require_login('/mall/login.php');
$member = mall_current_member();

$order_id = (int)($_GET['id'] ?? 0);

$conn = get_db_connection();
$stmt = $conn->prepare(
    'SELECT id, order_number, total_amount, payment_method, status, created_at
     FROM mall_orders WHERE id = ? AND member_id = ?'
);
$stmt->bind_param('ii', $order_id, $member['id']);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

$mall_redesigned = true;
$show_bottom_nav = true;
$page_title = '주문완료';
require_once __DIR__ . '/partials/header.php';

$payment_labels = ['cod' => '착불(현장결제)', 'offline' => '오프라인 결제'];
?>
<style>
.done-wrap { display: flex; flex-direction: column; align-items: center; text-align: center; padding: var(--space-6) var(--space-5) 0; }
.done-check { width: 72px; height: 72px; border-radius: 50%; background: var(--brand-green); color: var(--static-white); display: flex; align-items: center; justify-content: center; margin-bottom: var(--space-5); }
.done-check svg { width: 36px; height: 36px; }
.done-summary { width: 100%; max-width: 340px; text-align: left; margin-top: var(--space-5); }
.done-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--line-alternative); font: var(--t-label2) var(--font-sans); color: var(--label-neutral); }
.done-row span:first-child { color: var(--label-alternative); }
.done-actions { display: flex; gap: 10px; width: 100%; max-width: 340px; margin-top: var(--space-6); }
</style>

<?php if (!$order): ?>
    <p class="empty-state">주문 정보를 찾을 수 없습니다.</p>
<?php else: ?>
<div class="done-wrap">
    <div class="done-check"><svg><use href="#i-check"></use></svg></div>
    <div style="font:var(--t-title3) var(--font-sans);">주문이 접수되었습니다</div>
    <div style="font:var(--t-label2) var(--font-sans);color:var(--label-alternative);margin-top:4px;">주문번호 <?php echo htmlspecialchars($order['order_number']); ?></div>

    <div class="done-summary">
        <div class="done-row"><span>주문일시</span><span><?php echo htmlspecialchars(substr($order['created_at'], 0, 16)); ?></span></div>
        <div class="done-row"><span>결제수단</span><span><?php echo htmlspecialchars($payment_labels[$order['payment_method']] ?? $order['payment_method']); ?></span></div>
        <div class="done-row"><span>결제금액</span><span style="font-weight:700;color:var(--label-normal);"><?php echo number_format((float)$order['total_amount'], 2); ?></span></div>
        <div class="done-row" style="border-bottom:none;"><span>배송</span><span>영업일 기준 1~3일 내 배송 안내드립니다</span></div>
    </div>

    <div class="done-actions">
        <a href="/mall/order_detail.php?id=<?php echo (int)$order['id']; ?>" class="btn" style="flex:1;background:var(--fill-strong);color:var(--label-normal);">주문상세</a>
        <a href="/mall/index.php" class="btn btn-primary" style="flex:1;">쇼핑 계속하기</a>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
