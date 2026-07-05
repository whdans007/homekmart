<?php
$page_title = 'Order History';
require_once __DIR__ . '/partials/header.php';

$store_id = store_current_store_id();
$status   = $_GET['status'] ?? 'all';
$page     = max(1, (int)($_GET['page'] ?? 1));
$limit    = 20;
$offset   = ($page - 1) * $limit;

try {
    $conn = get_store_db();

    // Design Ref: §5.2 — 배송 대기 중 주문 조회 (incoming shipments)
    $st = $conn->prepare(
        "SELECT o.id, o.order_date, o.shipped_at, o.status
         FROM lc_orders o
         WHERE o.store_id = ? AND o.status = 'shipped'
         ORDER BY o.shipped_at DESC"
    );
    $st->bind_param('i', $store_id);
    $st->execute();
    $incoming_orders_raw = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    // Build incoming orders with product details
    $incoming_orders = [];
    foreach ($incoming_orders_raw as $ord) {
        $st = $conn->prepare(
            "SELECT oi.id, oi.product_id, oi.quantity, p.name_en, p.name_ko
             FROM lc_order_items oi
             JOIN lc_products p ON p.id = oi.product_id
             WHERE oi.order_id = ?
             ORDER BY oi.id"
        );
        $st->bind_param('i', $ord['id']);
        $st->execute();
        $ord['items'] = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        $incoming_orders[] = $ord;
    }

    // 일반 주문 목록 조회
    $conds  = ['o.store_id = ?'];
    $params = [$store_id];
    $types  = 'i';

    if ($status !== 'all') {
        $conds[] = "o.status = ?";
        $params[] = $status;
        $types   .= 's';
    }

    $where = 'WHERE ' . implode(' AND ', $conds);

    $st = $conn->prepare("SELECT COUNT(*) FROM lc_orders o $where");
    $st->bind_param($types, ...$params); $st->execute();
    $total = (int)$st->get_result()->fetch_row()[0]; $st->close();
    $total_pages = max(1, ceil($total / $limit));

    $st = $conn->prepare(
        "SELECT o.id, o.order_date, o.status, o.notes, o.created_at,
                COUNT(oi.id) AS item_count, COALESCE(SUM(oi.quantity),0) AS total_qty
         FROM lc_orders o
         LEFT JOIN lc_order_items oi ON oi.order_id = o.id
         $where
         GROUP BY o.id
         ORDER BY o.created_at DESC
         LIMIT $limit OFFSET $offset"
    );
    $st->bind_param($types, ...$params); $st->execute();
    $orders = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
    $conn->close();
} catch (Exception $e) {
    $orders = []; $total = 0; $total_pages = 1; $incoming_orders = [];
}

$statuses = ['all'=>'All','pending'=>'Pending','approved'=>'Approved','shipped'=>'Shipped','delivered'=>'Delivered','cancelled'=>'Cancelled'];
?>

<div class="flex items-center justify-between mb-4">
    <h2 class="text-lg font-bold text-gray-900">Order History</h2>
    <a href="<?php echo STORE_BASE; ?>/order.php"
       class="px-4 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700">
        <i class="fas fa-plus mr-1"></i>New Order
    </a>
</div>

<!-- Status Filter -->
<div class="flex gap-2 overflow-x-auto pb-2 mb-4 scrollbar-none">
    <?php foreach ($statuses as $key => $label): ?>
    <a href="?status=<?php echo $key; ?>"
       class="flex-shrink-0 px-4 py-1.5 rounded-full text-sm font-medium border transition-colors
              <?php echo $status === $key ? 'bg-teal-600 text-white border-teal-600' : 'bg-white text-gray-600 border-gray-300 hover:bg-teal-50'; ?>">
        <?php echo $label; ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Design Ref: §5.2 — 배송 대기 중 (Incoming Shipments Section) -->
<?php if (!empty($incoming_orders)): ?>
<div class="mb-6">
    <div class="flex items-center gap-2 mb-3">
        <h3 class="text-lg font-bold text-teal-700">배송 대기 중</h3>
        <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-teal-100 text-teal-700 text-xs font-bold">
            <?php echo count($incoming_orders); ?>
        </span>
    </div>
    <div class="space-y-3">
    <?php foreach ($incoming_orders as $o): ?>
    <div class="order-card bg-white rounded-xl border-2 border-teal-200 p-4">
        <div class="flex items-start justify-between mb-3">
            <div>
                <span class="font-mono text-sm font-bold text-teal-700">#<?php echo str_pad($o['id'], 4, '0', STR_PAD_LEFT); ?></span>
                <div class="text-xs text-gray-500 mt-0.5">
                    <i class="fas fa-calendar-day mr-1"></i>배송일: <?php echo date('Y-m-d H:i', strtotime($o['shipped_at'])); ?>
                </div>
            </div>
        </div>
        <div class="bg-gray-50 rounded px-3 py-2 mb-3 text-xs">
            <?php foreach ($o['items'] as $item): ?>
            <div class="text-gray-700">
                <span class="font-medium"><?php echo htmlspecialchars($item['name_en']); ?></span>
                <?php if ($item['name_ko']): ?>
                    <span class="text-gray-500">(<?php echo htmlspecialchars($item['name_ko']); ?>)</span>
                <?php endif; ?>
                <span class="float-right font-semibold">×<?php echo $item['quantity']; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="flex gap-2">
            <button type="button" class="flex-1 px-4 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700 transition-colors confirm-delivery-btn disabled:opacity-50 disabled:cursor-not-allowed"
                    data-order-id="<?php echo $o['id']; ?>">
                <i class="fas fa-check-circle mr-1"></i>수령했습니다
            </button>
            <a href="<?php echo STORE_BASE; ?>/order_detail.php?id=<?php echo $o['id']; ?>"
               class="px-4 py-2 bg-gray-100 text-gray-600 text-sm font-medium rounded-lg hover:bg-gray-200 transition-colors">
                <i class="fas fa-info-circle mr-1"></i>상세
            </a>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (empty($orders)): ?>
<div class="bg-white rounded-xl border border-gray-200 p-10 text-center text-gray-400">
    <i class="fas fa-clipboard-list text-4xl mb-3 block"></i>
    <p>No order history.</p>
    <a href="<?php echo STORE_BASE; ?>/order.php"
       class="mt-4 inline-block px-5 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700">
        Place Order
    </a>
</div>
<?php else: ?>

<div class="space-y-3">
<?php foreach ($orders as $o): ?>
<a href="<?php echo STORE_BASE; ?>/order_detail.php?id=<?php echo $o['id']; ?>"
   class="block bg-white rounded-xl border border-gray-200 p-4 hover:border-teal-300 transition-colors">
    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="font-mono text-sm font-bold text-gray-700">#<?php echo str_pad($o['id'], 4, '0', STR_PAD_LEFT); ?></span>
                <span class="text-xs px-2 py-0.5 rounded-full font-medium <?php echo store_status_class($o['status']); ?>">
                    <?php echo store_status_label($o['status']); ?>
                </span>
            </div>
            <div class="text-xs text-gray-500">
                <?php echo date('d M Y H:i', strtotime($o['created_at'])); ?>
            </div>
            <?php if ($o['notes']): ?>
            <div class="text-xs text-gray-400 mt-1 truncate max-w-xs"><?php echo htmlspecialchars($o['notes']); ?></div>
            <?php endif; ?>
        </div>
        <div class="text-right">
            <div class="text-sm font-semibold text-gray-700"><?php echo $o['item_count']; ?> items</div>
            <div class="text-xs text-gray-400">Total <?php echo number_format($o['total_qty']); ?></div>
        </div>
    </div>
</a>
<?php endforeach; ?>
</div>

<?php if ($total_pages > 1): ?>
<div class="flex justify-center gap-2 mt-5">
    <?php if ($page > 1): ?>
    <a href="?status=<?php echo $status; ?>&page=<?php echo $page-1; ?>"
       class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-sm hover:bg-gray-50">Previous</a>
    <?php endif; ?>
    <span class="px-4 py-2 text-sm text-gray-500"><?php echo $page; ?> / <?php echo $total_pages; ?></span>
    <?php if ($page < $total_pages): ?>
    <a href="?status=<?php echo $status; ?>&page=<?php echo $page+1; ?>"
       class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-sm hover:bg-gray-50">Next</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<!-- Design Ref: §5.2 — 배송 확인 버튼 handler (confirm delivery JavaScript) -->
<script>
(function() {
    var STORE_BASE = '<?php echo STORE_BASE; ?>';
    var CSRF_TOKEN = '<?php echo store_csrf_token(); ?>';
    var STORE_ID = <?php echo (int)$store_id; ?>;

    document.querySelectorAll('.confirm-delivery-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var orderId = parseInt(this.dataset.orderId, 10);
            var btn = this;
            var card = this.closest('.order-card');

            if (!orderId) return;

            btn.disabled = true;
            var originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-circle-notch fa-spin mr-1"></i>확인 중...';

            var formData = new FormData();
            formData.append('action', 'confirm_delivery');
            formData.append('order_id', orderId);
            formData.append('store_id', STORE_ID);
            formData.append('csrf_token', CSRF_TOKEN);

            // Cross-system API call: @store → @logistics (both on 192.168.1.116)
            var logisticsApiUrl = STORE_BASE.replace('/store', '') + '/logistics/ajax/order_confirm_delivery.php';

            fetch(logisticsApiUrl, {
                method: 'POST',
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    card.style.opacity = '0.5';
                    card.style.textDecoration = 'line-through';
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-check-circle mr-1"></i>확인됨';
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    alert('오류: ' + (data.message || '배송 확인에 실패했습니다.'));
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
            })
            .catch(function(e) {
                alert('네트워크 오류: ' + e.message);
                btn.disabled = false;
                btn.innerHTML = originalText;
            });
        });
    });
})();
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
