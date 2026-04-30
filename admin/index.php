<?php
require_once __DIR__ . '/../lib/lang_helper.php';
require_once __DIR__ . '/../lib/mobile_detect.php';

// 모바일 기기에서 자동 리다이렉트 (태블릿 포함)
redirect_if_mobile('mobile_main.php', true);

$page_title = t('dashboard.title') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';

// 관리자 접근 권한 확인
if (!has_permission('admin_access')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

$stats = [
    'total_users' => 0,
    'unassigned_users' => 0,
    'stores' => []
];
$expiring_products = [];
$expired_products  = [];
$error_message = '';

try {
    $conn = get_db_connection();

    // 1. 총 회원 수
    $r = $conn->query("SELECT COUNT(id) FROM users");
    if ($r) { $stats['total_users'] = $r->fetch_row()[0]; $r->free(); }

    // 2. 지점별 회원 수
    $r = $conn->query("
        SELECT s.name, COUNT(u.id) as user_count
        FROM stores s
        LEFT JOIN users u ON s.id = u.store_id
        GROUP BY s.id, s.name
        ORDER BY s.name ASC
    ");
    if ($r) { while ($row = $r->fetch_assoc()) { $stats['stores'][] = $row; } $r->free(); }

    // 3. 미지정 회원 수
    $r = $conn->query("SELECT COUNT(id) FROM users WHERE store_id IS NULL");
    if ($r) { $stats['unassigned_users'] = $r->fetch_row()[0]; $r->free(); }

    // 4. 유통기한 임박 상품 (30일 이내) - inventory_expirations 테이블이 없을 수 있음
    $r = $conn->query("
        SELECT p.id, p.name_ko, p.sku, ie.expiration_date, SUM(ie.quantity) AS total_quantity
        FROM inventory_expirations ie
        JOIN products p ON ie.product_id = p.id
        WHERE ie.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
          AND ie.expiration_date >= CURDATE()
        GROUP BY p.id, p.name_ko, p.sku, ie.expiration_date
        ORDER BY ie.expiration_date ASC
        LIMIT 10
    ");
    if ($r) { while ($row = $r->fetch_assoc()) { $expiring_products[] = $row; } $r->free(); }

    // 5. 이미 지난 상품들
    $r = $conn->query("
        SELECT p.id, p.name_ko, p.sku, ie.expiration_date, SUM(ie.quantity) AS total_quantity
        FROM inventory_expirations ie
        JOIN products p ON ie.product_id = p.id
        WHERE ie.expiration_date < CURDATE()
        GROUP BY p.id, p.name_ko, p.sku, ie.expiration_date
        ORDER BY ie.expiration_date DESC
        LIMIT 10
    ");
    if ($r) { while ($row = $r->fetch_assoc()) { $expired_products[] = $row; } $r->free(); }

    $conn->close();

} catch (Exception $e) {
    error_log("index.php DB error: " . $e->getMessage());
    $error_message = t('dashboard.statistics_error') . ': ' . $e->getMessage();
}
?>

<!-- Main Container with max width -->
<div class="max-w-5xl mx-auto">
    <!-- Page header -->
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900"><?php echo t('dashboard.title'); ?></h1>
        <p class="mt-2 text-sm text-gray-600"><?php echo t('dashboard.welcome'); ?></p>
    </div>

    <?php if ($error_message): ?>
        <div class="mb-6 bg-red-50 border border-red-200 rounded-md p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-exclamation-circle text-red-400"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-red-800"><?php echo htmlspecialchars($error_message); ?></p>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Stats Grid -->
        <div class="flex flex-wrap gap-2">
        <!-- Total Users Card -->
        <div class="bg-white overflow-hidden shadow-sm rounded border border-gray-200" style="width: 240px;">
            <div class="p-4">
                <div class="flex flex-col items-center justify-center" style="height: 80px;">
                    <div class="flex items-center mb-2">
                        <div class="w-6 h-6 bg-green-500 rounded flex items-center justify-center mr-2">
                            <i class="fas fa-users text-white text-xs"></i>
                        </div>
                        <dt class="text-xs font-medium text-gray-500"><?php echo t('dashboard.total_users'); ?></dt>
                    </div>
                    <dd class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['total_users']); ?></dd>
                </div>
            </div>
        </div>

        <!-- Store Cards -->
        <?php foreach ($stats['stores'] as $store_stat): ?>
        <div class="bg-white overflow-hidden shadow-sm rounded border border-gray-200" style="width: 240px;">
            <div class="p-4">
                <div class="flex flex-col items-center justify-center" style="height: 80px;">
                    <div class="flex items-center mb-2">
                        <div class="w-6 h-6 bg-blue-500 rounded flex items-center justify-center mr-2">
                            <i class="fas fa-store text-white text-xs"></i>
                        </div>
                        <dt class="text-xs font-medium text-gray-500"><?php echo htmlspecialchars($store_stat['name']); ?></dt>
                    </div>
                    <dd class="text-2xl font-bold text-gray-900"><?php echo number_format($store_stat['user_count']); ?></dd>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Unassigned Users Card -->
        <div class="bg-white overflow-hidden shadow-sm rounded border border-gray-200" style="width: 240px;">
            <div class="p-4">
                <div class="flex flex-col items-center justify-center" style="height: 80px;">
                    <div class="flex items-center mb-2">
                        <div class="w-6 h-6 bg-yellow-500 rounded flex items-center justify-center mr-2">
                            <i class="fas fa-question-circle text-white text-xs"></i>
                        </div>
                        <dt class="text-xs font-medium text-gray-500"><?php echo t('dashboard.unassigned_users'); ?></dt>
                    </div>
                    <dd class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['unassigned_users']); ?></dd>
                </div>
            </div>
        </div>
        </div>

        <!-- Expiration Alerts -->
        <div class="mt-6 flex flex-col md:flex-row gap-6">
            <!-- 임박 상품 -->
            <div class="flex-1 bg-white rounded shadow-sm border border-yellow-200">
                <div class="p-4 border-b border-gray-200 bg-yellow-50 flex items-center justify-between">
                    <h2 class="text-base font-medium text-yellow-800"><i class="fas fa-exclamation-triangle mr-2"></i>유통기한 임박 상품 (30일 이내)</h2>
                    <span class="bg-yellow-100 text-yellow-800 text-xs px-2 py-1 rounded-full"><?php echo count($expiring_products); ?>건</span>
                </div>
                <div class="p-0 max-h-64 overflow-y-auto">
                    <?php if (count($expiring_products) > 0): ?>
                        <ul class="divide-y divide-gray-200">
                            <?php foreach ($expiring_products as $product): 
                                $days_left = (strtotime($product['expiration_date']) - time()) / 86400;
                                $days_left = ceil($days_left);
                            ?>
                            <li class="p-3 hover:bg-gray-50 flex justify-between items-center">
                                <div>
                                    <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($product['name_ko']); ?></p>
                                    <p class="text-xs text-gray-500 font-mono"><?php echo htmlspecialchars($product['sku'] ?? '-'); ?></p>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold text-yellow-600"><?php echo htmlspecialchars($product['expiration_date']); ?></p>
                                    <p class="text-xs text-yellow-500">D-<?php echo $days_left; ?></p>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="p-4 text-center text-gray-500 text-sm">임박한 상품이 없습니다.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 만료 상품 -->
            <div class="flex-1 bg-white rounded shadow-sm border border-red-200">
                <div class="p-4 border-b border-gray-200 bg-red-50 flex items-center justify-between">
                    <h2 class="text-base font-medium text-red-800"><i class="fas fa-times-circle mr-2"></i>유통기한 만료 상품</h2>
                    <span class="bg-red-100 text-red-800 text-xs px-2 py-1 rounded-full"><?php echo count($expired_products); ?>건</span>
                </div>
                <div class="p-0 max-h-64 overflow-y-auto">
                    <?php if (count($expired_products) > 0): ?>
                        <ul class="divide-y divide-gray-200">
                            <?php foreach ($expired_products as $product): ?>
                            <li class="p-3 hover:bg-gray-50 flex justify-between items-center">
                                <div>
                                    <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($product['name_ko']); ?></p>
                                    <p class="text-xs text-gray-500 font-mono"><?php echo htmlspecialchars($product['sku'] ?? '-'); ?></p>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold text-red-600"><?php echo htmlspecialchars($product['expiration_date']); ?></p>
                                    <p class="text-xs text-red-500">만료됨</p>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="p-4 text-center text-gray-500 text-sm">만료된 상품이 없습니다.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="mt-6">
            <h2 class="text-base font-medium text-gray-900 mb-3"><?php echo t('dashboard.quick_actions'); ?></h2>
            <div class="flex flex-wrap gap-3">
            <?php if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'super_admin'): ?>
            <a href="add_user.php" class="relative group bg-white p-3 focus-within:ring-2 focus-within:ring-inset focus-within:ring-primary-500 rounded-lg shadow-sm border border-gray-200 hover:shadow transition-shadow duration-200" style="width: 180px;">
                <div>
                    <span class="rounded inline-flex p-2 bg-primary-50 text-primary-700">
                        <i class="fas fa-user-plus text-sm"></i>
                    </span>
                </div>
                <div class="mt-2">
                    <h3 class="text-sm font-medium">
                        <span class="absolute inset-0" aria-hidden="true"></span>
                        <?php echo t('user.add'); ?>
                    </h3>
                    <p class="mt-1 text-xs text-gray-500 line-clamp-2"><?php echo t('dashboard.add_user_desc'); ?></p>
                </div>
                <span class="pointer-events-none absolute top-3 right-3 text-gray-300 group-hover:text-gray-400" aria-hidden="true">
                    <i class="fas fa-arrow-right text-xs"></i>
                </span>
            </a>
            <?php endif; ?>

            <?php if ($_SESSION['role'] === 'super_admin'): ?>
            <a href="add_store.php" class="relative group bg-white p-3 focus-within:ring-2 focus-within:ring-inset focus-within:ring-primary-500 rounded-lg shadow-sm border border-gray-200 hover:shadow transition-shadow duration-200" style="width: 180px;">
                <div>
                    <span class="rounded inline-flex p-2 bg-green-50 text-green-700">
                        <i class="fas fa-store text-sm"></i>
                    </span>
                </div>
                <div class="mt-2">
                    <h3 class="text-sm font-medium">
                        <span class="absolute inset-0" aria-hidden="true"></span>
                        <?php echo t('store.add'); ?>
                    </h3>
                    <p class="mt-1 text-xs text-gray-500 line-clamp-2"><?php echo t('dashboard.add_store_desc'); ?></p>
                </div>
                <span class="pointer-events-none absolute top-3 right-3 text-gray-300 group-hover:text-gray-400" aria-hidden="true">
                    <i class="fas fa-arrow-right text-xs"></i>
                </span>
            </a>

            <a href="add_brand.php" class="relative group bg-white p-3 focus-within:ring-2 focus-within:ring-inset focus-within:ring-primary-500 rounded-lg shadow-sm border border-gray-200 hover:shadow transition-shadow duration-200" style="width: 180px;">
                <div>
                    <span class="rounded inline-flex p-2 bg-purple-50 text-purple-700">
                        <i class="fas fa-tags text-sm"></i>
                    </span>
                </div>
                <div class="mt-2">
                    <h3 class="text-sm font-medium">
                        <span class="absolute inset-0" aria-hidden="true"></span>
                        <?php echo t('brand.add'); ?>
                    </h3>
                    <p class="mt-1 text-xs text-gray-500 line-clamp-2"><?php echo t('dashboard.add_brand_desc'); ?></p>
                </div>
                <span class="pointer-events-none absolute top-3 right-3 text-gray-300 group-hover:text-gray-400" aria-hidden="true">
                    <i class="fas fa-arrow-right text-xs"></i>
                </span>
            </a>
            <?php endif; ?>

            <a href="add_purchase.php" class="relative group bg-white p-3 focus-within:ring-2 focus-within:ring-inset focus-within:ring-primary-500 rounded-lg shadow-sm border border-gray-200 hover:shadow transition-shadow duration-200" style="width: 180px;">
                <div>
                    <span class="rounded inline-flex p-2 bg-orange-50 text-orange-700">
                        <i class="fas fa-shopping-cart text-sm"></i>
                    </span>
                </div>
                <div class="mt-2">
                    <h3 class="text-sm font-medium">
                        <span class="absolute inset-0" aria-hidden="true"></span>
                        <?php echo t('purchase.add'); ?>
                    </h3>
                    <p class="mt-1 text-xs text-gray-500 line-clamp-2"><?php echo t('dashboard.add_purchase_desc'); ?></p>
                </div>
                <span class="pointer-events-none absolute top-3 right-3 text-gray-300 group-hover:text-gray-400" aria-hidden="true">
                    <i class="fas fa-arrow-right text-xs"></i>
                </span>
            </a>
        </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>