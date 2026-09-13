<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('mall_fresh_products.product_history_title') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/fresh_product_common.php';

if (!has_permission('product_management') && !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
    echo '<div class="bg-red-50 text-red-700 p-4">' . htmlspecialchars(t('messages.permission_denied'), ENT_QUOTES, 'UTF-8') . '</div>';
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

function fhh($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fresh_history_unit_cost(array $row): string
{
    // Use the receipt's stored unit cost, not the latest product reference cost.
    $isPiece = $row['pieces_per_box'] !== null;
    $cost = $isPiece ? $row['unit_cost_per_piece'] : $row['unit_cost_per_100g'];
    $unit = $isPiece ? (in_array($row['unit_type'], ['pcs', 'pack'], true) ? $row['unit_type'] : 'pcs') : 'kg';
    return $cost !== null ? number_format((float)$cost, 2) . ' / ' . $unit : '-';
}

$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$productId = filter_var($_GET['product_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$categoryOptions = fresh_category_options();
$categoryFilter = is_string($_GET['fresh_category'] ?? null) ? $_GET['fresh_category'] : '';
if (!array_key_exists($categoryFilter, $categoryOptions)) {
    $categoryFilter = '';
}
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$like = '%' . $search . '%';
$conn = get_db_connection();
$markupStmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'mall_wholesale_reference_markup_rate'");
$markupStmt->execute();
$markupRow = $markupStmt->get_result()->fetch_assoc();
$markupStmt->close();
$referenceMarkupRate = $markupRow ? (float)$markupRow['setting_value'] : 15.0;

// Keep legacy receipts without a batch, but exclude cancelled batches.
// Do not filter by current product status: inactive products retain their history.
$from = ' FROM fresh_purchase_items i
          JOIN mall_fresh_products p ON p.id = i.mall_fresh_product_id
          LEFT JOIN fresh_purchase_batches b ON b.id = i.batch_id';
$where = ' WHERE (i.batch_id IS NULL OR (b.id IS NOT NULL AND b.deleted_at IS NULL))
             AND (? = \'\' OR p.code LIKE ? OR p.name_ko LIKE ? OR p.name_en LIKE ?)
             AND (? = \'\' OR p.fresh_category = ?)
             AND (? = 0 OR p.id = ?)';
$stmt = $conn->prepare('SELECT COUNT(*) AS cnt' . $from . $where);
$stmt->bind_param('ssssssii', $search, $like, $like, $like, $categoryFilter, $categoryFilter, $productId, $productId);
$stmt->execute();
$totalCount = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = 'SELECT p.id AS product_id, p.code, p.name_ko, p.name_en, p.unit_type,
                   COALESCE(b.purchase_date, i.purchase_date) AS purchase_date,
                   i.quantity_boxes, i.box_cost, i.pieces_per_box, i.total_cost,
                   i.unit_cost_per_100g, i.unit_cost_per_piece,
                   s.name AS store_name, sup.name AS supplier_name' . $from . '
            LEFT JOIN stores s ON s.id = COALESCE(b.store_id, i.store_id)
            LEFT JOIN suppliers sup ON sup.id = COALESCE(b.supplier_id, i.supplier_id)' . $where . '
            ORDER BY COALESCE(b.purchase_date, i.purchase_date) DESC, i.id DESC LIMIT ? OFFSET ?';
$stmt = $conn->prepare($sql);
$stmt->bind_param('ssssssiiii', $search, $like, $like, $like, $categoryFilter, $categoryFilter, $productId, $productId, $perPage, $offset);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();
$baseParams = ['q' => $search, 'fresh_category' => $categoryFilter];
if ($productId > 0) { $baseParams['product_id'] = $productId; }
$columns = ['mall_fresh_products.history_date', 'mall_fresh_products.history_product_name', 'mall_fresh_products.history_purchase_quantity', 'mall_fresh_products.history_purchase_cost', 'mall_fresh_products.line_total_cost_label', 'mall_fresh_products.unit_cost_label', 'mall_fresh_products.history_wholesale_price', 'mall_fresh_products.history_selling_price', 'mall_fresh_products.history_supplier_name', 'mall_fresh_products.history_store_name'];
?>
<div class="w-full px-2 sm:px-3 md:px-4 py-8">
    <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400">
        <div class="px-6 py-4 border-b border-gray-200">
            <h1 class="text-lg font-semibold text-gray-900"><i class="fas fa-history mr-2"></i><?php echo fhh(t('mall_fresh_products.product_history_title')); ?></h1>
            <p class="text-sm text-gray-500 mt-2"><?php echo fhh(t('mall_fresh_products.product_history_description')); ?></p>
            <p class="text-xs text-gray-500 mt-1"><?php echo fhh(t('mall_fresh_products.history_reference_prices_hint')); ?></p>
            <div class="flex flex-wrap items-center gap-3 mt-4">
                <form method="get" class="flex items-center gap-2">
                    <input type="hidden" name="fresh_category" value="<?php echo fhh($categoryFilter); ?>">
                    <input type="search" name="q" value="<?php echo fhh($search); ?>" aria-label="<?php echo fhh(t('mall_fresh_products.search_master_placeholder')); ?>" placeholder="<?php echo fhh(t('mall_fresh_products.search_master_placeholder')); ?>" class="px-3 py-2 border border-gray-300 rounded-md text-sm w-full sm:w-64">
                    <button type="submit" class="px-3 py-2 rounded-md text-sm text-white bg-primary-600 hover:bg-primary-700"><?php echo fhh(t('common.search')); ?></button>
                </form>
                <?php if ($productId > 0): ?>
                    <a href="fresh_product_history.php" class="px-3 py-2 border border-gray-300 rounded-md text-sm text-blue-700 hover:underline"><?php echo fhh(t('mall_fresh_products.history_back')); ?></a>
                <?php endif; ?>
                <div class="flex flex-wrap items-center gap-2">
                    <?php foreach (['' => t('common.all')] + $categoryOptions as $categoryCode => $categoryName): ?>
                        <a href="?<?php echo fhh(http_build_query(['fresh_category' => $categoryCode])); ?>"
                           <?php echo $categoryFilter === $categoryCode ? 'aria-current="true"' : ''; ?>
                           class="px-3 py-2 border rounded-md text-sm whitespace-nowrap <?php echo $categoryFilter === $categoryCode ? 'bg-primary-50 border-primary-500 text-primary-600' : 'border-gray-300 text-gray-600 hover:bg-gray-50'; ?>"><?php echo fhh($categoryName); ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr><?php foreach ($columns as $column): ?><th scope="col" class="px-6 py-2 text-left text-xs font-semibold text-gray-700 whitespace-nowrap"><?php echo fhh(t($column)); ?></th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="<?php echo count($columns); ?>" class="px-6 py-12 text-center text-gray-500"><?php echo fhh(t('mall_fresh_products.no_purchase_history')); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row):
                        $nameKo = trim((string)($row['name_ko'] ?? ''));
                        $nameEn = trim((string)($row['name_en'] ?? ''));
                        // Both reference prices use the same receipt unit cost displayed in this row.
                        $referenceCost = $row['pieces_per_box'] !== null ? $row['unit_cost_per_piece'] : $row['unit_cost_per_100g'];
                        $referenceWholesale = $referenceCost !== null ? ceil((float)$referenceCost * (1 + $referenceMarkupRate / 100)) : null;
                        $referenceSelling = $referenceCost !== null ? ceil((float)$referenceCost * 1.4) : null;
                    ?>
                        <tr class="border-b border-gray-100 hover:bg-gray-50">
                            <td class="px-6 py-2 whitespace-nowrap"><?php echo fhh($row['purchase_date']); ?></td>
                            <td class="px-6 py-2">
                                <a href="?<?php echo fhh(http_build_query(['product_id' => (int)$row['product_id']])); ?>" class="block hover:underline">
                                <div class="font-medium text-gray-900"><?php echo fhh($nameKo !== '' ? $nameKo : ($nameEn !== '' ? $nameEn : '-')); ?></div>
                                <?php if ($nameKo !== '' && $nameEn !== ''): ?>
                                    <div class="text-sm text-gray-600"><?php echo fhh($nameEn); ?></div>
                                <?php endif; ?>
                                <div class="text-xs text-gray-500"><?php echo fhh($row['code']); ?></div>
                                </a>
                            </td>
                            <td class="px-6 py-2 whitespace-nowrap"><?php echo $row['quantity_boxes'] !== null ? fhh(fmt_num($row['quantity_boxes'])) : '-'; ?></td>
                            <td class="px-6 py-2 whitespace-nowrap"><?php echo $row['box_cost'] !== null ? number_format((float)$row['box_cost'], 2) : '-'; ?></td>
                            <td class="px-6 py-2 whitespace-nowrap"><?php echo number_format((float)$row['total_cost'], 2); ?></td>
                            <td class="px-6 py-2 whitespace-nowrap"><?php echo fhh(fresh_history_unit_cost($row)); ?></td>
                            <td class="px-6 py-2 whitespace-nowrap"><?php echo $referenceWholesale !== null ? number_format((float)$referenceWholesale, 2) : '-'; ?></td>
                            <td class="px-6 py-2 whitespace-nowrap"><?php echo $referenceSelling !== null ? number_format((float)$referenceSelling, 2) : '-'; ?></td>
                            <td class="px-6 py-2"><?php echo fhh($row['supplier_name'] ?? '-'); ?></td>
                            <td class="px-6 py-2"><?php echo fhh($row['store_name'] ?? '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <nav class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 px-6 py-4" aria-label="<?php echo fhh(t('mall_fresh_products.product_history_title')); ?>">
            <span class="text-sm text-gray-600"><?php echo $page . ' / ' . $totalPages . ' (' . number_format($totalCount) . ')'; ?></span>
            <?php if ($totalPages > 1): ?>
                <div class="flex gap-1">
                    <?php
                    $pages = array_unique(array_merge([1, max(1, $page - 1)], range(max(1, $page - 2), min($totalPages, $page + 2)), [min($totalPages, $page + 1), $totalPages]));
                    sort($pages);
                    foreach ($pages as $number): ?>
                        <a href="?<?php echo fhh(http_build_query(array_merge($baseParams, ['page' => $number]))); ?>" <?php echo $number === $page ? 'aria-current="page"' : ''; ?> class="px-3 py-1.5 border rounded-md text-sm <?php echo $number === $page ? 'bg-primary-50 border-primary-500 text-primary-600' : 'border-gray-300 text-gray-600 hover:bg-gray-50'; ?>"><?php echo $number; ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </nav>
    </div>
</div>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
