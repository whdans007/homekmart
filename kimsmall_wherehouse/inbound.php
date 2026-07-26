<?php
$page_title = "Inbound Management - KIM'S MALL WAREHOUSE";
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/config/db.php';

kw_require_staff();

$search_supplier = trim($_GET['supplier'] ?? '');
$search_date     = trim($_GET['date']     ?? '');
$page            = max(1, (int)($_GET['page'] ?? 1));
$limit           = 10;
$offset          = ($page - 1) * $limit;

try {
    $conn = get_lc_db();

    // is_confirmed 컬럼 존재 여부 확인 (마이그레이션 v6 적용 전 호환)
    $col_check = $conn->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kw_inbound_batches' AND COLUMN_NAME = 'is_confirmed'");
    $has_confirmed = (bool)$col_check->fetch_row()[0];

    $conds = []; $params = []; $types = '';

    if ($search_supplier) {
        $conds[] = "s.name LIKE ?";
        $params[] = "%$search_supplier%"; $types .= 's';
    }
    if ($search_date) {
        $conds[] = "b.inbound_date = ?";
        $params[] = $search_date; $types .= 's';
    }

    $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

    $cnt_sql = "SELECT COUNT(*) FROM kw_inbound_batches b
                LEFT JOIN kw_suppliers s ON b.supplier_id = s.id
                $where";
    if ($params) {
        $st = $conn->prepare($cnt_sql); $st->bind_param($types, ...$params); $st->execute();
        $total = (int)$st->get_result()->fetch_row()[0]; $st->close();
    } else {
        $total = (int)$conn->query($cnt_sql)->fetch_row()[0];
    }
    $total_pages = max(1, (int)ceil($total / $limit));

    $confirmed_col = $has_confirmed ? 'b.is_confirmed,' : '0 AS is_confirmed,';
    $sql = "SELECT b.id, b.inbound_date, b.created_at, b.notes, $confirmed_col
                   s.name AS supplier_name,
                   u.full_name AS created_by_name,
                   COUNT(i.id) AS item_count,
                   COALESCE(SUM(i.quantity * i.cost_price), 0) AS total_amount
            FROM kw_inbound_batches b
            LEFT JOIN kw_suppliers s ON b.supplier_id = s.id
            LEFT JOIN users u ON b.created_by = u.id
            LEFT JOIN kw_inbound i ON i.batch_id = b.id
            $where
            GROUP BY b.id
            ORDER BY b.inbound_date DESC, b.id DESC
            LIMIT $limit OFFSET $offset";
    if ($params) {
        $st = $conn->prepare($sql); $st->bind_param($types, ...$params); $st->execute();
        $list = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
    } else {
        $list = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    }
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
<div class="flex items-center justify-between shrink-0 pt-1 pr-1">
    <h2 class="text-xl font-bold text-gray-900">Inbound Management</h2>
</div>

<!-- 검색 폼 -->
<form method="get" class="bg-white rounded-lg border border-gray-200 px-3 py-2 shrink-0">
    <div class="flex flex-wrap items-center gap-2">
        <input type="text" name="supplier" value="<?php echo htmlspecialchars($search_supplier); ?>"
               placeholder="Search supplier name"
               class="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500 w-48">
        <input type="date" name="date" value="<?php echo htmlspecialchars($search_date); ?>"
               class="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
        <button type="submit" class="px-3 py-1.5 bg-teal-600 text-white text-sm rounded-md hover:bg-teal-700">
            <i class="fas fa-search mr-1"></i>Search
        </button>
        <a href="<?php echo LC_BASE; ?>/inbound.php" class="px-3 py-1.5 bg-gray-100 text-gray-600 text-sm rounded-md hover:bg-gray-200">Reset</a>
        <a href="<?php echo LC_BASE; ?>/export_inbound.php?<?php echo http_build_query(['supplier'=>$search_supplier,'date'=>$search_date]); ?>"
           class="px-3 py-1.5 bg-green-600 text-white text-sm rounded-md hover:bg-green-700"><i class="fas fa-file-excel mr-1"></i>Excel Download</a>
        <button type="button" onclick="openPrintPreview()"
           class="px-3 py-1.5 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700"><i class="fas fa-print mr-1"></i>Print</button>
        <a href="<?php echo LC_BASE; ?>/inbound_add.php"
           class="ml-auto inline-flex items-center px-4 py-2 bg-orange-600 hover:bg-orange-700 text-black text-sm font-bold rounded-lg shadow-sm hover:shadow transition-colors">
            <i class="fas fa-plus mr-2"></i>Register Inbound
        </a>
    </div>
</form>

<?php if (isset($db_error)): ?>
<div class="bg-red-50 border border-red-200 rounded-lg p-4 text-red-700 text-sm shrink-0"><?php echo htmlspecialchars($db_error); ?></div>
<?php endif; ?>

<!-- 테이블 카드 -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden flex flex-col flex-1 min-h-0">
    <div class="px-4 py-3 border-b border-gray-100 text-sm text-gray-500 shrink-0">Total <?php echo number_format($total); ?> records</div>
    <div class="overflow-auto flex-1 min-h-0">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 sticky top-0 z-10"><tr>
                <th class="px-4 py-3 text-center text-xs text-gray-500 font-medium">#</th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium">Inbound Date</th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium">Supplier</th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium">Item Count</th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium">Total Amount</th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium">Registered By</th>
                <th class="px-4 py-3 text-center text-xs text-gray-500 font-medium">Status</th>
                <th class="px-4 py-3 text-center text-xs text-gray-500 font-medium">Action</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php if (empty($list)): ?>
            <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">
                <i class="fas fa-boxes text-3xl mb-2 block text-gray-300"></i>
                No inbound records found.
            </td></tr>
            <?php endif; ?>
            <?php
            foreach ($list as $row): ?>
            <tr class="hover:bg-gray-50 transition-colors cursor-pointer"
                onclick="location.href='<?php echo LC_BASE; ?>/inbound_detail.php?batch_id=<?php echo $row['id']; ?>'">
                <td class="px-4 py-3 text-center text-gray-500"><?php echo $row['id']; ?></td>
                <td class="px-4 py-3">
                    <div class="text-gray-700"><?php echo date('d M Y', strtotime($row['inbound_date'])); ?></div>
                    <div class="text-xs text-gray-400 mt-0.5"><?php echo date('H:i', strtotime($row['created_at'])); ?></div>
                </td>
                <td class="px-4 py-3 font-medium text-gray-900"><?php echo htmlspecialchars($row['supplier_name'] ?? '-'); ?></td>
                <td class="px-4 py-3 text-right text-gray-700"><?php echo number_format($row['item_count']); ?> items</td>
                <td class="px-4 py-3 text-right font-semibold text-gray-900"><?php echo number_format($row['total_amount'], 2); ?></td>
                <td class="px-4 py-3 text-gray-500 text-xs"><?php echo htmlspecialchars($row['created_by_name'] ?? '-'); ?></td>
                <td class="px-4 py-3 text-center">
                    <?php if ($row['is_confirmed']): ?>
                    <span class="px-2 py-1 text-xs font-medium bg-gray-100 text-gray-600 rounded-full">
                        <i class="fas fa-lock mr-1"></i>Locked
                    </span>
                    <?php else: ?>
                    <span class="px-2 py-1 text-xs font-medium bg-green-100 text-green-700 rounded-full">
                        <i class="fas fa-unlock mr-1"></i>Editable
                    </span>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-center" onclick="event.stopPropagation()">
                    <a href="<?php echo LC_BASE; ?>/inbound_detail.php?batch_id=<?php echo $row['id']; ?>"
                       class="inline-flex items-center px-3 py-1 text-xs font-medium text-teal-700 bg-teal-50 border border-teal-200 rounded-md hover:bg-teal-100">
                        <i class="fas fa-eye mr-1"></i>Details
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- 페이지네이션 -->
    <?php if ($total_pages > 1): ?>
    <?php
        $window = 10;
        $block_start = (int)(floor(($page - 1) / $window) * $window) + 1;
        $block_end   = min($total_pages, $block_start + $window - 1);
        $qs = ['supplier' => $search_supplier, 'date' => $search_date];
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
           class="flex items-center justify-center rounded font-medium transition-colors
                  <?php echo $i === $page ? 'w-9 h-9 bg-teal-600 text-white text-base shadow-md ring-2 ring-teal-300' : 'w-8 h-8 text-sm text-gray-500 hover:bg-gray-100'; ?>">
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

<script>
(function() {
    var LC_BASE = '<?php echo LC_BASE; ?>';

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closePrintPreview();
        }
    });

    window.openPrintPreview = function() {
        var qs = new URLSearchParams({
            supplier: '<?php echo addslashes($search_supplier); ?>',
            date: '<?php echo addslashes($search_date); ?>'
        }).toString();
        document.getElementById('printPreviewFrame').src = LC_BASE + '/print_inbound.php?' + qs;
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
