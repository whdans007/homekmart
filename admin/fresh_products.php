<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('mall_fresh_products.master_management') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/fresh_product_common.php';

if (!has_permission('product_management') && !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>" . t('messages.permission_denied') . "</p></div></div></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

$conn = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    try {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('삭제할 신선상품을 선택해 주세요.');
        }
        $stmt = $conn->prepare('DELETE FROM mall_fresh_products WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            throw new InvalidArgumentException('삭제할 신선상품을 찾을 수 없습니다.');
        }
        $stmt->close();
        fresh_admin_flash('success', t('mall_fresh_products.delete_success'));
    } catch (mysqli_sql_exception $e) {
        error_log('fresh_products.php delete error: ' . $e->getMessage());
        fresh_admin_flash('error', t('mall_fresh_products.save_failed'));
    } catch (InvalidArgumentException $e) {
        fresh_admin_flash('error', $e->getMessage());
    }
    $conn->close();
    fresh_admin_redirect('fresh_products.php');
}

$search = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';
if (!in_array($statusFilter, ['', 'active', 'inactive'], true)) {
    $statusFilter = '';
}
$categoryOptions = fresh_category_options();
$categoryFilter = $_GET['fresh_category'] ?? '';
if (!array_key_exists($categoryFilter, $categoryOptions)) {
    $categoryFilter = '';
}
$like = '%' . $search . '%';
$stmt = $conn->prepare(
    "SELECT fp.id, fp.code, fp.name_ko, fp.name_en, fp.fresh_category, fp.sale_type, fp.unit_step_g,
            fp.pkg_weight_kg, fp.pkg_pieces_per_box, fp.price_per_100g, fp.box_sale_price,
            fp.status,
            (SELECT fpi.total_cost FROM fresh_purchase_items fpi
             WHERE fpi.mall_fresh_product_id = fp.id
             ORDER BY fpi.purchase_date DESC, fpi.id DESC LIMIT 1) AS latest_box_cost,
            (SELECT fpi.unit_cost_per_100g FROM fresh_purchase_items fpi
             WHERE fpi.mall_fresh_product_id = fp.id
             ORDER BY fpi.purchase_date DESC, fpi.id DESC LIMIT 1) AS latest_unit_cost_100g,
            (SELECT fpi.unit_cost_per_piece FROM fresh_purchase_items fpi
             WHERE fpi.mall_fresh_product_id = fp.id
             ORDER BY fpi.purchase_date DESC, fpi.id DESC LIMIT 1) AS latest_unit_cost_piece
     FROM mall_fresh_products fp
     WHERE (? = '' OR fp.code LIKE ? OR fp.name_ko LIKE ? OR COALESCE(fp.name_en, '') LIKE ?)
       AND (? = '' OR fp.status = ?)
       AND (? = '' OR fp.fresh_category = ?)
     ORDER BY fp.id DESC"
);
$stmt->bind_param('ssssssss', $search, $like, $like, $like, $statusFilter, $statusFilter, $categoryFilter, $categoryFilter);
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();
$flash = fresh_admin_take_flash();

function fresh_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function fresh_category_filter_url(string $category, string $search, string $statusFilter): string
{
    $params = [];
    if ($category !== '') { $params['fresh_category'] = $category; }
    if ($search !== '') { $params['q'] = $search; }
    if ($statusFilter !== '') { $params['status'] = $statusFilter; }
    return $params ? '?' . http_build_query($params) : '?';
}
?>
<div class="w-full px-2 sm:px-3 md:px-4 py-8">
    <div class="flex items-center justify-between gap-3 mb-4">
        <h1 class="text-lg font-bold text-gray-800"><i class="fas fa-apple-whole mr-2"></i><?php echo fresh_h(t('mall_fresh_products.master_management')); ?></h1>
        <div class="flex gap-2">
            <a href="add_fresh_purchase_item.php" class="px-3 py-2 text-xs font-semibold bg-amber-600 text-white rounded-md"><i class="fas fa-truck-ramp-box mr-1"></i><?php echo fresh_h(t('mall_fresh_products.purchase_link_title')); ?></a>
            <a href="add_fresh_product.php" class="px-3 py-2 text-xs font-semibold bg-blue-600 text-white rounded-md"><i class="fas fa-plus mr-1"></i><?php echo fresh_h(t('mall_fresh_products.register')); ?></a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="mb-4 px-4 py-3 text-sm rounded-md border <?php echo $flash['type'] === 'error' ? 'bg-red-50 text-red-700 border-red-200' : 'bg-green-50 text-green-700 border-green-200'; ?>"><?php echo fresh_h($flash['message']); ?></div>
    <?php endif; ?>

    <section class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <form method="get" class="p-4 border-b border-gray-200 flex gap-2 flex-wrap items-center">
            <input type="hidden" name="fresh_category" value="<?php echo fresh_h($categoryFilter); ?>">
            <div class="flex gap-2 flex-wrap items-center">
                <a href="<?php echo fresh_h(fresh_category_filter_url('', $search, $statusFilter)); ?>"
                   class="px-3 py-2 rounded-md text-sm font-medium <?php echo $categoryFilter === '' ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>"><?php echo fresh_h(t('mall_fresh_products.all_categories')); ?></a>
                <?php foreach ($categoryOptions as $code => $label): ?>
                    <a href="<?php echo fresh_h(fresh_category_filter_url($code, $search, $statusFilter)); ?>"
                       class="px-3 py-2 rounded-md text-sm font-medium <?php echo $categoryFilter === $code ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>"><?php echo fresh_h($label); ?></a>
                <?php endforeach; ?>
            </div>
            <input name="q" value="<?php echo fresh_h($search); ?>" placeholder="<?php echo fresh_h(t('mall_fresh_products.search_placeholder')); ?>" class="border border-gray-300 rounded-md px-3 py-2 text-sm min-w-64">
            <select name="status" class="border border-gray-300 rounded-md px-3 py-2 text-sm"><option value=""><?php echo fresh_h(t('mall_fresh_products.all_status')); ?></option><option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>><?php echo fresh_h(t('common.active')); ?></option><option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>><?php echo fresh_h(t('common.inactive')); ?></option></select>
            <button class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 rounded-md"><i class="fas fa-search mr-1"></i><?php echo fresh_h(t('common.search')); ?></button>
        </form>
        <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-gray-100 text-gray-600"><tr>
            <th class="px-4 py-3 text-left"><?php echo fresh_h(t('mall_fresh_products.code')); ?></th>
            <th class="px-4 py-3 text-left"><?php echo fresh_h(t('product.name')); ?></th>
            <th class="px-4 py-3 text-left"><?php echo fresh_h(t('mall_fresh_products.box_composition_label')); ?></th>
            <th class="px-4 py-3 text-left"><?php echo fresh_h(t('mall_fresh_products.category_label')); ?></th>
            <th class="px-4 py-3 text-center"><?php echo fresh_h(t('mall_fresh_products.sale_type_label')); ?></th>
            <th class="px-4 py-3 text-right"><?php echo fresh_h(t('mall_fresh_products.total_cost_label')); ?></th>
            <th class="px-4 py-3 text-right"><?php echo fresh_h(t('mall_fresh_products.unit_cost_label')); ?></th>
            <th class="px-4 py-3 text-right"><?php echo fresh_h(t('mall_fresh_products.box_sale_price_label')); ?></th>
            <th class="px-4 py-3 text-right"><?php echo fresh_h(t('mall_fresh_products.price_per_100g')); ?></th>
            <th class="px-4 py-3 text-center"><?php echo fresh_h(t('common.status')); ?></th>
            <th class="px-4 py-3 text-right"><?php echo fresh_h(t('common.actions')); ?></th>
        </tr></thead><tbody>
        <?php if (!$products): ?><tr><td colspan="11" class="px-4 py-10 text-center text-gray-500"><?php echo fresh_h(t('mall_fresh_products.no_products')); ?></td></tr><?php endif; ?>
        <?php foreach ($products as $product):
            $unitCostValue = $product['sale_type'] === 'piece' ? $product['latest_unit_cost_piece'] : $product['latest_unit_cost_100g'];
            $unitCostSuffix = $product['sale_type'] === 'piece' ? '' : '100g';
        ?><tr class="border-t border-gray-100"><td class="px-4 py-3 font-mono text-xs"><?php echo fresh_h($product['code']); ?></td><td class="px-4 py-3"><div class="font-semibold"><?php echo fresh_h($product['name_ko']); ?></div><div class="text-xs text-gray-500"><?php echo fresh_h($product['name_en']); ?></div></td><td class="px-4 py-3"><?php echo $product['sale_type'] === 'weight' && $product['pkg_weight_kg'] !== null ? number_format((float)$product['pkg_weight_kg'], 3) . 'kg' : ($product['sale_type'] === 'piece' && $product['pkg_pieces_per_box'] !== null ? number_format((int)$product['pkg_pieces_per_box']) . ' / box' : '-'); ?></td><td class="px-4 py-3"><?php echo fresh_h(fresh_category_label($product['fresh_category'])); ?></td><td class="px-4 py-3 text-center"><span class="px-2 py-1 rounded-full text-xs <?php echo $product['sale_type'] === 'piece' ? 'bg-purple-100 text-purple-700' : 'bg-sky-100 text-sky-700'; ?>"><?php echo $product['sale_type'] === 'piece' ? fresh_h(t('mall_fresh_products.sale_type_piece')) : fresh_h(t('mall_fresh_products.sale_type_weight')); ?></span></td><td class="px-4 py-3 text-right text-gray-500"><?php echo $product['latest_box_cost'] !== null ? number_format((float)$product['latest_box_cost'], 2) : '-'; ?></td><td class="px-4 py-3 text-right text-gray-500"><?php echo $unitCostValue !== null ? number_format((float)$unitCostValue, 2) . ($unitCostSuffix !== '' ? ' / ' . $unitCostSuffix : '') : '-'; ?></td><td class="px-4 py-3 text-right text-gray-700"><?php echo $product['box_sale_price'] !== null ? number_format((float)$product['box_sale_price'], 2) : '-'; ?></td><td class="px-4 py-3 text-right text-gray-700"><?php echo $product['price_per_100g'] !== null && (float)$product['price_per_100g'] > 0 ? number_format((float)$product['price_per_100g'], 2) : '-'; ?></td><td class="px-4 py-3 text-center"><span class="px-2 py-1 rounded-full text-xs <?php echo $product['status'] === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600'; ?>"><?php echo $product['status'] === 'active' ? fresh_h(t('common.active')) : fresh_h(t('common.inactive')); ?></span></td><td class="px-4 py-3"><div class="flex justify-end gap-3"><a class="text-blue-700" href="edit_fresh_product.php?id=<?php echo (int)$product['id']; ?>"><?php echo fresh_h(t('common.edit')); ?></a><form method="post" onsubmit="return confirm('<?php echo fresh_h(t('mall_fresh_products.delete_confirm')); ?>');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$product['id']; ?>"><button class="text-red-600"><?php echo fresh_h(t('common.delete')); ?></button></form></div></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
</div>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
