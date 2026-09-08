<?php
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../lib/lang_helper.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../config/mall_config.php';

ensure_logged_in();
require_permission('mall_management', '../../admin/index.php');

$current_page = 'dashboard.php';
$store_id = MALL_STORE_ID;

$conn = get_db_connection();

$today_stmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS total
     FROM mall_orders WHERE store_id = ? AND DATE(created_at) = CURDATE()"
);
$today_stmt->bind_param('i', $store_id);
$today_stmt->execute();
$today = $today_stmt->get_result()->fetch_assoc();
$today_stmt->close();

$month_stmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS total
     FROM mall_orders WHERE store_id = ? AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())"
);
$month_stmt->bind_param('i', $store_id);
$month_stmt->execute();
$month = $month_stmt->get_result()->fetch_assoc();
$month_stmt->close();

$pending_row = $conn->query(
    "SELECT COUNT(*) AS cnt FROM mall_members WHERE member_type = 'wholesale' AND wholesale_status = 'pending'"
)->fetch_assoc();
$pending_wholesale = (int)($pending_row['cnt'] ?? 0);

$pending_orders_row = $conn->query(
    "SELECT COUNT(*) AS cnt FROM mall_orders WHERE store_id = " . (int)$store_id . " AND status = 'pending'"
)->fetch_assoc();
$pending_orders = (int)($pending_orders_row['cnt'] ?? 0);

$recent_orders = $conn->query(
    "SELECT o.id, o.order_number, o.channel, o.total_amount, o.status, o.created_at, m.name AS member_name
     FROM mall_orders o INNER JOIN mall_members m ON m.id = o.member_id
     WHERE o.store_id = " . (int)$store_id . "
     ORDER BY o.created_at DESC LIMIT 10"
)->fetch_all(MYSQLI_ASSOC);

$conn->close();

$status_labels = [
    'pending' => t('mall_admin.order_status.pending'), 'confirmed' => t('mall_admin.order_status.confirmed'),
    'preparing' => t('mall_admin.order_status.preparing'), 'ready' => t('mall_admin.order_status.ready'),
    'assigned' => t('mall_admin.order_status.assigned'), 'delivering' => t('mall_admin.order_status.delivering'),
    'arrived' => t('mall_admin.order_status.arrived'), 'completed' => t('mall_admin.order_status.completed'),
    'cancelled' => t('mall_admin.order_status.cancelled'), 'delivery_failed' => t('mall_admin.order_status.delivery_failed'),
];
?>
<!DOCTYPE html>
<html lang="<?php echo get_language(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('mall_admin.nav.dashboard'); ?> - HOME K MART <?php echo t('mall_admin.title'); ?></title>
    <link rel="icon" href="data:,">
    <link href="../../admin/css/style.css" rel="stylesheet">
    <link href="../../admin/css/design-system.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body class="bg-gray-50 min-h-screen">
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<main class="p-6">
        <h1 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-gauge mr-2"></i><?php echo t('mall_admin.nav.dashboard'); ?></h1>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="text-xs text-gray-500 mb-1"><?php echo t('mall_admin.dashboard.today_orders'); ?></div>
                <div class="text-xl font-bold"><?php echo t('mall_admin.dashboard.count_unit', ['count' => (int)$today['cnt']]); ?></div>
                <div class="text-xs text-gray-400"><?php echo number_format((float)$today['total'], 2); ?></div>
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="text-xs text-gray-500 mb-1"><?php echo t('mall_admin.dashboard.month_orders'); ?></div>
                <div class="text-xl font-bold"><?php echo t('mall_admin.dashboard.count_unit', ['count' => (int)$month['cnt']]); ?></div>
                <div class="text-xs text-gray-400"><?php echo number_format((float)$month['total'], 2); ?></div>
            </div>
            <a href="members.php?tab=wholesale_pending" class="bg-white rounded-lg border border-gray-200 p-4 hover:bg-yellow-50">
                <div class="text-xs text-gray-500 mb-1"><?php echo t('mall_admin.dashboard.wholesale_pending'); ?></div>
                <div class="text-xl font-bold text-yellow-600"><?php echo t('mall_admin.dashboard.people_unit', ['count' => $pending_wholesale]); ?></div>
            </a>
            <a href="orders.php?status=pending" class="bg-white rounded-lg border border-gray-200 p-4 hover:bg-blue-50">
                <div class="text-xs text-gray-500 mb-1"><?php echo t('mall_admin.dashboard.orders_pending'); ?></div>
                <div class="text-xl font-bold text-blue-600"><?php echo t('mall_admin.dashboard.count_unit', ['count' => $pending_orders]); ?></div>
            </a>
        </div>

        <section class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
            <div class="px-4 py-3 border-b border-gray-100 text-sm font-bold text-gray-700"><?php echo t('mall_admin.dashboard.recent_orders'); ?></div>
            <table class="min-w-full text-xs">
                <thead class="bg-gray-100 text-gray-600">
                    <tr>
                        <th class="px-3 py-2 text-left"><?php echo t('mall_admin.orders.order_number'); ?></th>
                        <th class="px-3 py-2 text-left"><?php echo t('mall_admin.orders.member'); ?></th>
                        <th class="px-3 py-2 text-left"><?php echo t('mall_admin.orders.channel'); ?></th>
                        <th class="px-3 py-2 text-left"><?php echo t('mall_admin.orders.total'); ?></th>
                        <th class="px-3 py-2 text-left"><?php echo t('mall_admin.orders.datetime'); ?></th>
                        <th class="px-3 py-2 text-left"><?php echo t('common.status'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($recent_orders)): ?>
                    <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400"><?php echo t('mall_admin.dashboard.no_orders'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($recent_orders as $o): ?>
                    <tr class="border-t border-gray-100">
                        <td class="px-3 py-2 font-mono"><?php echo htmlspecialchars($o['order_number']); ?></td>
                        <td class="px-3 py-2"><?php echo htmlspecialchars($o['member_name']); ?></td>
                        <td class="px-3 py-2"><?php echo $o['channel'] === 'wholesale' ? t('mall_admin.orders.channel_wholesale') : t('mall_admin.orders.channel_retail'); ?></td>
                        <td class="px-3 py-2"><?php echo number_format((float)$o['total_amount'], 2); ?></td>
                        <td class="px-3 py-2"><?php echo htmlspecialchars(substr($o['created_at'], 0, 16)); ?></td>
                        <td class="px-3 py-2"><?php echo $status_labels[$o['status']] ?? $o['status']; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </main>
</body>
</html>
