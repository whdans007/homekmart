<?php
/**
 * 배달 관리 — 현재 배송중(delivering)인 주문을 기사별로 묶어 한 화면에서 모아본다.
 * Design Ref: mall-delivery-dispatch.design.md 확장 — orders.php(전체 주문)/delivery_map.php(지도)와
 * 별개로 "지금 배송중인 건이 기사별로 몇 건인지" 단일 목록이 없어 신설.
 */
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../lib/lang_helper.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../config/mall_config.php';

ensure_logged_in();
require_permission('mall_management', '../../admin/index.php');

$current_page = 'deliveries.php';

$conn = get_db_connection();
$rows = $conn->query(
    "SELECT o.id AS order_id, o.order_number, o.current_driver_id,
            mem.name AS member_name,
            d.name AS driver_name, d.phone AS driver_phone,
            a.delivering_at,
            d.last_seen_at
     FROM mall_orders o
     INNER JOIN mall_members mem ON mem.id = o.member_id
     INNER JOIN mall_drivers d ON d.id = o.current_driver_id
     LEFT JOIN mall_order_driver_assignments a ON a.id = (
         SELECT id FROM mall_order_driver_assignments WHERE order_id = o.id ORDER BY id DESC LIMIT 1
     )
     WHERE o.status = 'delivering'
     ORDER BY d.name, a.delivering_at"
)->fetch_all(MYSQLI_ASSOC);
$conn->close();

// 기사별로 묶는다.
$by_driver = [];
foreach ($rows as $r) {
    $did = (int)$r['current_driver_id'];
    if (!isset($by_driver[$did])) {
        $by_driver[$did] = ['driver_name' => $r['driver_name'], 'driver_phone' => $r['driver_phone'], 'last_seen_at' => $r['last_seen_at'], 'orders' => []];
    }
    $by_driver[$did]['orders'][] = $r;
}
uasort($by_driver, function ($a, $b) { return strcmp($a['driver_name'], $b['driver_name']); });
?>
<!DOCTYPE html>
<html lang="<?php echo get_language(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('mall_admin.nav.deliveries'); ?> - HOME K MART <?php echo t('mall_admin.title'); ?></title>
    <link rel="icon" href="data:,">
    <link href="../../admin/css/style.css" rel="stylesheet">
    <link href="../../admin/css/design-system.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        .driver-group { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; margin-bottom: 14px; overflow: hidden; }
        .driver-group .head { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; }
        .driver-group .head .name { font-size: 13px; font-weight: 700; color: #111; }
        .driver-group .head .phone { font-size: 11px; color: #6b7280; margin-left: 8px; }
        .driver-group .head .count { font-size: 11px; font-weight: 700; color: #2563eb; background: #eff6ff; padding: 2px 8px; border-radius: 999px; }
        .delivery-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; border-top: 1px solid #f3f4f6; cursor: pointer; }
        .delivery-row:hover { background: #f9fafb; }
        .delivery-row .order-no { font-size: 12px; font-weight: 700; font-family: monospace; color: #111; }
        .delivery-row .member { font-size: 11px; color: #6b7280; margin-left: 8px; }
        .delivery-row .time { font-size: 11px; color: #9ca3af; }
        .empty-hint { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 32px; text-align: center; color: #9ca3af; font-size: 13px; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<main class="p-6 max-w-3xl">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-lg font-bold text-gray-800"><i class="fas fa-truck-fast mr-2"></i><?php echo t('mall_admin.nav.deliveries'); ?></h1>
        <a href="delivery_map.php" class="text-xs text-blue-600 font-semibold hover:underline"><i class="fas fa-map-location-dot mr-1"></i><?php echo t('mall_admin.deliveries.view_map'); ?></a>
    </div>
    <p class="text-xs text-gray-400 mb-4"><?php echo t('mall_admin.deliveries.hint'); ?></p>

    <div id="delivery-list">
        <?php if (empty($by_driver)): ?>
            <div class="empty-hint"><?php echo t('mall_admin.deliveries.empty'); ?></div>
        <?php else: ?>
            <?php foreach ($by_driver as $group): ?>
                <div class="driver-group">
                    <div class="head">
                        <div>
                            <span class="name"><i class="fas fa-motorcycle mr-1"></i><?php echo htmlspecialchars($group['driver_name']); ?></span>
                            <span class="phone"><?php echo htmlspecialchars($group['driver_phone']); ?></span>
                        </div>
                        <span class="count"><?php echo t('mall_admin.deliveries.delivering_count', ['count' => count($group['orders'])]); ?></span>
                    </div>
                    <?php foreach ($group['orders'] as $o): ?>
                        <div class="delivery-row" onclick="window.location.href='orders.php?open=<?php echo (int)$o['order_id']; ?>'">
                            <div>
                                <span class="order-no"><?php echo htmlspecialchars($o['order_number']); ?></span>
                                <span class="member"><?php echo htmlspecialchars($o['member_name']); ?></span>
                            </div>
                            <span class="time"><?php echo $o['delivering_at'] ? htmlspecialchars(substr($o['delivering_at'], 5, 11)) . ' ' . t('mall_admin.deliveries.departed') : ''; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>

<script>
// 30초마다 목록을 다시 불러온다(간단히 페이지 자체를 새로고침하지 않고 목록 영역만 교체).
setInterval(function () {
    fetch(window.location.pathname)
        .then(function (r) { return r.text(); })
        .then(function (html) {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const fresh = doc.getElementById('delivery-list');
            if (fresh) document.getElementById('delivery-list').innerHTML = fresh.innerHTML;
        })
        .catch(function () {});
}, 30000);
</script>
</body>
</html>
