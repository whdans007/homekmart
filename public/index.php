<?php
$page_title = "대시보드 - HOME K MART";
require_once __DIR__ . '/partials/header.php';
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
    $error_message = "통계 데이터를 불러오는 데 실패했습니다: " . $e->getMessage();
}
?>

<!-- Page header -->
<div class="mb-8">
    <h1 class="text-3xl font-bold text-gray-900">대시보드</h1>
    <p class="mt-2 text-sm text-gray-600">HOME K MART 관리 시스템에 오신 것을 환영합니다.</p>
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
    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        <!-- Total Users Card -->
        <div class="bg-white overflow-hidden shadow rounded-lg">
            <div class="p-5">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-green-500 rounded-md flex items-center justify-center">
                            <i class="fas fa-users text-white text-sm"></i>
                        </div>
                    </div>
                    <div class="ml-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-gray-500 truncate">총 회원 수</dt>
                            <dd class="text-lg font-medium text-gray-900"><?php echo number_format($stats['total_users']); ?></dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <!-- Store Cards -->
        <?php foreach ($stats['stores'] as $store_stat): ?>
        <div class="bg-white overflow-hidden shadow rounded-lg">
            <div class="p-5">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-blue-500 rounded-md flex items-center justify-center">
                            <i class="fas fa-store text-white text-sm"></i>
                        </div>
                    </div>
                    <div class="ml-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-gray-500 truncate"><?php echo htmlspecialchars($store_stat['name']); ?></dt>
                            <dd class="text-lg font-medium text-gray-900"><?php echo number_format($store_stat['user_count']); ?></dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Unassigned Users Card -->
        <div class="bg-white overflow-hidden shadow rounded-lg">
            <div class="p-5">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-yellow-500 rounded-md flex items-center justify-center">
                            <i class="fas fa-question-circle text-white text-sm"></i>
                        </div>
                    </div>
                    <div class="ml-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-gray-500 truncate">미지정 회원</dt>
                            <dd class="text-lg font-medium text-gray-900"><?php echo number_format($stats['unassigned_users']); ?></dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="mt-8">
        <h2 class="text-lg font-medium text-gray-900 mb-4">빠른 작업</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <?php if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'super_admin'): ?>
            <a href="add_user.php" class="relative group bg-white p-6 focus-within:ring-2 focus-within:ring-inset focus-within:ring-primary-500 rounded-lg shadow hover:shadow-md transition-shadow duration-200">
                <div>
                    <span class="rounded-lg inline-flex p-3 bg-primary-50 text-primary-700 ring-4 ring-white">
                        <i class="fas fa-user-plus text-lg"></i>
                    </span>
                </div>
                <div class="mt-4">
                    <h3 class="text-lg font-medium">
                        <span class="absolute inset-0" aria-hidden="true"></span>
                        회원 추가
                    </h3>
                    <p class="mt-2 text-sm text-gray-500">새로운 회원을 시스템에 등록합니다.</p>
                </div>
                <span class="pointer-events-none absolute top-6 right-6 text-gray-300 group-hover:text-gray-400" aria-hidden="true">
                    <i class="fas fa-arrow-right"></i>
                </span>
            </a>
            <?php endif; ?>

            <?php if ($_SESSION['role'] === 'super_admin'): ?>
            <a href="add_store.php" class="relative group bg-white p-6 focus-within:ring-2 focus-within:ring-inset focus-within:ring-primary-500 rounded-lg shadow hover:shadow-md transition-shadow duration-200">
                <div>
                    <span class="rounded-lg inline-flex p-3 bg-green-50 text-green-700 ring-4 ring-white">
                        <i class="fas fa-store text-lg"></i>
                    </span>
                </div>
                <div class="mt-4">
                    <h3 class="text-lg font-medium">
                        <span class="absolute inset-0" aria-hidden="true"></span>
                        지점 추가
                    </h3>
                    <p class="mt-2 text-sm text-gray-500">새로운 지점을 등록합니다.</p>
                </div>
                <span class="pointer-events-none absolute top-6 right-6 text-gray-300 group-hover:text-gray-400" aria-hidden="true">
                    <i class="fas fa-arrow-right"></i>
                </span>
            </a>

            <a href="add_brand.php" class="relative group bg-white p-6 focus-within:ring-2 focus-within:ring-inset focus-within:ring-primary-500 rounded-lg shadow hover:shadow-md transition-shadow duration-200">
                <div>
                    <span class="rounded-lg inline-flex p-3 bg-purple-50 text-purple-700 ring-4 ring-white">
                        <i class="fas fa-tags text-lg"></i>
                    </span>
                </div>
                <div class="mt-4">
                    <h3 class="text-lg font-medium">
                        <span class="absolute inset-0" aria-hidden="true"></span>
                        브랜드 추가
                    </h3>
                    <p class="mt-2 text-sm text-gray-500">새로운 브랜드를 등록합니다.</p>
                </div>
                <span class="pointer-events-none absolute top-6 right-6 text-gray-300 group-hover:text-gray-400" aria-hidden="true">
                    <i class="fas fa-arrow-right"></i>
                </span>
            </a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>