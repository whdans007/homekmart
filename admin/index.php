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
$error_message = '';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. 총 회원 수
    $stats['total_users'] = $pdo->query("SELECT COUNT(id) FROM users")->fetchColumn();

    // 2. 지점별 회원 수
    $stmt_stores = $pdo->query("
        SELECT s.name, COUNT(u.id) as user_count
        FROM stores s
        LEFT JOIN users u ON s.id = u.store_id
        GROUP BY s.id, s.name
        ORDER BY s.name ASC
    ");
    $stats['stores'] = $stmt_stores->fetchAll(PDO::FETCH_ASSOC);

    // 3. 미지정 회원 수
    $stats['unassigned_users'] = $pdo->query("SELECT COUNT(id) FROM users WHERE store_id IS NULL")->fetchColumn();

} catch (PDOException $e) {
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