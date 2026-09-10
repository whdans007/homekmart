<?php
/**
 * Foodpang 외부 도매 매핑(fpwx) 대시보드.
 * 원본 Foodpang 데이터(fpwx_raw_*)나 기존 상품(products)은 조회만 하며 절대 변경하지 않는다.
 */
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../lib/lang_helper.php';
ensure_logged_in();
require_permission('foodpang_wholesale_management', '/index.php');
require_once __DIR__ . '/../../lib/fpwx_helper.php';

$setup_required = false;
$stats = ['batches' => 0, 'active_mappings' => 0, 'pending_exceptions' => 0, 'base_products' => 0, 'sales_products' => 0];
$recent_batches = [];

try {
    $pdo = fpwx_pdo();
    $stats['batches'] = (int)$pdo->query('SELECT COUNT(*) FROM fpwx_import_batches')->fetchColumn();
    $stats['active_mappings'] = (int)$pdo->query("SELECT COUNT(*) FROM fpwx_hkm_mappings WHERE status = 'active'")->fetchColumn();
    $stats['pending_exceptions'] = (int)$pdo->query("SELECT COUNT(*) FROM fpwx_match_candidates WHERE status = 'pending'")->fetchColumn();
    $stats['base_products'] = (int)$pdo->query('SELECT COUNT(*) FROM fpwx_base_products')->fetchColumn();
    $stats['sales_products'] = (int)$pdo->query('SELECT COUNT(*) FROM fpwx_sales_products')->fetchColumn();

    $recent_batches = $pdo->query(
        'SELECT id, original_filename, barcode_row_count, pms_row_count, auto_matched_count, exception_count, status, created_at
         FROM fpwx_import_batches ORDER BY id DESC LIMIT 10'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $setup_required = true;
    error_log('fpwx dashboard setup check: ' . $e->getMessage());
}

$current_page = 'wholesale_dashboard.php';
?>
<!DOCTYPE html>
<html lang="<?php echo get_language(); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Foodpang - <?php echo t('foodpang_wholesale.nav_dashboard'); ?></title>
<link rel="icon" href="data:,">
<link href="../../admin/css/style.css" rel="stylesheet">
<link href="../../admin/css/design-system.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="bg-gray-50 min-h-screen">
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<main class="p-6">
<h1 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-truck-ramp-box mr-2"></i><?php echo t('foodpang_wholesale.nav_dashboard'); ?></h1>

<?php if ($setup_required): ?>
<div class="mb-5 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-4 text-sm">
    <i class="fas fa-triangle-exclamation mr-2"></i><?php echo t('foodpang_wholesale.setup_required'); ?>
    <a class="font-bold underline" href="/sql/run_create_fpwx_tables_migration.php"><?php echo t('foodpang_wholesale.setup_link'); ?></a>
</div>
<?php else: ?>

<div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
    <div class="bg-white rounded-lg border border-gray-200 p-4">
        <div class="text-xs text-gray-500 mb-1"><?php echo t('foodpang_wholesale.stat_batches'); ?></div>
        <div class="text-xl font-bold text-gray-800"><?php echo $stats['batches']; ?></div>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-4">
        <div class="text-xs text-gray-500 mb-1"><?php echo t('foodpang_wholesale.stat_base_products'); ?></div>
        <div class="text-xl font-bold text-gray-800"><?php echo $stats['base_products']; ?></div>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-4">
        <div class="text-xs text-gray-500 mb-1"><?php echo t('foodpang_wholesale.stat_sales_products'); ?></div>
        <div class="text-xl font-bold text-gray-800"><?php echo $stats['sales_products']; ?></div>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-4">
        <div class="text-xs text-gray-500 mb-1"><?php echo t('foodpang_wholesale.stat_active_mappings'); ?></div>
        <div class="text-xl font-bold text-green-600"><?php echo $stats['active_mappings']; ?></div>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-4">
        <div class="text-xs text-gray-500 mb-1"><?php echo t('foodpang_wholesale.stat_pending_exceptions'); ?></div>
        <div class="text-xl font-bold text-red-600"><?php echo $stats['pending_exceptions']; ?></div>
    </div>
</div>

<div class="mb-6 flex gap-2">
    <a href="wholesale_upload.php" class="inline-flex items-center px-4 py-2 bg-pink-600 hover:bg-pink-700 text-white rounded-md text-sm font-semibold"><i class="fas fa-file-arrow-up mr-2"></i><?php echo t('foodpang_wholesale.nav_upload'); ?></a>
    <a href="wholesale_review.php" class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 rounded-md text-sm font-semibold"><i class="fas fa-triangle-exclamation mr-2"></i><?php echo t('foodpang_wholesale.nav_review'); ?></a>
    <a href="wholesale_mappings.php" class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 rounded-md text-sm font-semibold"><i class="fas fa-link mr-2"></i><?php echo t('foodpang_wholesale.nav_mappings'); ?></a>
</div>

<section class="bg-white rounded-lg border border-gray-200 p-4">
    <h2 class="text-sm font-semibold text-gray-700 mb-3"><?php echo t('foodpang_wholesale.recent_batches'); ?></h2>
    <div class="overflow-x-auto">
    <table class="min-w-full text-xs">
        <thead class="bg-gray-100 text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left"><?php echo t('foodpang_wholesale.col_filename'); ?></th>
                <th class="px-3 py-2 text-center"><?php echo t('foodpang_wholesale.col_barcode_rows'); ?></th>
                <th class="px-3 py-2 text-center"><?php echo t('foodpang_wholesale.col_pms_rows'); ?></th>
                <th class="px-3 py-2 text-center"><?php echo t('foodpang_wholesale.col_auto_matched'); ?></th>
                <th class="px-3 py-2 text-center"><?php echo t('foodpang_wholesale.col_exceptions'); ?></th>
                <th class="px-3 py-2 text-center"><?php echo t('foodpang_wholesale.col_status'); ?></th>
                <th class="px-3 py-2 text-left"><?php echo t('foodpang_wholesale.col_uploaded_at'); ?></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            <?php if (empty($recent_batches)): ?>
            <tr><td colspan="7" class="px-3 py-8 text-center text-gray-400"><?php echo t('foodpang_wholesale.no_batches'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($recent_batches as $b): ?>
            <tr>
                <td class="px-3 py-2 font-mono"><?php echo htmlspecialchars($b['original_filename']); ?></td>
                <td class="px-3 py-2 text-center"><?php echo (int)$b['barcode_row_count']; ?></td>
                <td class="px-3 py-2 text-center"><?php echo (int)$b['pms_row_count']; ?></td>
                <td class="px-3 py-2 text-center text-green-600"><?php echo (int)$b['auto_matched_count']; ?></td>
                <td class="px-3 py-2 text-center text-red-600"><?php echo (int)$b['exception_count']; ?></td>
                <td class="px-3 py-2 text-center"><?php echo htmlspecialchars($b['status']); ?></td>
                <td class="px-3 py-2"><?php echo htmlspecialchars($b['created_at']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php endif; ?>
</main>
</body>
</html>
