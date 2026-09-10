<?php
/**
 * Foodpang 외부 도매 매핑 - 예외 검토 화면.
 * 1:N 판매코드 변형(one_to_many_variant), 바코드 누락(missing_barcode),
 * 바코드 불일치(barcode_not_found) 건을 수동으로 HKM 상품에 매핑한다.
 */
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../lib/lang_helper.php';
ensure_logged_in();
require_permission('foodpang_wholesale_management', '/index.php');
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../mall/lib/csrf.php';
require_once __DIR__ . '/../../lib/fpwx_helper.php';

$csrf_token = mall_csrf_token();
$batch_id = isset($_GET['batch_id']) && $_GET['batch_id'] !== '' ? (int)$_GET['batch_id'] : null;
$reason = trim($_GET['reason'] ?? '') ?: null;

$candidates = [];
$setup_required = false;
try {
    $pdo = fpwx_pdo();
    $candidates = fpwx_get_pending_candidates($pdo, ['batch_id' => $batch_id, 'reason' => $reason]);
} catch (Throwable $e) {
    $setup_required = true;
    error_log('fpwx review setup check: ' . $e->getMessage());
}

$reason_labels = [
    'one_to_many_variant' => t('foodpang_wholesale.reason_one_to_many'),
    'missing_barcode' => t('foodpang_wholesale.reason_missing_barcode'),
    'barcode_not_found' => t('foodpang_wholesale.reason_barcode_not_found'),
];

$current_page = 'wholesale_review.php';
?>
<!DOCTYPE html>
<html lang="<?php echo get_language(); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Foodpang - <?php echo t('foodpang_wholesale.nav_review'); ?></title>
<link rel="icon" href="data:,">
<link href="../../admin/css/style.css" rel="stylesheet">
<link href="../../admin/css/design-system.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="bg-gray-50 min-h-screen">
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<main class="p-6">
<h1 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-triangle-exclamation mr-2"></i><?php echo t('foodpang_wholesale.nav_review'); ?></h1>

<?php if ($setup_required): ?>
<div class="mb-5 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-4 text-sm">
    <i class="fas fa-triangle-exclamation mr-2"></i><?php echo t('foodpang_wholesale.setup_required'); ?>
    <a class="font-bold underline" href="/sql/run_create_fpwx_tables_migration.php"><?php echo t('foodpang_wholesale.setup_link'); ?></a>
</div>
<?php else: ?>

<div id="fpwx-flash" class="mb-4 hidden rounded-md p-3 text-sm"></div>

<div class="mb-4 flex flex-wrap gap-2 text-xs">
    <a href="wholesale_review.php" class="px-2.5 py-1.5 rounded-md <?php echo !$reason ? 'bg-blue-100 text-blue-800' : 'bg-white border border-gray-300 text-gray-600'; ?>"><?php echo t('foodpang_wholesale.filter_all'); ?></a>
    <?php foreach ($reason_labels as $key => $label): ?>
    <a href="wholesale_review.php?reason=<?php echo urlencode($key); ?>" class="px-2.5 py-1.5 rounded-md <?php echo $reason === $key ? 'bg-blue-100 text-blue-800' : 'bg-white border border-gray-300 text-gray-600'; ?>"><?php echo htmlspecialchars($label); ?></a>
    <?php endforeach; ?>
</div>

<section class="bg-white rounded-lg border border-gray-200 p-4">
    <div class="mb-3 flex items-center justify-between">
        <h2 class="text-sm font-semibold text-gray-700"><?php echo t('foodpang_wholesale.pending_title'); ?></h2>
        <span class="text-xs text-gray-400"><?php echo count($candidates); ?><?php echo t('foodpang_admin.count_suffix'); ?></span>
    </div>
    <div class="overflow-x-auto">
    <table class="min-w-full text-xs">
        <thead class="bg-gray-100 text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left"><?php echo t('foodpang_wholesale.col_sales_code'); ?></th>
                <th class="px-3 py-2 text-left"><?php echo t('foodpang_wholesale.col_base_code'); ?></th>
                <th class="px-3 py-2 text-left"><?php echo t('foodpang_wholesale.col_fp_product'); ?></th>
                <th class="px-3 py-2 text-left"><?php echo t('foodpang_wholesale.col_reason'); ?></th>
                <th class="px-3 py-2 text-left"><?php echo t('foodpang_wholesale.col_suggested'); ?></th>
                <th class="px-3 py-2 text-left"><?php echo t('foodpang_wholesale.col_map_to'); ?></th>
                <th class="px-3 py-2"></th>
            </tr>
        </thead>
        <tbody id="fpwx-candidate-body" class="divide-y divide-gray-100">
            <?php if (empty($candidates)): ?>
            <tr><td colspan="7" class="px-3 py-8 text-center text-gray-400"><?php echo t('foodpang_wholesale.no_pending'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($candidates as $c): ?>
            <tr class="fpwx-candidate-row" data-candidate-id="<?php echo (int)$c['id']; ?>" data-sales-code="<?php echo htmlspecialchars($c['sales_code']); ?>">
                <td class="px-3 py-2 font-mono"><?php echo htmlspecialchars($c['sales_code']); ?></td>
                <td class="px-3 py-2 font-mono"><?php echo htmlspecialchars($c['base_code'] ?? '-'); ?></td>
                <td class="px-3 py-2">
                    <div><?php echo htmlspecialchars($c['sales_product_name'] ?? '-'); ?></div>
                    <div class="text-gray-400 font-mono"><?php echo htmlspecialchars($c['sales_barcode'] ?? '-'); ?></div>
                </td>
                <td class="px-3 py-2"><span class="px-2 py-0.5 rounded bg-amber-100 text-amber-800"><?php echo htmlspecialchars($reason_labels[$c['reason']] ?? $c['reason']); ?></span></td>
                <td class="px-3 py-2">
                    <?php if ($c['hkm_product_id']): ?>
                        <?php echo htmlspecialchars($c['hkm_name_ko']); ?> (<?php echo htmlspecialchars($c['hkm_sku']); ?>)
                    <?php else: ?>
                        <span class="text-gray-400">-</span>
                    <?php endif; ?>
                </td>
                <td class="px-3 py-2">
                    <input type="text" class="fpwx-hkm-search w-40 border border-gray-200 rounded px-2 py-1 text-xs mb-1" placeholder="<?php echo htmlspecialchars(t('foodpang_wholesale.search_hkm_placeholder')); ?>" value="<?php echo $c['hkm_product_id'] ? htmlspecialchars($c['hkm_name_ko'] . ' (' . $c['hkm_sku'] . ')') : ''; ?>">
                    <input type="hidden" class="fpwx-hkm-product-id" value="<?php echo (int)($c['hkm_product_id'] ?? 0); ?>">
                    <div class="fpwx-hkm-results hidden border border-gray-200 rounded bg-white shadow text-xs"></div>
                    <div class="flex gap-1 mt-1">
                        <select class="fpwx-package-type border border-gray-200 rounded px-1 py-1 text-xs">
                            <option value="EA">EA</option>
                            <option value="BOX">BOX</option>
                        </select>
                        <input type="number" step="0.01" min="0.01" value="1" class="fpwx-units-per-sale w-16 border border-gray-200 rounded px-1 py-1 text-xs" title="<?php echo htmlspecialchars(t('foodpang_wholesale.units_per_sale')); ?>">
                    </div>
                </td>
                <td class="px-3 py-2 text-right whitespace-nowrap">
                    <button type="button" class="fpwx-map-btn text-xs text-pink-600 hover:text-pink-800 font-medium mr-2"><?php echo t('foodpang_wholesale.confirm_mapping'); ?></button>
                    <button type="button" class="fpwx-dismiss-btn text-xs text-gray-400 hover:text-gray-700"><?php echo t('foodpang_wholesale.dismiss'); ?></button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php endif; ?>
</main>

<script>
(function () {
    const AJAX_URL = 'ajax_wholesale.php';
    const csrfToken = <?php echo json_encode($csrf_token); ?>;
    const flashBox = document.getElementById('fpwx-flash');

    function showFlash(message, type) {
        if (!flashBox) return;
        flashBox.textContent = message;
        flashBox.className = 'mb-4 rounded-md p-3 text-sm ' + (type === 'error' ? 'bg-red-50 text-red-800 border border-red-200' : 'bg-green-50 text-green-800 border border-green-200');
        flashBox.classList.remove('hidden');
        setTimeout(() => flashBox.classList.add('hidden'), 3000);
    }

    function postForm(action, data) {
        const params = new URLSearchParams(data);
        params.set('action', action);
        params.set('csrf_token', csrfToken);
        return fetch(AJAX_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        }).then(r => r.json());
    }

    let searchTimer = null;
    document.querySelectorAll('.fpwx-candidate-row').forEach(function (row) {
        const searchInput = row.querySelector('.fpwx-hkm-search');
        const hiddenId = row.querySelector('.fpwx-hkm-product-id');
        const resultsBox = row.querySelector('.fpwx-hkm-results');

        searchInput.addEventListener('input', function () {
            hiddenId.value = '';
            clearTimeout(searchTimer);
            const q = searchInput.value.trim();
            if (!q) { resultsBox.classList.add('hidden'); resultsBox.innerHTML = ''; return; }
            searchTimer = setTimeout(function () {
                fetch(AJAX_URL + '?action=search_hkm_products&q=' + encodeURIComponent(q))
                    .then(r => r.json())
                    .then(res => {
                        resultsBox.innerHTML = '';
                        if (!res.success || !res.data.length) {
                            resultsBox.classList.add('hidden');
                            return;
                        }
                        res.data.forEach(item => {
                            const opt = document.createElement('div');
                            opt.className = 'px-2 py-1 hover:bg-gray-100 cursor-pointer';
                            opt.textContent = item.name_ko + ' (' + item.sku + ')';
                            opt.addEventListener('click', function () {
                                hiddenId.value = item.id;
                                searchInput.value = item.name_ko + ' (' + item.sku + ')';
                                resultsBox.classList.add('hidden');
                            });
                            resultsBox.appendChild(opt);
                        });
                        resultsBox.classList.remove('hidden');
                    });
            }, 250);
        });

        row.querySelector('.fpwx-map-btn').addEventListener('click', function () {
            const hkmProductId = hiddenId.value;
            if (!hkmProductId) {
                showFlash('<?php echo addslashes(t('foodpang_wholesale.select_hkm_product_first')); ?>', 'error');
                return;
            }
            postForm('manual_map', {
                sales_code: row.dataset.salesCode,
                hkm_product_id: hkmProductId,
                package_type: row.querySelector('.fpwx-package-type').value,
                units_per_sale: row.querySelector('.fpwx-units-per-sale').value
            }).then(res => {
                if (res.success) {
                    row.remove();
                    showFlash('<?php echo addslashes(t('foodpang_wholesale.mapping_saved')); ?>', 'success');
                } else {
                    showFlash((res.error && res.error.message) || '<?php echo addslashes(t('common.error_occurred')); ?>', 'error');
                }
            });
        });

        row.querySelector('.fpwx-dismiss-btn').addEventListener('click', function () {
            if (!confirm('<?php echo addslashes(t('foodpang_wholesale.confirm_dismiss')); ?>')) return;
            postForm('dismiss_candidate', { candidate_id: row.dataset.candidateId }).then(res => {
                if (res.success) {
                    row.remove();
                } else {
                    showFlash((res.error && res.error.message) || '<?php echo addslashes(t('common.error_occurred')); ?>', 'error');
                }
            });
        });
    });
})();
</script>
</body>
</html>
