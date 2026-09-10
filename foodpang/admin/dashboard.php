<?php
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../lib/lang_helper.php';
require_once __DIR__ . '/../../config/db_config.php';
ensure_logged_in();
require_permission('foodpang_management', '/index.php');
$store_id = max(1, (int)($_SESSION['store_id'] ?? 1));
$stats = ['total' => 0, 'active' => 0, 'sold_out' => 0];
$category_count = 0;
$setup_required = false;
try {
    $conn = get_db_connection();
    $stmt = $conn->prepare('SELECT COUNT(*) total, SUM(is_active = 1) active, SUM(is_sold_out = 1) sold_out FROM foodpang_products WHERE store_id = ?');
    $stmt->bind_param('i', $store_id); $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc(); $stmt->close();
    $stmt = $conn->prepare('SELECT COUNT(*) total FROM foodpang_categories WHERE store_id = ?');
    $stmt->bind_param('i', $store_id); $stmt->execute();
    $category_count = (int)$stmt->get_result()->fetch_assoc()['total']; $stmt->close(); $conn->close();
} catch (Throwable $e) {
    $setup_required = true;
    error_log('Foodpang dashboard setup check: ' . $e->getMessage());
}
$current_page = 'dashboard.php';
?>
<!DOCTYPE html><html lang="<?php echo get_language(); ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Foodpang - <?php echo t('foodpang_admin.dashboard'); ?></title><link rel="icon" href="data:,"><link href="../../admin/css/style.css" rel="stylesheet"><link href="../../admin/css/design-system.css" rel="stylesheet"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"></head>
<body class="bg-gray-50 min-h-screen"><?php include __DIR__ . '/partials/sidebar.php'; ?>
<main class="p-6"><h1 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-gauge mr-2"></i><?php echo t('foodpang_admin.dashboard'); ?></h1>
<?php if ($setup_required): ?><div class="mb-5 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-4 text-sm"><i class="fas fa-triangle-exclamation mr-2"></i>Foodpang DB 설치가 필요합니다. <a class="font-bold underline" href="/sql/run_create_foodpang_products_migration.php">설치 페이지 열기</a></div><?php endif; ?>
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
<?php foreach ([[t('foodpang_admin.total_products'),(int)($stats['total'] ?? 0),'text-gray-800'],[t('foodpang_admin.visible_products'),(int)($stats['active'] ?? 0),'text-green-600'],[t('foodpang_admin.sold_out_products'),(int)($stats['sold_out'] ?? 0),'text-red-600'],[t('foodpang_admin.total_categories'),$category_count,'text-pink-600']] as $card): ?>
<div class="bg-white rounded-lg border border-gray-200 p-4"><div class="text-xs text-gray-500 mb-1"><?php echo $card[0]; ?></div><div class="text-xl font-bold <?php echo $card[2]; ?>"><?php echo $card[1]; ?></div></div><?php endforeach; ?>
</div><a href="products.php" class="inline-flex items-center px-4 py-2 bg-pink-600 hover:bg-pink-700 text-white rounded-md text-sm font-semibold"><i class="fas fa-box mr-2"></i><?php echo t('foodpang_admin.manage_products'); ?></a></main></body></html>
