<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('mall_fresh_products.batch_detail_title') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/fresh_product_common.php';

if (!has_permission('product_management') && !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>" . t('messages.permission_denied') . "</p></div></div></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

$conn = get_db_connection();
$batchId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    if (!has_permission('product_management') && !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
        echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>" . t('messages.permission_denied') . "</p></div></div></div>";
        require_once __DIR__ . '/partials/footer.php';
        exit;
    }

    $checkStmt = $conn->prepare('SELECT id FROM fresh_purchase_batches WHERE id = ? AND deleted_at IS NULL');
    $checkStmt->bind_param('i', $batchId);
    $checkStmt->execute();
    $batchExists = (bool)$checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if ($batchId <= 0 || !$batchExists) {
        $conn->close();
        fresh_admin_flash('error', t('mall_fresh_products.batch_not_found'));
        fresh_admin_redirect('fresh_purchase_items.php');
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $cancelStmt = $conn->prepare(
        'UPDATE fresh_purchase_batches
         SET deleted_at = NOW(), deleted_by_user_id = ?
         WHERE id = ? AND deleted_at IS NULL'
    );
    $cancelStmt->bind_param('ii', $userId, $batchId);
    $cancelStmt->execute();
    $cancelled = $cancelStmt->affected_rows === 1;
    $cancelStmt->close();
    $conn->close();

    if (!$cancelled) {
        fresh_admin_flash('error', t('mall_fresh_products.batch_not_found'));
        fresh_admin_redirect('fresh_purchase_items.php');
    }

    fresh_admin_flash('success', t('mall_fresh_products.batch_cancel_success'));
    fresh_admin_redirect('fresh_purchase_items.php');
}

if ($batchId <= 0) {
    $conn->close();
    fresh_admin_flash('error', t('mall_fresh_products.batch_not_found'));
    fresh_admin_redirect('fresh_purchase_items.php');
}

$batchStmt = $conn->prepare(
    'SELECT b.id, b.purchase_date, b.total_amount, b.total_items,
            s.name AS store_name, sup.name AS supplier_name
     FROM fresh_purchase_batches b
     JOIN stores s ON s.id = b.store_id
     JOIN suppliers sup ON sup.id = b.supplier_id
     WHERE b.id = ? AND b.deleted_at IS NULL'
);
$batchStmt->bind_param('i', $batchId);
$batchStmt->execute();
$batch = $batchStmt->get_result()->fetch_assoc();
$batchStmt->close();

if (!$batch) {
    $conn->close();
    fresh_admin_flash('error', t('mall_fresh_products.batch_not_found'));
    fresh_admin_redirect('fresh_purchase_items.php');
}

$itemsStmt = $conn->prepare(
    'SELECT fpi.quantity_boxes, fpi.box_cost, fpi.weight_kg, fpi.pieces_per_box,
            fpi.unit_cost_per_100g, fpi.unit_cost_per_piece, fpi.total_cost,
            fp.code AS fresh_code, fp.name_ko AS fresh_name_ko
     FROM fresh_purchase_items fpi
     JOIN mall_fresh_products fp ON fp.id = fpi.mall_fresh_product_id
     WHERE fpi.batch_id = ?
     ORDER BY fpi.sort_order ASC, fpi.id ASC'
);
$itemsStmt->bind_param('i', $batchId);
$itemsStmt->execute();
$items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$itemsStmt->close();
$conn->close();

function fpbdh($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<div class="w-full px-2 sm:px-3 md:px-4 py-8">
    <section class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400 mb-6">
        <div class="px-6 py-4 border-b border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold text-gray-900"><i class="fas fa-file-invoice mr-2"></i><?php echo fpbdh(t('mall_fresh_products.batch_detail_title')); ?></h1>
                <p class="mt-1 text-sm text-gray-500"><?php echo fpbdh(t('mall_fresh_products.batch_id_label')); ?>: #<?php echo (int)$batch['id']; ?></p>
            </div>
            <form method="post" onsubmit="return confirm('<?php echo fpbdh(t('mall_fresh_products.batch_cancel_confirm')); ?>');">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="id" value="<?php echo (int)$batch['id']; ?>">
                <button type="submit" class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-red-600 hover:bg-red-700 rounded-md">
                    <i class="fas fa-ban mr-2"></i><?php echo fpbdh(t('mall_fresh_products.batch_cancel_button')); ?>
                </button>
            </form>
        </div>
        <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 px-6 py-5">
            <div><dt class="text-xs font-semibold text-gray-500"><?php echo fpbdh(t('mall_fresh_products.purchase_date_label')); ?></dt><dd class="mt-1 text-sm text-gray-900"><?php echo fpbdh($batch['purchase_date']); ?></dd></div>
            <div><dt class="text-xs font-semibold text-gray-500"><?php echo fpbdh(t('common.store')); ?></dt><dd class="mt-1 text-sm text-gray-900"><?php echo fpbdh($batch['store_name']); ?></dd></div>
            <div><dt class="text-xs font-semibold text-gray-500"><?php echo fpbdh(t('purchase.supplier')); ?></dt><dd class="mt-1 text-sm text-gray-900"><?php echo fpbdh($batch['supplier_name']); ?></dd></div>
            <div><dt class="text-xs font-semibold text-gray-500"><?php echo fpbdh(t('mall_fresh_products.total_items_count_label')); ?></dt><dd class="mt-1 text-sm text-gray-900"><?php echo number_format((int)$batch['total_items']); ?></dd></div>
            <div><dt class="text-xs font-semibold text-gray-500"><?php echo fpbdh(t('mall_fresh_products.batch_total_cost_label')); ?></dt><dd class="mt-1 text-sm font-semibold text-gray-900"><?php echo number_format((float)$batch['total_amount'], 2); ?></dd></div>
        </dl>
    </section>

    <section class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400">
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50 border-b border-gray-200"><tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700"><?php echo fpbdh(t('mall_fresh_products.fresh_product_label')); ?></th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-700"><?php echo fpbdh(t('mall_fresh_products.quantity_boxes_label')); ?></th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-700"><?php echo fpbdh(t('mall_fresh_products.total_cost_label')); ?></th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-700"><?php echo fpbdh(t('mall_fresh_products.composition_label')); ?></th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-700"><?php echo fpbdh(t('mall_fresh_products.unit_cost_label')); ?></th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-700"><?php echo fpbdh(t('mall_fresh_products.line_total_cost_label')); ?></th>
                </tr></thead>
                <tbody class="bg-white">
                    <?php if (!$items): ?><tr><td colspan="6" class="px-4 py-10 text-center text-sm text-gray-500"><?php echo fpbdh(t('mall_fresh_products.no_purchase_history')); ?></td></tr><?php endif; ?>
                    <?php foreach ($items as $item): $display = fresh_purchase_row_display($item); ?>
                        <tr class="border-b border-gray-100 hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm"><div class="font-medium text-gray-900"><?php echo fpbdh($item['fresh_code']); ?></div><div class="text-xs text-gray-500"><?php echo fpbdh($item['fresh_name_ko']); ?></div></td>
                            <td class="px-4 py-3 text-sm text-gray-500 text-right"><?php echo number_format((int)$item['quantity_boxes']); ?></td>
                            <td class="px-4 py-3 text-sm text-gray-500 text-right"><?php echo number_format((float)$item['box_cost'], 2); ?></td>
                            <td class="px-4 py-3 text-sm text-gray-500 text-right"><?php echo fpbdh($display['composition']); ?></td>
                            <td class="px-4 py-3 text-sm text-gray-500 text-right"><?php echo fpbdh($display['unit_cost']); ?></td>
                            <td class="px-4 py-3 text-sm font-medium text-gray-900 text-right"><?php echo number_format((float)$item['total_cost'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="mt-4"><a href="fresh_purchase_items.php" class="text-sm text-gray-600 hover:text-gray-900"><i class="fas fa-arrow-left mr-1"></i><?php echo fpbdh(t('common.back')); ?></a></div>
</div>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
