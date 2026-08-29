<?php
$page_title = 'Order Details';
require_once __DIR__ . '/partials/header.php';

$store_id = store_current_store_id();
$id       = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . STORE_BASE . '/orders.php'); exit; }

try {
    $conn = get_store_db();

    $st = $conn->prepare(
        "SELECT o.*, u.full_name AS created_by_name, s.name AS store_name
         FROM lc_orders o
         LEFT JOIN users u ON o.created_by = u.id
         LEFT JOIN stores s ON o.store_id = s.id
         WHERE o.id = ? AND o.store_id = ?"
    );
    $st->bind_param('ii', $id, $store_id); $st->execute();
    $order = $st->get_result()->fetch_assoc(); $st->close();

    if (!$order) {
        $conn->close();
        store_set_flash('error', 'Order not found.');
        header('Location: ' . STORE_BASE . '/orders.php'); exit;
    }

    $st = $conn->prepare(
        "SELECT oi.*, CONCAT(p.name_en, IFNULL(CONCAT(' (',p.name_ko,')'),'')) AS product_name,
                p.name_en, p.name_ko, p.unit, p.capacity, p.pieces_per_box AS product_ppb,
                COALESCE(p.barcode_unit, p.barcode_box, p.barcode_logistics) AS barcode,
                b.name_en AS brand_name, b.name_ko AS brand_name_ko
         FROM lc_order_items oi
         JOIN lc_products p ON oi.product_id = p.id
         LEFT JOIN lc_brands b ON p.brand_id = b.id
         WHERE oi.order_id = ?"
    );
    $st->bind_param('i', $id); $st->execute();
    $items = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

    // 재고 차감 완료 주문(대책 C: 접수 즉시 차감): lot 유통기한 정보 로드
    $expiry_by_item = [];
    if (in_array($order['status'], ['pending', 'approved', 'cancel_requested', 'shipped', 'delivered'])) {
        $item_ids_csv = implode(',', array_column($items, 'id') ?: [0]);
        $lot_rows = $conn->query(
            "SELECT oll.order_item_id,
                    GROUP_CONCAT(
                        CASE WHEN inv.expiry_date IS NOT NULL
                             THEN CONCAT(DATE_FORMAT(inv.expiry_date,'%d %b %Y'), ' ×', oll.quantity)
                             ELSE NULL END
                        ORDER BY inv.expiry_date ASC SEPARATOR ' / '
                    ) AS expiry_info,
                    MIN(inv.expiry_date) AS earliest_expiry
             FROM lc_order_item_lots oll
             JOIN lc_inventory inv ON oll.inventory_id = inv.id
             WHERE oll.order_item_id IN ($item_ids_csv)
             GROUP BY oll.order_item_id"
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($lot_rows as $lr) {
            $expiry_by_item[$lr['order_item_id']] = $lr;
        }
    }

    $conn->close();
} catch (Exception $e) {
    store_set_flash('error', 'DB Error: ' . $e->getMessage());
    header('Location: ' . STORE_BASE . '/orders.php'); exit;
}

$total_qty = array_sum(array_column($items, 'quantity'));

$timeline = [
    'pending'   => ['icon'=>'fa-clock',          'label'=>'Order Received',  'color'=>'text-yellow-500'],
    'approved'  => ['icon'=>'fa-check-circle',    'label'=>'Approved',       'color'=>'text-blue-500'],
    'shipped'   => ['icon'=>'fa-truck',           'label'=>'Shipped',       'color'=>'text-purple-500'],
    'delivered' => ['icon'=>'fa-check-double',    'label'=>'Delivered',  'color'=>'text-green-500'],
    'cancelled' => ['icon'=>'fa-times-circle',    'label'=>'Cancelled',       'color'=>'text-gray-400'],
];
$status_order = ['pending','approved','shipped','delivered'];
$current_idx  = array_search($order['status'], $status_order);
?>

<div class="flex items-center justify-between mb-5">
    <div class="flex items-center gap-3">
        <a href="<?php echo STORE_BASE; ?>/orders.php" class="text-gray-400 hover:text-gray-600">
            <i class="fas fa-arrow-left"></i>
        </a>
        <h2 class="text-lg font-bold text-gray-900">
            Order #<?php echo str_pad($order['id'], 4, '0', STR_PAD_LEFT); ?>
        </h2>
        <span class="text-xs px-2.5 py-1 rounded-full font-medium <?php echo store_status_class($order['status']); ?>">
            <?php echo store_status_label($order['status']); ?>
        </span>
    </div>
    <?php if ($order['status'] === 'pending'): ?>
    <div class="flex items-center gap-2">
        <a href="<?php echo STORE_BASE; ?>/order.php?edit=<?php echo $order['id']; ?>"
           class="px-4 py-2 text-teal-700 border border-teal-200 text-sm font-medium rounded-lg hover:bg-teal-50 transition-colors">
            <i class="fas fa-pen-to-square mr-1.5"></i>Edit Order
        </a>
        <button type="button" onclick="openCancelModal()"
                class="px-4 py-2 text-red-600 border border-red-200 text-sm font-medium rounded-lg hover:bg-red-50 transition-colors">
            <i class="fas fa-times mr-1.5"></i>Cancel Order
        </button>
    </div>
    <?php elseif ($order['status'] === 'approved'): ?>
    <button type="button" onclick="openCancelModal()"
            class="px-4 py-2 text-red-600 border border-red-200 text-sm font-medium rounded-lg hover:bg-red-50 transition-colors">
        <i class="fas fa-times mr-1.5"></i>Cancel Order
    </button>
    <?php elseif ($order['status'] === 'cancel_requested'): ?>
    <span class="px-3 py-1.5 bg-orange-100 text-orange-700 text-xs font-semibold rounded-lg">
        <i class="fas fa-clock mr-1"></i>Awaiting cancellation approval
    </span>
    <?php elseif ($order['status'] === 'cancelled'): ?>
    <a href="<?php echo STORE_BASE; ?>/order.php?from_order=<?php echo $order['id']; ?>"
       class="px-4 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700 transition-colors">
        <i class="fas fa-redo mr-1.5"></i>Reorder
    </a>
    <?php endif; ?>
</div>

<!-- Order Information -->
<div class="bg-white rounded-xl border border-gray-200 p-4 mb-4">
    <div class="grid grid-cols-2 gap-4 text-sm">
        <div>
            <p class="text-xs text-gray-500 mb-0.5">Order Date</p>
            <p class="font-medium text-gray-800"><?php echo date('d M Y', strtotime($order['order_date'])); ?></p>
        </div>
        <div>
            <p class="text-xs text-gray-500 mb-0.5">Received Time</p>
            <p class="font-medium text-gray-800"><?php echo date('H:i', strtotime($order['created_at'])); ?></p>
        </div>
        <div>
            <p class="text-xs text-gray-500 mb-0.5">Ordered By</p>
            <p class="font-medium text-gray-800"><?php echo htmlspecialchars($order['created_by_name'] ?? '-'); ?></p>
        </div>
        <div>
            <p class="text-xs text-gray-500 mb-0.5">Total Quantity</p>
            <p class="font-medium text-gray-800"><?php echo number_format($total_qty); ?> units</p>
        </div>
    </div>
    <?php if ($order['notes']): ?>
    <div class="mt-3 pt-3 border-t border-gray-100 text-sm text-gray-600">
        <p class="text-xs text-gray-500 mb-0.5">Notes</p>
        <p><?php echo htmlspecialchars($order['notes']); ?></p>
    </div>
    <?php endif; ?>
</div>

<!-- Progress Status Timeline -->
<?php if ($order['status'] !== 'cancelled'): ?>
<div class="bg-white rounded-xl border border-gray-200 p-4 mb-4">
    <p class="text-xs font-semibold text-gray-500 uppercase mb-3">Progress Status</p>
    <div class="flex items-center justify-between">
    <?php foreach ($status_order as $idx => $st_key):
        $done   = $current_idx !== false && $idx <= $current_idx;
        $active = $order['status'] === $st_key;
        $info   = $timeline[$st_key];
    ?>
        <div class="flex flex-col items-center flex-1 text-center">
            <div class="w-8 h-8 rounded-full flex items-center justify-center mb-1
                        <?php echo $done ? 'bg-teal-100' : 'bg-gray-100'; ?>">
                <i class="fas <?php echo $info['icon']; ?> text-sm
                   <?php echo $done ? 'text-teal-600' : 'text-gray-300'; ?>"></i>
            </div>
            <span class="text-xs <?php echo $active ? 'font-bold text-teal-700' : ($done ? 'text-gray-600' : 'text-gray-300'); ?>">
                <?php echo $info['label']; ?>
            </span>
        </div>
        <?php if ($idx < count($status_order) - 1): ?>
        <div class="flex-1 h-0.5 <?php echo ($current_idx !== false && $idx < $current_idx) ? 'bg-teal-400' : 'bg-gray-200'; ?> mx-1 mb-5"></div>
        <?php endif; ?>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Order Items -->
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <div class="px-4 py-3 border-b border-gray-100">
        <span class="text-sm font-semibold text-gray-700">Order Items (<?php echo count($items); ?> items)</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="px-2 py-3 text-center text-xs text-gray-500 font-medium" style="width:36px;white-space:nowrap;">#</th>
                <th class="px-3 py-3 text-center text-xs text-gray-500 font-medium" style="width:130px;">Barcode</th>
                <th class="px-3 py-3 text-left text-xs text-gray-500 font-medium" style="width:96px;">Brand</th>
                <th class="px-3 py-3 text-left text-xs text-gray-500 font-medium">Product Name</th>
                <th class="px-2 py-3 text-center text-xs text-gray-500 font-medium whitespace-nowrap" style="width:62px;">Capacity</th>
                <th class="px-2 py-3 text-center text-xs text-gray-500 font-medium" style="width:44px;">Qty</th>
                <th class="px-2 py-3 text-center text-xs text-gray-500 font-medium" style="width:38px;">Unit</th>
                <th class="px-2 py-3 text-center text-xs text-gray-500 font-medium" style="width:32px;">PKG</th>
                <th class="px-2 py-3 text-center text-xs text-gray-500 font-medium" style="width:96px;">Expiry</th>
                <th class="px-2 py-3 text-right text-xs text-gray-500 font-medium whitespace-nowrap" style="width:76px;">Unit Price</th>
                <th class="px-3 py-3 text-right text-xs text-gray-500 font-medium" style="width:80px;">Subtotal</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php $row_no = 0; foreach ($items as $item): $row_no++;
                $expiry   = $expiry_by_item[$item['id']] ?? null;
                $row_unit = !empty($item['order_unit']) ? $item['order_unit'] : ($item['unit'] ?? '-');
                $row_ppb  = (int)($item['pieces_per_box'] ?? 0) ?: (int)($item['product_ppb'] ?? 0);
            ?>
            <tr>
                <td class="px-2 py-2 text-center text-xs text-gray-500" style="white-space:nowrap;"><?php echo $row_no; ?></td>
                <td class="px-3 py-2 text-center" style="line-height:1;">
                    <?php if (!empty($item['barcode'])): $bc = trim((string)$item['barcode']); ?>
                    <svg class="barcode-svg" data-sku="<?php echo htmlspecialchars($bc); ?>" style="display:block;margin:0 auto;max-width:100%;"></svg>
                    <div class="font-mono text-xs text-gray-600" style="margin-top:2px;line-height:1;"><?php echo htmlspecialchars($bc); ?></div>
                    <?php else: ?>
                    <span class="text-gray-300">-</span>
                    <?php endif; ?>
                </td>
                <td class="px-3 py-2 text-xs" style="line-height:1.2;">
                    <?php if (!empty($item['brand_name']) || !empty($item['brand_name_ko'])): ?>
                    <?php if (!empty($item['brand_name_ko'])): ?>
                    <div class="text-gray-900 font-semibold"><?php echo htmlspecialchars($item['brand_name_ko']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($item['brand_name'])): ?>
                    <div class="text-gray-500"><?php echo htmlspecialchars($item['brand_name']); ?></div>
                    <?php endif; ?>
                    <?php else: ?>
                    <span class="text-gray-300">-</span>
                    <?php endif; ?>
                </td>
                <td class="px-3 py-2 font-medium text-gray-900" style="line-height:1.2;">
                    <div><?php echo htmlspecialchars($item['name_en']); ?></div>
                    <?php if (!empty($item['name_ko'])): ?>
                    <div class="text-xs text-gray-500" style="margin-top:1px;"><?php echo htmlspecialchars($item['name_ko']); ?></div>
                    <?php endif; ?>
                </td>
                <td class="px-2 py-2 text-xs text-gray-600 text-center"><?php echo htmlspecialchars($item['capacity'] ?? '') ?: '-'; ?></td>
                <td class="px-2 py-3 text-center font-bold text-teal-700"><?php echo number_format($item['quantity']); ?></td>
                <td class="px-2 py-2 text-center text-xs font-semibold text-gray-700"><?php echo htmlspecialchars($row_unit); ?></td>
                <td class="px-2 py-2 text-center text-xs text-gray-600"><?php echo $row_ppb ?: '-'; ?></td>
                <td class="px-2 py-2 text-xs text-center">
                    <?php if ($expiry && $expiry['expiry_info']):
                        $days_left = $expiry['earliest_expiry']
                            ? (int)((strtotime($expiry['earliest_expiry']) - time()) / 86400)
                            : null;
                        $expiry_cls = $days_left === null ? 'text-gray-500'
                            : ($days_left < 0  ? 'text-red-600'
                            : ($days_left <= 7 ? 'text-orange-600'
                            : ($days_left <= 30 ? 'text-yellow-600'
                            : 'text-green-700')));
                    ?>
                    <span class="<?php echo $expiry_cls; ?>"><?php echo htmlspecialchars($expiry['expiry_info']); ?></span>
                    <?php else: ?>
                    <span class="text-gray-300">-</span>
                    <?php endif; ?>
                </td>
                <td class="px-2 py-3 text-right text-gray-600"><?php echo number_format($item['unit_price'], 2); ?></td>
                <td class="px-3 py-3 text-right font-bold text-gray-900"><?php echo number_format($item['unit_price'] * $item['quantity'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="bg-gray-50">
                <td colspan="10" class="px-3 py-3 text-right text-sm font-semibold text-gray-700">Total</td>
                <td class="px-3 py-3 text-right text-base font-bold text-gray-900"><?php echo number_format($order['total_amount'] ?? array_sum(array_map(fn($i) => $i['unit_price'] * $i['quantity'], $items)), 2); ?></td>
            </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- 바코드 렌더링 (JsBarcode) — logistics/order_detail.php 와 동일 방식 -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
(function() {
    // 자릿수만으로 심볼로지 판별(체크디지트 검증 X). 바코드는 자연 크기로 렌더(축소 금지).
    function renderBarcodes() {
        if (typeof JsBarcode === 'undefined') return;
        var common = { displayValue: false, height: 20, margin: 0, lineColor: '#000', background: '#fff' };
        document.querySelectorAll('.barcode-svg').forEach(function(svg) {
            var sku = svg.getAttribute('data-sku');
            if (!sku) return;
            function draw(value, opts) { JsBarcode(svg, value, Object.assign({}, common, opts)); }
            try {
                if (/^\d{13}$/.test(sku)) {
                    draw(sku, { format: 'EAN13', width: 1.2 });
                } else if (/^\d{12}$/.test(sku)) {
                    draw('0' + sku, { format: 'EAN13', width: 1.2 }); // UPC-A → EAN13
                } else if (/^\d{8}$/.test(sku)) {
                    draw(sku, { format: 'EAN8', width: 1.6 });
                } else {
                    draw(sku, { format: 'CODE128', width: 1.2 });
                }
            } catch (e) {
                try { draw(sku, { format: 'CODE128', width: 1.2 }); }
                catch (e2) { /* 인코딩 불가 코드는 무시 */ }
            }
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderBarcodes);
    } else {
        renderBarcodes();
    }
})();
</script>

<?php if (in_array($order['status'], ['pending', 'approved'])): ?>
<!-- Cancel confirmation modal -->
<div id="cancelModal" class="hidden fixed inset-0 z-50 flex items-center justify-center" style="background:rgba(0,0,0,0.5);">
    <div class="bg-white rounded-xl shadow-xl p-4" style="width:100%;max-width:20rem;margin:0 1rem;">
        <div class="flex items-center gap-2 mb-2">
            <div class="w-7 h-7 rounded-full bg-red-100 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-times text-red-600 text-xs"></i>
            </div>
            <h3 class="text-sm font-semibold text-gray-900">Cancel Order #<?php echo str_pad($order['id'], 4, '0', STR_PAD_LEFT); ?></h3>
        </div>
        <?php if ($order['status'] === 'pending'): ?>
        <p class="text-xs text-gray-600 mb-3">Do you want to immediately cancel this order?</p>
        <?php else: ?>
        <p class="text-xs text-orange-700 bg-orange-50 rounded-lg px-2.5 py-1.5 mb-3">
            <i class="fas fa-info-circle mr-1"></i>Logistics center approval is required after submitting a cancellation request.
        </p>
        <?php endif; ?>
        <div class="flex gap-2">
            <button type="button" onclick="confirmCancel()" id="cancelConfirmBtn"
                    class="flex-1 py-2 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-700 transition-colors">
                네, 취소
            </button>
            <button type="button" onclick="document.getElementById('cancelModal').classList.add('hidden')"
                    class="flex-1 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200 transition-colors">
                아니오, 주문
            </button>
        </div>
    </div>
</div>

<script>
(function() {
    var STORE_BASE = '<?php echo STORE_BASE; ?>';
    var CSRF       = '<?php echo htmlspecialchars(store_csrf_token()); ?>';
    var ORDER_ID   = <?php echo $order['id']; ?>;

    window.openCancelModal = function() {
        document.getElementById('cancelModal').classList.remove('hidden');
    };

    window.confirmCancel = function() {
        var btn = document.getElementById('cancelConfirmBtn');
        btn.disabled = true;
        btn.textContent = 'Processing…';

        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('order_id', ORDER_ID);

        fetch(STORE_BASE + '/ajax/cancel_order.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) {
                    alert(data.message || 'An error occurred.');
                    btn.disabled = false;
                    btn.textContent = '네, 취소';
                    return;
                }
                if (data.mode === 'requested') {
                    alert('Cancellation request submitted. Cancellation will be completed after logistics center approval.');
                    location.href = STORE_BASE + '/orders.php';
                } else {
                    // 대기 중 취소 → 재주문 화면으로
                    location.href = STORE_BASE + '/order.php?from_order=' + ORDER_ID;
                }
            })
            .catch(function() {
                alert('Request failed.');
                btn.disabled = false;
                btn.textContent = '네, 취소';
            });
    };
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
