<?php
/**
 * 배송기사 배달 목록 — 진행중(배정됨/배송중/도착) + 완료(완료/실패) 탭
 * Design Ref: mall-delivery-dispatch.design.md §5.4 — 여러 건 동시 표시
 * 목록 카드는 주문번호가 아니라 상세주소/건물명을 기준으로 식별한다(기사는 주문코드가 아니라
 * "어디로 가야 하는지"로 배달을 구분하므로).
 */
require_once __DIR__ . '/../lib/driver.php';
require_once __DIR__ . '/../lib/delivery.php';
require_once __DIR__ . '/../lib/address.php';
require_once __DIR__ . '/../lib/order_chat.php';

mall_driver_require_login('/mall/driver/login.php');
$driver = mall_driver_current();
if (!$driver) {
    header('Location: /mall/driver/login.php');
    exit;
}

$active_orders = mall_driver_get_assigned_orders($driver['id']);
$done_orders = mall_driver_get_completed_orders($driver['id']);
$status_labels = ['assigned' => '배정됨', 'delivering' => '배송중', 'arrived' => '도착'];

// 완료된 배송은 채팅 접근 자체가 막히므로(배송 종료 시 채팅 차단), 안읽음 배지도 진행중 건만 계산한다.
$chat_unread_map = mall_order_chat_unread_map_for_driver(array_column($active_orders, 'order_id'));

$active_driver_nav = 'list';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>배달 목록 - 배송기사</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="/mall/css/wanted-tokens.css">
    <link rel="stylesheet" href="/mall/css/mall.css">
    <style>
        body { padding-bottom: 76px; }
        .driver-header { display: flex; align-items: center; justify-content: space-between; padding: var(--space-4) var(--space-5); border-bottom: 1px solid var(--line-alternative); }
        .list-tabs { display: flex; border-bottom: 1px solid var(--line-alternative); }
        .list-tab-btn { flex: 1; padding: 12px 0; text-align: center; font: 700 13px var(--font-sans); color: var(--label-alternative); background: none; border: none; border-bottom: 2px solid transparent; }
        .list-tab-btn.active { color: var(--primary-normal); border-bottom-color: var(--primary-normal); }
        .order-card { display: block; margin: var(--space-4) var(--space-5) 0; padding: var(--space-4); border: 1px solid var(--line-alternative); border-radius: var(--radius-lg); text-decoration: none; color: inherit; }
        .order-card .top { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 4px; }
        .order-card .address { font: 700 15px var(--font-sans); line-height: 1.35; }
        .order-card .landmark { font: var(--t-caption1) var(--font-sans); color: var(--label-alternative); margin-top: 2px; }
        .order-card .delivery-fee { font: 700 var(--t-caption1) var(--font-sans); color: var(--primary-strong); margin-top: 2px; }
        .order-card .meta-line { display: flex; align-items: center; justify-content: space-between; margin-top: 8px; }
        .order-card .order-no { font: var(--t-caption1) var(--font-sans); color: var(--label-assistive); font-family: monospace; }
        .order-card .member { font: var(--t-caption1) var(--font-sans); color: var(--label-alternative); }
        .order-card .chat-badge { display: inline-block; margin-left: 6px; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: var(--brand-red); color: #fff; font: 700 11px/18px var(--font-sans); text-align: center; }
        .fail-reason { font: var(--t-caption1) var(--font-sans); color: var(--brand-red); margin-top: 4px; }
    </style>
</head>
<body>
    <div class="driver-header">
        <div style="font:700 1rem var(--font-sans);"><i class="fas fa-motorcycle"></i> <?php echo htmlspecialchars($driver['name']); ?>님</div>
        <a href="/mall/driver/logout.php" style="font:var(--t-caption1) var(--font-sans);color:var(--label-alternative);">로그아웃</a>
    </div>

    <div class="list-tabs">
        <button type="button" class="list-tab-btn active" data-tab="active">진행중 (<?php echo count($active_orders); ?>)</button>
        <button type="button" class="list-tab-btn" data-tab="done">완료 (<?php echo count($done_orders); ?>)</button>
    </div>

    <div id="tab-active">
        <?php if (empty($active_orders)): ?>
            <p class="empty-state">배정된 배송이 없습니다.</p>
        <?php else: ?>
            <?php foreach ($active_orders as $o): ?>
            <a class="order-card" href="/mall/driver/order_detail.php?id=<?php echo (int)$o['order_id']; ?>">
                <div class="top">
                    <span class="address"><?php echo htmlspecialchars(mall_driver_address_line($o)); ?></span>
                    <span>
                        <span class="badge badge-blue"><?php echo $status_labels[$o['status']] ?? $o['status']; ?></span>
                        <?php if (!empty($chat_unread_map[(int)$o['order_id']])): ?>
                            <span class="chat-badge"><?php echo $chat_unread_map[(int)$o['order_id']] > 99 ? '99+' : $chat_unread_map[(int)$o['order_id']]; ?></span>
                        <?php endif; ?>
                    </span>
                </div>
                <?php if (!empty($o['ship_landmark'])): ?>
                    <div class="landmark">랜드마크: <?php echo htmlspecialchars($o['ship_landmark']); ?></div>
                <?php endif; ?>
                <?php if ((float)($o['shipping_fee'] ?? 0) > 0): ?>
                    <div class="delivery-fee">배달료 <?php echo number_format((float)$o['shipping_fee'], 2); ?></div>
                <?php endif; ?>
                <div class="meta-line">
                    <span class="member"><?php echo htmlspecialchars($o['member_name']); ?></span>
                    <span class="order-no"><?php echo htmlspecialchars($o['order_number']); ?></span>
                </div>
            </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div id="tab-done" style="display:none;">
        <?php if (empty($done_orders)): ?>
            <p class="empty-state">완료된 배송이 없습니다.</p>
        <?php else: ?>
            <?php foreach ($done_orders as $o): ?>
            <a class="order-card" href="/mall/driver/order_detail.php?id=<?php echo (int)$o['order_id']; ?>" style="<?php echo $o['assignment_status'] === 'failed' ? 'opacity:.75;' : ''; ?>">
                <div class="top">
                    <span class="address"><?php echo htmlspecialchars(mall_driver_address_line($o)); ?></span>
                    <span>
                        <span class="badge <?php echo $o['assignment_status'] === 'failed' ? 'badge-red' : 'badge-blue'; ?>"><?php echo $o['assignment_status'] === 'failed' ? '배송실패' : '완료'; ?></span>
                        <?php if (!empty($chat_unread_map[(int)$o['order_id']])): ?>
                            <span class="chat-badge"><?php echo $chat_unread_map[(int)$o['order_id']] > 99 ? '99+' : $chat_unread_map[(int)$o['order_id']]; ?></span>
                        <?php endif; ?>
                    </span>
                </div>
                <?php if ($o['assignment_status'] === 'failed' && !empty($o['failed_reason'])): ?>
                    <div class="fail-reason">사유: <?php echo htmlspecialchars($o['failed_reason']); ?></div>
                <?php endif; ?>
                <?php if ((float)($o['shipping_fee'] ?? 0) > 0): ?>
                    <div class="delivery-fee">배달료 <?php echo number_format((float)$o['shipping_fee'], 2); ?></div>
                <?php endif; ?>
                <div class="meta-line">
                    <span class="member"><?php echo htmlspecialchars($o['member_name']); ?></span>
                    <span class="order-no"><?php echo htmlspecialchars($o['order_number']); ?></span>
                </div>
            </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php require __DIR__ . '/partials/nav.php'; ?>

<script>
document.querySelectorAll('.list-tab-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.list-tab-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('tab-active').style.display = btn.dataset.tab === 'active' ? 'block' : 'none';
        document.getElementById('tab-done').style.display = btn.dataset.tab === 'done' ? 'block' : 'none';
    });
});
</script>
</body>
</html>
