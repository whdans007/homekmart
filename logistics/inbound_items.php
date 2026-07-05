<?php
$page_title = 'Inbound Items List - Logistics Center';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/inbound_helper.php';

// Design Ref: §1 - Authentication
lc_require_staff();

// Design Ref: §6 - Filter Parsing
$filters = [
    'search' => trim($_GET['search'] ?? ''),
    'date_from' => trim($_GET['date_from'] ?? ''),
    'date_to' => trim($_GET['date_to'] ?? ''),
];
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 13;

// Design Ref: §4 - Call Helper Function
$result = getFilteredInboundItems($filters, $page, $limit);

$items = $result['items'] ?? [];
$total = $result['total'] ?? 0;
$total_pages = $result['total_pages'] ?? 1;
$db_error = $result['error'] ?? null;

// Plan SC-10: Performance < 2 seconds
$query_time = microtime(true);

?>

<style>
main { overflow: hidden !important; }
</style>

<div class="flex flex-col h-full gap-2 overflow-hidden">

    <!-- Page Header -->
    <div class="flex items-center justify-between shrink-0 px-4 py-2">
        <h2 class="text-xl font-bold text-gray-900">Inbound Items List</h2>
        <p class="text-sm text-gray-500">Total: <?php echo number_format($total); ?> items</p>
    </div>

    <!-- Filter Panel -->
    <form method="get" class="bg-white rounded-lg border border-gray-200 px-3 py-1.5 shrink-0 mx-4">
        <div class="flex flex-wrap items-center gap-2">
            <input type="text" name="search"
                   value="<?php echo htmlspecialchars($filters['search']); ?>"
                   placeholder="Search by supplier, product name, or barcode"
                   class="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500 w-64">
            <input type="date" name="date_from"
                   value="<?php echo htmlspecialchars($filters['date_from']); ?>"
                   class="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            <input type="date" name="date_to"
                   value="<?php echo htmlspecialchars($filters['date_to']); ?>"
                   class="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            <button type="submit" class="px-3 py-1.5 bg-teal-600 text-white text-sm rounded-md hover:bg-teal-700 font-medium transition-colors">
                <i class="fas fa-search mr-1"></i>Search
            </button>
            <a href="<?php echo LC_BASE; ?>/inbound_items.php" class="px-3 py-1.5 bg-gray-100 text-gray-600 text-sm rounded-md hover:bg-gray-200 font-medium transition-colors">
                <i class="fas fa-redo mr-1"></i>Reset
            </a>
            <a href="<?php echo LC_BASE; ?>/export_inbound_items.php?<?php echo http_build_query(['search'=>$filters['search'],'date_from'=>$filters['date_from'],'date_to'=>$filters['date_to']]); ?>"
               class="px-3 py-1.5 bg-green-600 text-white text-sm rounded-md hover:bg-green-700 font-medium transition-colors">
                <i class="fas fa-file-excel mr-1"></i>Excel Download
            </a>
            <button type="button" onclick="openPrintPreview()"
               class="px-3 py-1.5 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700 font-medium transition-colors">
                <i class="fas fa-print mr-1"></i>Print
            </button>
        </div>
    </form>

    <!-- Error Message -->
    <?php if ($db_error): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg px-4 py-2 text-red-700 text-sm shrink-0 mx-4">
        <i class="fas fa-exclamation-circle mr-1"></i>
        <?php echo htmlspecialchars($db_error); ?>
    </div>
    <?php endif; ?>

    <!-- Results Table -->
    <div id="tableCard" class="bg-white rounded-lg border border-gray-200 overflow-hidden flex flex-col flex-1 min-h-0 mx-4">
        <div class="px-4 py-3 border-b border-gray-100 text-sm text-gray-500 shrink-0">Total <?php echo number_format($total); ?> items</div>
        <div id="tableScrollBody" class="overflow-auto flex-1 min-h-0">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 sticky top-0 z-10">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium w-12">#</th>
                        <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium">Category</th>
                        <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium">Brand</th>
                        <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium">Product Name</th>
                        <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium">Capacity</th>
                        <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium">Unit</th>
                        <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium">PKG</th>
                        <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium">Unit Barcode</th>
                        <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium">Supplier</th>
                        <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium">Cost Price</th>
                        <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium">Qty</th>
                        <th class="px-4 py-3 text-center text-xs text-gray-500 font-medium">Inbound Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="12" class="px-4 py-10 text-center text-gray-400">
                            <i class="fas fa-inbox text-3xl mb-2 block text-gray-300"></i>
                            No inbound items found
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($items as $item): ?>
                        <tr class="hover:bg-gray-50 cursor-pointer transition-colors"
                            onclick="goToBatchDetail(<?php echo intval($item['batch_id'] ?? 0); ?>)">
                            <td class="px-4 py-2 text-gray-600 text-sm"><?php echo intval($item['id']); ?></td>
                            <td class="px-4 py-2 text-gray-600 text-xs"><?php echo htmlspecialchars($item['category_name'] ?: '—'); ?></td>
                            <td class="px-4 py-2 text-gray-600 text-xs"><?php echo htmlspecialchars($item['brand_name'] ?: '—'); ?></td>
                            <td class="px-4 py-2 text-gray-900 text-sm">
                                <?php echo htmlspecialchars($item['product_name'] ?? '—'); ?>
                                <?php if (!empty($item['product_name_ko']) && $item['product_name_ko'] !== ($item['product_name'] ?? '')): ?>
                                <span class="text-gray-400 ml-1"><?php echo htmlspecialchars($item['product_name_ko']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-2 text-gray-600 text-xs"><?php echo htmlspecialchars($item['capacity'] ?: '—'); ?></td>
                            <td class="px-4 py-2 text-gray-600 text-xs"><?php echo htmlspecialchars($item['product_unit'] ?: '—'); ?></td>
                            <td class="px-4 py-2 text-right font-mono text-xs text-gray-500"><?php echo (int)($item['pieces_per_box'] ?? 1); ?></td>
                            <td class="px-4 py-2 font-mono text-xs text-gray-500"><?php echo htmlspecialchars($item['barcode'] ?: '—'); ?></td>
                            <td class="px-4 py-2 text-gray-900 font-medium text-sm"><?php echo htmlspecialchars($item['supplier_name'] ?? '—'); ?></td>
                            <?php $iu = $item['inbound_unit'] ?? 'PCS'; // Design Ref: box-pcs-unit §5.4 ?>
                            <td class="px-4 py-2 text-right font-mono text-xs text-gray-900">
                                <?php echo number_format((float)$item['cost_price'], 2); ?>
                                <?php if ($iu === 'BOX' && (float)($item['cost_price_pcs'] ?? 0) > 0): // Plan SC-3 ?>
                                <span class="block text-gray-400">PCS <?php echo number_format((float)$item['cost_price_pcs'], 2); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-2 text-right font-mono text-xs text-gray-900">
                                <?php echo number_format((int)$item['quantity']); ?>
                                <span class="<?php echo $iu === 'BOX' ? 'text-teal-600 font-semibold' : 'text-gray-400'; ?>"><?php echo $iu; ?></span>
                            </td>
                            <td class="px-4 py-2 text-center font-mono text-xs text-gray-600"><?php echo htmlspecialchars($item['inbound_date'] ?? '—'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total_pages > 1): ?>
        <?php
        $window = 10;
        $block_start = (int)(floor(($page - 1) / $window) * $window) + 1;
        $block_end   = min($total_pages, $block_start + $window - 1);
        ?>
        <div id="tablePagination" class="px-4 py-3 border-t border-gray-100 flex items-center justify-center gap-1 shrink-0">
            <?php if ($block_start > 1): ?>
            <a href="<?php echo LC_BASE; ?>/inbound_items.php?<?php echo http_build_query(array_merge($filters, ['page' => $block_start - $window])); ?>"
               class="w-8 h-8 flex items-center justify-center rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 text-sm transition-colors" title="Previous 10 pages">
                <i class="fas fa-angle-double-left text-xs"></i>
            </a>
            <?php endif; ?>

            <?php if ($page > 1): ?>
            <a href="<?php echo LC_BASE; ?>/inbound_items.php?<?php echo http_build_query(array_merge($filters, ['page' => $page-1])); ?>"
               class="w-8 h-8 flex items-center justify-center rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 text-sm transition-colors">
                <i class="fas fa-chevron-left text-xs"></i>
            </a>
            <?php endif; ?>

            <?php for ($i = $block_start; $i <= $block_end; $i++): ?>
            <a href="<?php echo LC_BASE; ?>/inbound_items.php?<?php echo http_build_query(array_merge($filters, ['page' => $i])); ?>"
               class="flex items-center justify-center rounded font-medium transition-colors
                      <?php echo $i === $page
                          ? 'w-9 h-9 bg-teal-600 text-white text-base shadow-md ring-2 ring-teal-300'
                          : 'w-8 h-8 text-sm text-gray-500 hover:bg-gray-100'; ?>">
                <?php echo $i; ?>
            </a>
            <?php endfor; ?>

            <?php if ($page < $total_pages): ?>
            <a href="<?php echo LC_BASE; ?>/inbound_items.php?<?php echo http_build_query(array_merge($filters, ['page' => $page+1])); ?>"
               class="w-8 h-8 flex items-center justify-center rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 text-sm transition-colors">
                <i class="fas fa-chevron-right text-xs"></i>
            </a>
            <?php endif; ?>

            <?php if ($block_end < $total_pages): ?>
            <a href="<?php echo LC_BASE; ?>/inbound_items.php?<?php echo http_build_query(array_merge($filters, ['page' => $block_end + 1])); ?>"
               class="w-8 h-8 flex items-center justify-center rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 text-sm transition-colors" title="Next 10 pages">
                <i class="fas fa-angle-double-right text-xs"></i>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 프린트 미리보기 모달 -->
<div id="printPreviewModal" class="hidden fixed inset-0 z-50 flex items-center justify-center">
    <div class="absolute inset-0 bg-black bg-opacity-50"></div>
    <div class="relative bg-white rounded-xl shadow-xl w-full max-w-5xl mx-4 flex flex-col" style="height:90vh">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 shrink-0">
            <h3 class="text-base font-semibold text-gray-900"><i class="fas fa-print text-blue-600 mr-2"></i>Print Preview</h3>
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

<!-- Navigation Script -->
<script>
(function() {
    var LC_BASE = '<?php echo LC_BASE; ?>';

    window.goToBatchDetail = function(batchId) {
        if (batchId > 0) {
            // 현재 검색/필터 상태를 return 으로 넘겨, 상세에서 삭제/뒤로가기 시 이 목록으로 복귀
            var ret = encodeURIComponent(window.location.pathname + window.location.search);
            window.location.href = LC_BASE + '/inbound_detail.php?batch_id=' + batchId + '&return=' + ret;
        }
    };

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closePrintPreview();
        }
    });

    window.openPrintPreview = function() {
        var qs = new URLSearchParams({
            search: '<?php echo addslashes($filters['search']); ?>',
            date_from: '<?php echo addslashes($filters['date_from']); ?>',
            date_to: '<?php echo addslashes($filters['date_to']); ?>'
        }).toString();
        document.getElementById('printPreviewFrame').src = LC_BASE + '/print_inbound_items.php?' + qs;
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

<?php include __DIR__ . '/partials/footer.php'; ?>
