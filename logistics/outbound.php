<?php
$page_title = t('logistics.outbound.page_title');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/inventory_helper.php';

lc_require_staff();

$search = trim($_GET['search'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 25;
$offset = ($page - 1) * $limit;

// 출고 이력 = 출고 처리된 주문의 상세 (shipped/delivered)
try {
    $conn = get_lc_db();

    $where  = "WHERE o.status IN ('shipped','delivered')";
    $params = [];
    $types  = '';
    if ($search) {
        $where .= " AND (p.name_en LIKE ? OR p.name_ko LIKE ? OR p.barcode_unit LIKE ? OR p.barcode_box LIKE ? OR p.barcode_logistics LIKE ? OR s.name LIKE ?)";
        $params = ["%$search%", "%$search%", "%$search%", "%$search%", "%$search%", "%$search%"];
        $types  = 'ssssss';
    }

    $cnt = $conn->prepare(
        "SELECT COUNT(*) FROM lc_order_items oi
         JOIN lc_orders o ON oi.order_id = o.id
         JOIN lc_products p ON oi.product_id = p.id
         JOIN stores s ON o.store_id = s.id
         $where"
    );
    if ($params) { $cnt->bind_param($types, ...$params); }
    $cnt->execute();
    $total = (int)$cnt->get_result()->fetch_row()[0];
    $cnt->close();
    $total_pages = max(1, (int)ceil($total / $limit));

    $sql = "SELECT o.id AS order_id, o.shipped_at, o.status,
                   s.name AS store_name,
                   COALESCE(p.barcode_unit, p.barcode_box, p.barcode_logistics) AS product_code,
                   CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS product_name, p.capacity, p.unit,
                   oi.id AS item_id,
                   oi.quantity, oi.unit_price,
                   oi.quantity * oi.unit_price AS subtotal,
                   (SELECT MIN(inv2.expiry_date)
                    FROM lc_order_item_lots oll2
                    JOIN lc_inventory inv2 ON oll2.inventory_id = inv2.id
                    WHERE oll2.order_item_id = oi.id
                      AND inv2.expiry_date IS NOT NULL) AS earliest_expiry,
                   (SELECT GROUP_CONCAT(
                        CONCAT(DATE_FORMAT(inv3.expiry_date, '%d %b %Y'), ' ×', oll3.quantity)
                        ORDER BY inv3.expiry_date ASC SEPARATOR ' / ')
                    FROM lc_order_item_lots oll3
                    JOIN lc_inventory inv3 ON oll3.inventory_id = inv3.id
                    WHERE oll3.order_item_id = oi.id
                      AND inv3.expiry_date IS NOT NULL) AS expiry_info,
                   (SELECT COALESCE(SUM(inv4.quantity_remain), 0)
                    FROM lc_inventory inv4
                    JOIN lc_inbound ib4 ON inv4.inbound_id = ib4.id
                    WHERE inv4.product_id = p.id AND inv4.quantity_remain > 0) AS remaining_stock
            FROM lc_order_items oi
            JOIN lc_orders o ON oi.order_id = o.id
            JOIN lc_products p ON oi.product_id = p.id
            JOIN stores s ON o.store_id = s.id
            $where
            ORDER BY o.shipped_at DESC, o.id DESC
            LIMIT $limit OFFSET $offset";
    $st = $conn->prepare($sql);
    if ($params) { $st->bind_param($types, ...$params); }
    $st->execute();
    $list = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    $conn->close();
} catch (Exception $e) {
    $db_error = $e->getMessage(); $list = []; $total = 0; $total_pages = 1;
}
?>

<style>
main { overflow: hidden !important; }
</style>

<div class="flex flex-col h-full gap-3 overflow-hidden">

<!-- 페이지 헤더 -->
<div class="flex items-center justify-between shrink-0">
    <h2 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars(t('logistics.outbound.title')); ?></h2>
</div>

<!-- 검색 폼 -->
<form method="get" class="bg-white rounded-lg border border-gray-200 px-3 py-2 shrink-0">
    <div class="flex flex-wrap items-center gap-2">
        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
               placeholder="<?php echo htmlspecialchars(t('logistics.outbound.search_placeholder')); ?>"
               class="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500 w-64">
        <button type="submit" class="px-3 py-1.5 bg-teal-600 text-white text-sm rounded-md hover:bg-teal-700">
            <i class="fas fa-search mr-1"></i><?php echo htmlspecialchars(t('logistics.outbound.search')); ?>
        </button>
        <?php if ($search): ?>
        <a href="<?php echo LC_BASE; ?>/outbound.php" class="px-3 py-1.5 bg-gray-100 text-gray-600 text-sm rounded-md hover:bg-gray-200"><?php echo htmlspecialchars(t('logistics.outbound.reset')); ?></a>
        <?php endif; ?>
        <button type="button" onclick="openPrintPreview()"
           class="px-3 py-1.5 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700"><i class="fas fa-print mr-1"></i><?php echo htmlspecialchars(t('logistics.outbound.print')); ?></button>
    </div>
</form>

<?php if (isset($db_error)): ?>
<div class="bg-red-50 border border-red-200 rounded-lg p-4 text-red-700 text-sm shrink-0"><?php echo htmlspecialchars($db_error); ?></div>
<?php endif; ?>

<!-- 테이블 카드 -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden flex flex-col flex-1 min-h-0">
    <div class="px-4 py-3 border-b border-gray-100 text-sm text-gray-500 shrink-0"><?php echo htmlspecialchars(t('logistics.outbound.total', ['count' => number_format($total)])); ?></div>
    <div class="overflow-auto flex-1 min-h-0">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 sticky top-0 z-10"><tr>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.outbound.order_number')); ?></th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.outbound.outbound_datetime')); ?></th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.outbound.store')); ?></th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.outbound.product_code')); ?></th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.outbound.product_name')); ?></th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.outbound.spec')); ?></th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.outbound.expiry_by_lot')); ?></th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.outbound.remaining_stock')); ?></th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.outbound.quantity')); ?></th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.outbound.unit_price')); ?></th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.outbound.subtotal')); ?></th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php if (empty($list)): ?>
            <tr><td colspan="11" class="px-4 py-10 text-center text-gray-400">
                <i class="fas fa-truck text-3xl mb-2 block text-gray-300"></i>
                <?php echo htmlspecialchars(t('logistics.outbound.empty')); ?>
            </td></tr>
            <?php endif; ?>
            <?php foreach ($list as $row): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3">
                    <a href="<?php echo LC_BASE; ?>/order_detail.php?id=<?php echo $row['order_id']; ?>"
                       class="font-mono text-teal-600 hover:underline">
                        #<?php echo str_pad($row['order_id'], 4, '0', STR_PAD_LEFT); ?>
                    </a>
                </td>
                <td class="px-4 py-3 text-gray-600 text-xs"><?php echo $row['shipped_at'] ?? '-'; ?></td>
                <td class="px-4 py-3 text-gray-700"><?php echo htmlspecialchars($row['store_name']); ?></td>
                <td class="px-4 py-3 text-xs font-mono text-gray-500"><?php echo $row['product_code'] ? htmlspecialchars($row['product_code']) : '-'; ?></td>
                <td class="px-4 py-3 font-medium text-gray-900"><?php echo htmlspecialchars($row['product_name']); ?></td>
                <td class="px-4 py-3 text-xs text-gray-500"><?php echo $row['capacity'] ? htmlspecialchars($row['capacity']) : '-'; ?></td>
                <td class="px-4 py-3 text-xs">
                    <?php if ($row['expiry_info']): ?>
                        <span class="<?php echo lc_expiry_class($row['earliest_expiry']); ?> px-1.5 py-0.5 rounded font-mono">
                            <?php echo htmlspecialchars($row['expiry_info']); ?>
                        </span>
                    <?php else: ?>
                        <span class="text-gray-300">-</span>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-right text-xs <?php echo $row['remaining_stock'] < 0 ? 'text-red-600 font-semibold' : 'text-gray-500'; ?>"><?php echo number_format($row['remaining_stock']); ?></td>
                <td class="px-4 py-3 text-right font-semibold"><?php echo number_format($row['quantity']); ?> <span class="text-xs text-gray-400"><?php echo htmlspecialchars($row['unit']); ?></span></td>
                <td class="px-4 py-3 text-right text-gray-600 editable-cell" data-item-id="<?php echo $row['item_id']; ?>">
                    <div class="cell-view flex items-center justify-end gap-1 cursor-pointer group" onclick="startPriceEdit(this)">
                        <span class="cell-text price-text"><?php echo number_format($row['unit_price'], 2); ?></span>
                        <i class="fas fa-pen text-gray-300 group-hover:text-teal-400 transition-colors" style="font-size:0.6rem;"></i>
                    </div>
                    <div class="cell-edit hidden flex items-center justify-end gap-1">
                        <input type="number" min="0" step="0.01" class="cell-input border border-teal-400 rounded px-2 py-1 text-xs w-20 text-right focus:outline-none focus:ring-1 focus:ring-teal-500"
                               value="<?php echo $row['unit_price']; ?>">
        <button type="button" onclick="savePriceEdit(this)" class="px-2 py-1 bg-teal-600 text-white text-xs rounded hover:bg-teal-700"><?php echo htmlspecialchars(t('logistics.outbound.save')); ?></button>
        <button type="button" onclick="cancelPriceEdit(this)" class="px-2 py-1 bg-gray-100 text-gray-600 text-xs rounded hover:bg-gray-200"><?php echo htmlspecialchars(t('logistics.outbound.cancel')); ?></button>
                    </div>
                </td>
                <td class="px-4 py-3 text-right font-bold subtotal-text" id="subtotal-<?php echo $row['item_id']; ?>"><?php echo number_format($row['subtotal'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <?php
        $window = 10;
        $block_start = (int)(floor(($page - 1) / $window) * $window) + 1;
        $block_end   = min($total_pages, $block_start + $window - 1);
        $qs = ['search' => $search];
    ?>
    <div class="px-4 py-3 border-t border-gray-100 flex items-center justify-center gap-1 shrink-0">
        <?php if ($block_start > 1): ?>
        <a href="?page=<?php echo $block_start - $window; ?>&<?php echo http_build_query($qs); ?>"
           class="w-8 h-8 flex items-center justify-center rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 text-sm transition-colors" title="Previous 10 pages">
            <i class="fas fa-angle-double-left text-xs"></i>
        </a>
        <?php endif; ?>
        <?php if ($page > 1): ?>
        <a href="?page=<?php echo $page-1; ?>&<?php echo http_build_query($qs); ?>"
           class="w-8 h-8 flex items-center justify-center rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 text-sm transition-colors">
            <i class="fas fa-chevron-left text-xs"></i>
        </a>
        <?php endif; ?>
        <?php for ($i = $block_start; $i <= $block_end; $i++): ?>
        <a href="?page=<?php echo $i; ?>&<?php echo http_build_query($qs); ?>"
           class="flex items-center justify-center rounded font-medium transition-colors <?php echo $i === $page ? 'w-9 h-9 bg-teal-600 text-white text-base shadow-md ring-2 ring-teal-300' : 'w-8 h-8 text-sm text-gray-500 hover:bg-gray-100'; ?>">
            <?php echo $i; ?>
        </a>
        <?php endfor; ?>
        <?php if ($page < $total_pages): ?>
        <a href="?page=<?php echo $page+1; ?>&<?php echo http_build_query($qs); ?>"
           class="w-8 h-8 flex items-center justify-center rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 text-sm transition-colors">
            <i class="fas fa-chevron-right text-xs"></i>
        </a>
        <?php endif; ?>
        <?php if ($block_end < $total_pages): ?>
        <a href="?page=<?php echo $block_end + 1; ?>&<?php echo http_build_query($qs); ?>"
           class="w-8 h-8 flex items-center justify-center rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 text-sm transition-colors" title="Next 10 pages">
            <i class="fas fa-angle-double-right text-xs"></i>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

</div><!-- /.flex.flex-col.h-full -->

<!-- 프린트 미리보기 모달 -->
<div id="printPreviewModal" class="hidden fixed inset-0 z-50 flex items-center justify-center">
    <div class="absolute inset-0 bg-black bg-opacity-50"></div>
    <div class="relative bg-white rounded-xl shadow-xl w-full max-w-5xl mx-4 flex flex-col" style="height:90vh">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 shrink-0">
    <h3 class="text-base font-semibold text-gray-900"><i class="fas fa-print text-blue-600 mr-2"></i><?php echo htmlspecialchars(t('logistics.outbound.print_preview')); ?></h3>
            <div class="flex items-center gap-2">
                <button type="button" onclick="printPreviewFrame()"
                        class="px-4 py-1.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700">
                    <i class="fas fa-print mr-1"></i>Print
                </button>
                <button type="button" onclick="closePrintPreview()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <div class="flex-1 min-h-0">
            <iframe id="printPreviewFrame" class="w-full h-full border-0"></iframe>
        </div>
    </div>
</div>

<script>
(function() {
    var LC_BASE = '<?php echo LC_BASE; ?>';
    var CSRF    = '<?php echo htmlspecialchars(lc_csrf_token()); ?>';

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closePrintPreview();
        }
    });

    // Design Ref: outbound-price-edit - Unit Price 셀별 인라인 수정
    window.startPriceEdit = function(viewEl) {
        var cell = viewEl.closest('.editable-cell');
        viewEl.classList.add('hidden');
        var edit = cell.querySelector('.cell-edit');
        edit.classList.remove('hidden');
        var input = edit.querySelector('.cell-input');
        input.focus();
        input.select();
    };

    window.cancelPriceEdit = function(btn) {
        var cell = btn.closest('.editable-cell');
        cell.querySelector('.cell-edit').classList.add('hidden');
        cell.querySelector('.cell-view').classList.remove('hidden');
    };

    window.savePriceEdit = function(btn) {
        var cell   = btn.closest('.editable-cell');
        var itemId = cell.dataset.itemId;
        var input  = cell.querySelector('.cell-input');

        var buttons = cell.querySelectorAll('.cell-edit button');
        buttons.forEach(function(b) { b.disabled = true; });
        var origText = btn.textContent;
        btn.textContent = '…';

        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('item_id', itemId);
        fd.append('unit_price', input.value);

        fetch(LC_BASE + '/ajax/update_outbound_price.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) { alert(data.message || <?php echo json_encode(t('logistics.outbound.save_failed')); ?>); return; }
                cell.querySelector('.price-text').textContent = data.unit_price_display;
                input.value = data.unit_price;
                var subtotalEl = document.getElementById('subtotal-' + itemId);
                if (subtotalEl) subtotalEl.textContent = data.subtotal_display;
                cancelPriceEdit(btn);
            })
            .catch(function() { alert(<?php echo json_encode(t('logistics.outbound.request_failed')); ?>); })
            .finally(function() {
                buttons.forEach(function(b) { b.disabled = false; });
                btn.textContent = origText;
            });
    };

    window.openPrintPreview = function() {
        var qs = new URLSearchParams({
            search: '<?php echo addslashes($search); ?>'
        }).toString();
        document.getElementById('printPreviewFrame').src = LC_BASE + '/print_outbound.php?' + qs;
        document.getElementById('printPreviewModal').classList.remove('hidden');
    };

    window.closePrintPreview = function() {
        document.getElementById('printPreviewModal').classList.add('hidden');
        document.getElementById('printPreviewFrame').src = 'about:blank';
    };

    window.printPreviewFrame = function() {
        var frame = document.getElementById('printPreviewFrame');
        frame.contentWindow.focus();
        frame.contentWindow.print();
    };
})();
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
