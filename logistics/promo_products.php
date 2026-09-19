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
$promo_manual_labels = get_language() === 'ko' ? [
    'register_manual' => '프로모션 수기 등록', 'close' => '닫기', 'search_product' => '상품 또는 LOT 검색',
    'search_placeholder' => '상품명, 바코드 또는 LOT 번호로 검색', 'change_product' => '변경', 'save' => '저장',
    'searching' => '검색 중...', 'search_failed' => '상품 검색에 실패했습니다.', 'no_search_results' => '등록 가능한 재고가 없습니다.',
    'invalid_rate' => '할인율을 0.01%에서 99.99% 사이로 입력해 주세요.', 'save_failed' => '프로모션 등록에 실패했습니다.'
] : [
    'register_manual' => 'Register Promotion', 'close' => 'Close', 'search_product' => 'Search Product or LOT',
    'search_placeholder' => 'Search by product name, barcode, or LOT number', 'change_product' => 'Change', 'save' => 'Save',
    'searching' => 'Searching...', 'search_failed' => 'Product search failed.', 'no_search_results' => 'No available inventory found.',
    'invalid_rate' => 'Enter a discount rate between 0.01% and 99.99%.', 'save_failed' => 'Failed to register promotion.'
];
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars(t('logistics.promo_products.title')); ?></h2>
    </div>
    <button type="button" onclick="openPromoRegisterModal()"
            class="inline-flex items-center gap-2 rounded-lg bg-pink-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-pink-700">
        <i class="fas fa-plus"></i>
        <?php echo htmlspecialchars($promo_manual_labels['register_manual']); ?>
    </button>
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

<div id="promoRegisterModal" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-hidden="true">
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="fixed inset-0 bg-gray-900/50" onclick="closePromoRegisterModal()"></div>
        <div class="relative flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
                <h3 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($promo_manual_labels['register_manual']); ?></h3>
                <button type="button" onclick="closePromoRegisterModal()" class="text-gray-400 hover:text-gray-700" aria-label="<?php echo htmlspecialchars($promo_manual_labels['close']); ?>">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="overflow-y-auto px-6 py-5">
                <label for="promoProductSearch" class="mb-2 block text-sm font-medium text-gray-700">
                    <?php echo htmlspecialchars($promo_manual_labels['search_product']); ?>
                </label>
                <input id="promoProductSearch" type="search" autocomplete="off"
                       placeholder="<?php echo htmlspecialchars($promo_manual_labels['search_placeholder']); ?>"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-pink-500 focus:outline-none focus:ring-2 focus:ring-pink-200">
                <div id="promoSearchStatus" class="mt-2 text-xs text-gray-400"></div>
                <div id="promoSearchResults" class="mt-3 max-h-72 overflow-y-auto rounded-lg border border-gray-200"></div>

                <div id="promoSelectedProduct" class="mt-5 hidden rounded-lg border border-pink-200 bg-pink-50 p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div id="promoSelectedName" class="font-semibold text-gray-900"></div>
                            <div id="promoSelectedLot" class="mt-1 text-xs text-gray-600"></div>
                        </div>
                        <button type="button" onclick="clearPromoSelection()" class="text-xs text-gray-500 hover:text-gray-900">
                            <?php echo htmlspecialchars($promo_manual_labels['change_product']); ?>
                        </button>
                    </div>
                    <div class="mt-4 flex items-end gap-3">
                        <div class="w-40">
                            <label for="promoDiscountRate" class="mb-1 block text-xs font-medium text-gray-700">
                                <?php echo htmlspecialchars(t('logistics.promo_products.discount_rate')); ?>
                            </label>
                            <div class="relative">
                                <input id="promoDiscountRate" type="number" min="0.01" max="99.99" step="0.01"
                                       class="w-full rounded-lg border border-gray-300 px-3 py-2 pr-8 text-sm focus:border-pink-500 focus:outline-none focus:ring-2 focus:ring-pink-200">
                                <span class="absolute right-3 top-2 text-sm text-gray-400">%</span>
                            </div>
                        </div>
                        <button id="promoRegisterSubmit" type="button" onclick="submitManualPromotion()"
                                class="rounded-lg bg-pink-600 px-4 py-2 text-sm font-semibold text-white hover:bg-pink-700">
                            <?php echo htmlspecialchars($promo_manual_labels['save']); ?>
                        </button>
                    </div>
                </div>
                <div id="promoModalError" class="mt-4 hidden rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"></div>
            </div>
        </div>
    </div>
</div>

<script>
let promoSelectedInventoryId = 0;
let promoSearchTimer = null;
let promoSearchProducts = [];

function openPromoRegisterModal() {
    document.getElementById('promoRegisterModal').classList.remove('hidden');
    document.getElementById('promoRegisterModal').setAttribute('aria-hidden', 'false');
    document.getElementById('promoProductSearch').focus();
}

function closePromoRegisterModal() {
    document.getElementById('promoRegisterModal').classList.add('hidden');
    document.getElementById('promoRegisterModal').setAttribute('aria-hidden', 'true');
    clearPromoSelection();
    document.getElementById('promoProductSearch').value = '';
    document.getElementById('promoSearchResults').innerHTML = '';
    document.getElementById('promoSearchStatus').textContent = '';
}

function clearPromoSelection() {
    promoSelectedInventoryId = 0;
    document.getElementById('promoSelectedProduct').classList.add('hidden');
    document.getElementById('promoDiscountRate').value = '';
}

function renderPromoSearchResults(products) {
    const results = document.getElementById('promoSearchResults');
    promoSearchProducts = products;
    if (!products.length) {
        results.innerHTML = '<div class="px-4 py-8 text-center text-sm text-gray-400">' + <?php echo json_encode($promo_manual_labels['no_search_results']); ?> + '</div>';
        return;
    }
    results.innerHTML = products.map((product, index) => {
        const name = escapePromoHtml(product.name_en || '-') + (product.name_ko ? ' (' + escapePromoHtml(product.name_ko) + ')' : '');
        const brand = product.brand_name_en ? escapePromoHtml(product.brand_name_en) : '';
        const expiry = product.expiry_date || '-';
        const lot = product.lot_number || '-';
        const meta = [brand, product.capacity, product.unit].filter(Boolean).map(escapePromoHtml).join(' · ');
        return '<button type="button" class="block w-full border-b border-gray-100 px-4 py-3 text-left last:border-b-0 hover:bg-pink-50" '
            + 'onclick="selectPromoInventory(' + index + ')">'
            + '<div class="font-medium text-gray-900">' + name + '</div>'
            + '<div class="mt-1 text-xs text-gray-500">' + meta + '</div>'
            + '<div class="mt-1 text-xs text-gray-600">LOT: ' + escapePromoHtml(lot) + ' · <?php echo htmlspecialchars(t('logistics.promo_products.expiry_date')); ?>: ' + escapePromoHtml(expiry) + ' · <?php echo htmlspecialchars(t('logistics.promo_products.remaining_quantity')); ?>: ' + Number(product.quantity_remain).toLocaleString() + ' ' + escapePromoHtml(product.unit || '') + '</div>'
            + '</button>';
    }).join('');
}

function escapePromoHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
}

function selectPromoInventory(index) {
    const product = promoSearchProducts[index];
    if (!product) return;
    promoSelectedInventoryId = Number(product.inventory_id);
    const name = (product.name_en || '-') + (product.name_ko ? ' (' + product.name_ko + ')' : '');
    document.getElementById('promoSelectedName').textContent = name;
    document.getElementById('promoSelectedLot').textContent = 'LOT: ' + (product.lot_number || '-') + ' · ' + <?php echo json_encode(t('logistics.promo_products.expiry_date')); ?> + ': ' + (product.expiry_date || '-') + ' · ' + <?php echo json_encode(t('logistics.promo_products.remaining_quantity')); ?> + ': ' + Number(product.quantity_remain).toLocaleString() + ' ' + (product.unit || '');
    document.getElementById('promoSelectedProduct').classList.remove('hidden');
    document.getElementById('promoModalError').classList.add('hidden');
    document.getElementById('promoDiscountRate').focus();
}

document.getElementById('promoProductSearch').addEventListener('input', function () {
    const query = this.value.trim();
    clearTimeout(promoSearchTimer);
    if (!query) {
        document.getElementById('promoSearchResults').innerHTML = '';
        document.getElementById('promoSearchStatus').textContent = '';
        return;
    }
    document.getElementById('promoSearchStatus').textContent = <?php echo json_encode($promo_manual_labels['searching']); ?>;
    promoSearchTimer = setTimeout(async () => {
        try {
            const response = await fetch('ajax/promo_product_search.php?q=' + encodeURIComponent(query));
            const result = await response.json();
            if (!result.success) throw new Error(result.message || 'search failed');
            renderPromoSearchResults(result.products || []);
            document.getElementById('promoSearchStatus').textContent = '';
        } catch (error) {
            document.getElementById('promoSearchStatus').textContent = <?php echo json_encode($promo_manual_labels['search_failed']); ?>;
        }
    }, 250);
});

async function submitManualPromotion() {
    const errorEl = document.getElementById('promoModalError');
    const rate = document.getElementById('promoDiscountRate').value;
    errorEl.classList.add('hidden');
    if (!promoSelectedInventoryId || !rate || Number(rate) <= 0 || Number(rate) >= 100) {
        errorEl.textContent = <?php echo json_encode($promo_manual_labels['invalid_rate']); ?>;
        errorEl.classList.remove('hidden');
        return;
    }
    const button = document.getElementById('promoRegisterSubmit');
    button.disabled = true;
    const formData = new FormData();
    formData.append('csrf_token', <?php echo json_encode($csrf_token); ?>);
    formData.append('inventory_id', String(promoSelectedInventoryId));
    formData.append('discount_rate', rate);
    try {
        const response = await fetch('ajax/promo_register.php', { method: 'POST', body: formData });
        const result = await response.json();
        if (!result.success) throw new Error(result.message || <?php echo json_encode($promo_manual_labels['save_failed']); ?>);
        window.location.reload();
    } catch (error) {
        errorEl.textContent = error.message || <?php echo json_encode($promo_manual_labels['save_failed']); ?>;
        errorEl.classList.remove('hidden');
        button.disabled = false;
    }
}

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
