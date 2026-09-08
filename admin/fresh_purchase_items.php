<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('mall_fresh_products.purchase_history_title') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/fresh_product_common.php';

if (!has_permission('product_management') && !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>" . t('messages.permission_denied') . "</p></div></div></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

$conn = get_db_connection();

$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;
$like = '%' . $search . '%';

$countStmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt
     FROM fresh_purchase_batches b
     JOIN stores s ON s.id = b.store_id
     JOIN suppliers sup ON sup.id = b.supplier_id
     WHERE b.deleted_at IS NULL
       AND (? = '' OR sup.name LIKE ? OR s.name LIKE ?)"
);
$countStmt->bind_param('sss', $search, $like, $like);
$countStmt->execute();
$totalCount = (int)($countStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
$countStmt->close();
$totalPages = max(1, (int)ceil($totalCount / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$stmt = $conn->prepare(
    "SELECT b.id, b.purchase_date, b.total_amount, b.total_items,
            s.name AS store_name, sup.name AS supplier_name
     FROM fresh_purchase_batches b
     JOIN stores s ON s.id = b.store_id
     JOIN suppliers sup ON sup.id = b.supplier_id
     WHERE b.deleted_at IS NULL
       AND (? = '' OR sup.name LIKE ? OR s.name LIKE ?)
     ORDER BY b.purchase_date DESC, b.id DESC
     LIMIT ? OFFSET ?"
);
$stmt->bind_param('sssii', $search, $like, $like, $perPage, $offset);
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();
$flash = fresh_admin_take_flash();

function fph($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$searchQuery = $search !== '' ? '&q=' . urlencode($search) : '';
?>
<div class="w-full px-2 sm:px-3 md:px-4 py-8">
    <?php if ($flash): ?>
        <div class="mb-4 p-3 rounded border <?php echo $flash['type'] === 'error' ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700'; ?>"><?php echo fph($flash['message']); ?></div>
    <?php endif; ?>

    <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400">
        <div class="px-6 py-4 border-b border-gray-200 bg-white flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
            <h3 class="text-lg leading-6 font-semibold text-gray-900">
                <i class="fas fa-truck-ramp-box mr-2"></i><?php echo fph(t('mall_fresh_products.purchase_history_title')); ?>
                <span class="text-sm font-normal text-gray-500">(<?php echo number_format($totalCount); ?>)</span>
            </h3>
            <div class="flex items-center gap-3">
                <form method="get" class="flex items-center">
                    <div class="relative flex-1 sm:flex-none">
                        <input type="search" name="q" value="<?php echo fph($search); ?>" placeholder="<?php echo fph(t('mall_fresh_products.batch_search_placeholder')); ?>"
                               class="pl-9 pr-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500 w-full sm:w-64">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                    </div>
                    <button type="submit" class="ml-2 inline-flex items-center px-3 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700"><?php echo fph(t('common.search')); ?></button>
                </form>
                <a href="add_fresh_purchase_item.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 whitespace-nowrap">
                    <i class="fas fa-plus mr-2"></i><?php echo fph(t('mall_fresh_products.new_purchase_button')); ?>
                </a>
                <a href="fresh_products.php" class="text-sm text-gray-600 whitespace-nowrap"><?php echo fph(t('mall_fresh_products.master_management')); ?></a>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo fph(t('mall_fresh_products.purchase_date_label')); ?></th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo fph(t('common.store')); ?></th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo fph(t('purchase.supplier')); ?></th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo fph(t('mall_fresh_products.total_items_count_label')); ?></th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo fph(t('mall_fresh_products.batch_total_cost_label')); ?></th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo fph(t('common.actions')); ?></th>
                    </tr>
                </thead>
                <tbody class="bg-white">
                    <?php if (!$history): ?>
                        <tr><td colspan="6" class="px-6 py-12 text-center text-sm text-gray-500">
                            <i class="fas fa-dolly-flatbed text-4xl text-gray-400 block mb-3"></i>
                            <?php echo fph(t('mall_fresh_products.no_purchase_history')); ?>
                        </td></tr>
                    <?php endif; ?>
                    <?php foreach ($history as $row): ?>
                        <tr class="border-b border-gray-100 hover:bg-gray-50 cursor-pointer transition-colors duration-150" onclick="window.location.href='fresh_purchase_batch_detail.php?id=<?php echo (int)$row['id']; ?>'">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo fph($row['purchase_date']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo fph($row['store_name']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo fph($row['supplier_name']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?php echo number_format((int)$row['total_items']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 text-right font-medium"><?php echo number_format((float)$row['total_amount'], 2); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right" onclick="event.stopPropagation()"><a href="fresh_purchase_batch_detail.php?id=<?php echo (int)$row['id']; ?>" class="text-blue-700 hover:text-blue-900"><?php echo fph(t('purchase.detail_view')); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <nav class="flex items-center justify-between border-t border-gray-200 px-4 sm:px-6 py-4">
            <div class="text-sm text-gray-700"><?php echo $page; ?> / <?php echo $totalPages; ?> (<?php echo number_format($totalCount); ?><?php echo fph(t('common.items')); ?>)</div>
            <div class="flex gap-1">
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                if ($endPage - $startPage < 4) {
                    if ($startPage == 1) { $endPage = min($totalPages, $startPage + 4); }
                    else { $startPage = max(1, $endPage - 4); }
                }
                ?>
                <?php if ($page > 1): ?><a href="?page=<?php echo ($page - 1) . $searchQuery; ?>" class="px-3 py-1.5 border border-gray-300 rounded-md text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <a href="?page=<?php echo $i . $searchQuery; ?>" class="px-3 py-1.5 border rounded-md text-sm <?php echo $i === $page ? 'bg-primary-50 border-primary-500 text-primary-600' : 'border-gray-300 text-gray-600 hover:bg-gray-50'; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?><a href="?page=<?php echo ($page + 1) . $searchQuery; ?>" class="px-3 py-1.5 border border-gray-300 rounded-md text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
            </div>
        </nav>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
