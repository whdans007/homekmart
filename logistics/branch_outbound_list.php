<?php
// Design Ref: §5.1 — 출고대기 목록 페이지 (Plan SC-2)
$page_title = 'Pending Outbound List - Logistics Center';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/config/db.php';

lc_require_staff();

$search_store = (int)($_GET['store_id'] ?? 0);
$search_date  = trim($_GET['date'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$limit        = 15;
$offset       = ($page - 1) * $limit;

try {
    $conn = get_lc_db();

    $stores = $conn->query("SELECT id, name FROM stores ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

    $conds = ["o.status = 'draft'"]; $params = []; $types = '';
    if ($search_store) {
        $conds[] = "o.store_id = ?";
        $params[] = $search_store; $types .= 'i';
    }
    if ($search_date) {
        $conds[] = "o.order_date = ?";
        $params[] = $search_date; $types .= 's';
    }
    $where = 'WHERE ' . implode(' AND ', $conds);

    $cnt_sql = "SELECT COUNT(*) FROM lc_orders o $where";
    $st = $conn->prepare($cnt_sql);
    if ($params) $st->bind_param($types, ...$params);
    $st->execute();
    $total = (int)$st->get_result()->fetch_row()[0];
    $st->close();
    $total_pages = max(1, ceil($total / $limit));

    $sql = "SELECT o.id, o.order_date, o.created_at, o.total_amount, o.notes,
                   s.name AS store_name,
                   u.full_name AS created_by_name,
                   COUNT(oi.id) AS item_count,
                   COALESCE(SUM(oi.quantity), 0) AS total_qty
            FROM lc_orders o
            LEFT JOIN stores s ON o.store_id = s.id
            LEFT JOIN users u ON o.created_by = u.id
            LEFT JOIN lc_order_items oi ON oi.order_id = o.id
            $where
            GROUP BY o.id
            ORDER BY o.id DESC
            LIMIT $limit OFFSET $offset";
    $st = $conn->prepare($sql);
    if ($params) $st->bind_param($types, ...$params);
    $st->execute();
    $list = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $conn->close();
} catch (Exception $e) {
    $db_error = $e->getMessage();
    $list = []; $stores = []; $total = 0; $total_pages = 1;
}
?>

<style>
main { overflow: hidden !important; }
</style>

<div class="flex flex-col h-full gap-3 overflow-hidden">

<!-- 페이지 헤더 -->
<div class="flex items-center justify-between shrink-0">
    <h2 class="text-xl font-bold text-gray-900">Pending Outbound List</h2>
    <a href="<?php echo LC_BASE; ?>/branch_outbound.php"
       class="inline-flex items-center px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium rounded-lg transition-colors">
        <i class="fas fa-plus mr-2"></i>New Outbound
    </a>
</div>

<!-- 검색 폼 -->
<form method="get" class="bg-white rounded-lg border border-gray-200 px-3 py-2 shrink-0">
    <div class="flex flex-wrap items-center gap-2">
        <select name="store_id" class="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500 bg-white w-48">
            <option value="">-- All Stores --</option>
            <?php foreach ($stores as $s): ?>
            <option value="<?php echo (int)$s['id']; ?>" <?php echo $search_store === (int)$s['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($s['name']); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="date" value="<?php echo htmlspecialchars($search_date); ?>"
               class="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
        <button type="submit" class="px-3 py-1.5 bg-teal-600 text-white text-sm rounded-md hover:bg-teal-700">
            <i class="fas fa-search mr-1"></i>Search
        </button>
        <a href="<?php echo LC_BASE; ?>/branch_outbound_list.php" class="px-3 py-1.5 bg-gray-100 text-gray-600 text-sm rounded-md hover:bg-gray-200">Reset</a>
    </div>
</form>

<?php if (isset($db_error)): ?>
<div class="bg-red-50 border border-red-200 rounded-lg p-4 text-red-700 text-sm shrink-0"><?php echo htmlspecialchars($db_error); ?></div>
<?php endif; ?>

<div id="listError" class="hidden bg-red-50 border border-red-200 rounded-lg px-4 py-2 text-red-700 text-sm shrink-0"></div>

<!-- 테이블 카드 -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden flex flex-col flex-1 min-h-0">
    <div class="px-4 py-3 border-b border-gray-100 text-sm text-gray-500 shrink-0">Pending outbound: <strong><?php echo number_format($total); ?></strong></div>
    <div class="overflow-auto flex-1 min-h-0">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 sticky top-0 z-10"><tr>
                <th class="px-4 py-3 text-center text-xs text-gray-500 font-medium">#</th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium">Created</th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium">Store</th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium">Items</th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium">Total Qty</th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium">Est. Amount</th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium">Created By</th>
                <th class="px-4 py-3 text-center text-xs text-gray-500 font-medium">Actions</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php if (empty($list)): ?>
            <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">
                <i class="fas fa-truck text-3xl mb-2 block text-gray-300"></i>
                No pending outbound shipments. Click [New Outbound] to create one.
            </td></tr>
            <?php endif; ?>
            <?php foreach ($list as $row): ?>
            <tr class="hover:bg-gray-50 transition-colors cursor-pointer"
                onclick="location.href='<?php echo LC_BASE; ?>/branch_outbound.php?draft_id=<?php echo $row['id']; ?>'">
                <td class="px-4 py-3 text-center text-gray-500"><?php echo $row['id']; ?></td>
                <td class="px-4 py-3">
                    <div class="text-gray-700"><?php echo date('d M Y', strtotime($row['order_date'])); ?></div>
                    <div class="text-xs text-gray-400 mt-0.5"><?php echo date('H:i', strtotime($row['created_at'])); ?></div>
                </td>
                <td class="px-4 py-3 font-medium text-gray-900"><?php echo htmlspecialchars($row['store_name'] ?? '-'); ?></td>
                <td class="px-4 py-3 text-right text-gray-700"><?php echo number_format($row['item_count']); ?></td>
                <td class="px-4 py-3 text-right text-gray-700"><?php echo number_format($row['total_qty']); ?></td>
                <td class="px-4 py-3 text-right font-semibold text-gray-900"><?php echo number_format($row['total_amount'], 2); ?></td>
                <td class="px-4 py-3 text-gray-500 text-xs"><?php echo htmlspecialchars($row['created_by_name'] ?? '-'); ?></td>
                <td class="px-4 py-3 text-center whitespace-nowrap" onclick="event.stopPropagation()">
                    <a href="<?php echo LC_BASE; ?>/branch_outbound.php?draft_id=<?php echo $row['id']; ?>"
                       class="inline-flex items-center px-2.5 py-1 text-xs font-medium text-teal-700 bg-teal-50 border border-teal-200 rounded-md hover:bg-teal-100 mr-1">
                        <i class="fas fa-pen mr-1"></i>Edit
                    </a>
                    <a href="<?php echo LC_BASE; ?>/print_branch_outbound.php?draft_id=<?php echo $row['id']; ?>" target="_blank"
                       class="inline-flex items-center px-2.5 py-1 text-xs font-medium text-gray-700 bg-gray-50 border border-gray-300 rounded-md hover:bg-gray-100 mr-1">
                        <i class="fas fa-print mr-1"></i>Print
                    </a>
                    <button type="button"
                            onclick="shipDraft(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['store_name'] ?? '-', ENT_QUOTES); ?>', <?php echo (int)$row['item_count']; ?>)"
                            class="inline-flex items-center px-2.5 py-1 text-xs font-medium text-white bg-teal-600 border border-teal-600 rounded-md hover:bg-teal-700 mr-1">
                        <i class="fas fa-truck mr-1"></i>Confirm Shipment
                    </button>
                    <button type="button"
                            onclick="deleteDraft(<?php echo $row['id']; ?>)"
                            class="inline-flex items-center px-2.5 py-1 text-xs font-medium text-red-600 bg-red-50 border border-red-200 rounded-md hover:bg-red-100">
                        <i class="fas fa-trash mr-1"></i>Delete
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- 페이지네이션 -->
    <?php if ($total_pages > 1): ?>
    <?php
        $window   = 10; $half = (int)floor($window / 2);
        $pg_start = max(1, min($page - $half, $total_pages - $window + 1));
        $pg_end   = min($total_pages, $pg_start + $window - 1);
        $qs = ['store_id' => $search_store ?: '', 'date' => $search_date];
    ?>
    <div class="px-4 py-3 border-t border-gray-100 flex items-center justify-center gap-1 shrink-0">
        <?php if ($page > 1): ?>
        <a href="?page=<?php echo $page-1; ?>&<?php echo http_build_query($qs); ?>"
           class="px-4 py-1.5 rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 text-sm font-medium flex items-center gap-1.5"><i class="fas fa-chevron-left text-xs"></i> Prev</a>
        <?php endif; ?>
        <?php for ($i = $pg_start; $i <= $pg_end; $i++): ?>
        <a href="?page=<?php echo $i; ?>&<?php echo http_build_query($qs); ?>"
           class="flex items-center justify-center rounded font-medium transition-colors <?php echo $i === $page ? 'w-9 h-9 bg-teal-600 text-white text-base shadow-md ring-2 ring-teal-300' : 'w-8 h-8 text-sm text-gray-500 hover:bg-gray-100'; ?>">
            <?php echo $i; ?>
        </a>
        <?php endfor; ?>
        <?php if ($page < $total_pages): ?>
        <a href="?page=<?php echo $page+1; ?>&<?php echo http_build_query($qs); ?>"
           class="px-4 py-1.5 rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 text-sm font-medium flex items-center gap-1.5">Next <i class="fas fa-chevron-right text-xs"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

</div><!-- /.flex.flex-col.h-full -->

<script>
(function() {
    var LC_BASE = '<?php echo LC_BASE; ?>';
    var CSRF_TOKEN = '<?php echo htmlspecialchars(lc_csrf_token()); ?>';

    function showError(msg) {
        var el = document.getElementById('listError');
        el.textContent = msg;
        el.classList.remove('hidden');
    }

    // Design §5.1 — 목록에서 최종 출고 (Plan SC-6)
    window.shipDraft = function(draftId, storeName, itemCount) {
        if (!confirm('[' + storeName + '] You are about to ship ' + itemCount + ' item(s).\nStock will be deducted based on current inventory and cannot be undone.\nContinue?')) return;

        var fd = new FormData();
        fd.append('action', 'ship_draft');
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('draft_id', draftId);

        fetch(LC_BASE + '/ajax/branch_outbound.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) { showError(data.message || 'Failed to process the shipment.'); return; }
                window.location.href = LC_BASE + '/order_detail.php?id=' + data.order_id;
            })
            .catch(function() { showError('A network error occurred.'); });
    };

    // Design §5.1 — draft 삭제 (Plan SC-4)
    window.deleteDraft = function(draftId) {
        if (!confirm('Are you sure you want to delete pending outbound #' + draftId + '?')) return;

        var fd = new FormData();
        fd.append('action', 'delete_draft');
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('draft_id', draftId);

        fetch(LC_BASE + '/ajax/branch_outbound.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) { showError(data.message || 'Failed to delete.'); return; }
                location.reload();
            })
            .catch(function() { showError('A network error occurred.'); });
    };
})();
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
