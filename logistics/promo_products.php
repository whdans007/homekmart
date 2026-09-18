<?php
require_once __DIR__ . '/lib/auth.php';
$page_title = t('logistics.promo_products.page_title');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/config/db.php';

lc_require_staff();

$promotions = [];
$db_error = null;

try {
    $conn = get_lc_db();
    $sql = "SELECT p.id AS promotion_id,
                   pr.name_en,
                   pr.name_ko,
                   c.name_en AS category_name_en,
                   c.name_ko AS category_name_ko,
                   COALESCE(pr.barcode_unit, pr.barcode_box, pr.barcode_logistics) AS barcode,
                   i.lot_number,
                   i.expiry_date,
                   DATEDIFF(i.expiry_date, CURDATE()) AS days_left,
                   i.quantity_remain,
                   p.base_price,
                   p.discount_rate,
                   p.discounted_price,
                   p.registered_at,
                   COALESCE(u.full_name, u.username) AS registered_by_name
            FROM lc_lot_promotions p
            JOIN lc_inventory i ON p.inventory_id = i.id
            JOIN lc_products pr ON p.product_id = pr.id
            LEFT JOIN lc_categories c ON pr.category_id = c.id
            LEFT JOIN users u ON p.registered_by = u.id
            WHERE p.status = 'active' AND i.quantity_remain > 0
            ORDER BY i.expiry_date ASC, pr.name_en ASC, p.id ASC";
    $promotions = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    $conn->close();
} catch (Exception $e) {
    $db_error = $e->getMessage();
}

$csrf_token = lc_csrf_token();
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars(t('logistics.promo_products.title')); ?></h2>
    </div>
</div>

<?php if ($db_error !== null): ?>
<div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
    <?php echo htmlspecialchars(t('logistics.promo_products.db_error')); ?>
</div>
<?php endif; ?>

<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500"><?php echo htmlspecialchars(t('logistics.promo_products.product_name')); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500"><?php echo htmlspecialchars(t('logistics.promo_products.category_barcode')); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500"><?php echo htmlspecialchars(t('logistics.promo_products.lot_number')); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500"><?php echo htmlspecialchars(t('logistics.promo_products.expiry_date')); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500"><?php echo htmlspecialchars(t('logistics.promo_products.d_day')); ?></th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500"><?php echo htmlspecialchars(t('logistics.promo_products.remaining_quantity')); ?></th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500"><?php echo htmlspecialchars(t('logistics.promo_products.base_price')); ?></th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500"><?php echo htmlspecialchars(t('logistics.promo_products.discount_rate')); ?></th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500"><?php echo htmlspecialchars(t('logistics.promo_products.discounted_price')); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500"><?php echo htmlspecialchars(t('logistics.promo_products.registered_at')); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500"><?php echo htmlspecialchars(t('logistics.promo_products.registered_by')); ?></th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500"><?php echo htmlspecialchars(t('logistics.promo_products.cancel')); ?></th>
                </tr>
            </thead>
            <tbody id="promoTableBody" class="divide-y divide-gray-100">
            <?php if (empty($promotions)): ?>
                <tr id="promoEmptyRow">
                    <td colspan="12" class="px-4 py-12 text-center text-sm text-gray-400">
                        <?php echo htmlspecialchars(t('logistics.promo_products.empty')); ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($promotions as $row): ?>
                <tr id="promo-row-<?php echo (int)$row['promotion_id']; ?>" class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-sm font-medium text-gray-900">
                        <?php echo htmlspecialchars($row['name_en'] . (!empty($row['name_ko']) ? ' (' . $row['name_ko'] . ')' : '')); ?>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-600">
                        <div><?php echo htmlspecialchars($row['category_name_en'] . (!empty($row['category_name_ko']) ? ' (' . $row['category_name_ko'] . ')' : '')); ?></div>
                        <div class="font-mono text-gray-400"><?php echo htmlspecialchars($row['barcode'] ?: '-'); ?></div>
                    </td>
                    <td class="px-4 py-3 text-xs font-mono text-gray-600"><?php echo htmlspecialchars($row['lot_number'] ?: '-'); ?></td>
                    <td class="px-4 py-3 text-sm text-gray-700"><?php echo htmlspecialchars($row['expiry_date'] ?: '-'); ?></td>
                    <td class="px-4 py-3 text-sm font-semibold <?php echo (int)$row['days_left'] <= 30 ? 'text-red-600' : 'text-orange-600'; ?>">
                        <?php echo htmlspecialchars(t('logistics.promo_products.d_day_value', ['days' => (int)$row['days_left']])); ?>
                    </td>
                    <td class="px-4 py-3 text-right text-sm text-gray-700"><?php echo number_format((float)$row['quantity_remain'], 2); ?></td>
                    <td class="px-4 py-3 text-right text-sm text-gray-400" style="text-decoration:line-through;"><?php echo number_format((float)$row['base_price'], 2); ?></td>
                    <td class="px-4 py-3 text-right text-sm text-pink-700"><?php echo number_format((float)$row['discount_rate'], 2); ?>%</td>
                    <td class="px-4 py-3 text-right text-sm font-semibold text-pink-700"><?php echo number_format((float)$row['discounted_price'], 2); ?></td>
                    <td class="px-4 py-3 text-xs text-gray-600"><?php echo htmlspecialchars($row['registered_at']); ?></td>
                    <td class="px-4 py-3 text-xs text-gray-600"><?php echo htmlspecialchars($row['registered_by_name'] ?: '-'); ?></td>
                    <td class="px-4 py-3 text-right">
                        <button type="button" class="rounded border border-red-200 px-2 py-1 text-xs text-red-600 hover:bg-red-50" onclick="cancelPromotion(<?php echo (int)$row['promotion_id']; ?>)">
                            <?php echo htmlspecialchars(t('logistics.promo_products.cancel')); ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="promoError" class="hidden mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"></div>

<script>
async function cancelPromotion(promotionId) {
    if (!confirm(<?php echo json_encode(t('logistics.promo_products.cancel_confirm')); ?>)) return;

    const formData = new FormData();
    formData.append('csrf_token', <?php echo json_encode($csrf_token); ?>);
    formData.append('promotion_id', String(promotionId));
    const errorEl = document.getElementById('promoError');
    errorEl.classList.add('hidden');

    try {
        const response = await fetch('ajax/promo_cancel.php', { method: 'POST', body: formData });
        const result = await response.json();
        if (result.success) {
            const row = document.getElementById('promo-row-' + promotionId);
            if (row) row.remove();
            if (!document.querySelector('#promoTableBody tr')) {
                document.getElementById('promoTableBody').innerHTML = '<tr><td colspan="12" class="px-4 py-12 text-center text-sm text-gray-400">' + <?php echo json_encode(t('logistics.promo_products.empty')); ?> + '</td></tr>';
            }
        } else {
            errorEl.textContent = result.message || <?php echo json_encode(t('logistics.promo_products.cancel_failed')); ?>;
            errorEl.classList.remove('hidden');
        }
    } catch (error) {
        errorEl.textContent = <?php echo json_encode(t('logistics.promo_products.network_error')); ?>;
        errorEl.classList.remove('hidden');
    }
}
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
