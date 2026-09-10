<?php
/**
 * Foodpang 외부 도매 매핑 - 확정 매핑 목록/검색/내보내기 화면.
 * fpwx_hkm_mappings(sales_code -> HKM products)만 조회/거부 처리하며,
 * Foodpang 원본 스냅샷이나 기존 products는 절대 변경하지 않는다.
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
$status = in_array($_GET['status'] ?? 'active', ['active', 'rejected', 'all'], true) ? $_GET['status'] : 'active';
$search = trim($_GET['search'] ?? '');

$mappings = [];
$setup_required = false;
try {
    $pdo = fpwx_pdo();

    $where = [];
    $params = [];
    if ($status !== 'all') {
        $where[] = 'm.status = ?';
        $params[] = $status;
    }
    if ($search !== '') {
        $where[] = '(m.sales_code LIKE ? OR p.sku LIKE ? OR p.name_ko LIKE ? OR p.name_en LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = $pdo->prepare(
        "SELECT m.*, sp.base_code, sp.barcode AS sales_barcode, p.sku AS hkm_sku, p.name_ko AS hkm_name_ko
         FROM fpwx_hkm_mappings m
         LEFT JOIN fpwx_sales_products sp ON sp.sales_code = m.sales_code
         LEFT JOIN products p ON p.id = m.hkm_product_id
         {$where_sql}
         ORDER BY m.mapped_at DESC
         LIMIT 500"
    );
    $stmt->execute($params);
    $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $setup_required = true;
    error_log('fpwx mappings setup check: ' . $e->getMessage());
}

$current_page = 'wholesale_mappings.php';
?>
<!DOCTYPE html>
<html lang="<?php echo get_language(); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Foodpang - <?php echo t('foodpang_wholesale.nav_mappings'); ?></title>
<link rel="icon" href="data:,">
<link href="../../admin/css/style.css" rel="stylesheet">
<link href="../../admin/css/design-system.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="bg-gray-50 min-h-screen">
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<main class="p-6">
<div class="mb-4 flex items-center justify-between flex-wrap gap-2">
    <h1 class="text-lg font-bold text-gray-800"><i class="fas fa-link mr-2"></i><?php echo t('foodpang_wholesale.nav_mappings'); ?></h1>
    <a href="wholesale_export.php?status=<?php echo urlencode($status); ?>&search=<?php echo urlencode($search); ?>" class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 rounded-md text-xs font-semibold"><i class="fas fa-file-csv mr-1.5"></i><?php echo t('foodpang_wholesale.export_csv'); ?></a>
</div>

<?php if ($setup_required): ?>
<div class="mb-5 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-4 text-sm">
    <i class="fas fa-triangle-exclamation mr-2"></i><?php echo t('foodpang_wholesale.setup_required'); ?>
    <a class="font-bold underline" href="/sql/run_create_fpwx_tables_migration.php"><?php echo t('foodpang_wholesale.setup_link'); ?></a>
</div>
<?php else: ?>

<div id="fpwx-flash" class="mb-4 hidden rounded-md p-3 text-sm"></div>

<form method="get" class="mb-4 flex flex-wrap gap-2 items-center bg-white rounded-lg border border-gray-200 p-3">
    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="<?php echo htmlspecialchars(t('foodpang_wholesale.search_mappings_placeholder')); ?>" class="border border-gray-300 rounded-md px-3 py-1.5 text-sm flex-1 min-w-[200px]">
    <select name="status" class="border border-gray-300 rounded-md px-2 py-1.5 text-sm">
        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>><?php echo t('foodpang_wholesale.status_active'); ?></option>
        <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>><?php echo t('foodpang_wholesale.status_rejected'); ?></option>
        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>><?php echo t('foodpang_wholesale.status_all'); ?></option>
    </select>
    <button type="submit" class="px-3 py-1.5 bg-gray-800 hover:bg-gray-900 text-white rounded-md text-sm"><?php echo t('common.search'); ?></button>
</form>

<section class="bg-white rounded-lg border border-gray-200 p-4">
    <div class="mb-3 flex items-center justify-between">
        <h2 class="text-sm font-semibold text-gray-700"><?php echo t('foodpang_wholesale.mappings_title'); ?></h2>
        <span class="text-xs text-gray-400"><?php echo count($mappings); ?><?php echo t('foodpang_admin.count_suffix'); ?></span>
    </div>
    <div class="overflow-x-auto">
    <table class="min-w-full text-xs">
        <thead class="bg-gray-100 text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left"><?php echo t('foodpang_wholesale.col_sales_code'); ?></th>
                <th class="px-3 py-2 text-left"><?php echo t('foodpang_wholesale.col_base_code'); ?></th>
                <th class="px-3 py-2 text-left"><?php echo t('foodpang_wholesale.col_hkm_product'); ?></th>
                <th class="px-3 py-2 text-center"><?php echo t('foodpang_wholesale.col_match_type'); ?></th>
                <th class="px-3 py-2 text-center"><?php echo t('foodpang_wholesale.col_package_type'); ?></th>
                <th class="px-3 py-2 text-center"><?php echo t('foodpang_wholesale.col_units_per_sale'); ?></th>
                <th class="px-3 py-2 text-center"><?php echo t('foodpang_wholesale.col_status'); ?></th>
                <th class="px-3 py-2"></th>
            </tr>
        </thead>
        <tbody id="fpwx-mappings-body" class="divide-y divide-gray-100">
            <?php if (empty($mappings)): ?>
            <tr><td colspan="8" class="px-3 py-8 text-center text-gray-400"><?php echo t('foodpang_wholesale.no_mappings'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($mappings as $m): ?>
            <tr class="fpwx-mapping-row" data-sales-code="<?php echo htmlspecialchars($m['sales_code']); ?>">
                <td class="px-3 py-2 font-mono"><?php echo htmlspecialchars($m['sales_code']); ?></td>
                <td class="px-3 py-2 font-mono"><?php echo htmlspecialchars($m['base_code'] ?? '-'); ?></td>
                <td class="px-3 py-2"><?php echo htmlspecialchars($m['hkm_name_ko'] ?? '-'); ?> <span class="text-gray-400 font-mono"><?php echo htmlspecialchars($m['hkm_sku'] ?? ''); ?></span></td>
                <td class="px-3 py-2 text-center"><?php echo $m['match_type'] === 'auto_exact' ? t('foodpang_wholesale.match_type_auto') : t('foodpang_wholesale.match_type_manual'); ?></td>
                <td class="px-3 py-2 text-center"><?php echo htmlspecialchars($m['package_type'] ?? '-'); ?></td>
                <td class="px-3 py-2 text-center"><?php echo fmt_num($m['units_per_sale']); ?></td>
                <td class="px-3 py-2 text-center">
                    <span class="px-2 py-0.5 rounded <?php echo $m['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-500'; ?>">
                        <?php echo $m['status'] === 'active' ? t('foodpang_wholesale.status_active') : t('foodpang_wholesale.status_rejected'); ?>
                    </span>
                </td>
                <td class="px-3 py-2 text-right whitespace-nowrap">
                    <?php if ($m['status'] === 'active'): ?>
                    <button type="button" class="fpwx-reject-btn text-xs text-red-500 hover:text-red-700"><?php echo t('foodpang_wholesale.reject'); ?></button>
                    <?php endif; ?>
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

    document.querySelectorAll('.fpwx-reject-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('<?php echo addslashes(t('foodpang_wholesale.confirm_reject')); ?>')) return;
            const row = btn.closest('.fpwx-mapping-row');
            const params = new URLSearchParams({ action: 'reject_mapping', sales_code: row.dataset.salesCode, csrf_token: csrfToken });
            fetch(AJAX_URL, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        location.reload();
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
