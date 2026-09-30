<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('mall_fresh_products.price_list_title') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/fresh_product_common.php';

if (!has_permission('product_management') && !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
    echo '<div class="bg-red-50 text-red-700 p-4">' . htmlspecialchars(t('messages.permission_denied'), ENT_QUOTES, 'UTF-8') . '</div>';
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

function fpl(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

const FRESH_PRICE_LIST_DAYS = 14;

$today = date('Y-m-d');
$endParam = is_string($_GET['end'] ?? null) ? $_GET['end'] : '';
$endDate = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $endParam) && $endParam <= $today) ? $endParam : $today;
$startDate = date('Y-m-d', strtotime($endDate . ' -' . (FRESH_PRICE_LIST_DAYS - 1) . ' days'));
$prevEnd = date('Y-m-d', strtotime($endDate . ' -' . FRESH_PRICE_LIST_DAYS . ' days'));
$nextEnd = date('Y-m-d', strtotime($endDate . ' +' . FRESH_PRICE_LIST_DAYS . ' days'));
$hasNext = $endDate < $today;
if ($nextEnd > $today) {
    $nextEnd = $today;
}

$dates = [];
for ($i = 0; $i < FRESH_PRICE_LIST_DAYS; $i++) {
    $dates[] = date('Y-m-d', strtotime($startDate . ' +' . $i . ' days'));
}

$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$like = '%' . $search . '%';
$categoryOptions = fresh_category_options();
$categoryFilter = is_string($_GET['fresh_category'] ?? null) ? $_GET['fresh_category'] : '';
if (!array_key_exists($categoryFilter, $categoryOptions)) {
    $categoryFilter = '';
}

$sortDir = (is_string($_GET['sort'] ?? null) && $_GET['sort'] === 'desc') ? 'desc' : 'asc';
$orderSql = $sortDir === 'desc'
    ? 'ORDER BY p.name_ko DESC, p.name_en DESC, p.id DESC'
    : 'ORDER BY p.name_ko, p.name_en, p.id';

$conn = get_db_connection();
// Same receipt rules as the history page: keep legacy receipts without a batch, exclude cancelled batches.
$stmt = $conn->prepare(
    'SELECT p.id AS product_id, p.code, p.name_ko, p.name_en,
            COALESCE(b.purchase_date, i.purchase_date) AS purchase_date,
            AVG(i.box_cost) AS avg_box_cost
     FROM fresh_purchase_items i
     JOIN mall_fresh_products p ON p.id = i.mall_fresh_product_id
     LEFT JOIN fresh_purchase_batches b ON b.id = i.batch_id
     WHERE (i.batch_id IS NULL OR (b.id IS NOT NULL AND b.deleted_at IS NULL))
       AND COALESCE(b.purchase_date, i.purchase_date) BETWEEN ? AND ?
       AND (? = \'\' OR p.code LIKE ? OR p.name_ko LIKE ? OR p.name_en LIKE ?)
       AND (? = \'\' OR p.fresh_category = ?)
     GROUP BY p.id, p.code, p.name_ko, p.name_en, COALESCE(b.purchase_date, i.purchase_date)
     ' . $orderSql
);
$stmt->bind_param('ssssssss', $startDate, $endDate, $search, $like, $like, $like, $categoryFilter, $categoryFilter);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

$products = [];
foreach ($rows as $row) {
    $pid = (int)$row['product_id'];
    if (!isset($products[$pid])) {
        $products[$pid] = ['code' => $row['code'], 'name_ko' => trim((string)$row['name_ko']), 'name_en' => trim((string)$row['name_en']), 'prices' => []];
    }
    if ($row['avg_box_cost'] !== null) {
        $products[$pid]['prices'][$row['purchase_date']] = (float)$row['avg_box_cost'];
    }
}

$filterParams = ['q' => $search, 'fresh_category' => $categoryFilter, 'sort' => $sortDir];
$sortToggleUrl = '?' . http_build_query(['q' => $search, 'fresh_category' => $categoryFilter, 'end' => $endDate, 'sort' => $sortDir === 'asc' ? 'desc' : 'asc']);
?>
<style>
/* 행 hover 시 고정된 상품명 셀(sticky)과 오늘 열 배경까지 함께 강조 */
.fpl-name { background-color: #fff; }
/* 세로(열) 강조 — 행 강조보다 약한 색, 교차 셀은 행 색이 우선 */
.fpl-table td.fpl-col-hl, .fpl-table th.fpl-col-hl { background-color: #fef08a !important; }
.fpl-row:hover td, .fpl-row:hover td.fpl-name { background-color: #fde047 !important; }
</style>
<div class="w-full px-2 sm:px-3 md:px-4 py-8">
    <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400">
        <div class="px-6 py-4 border-b border-gray-200">
            <h1 class="text-lg font-semibold text-gray-900"><i class="fas fa-tags mr-2"></i><?php echo fpl(t('mall_fresh_products.price_list_title')); ?></h1>
            <p class="text-sm text-gray-500 mt-2"><?php echo fpl(t('mall_fresh_products.price_list_description')); ?></p>
            <div class="flex flex-wrap items-center gap-3 mt-4">
                <form method="get" class="flex items-center gap-2">
                    <input type="hidden" name="fresh_category" value="<?php echo fpl($categoryFilter); ?>">
                    <input type="hidden" name="end" value="<?php echo fpl($endDate); ?>">
                    <input type="hidden" name="sort" value="<?php echo fpl($sortDir); ?>">
                    <input type="search" name="q" value="<?php echo fpl($search); ?>" aria-label="<?php echo fpl(t('mall_fresh_products.search_master_placeholder')); ?>" placeholder="<?php echo fpl(t('mall_fresh_products.search_master_placeholder')); ?>" class="px-3 py-2 border border-gray-300 rounded-md text-sm w-full sm:w-64">
                    <button type="submit" class="px-3 py-2 rounded-md text-sm text-white bg-primary-600 hover:bg-primary-700"><?php echo fpl(t('common.search')); ?></button>
                </form>
                <div class="flex flex-wrap items-center gap-2">
                    <?php foreach (['' => t('common.all')] + $categoryOptions as $categoryCode => $categoryName): ?>
                        <a href="?<?php echo fpl(http_build_query(['fresh_category' => $categoryCode, 'q' => $search, 'end' => $endDate, 'sort' => $sortDir])); ?>"
                           <?php echo $categoryFilter === $categoryCode ? 'aria-current="true"' : ''; ?>
                           class="px-3 py-2 border rounded-md text-sm whitespace-nowrap <?php echo $categoryFilter === $categoryCode ? 'bg-primary-50 border-primary-500 text-primary-600' : 'border-gray-300 text-gray-600 hover:bg-gray-50'; ?>"><?php echo fpl($categoryName); ?></a>
                    <?php endforeach; ?>
                </div>
                <div class="flex items-center gap-2 ml-auto">
                    <a href="?<?php echo fpl(http_build_query($filterParams + ['end' => $prevEnd])); ?>" class="px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-600 hover:bg-gray-50"><i class="fas fa-chevron-left mr-1"></i><?php echo fpl(t('mall_fresh_products.price_list_prev')); ?></a>
                    <span class="text-sm text-gray-600 whitespace-nowrap"><?php echo fpl($startDate . ' ~ ' . $endDate); ?></span>
                    <?php if ($hasNext): ?>
                        <a href="?<?php echo fpl(http_build_query($filterParams + ['end' => $nextEnd])); ?>" class="px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-600 hover:bg-gray-50"><?php echo fpl(t('mall_fresh_products.price_list_next')); ?><i class="fas fa-chevron-right ml-1"></i></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm fpl-table">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th scope="col" class="px-4 py-2 text-left text-xs font-semibold text-gray-700 whitespace-nowrap sticky left-0 bg-gray-50"><a href="<?php echo fpl($sortToggleUrl); ?>" class="hover:underline"><?php echo fpl(t('mall_fresh_products.price_list_product')); ?><i class="fas <?php echo $sortDir === 'asc' ? 'fa-arrow-up-a-z' : 'fa-arrow-down-z-a'; ?> ml-1"></i></a></th>
                        <?php foreach ($dates as $d): ?>
                            <th scope="col" class="px-3 py-2 text-right text-xs font-semibold whitespace-nowrap <?php echo $d === $today ? 'text-primary-600' : 'text-gray-700'; ?>"><?php echo fpl(date('m/d', strtotime($d))); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$products): ?>
                        <tr><td colspan="<?php echo count($dates) + 1; ?>" class="px-6 py-12 text-center text-gray-500"><?php echo fpl(t('mall_fresh_products.no_purchase_history')); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($products as $pid => $product): ?>
                        <tr class="fpl-row border-b border-gray-100">
                            <td class="px-4 py-2 whitespace-nowrap sticky left-0 fpl-name">
                                <a href="fresh_product_history.php?product_id=<?php echo (int)$pid; ?>" class="block hover:underline">
                                    <div class="font-medium text-gray-900"><?php echo fpl($product['name_ko'] !== '' ? $product['name_ko'] : ($product['name_en'] !== '' ? $product['name_en'] : '-')); ?></div>
                                    <?php if ($product['name_ko'] !== '' && $product['name_en'] !== ''): ?>
                                        <div class="text-xs text-gray-500"><?php echo fpl($product['name_en']); ?></div>
                                    <?php endif; ?>
                                </a>
                            </td>
                            <?php foreach ($dates as $d): ?>
                                <td class="px-3 py-2 text-right font-mono whitespace-nowrap <?php echo $d === $today ? 'bg-primary-50' : ''; ?>"><?php echo isset($product['prices'][$d]) ? number_format($product['prices'][$d], 2) : ''; ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
(function () {
    var table = document.querySelector('.fpl-table');
    if (!table) return;
    var lit = [];
    function clear() { lit.forEach(function (el) { el.classList.remove('fpl-col-hl'); }); lit = []; }
    table.addEventListener('mouseover', function (e) {
        var cell = e.target.closest('td, th');
        if (!cell || !table.contains(cell)) return;
        var idx = cell.cellIndex;
        clear();
        if (idx < 1) return; // 상품명 열은 행 강조만
        table.querySelectorAll('tr').forEach(function (tr) {
            var c = tr.cells[idx];
            if (c) { c.classList.add('fpl-col-hl'); lit.push(c); }
        });
    });
    table.addEventListener('mouseleave', clear);
})();
</script>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
