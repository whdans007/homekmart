<?php
$page_title = "지점 관리 - HOME K MART";
require_once __DIR__ . '/partials/header.php';

// 총괄관리자만 접근 가능
if ($_SESSION['role'] !== 'super_admin') {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'>
            <div class='flex'>
                <div class='flex-shrink-0'>
                    <i class='fas fa-exclamation-circle text-red-400'></i>
                </div>
                <div class='ml-3'>
                    <p class='text-sm text-red-800'>이 페이지에 접근할 권한이 없습니다.</p>
                </div>
            </div>
          </div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

// Flash message system
$flash = null;
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

require_once __DIR__ . '/../config/db_config.php';

$stores = [];
$error_message = '';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->query("SELECT id, name, created_at FROM stores ORDER BY id DESC");
    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = "데이터베이스에서 지점 목록을 불러오는 데 실패했습니다.";
}
?>

<!-- Page header -->
<div class="mb-8 sm:flex sm:items-center sm:justify-between">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">지점 목록</h1>
        <p class="mt-2 text-sm text-gray-700">시스템에 등록된 모든 지점을 관리합니다.</p>
    </div>
    <div class="mt-4 sm:mt-0">
        <a href="add_store.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors duration-200">
            <i class="fas fa-plus mr-2"></i>
            지점 추가
        </a>
    </div>
</div>

<?php if ($flash): ?>
    <div class="mb-6 <?php echo $flash['type'] === 'success' ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200'; ?> rounded-md p-4">
        <div class="flex">
            <div class="flex-shrink-0">
                <i class="fas <?php echo $flash['type'] === 'success' ? 'fa-check-circle text-green-400' : 'fa-exclamation-circle text-red-400'; ?>"></i>
            </div>
            <div class="ml-3">
                <p class="text-sm <?php echo $flash['type'] === 'success' ? 'text-green-800' : 'text-red-800'; ?>"><?php echo htmlspecialchars($flash['message']); ?></p>
            </div>
        </div>
    </div>
<?php endif; ?>

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
    <!-- Stores Table -->
    <div class="bg-white shadow overflow-hidden sm:rounded-md border border-gray-300">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 border-collapse border border-gray-300">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">ID</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">지점명</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">생성일</th>
                        <th scope="col" class="relative px-6 py-3 border border-gray-300">
                            <span class="sr-only">작업</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($stores as $store): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 border border-gray-300"><?php echo htmlspecialchars($store['id']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 border border-gray-300"><?php echo htmlspecialchars($store['name']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 border border-gray-300"><?php echo date('Y-m-d', strtotime($store['created_at'])); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium border border-gray-300">
                                <div class="flex space-x-2">
                                    <a href="edit_store.php?id=<?php echo $store['id']; ?>" 
                                       class="text-primary-600 hover:text-primary-900 transition-colors duration-200">
                                        <i class="fas fa-edit mr-1"></i>수정
                                    </a>
                                    <a href="delete_store.php?id=<?php echo $store['id']; ?>" 
                                       class="text-red-600 hover:text-red-900 transition-colors duration-200"
                                       onclick="return confirm('정말로 이 지점을 삭제하시겠습니까? 지점 삭제 시, 해당 지점에 소속된 회원들의 소속 정보가 \'미지정\'으로 변경됩니다.');">
                                        <i class="fas fa-trash mr-1"></i>삭제
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($stores)): ?>
                        <tr>
                            <td colspan="4" class="px-6 py-12 text-center text-sm text-gray-500 border border-gray-300">
                                <div class="flex flex-col items-center">
                                    <i class="fas fa-store text-4xl text-gray-300 mb-4"></i>
                                    <p>등록된 지점이 없습니다.</p>
                                    <a href="add_store.php" class="mt-2 text-primary-600 hover:text-primary-500">
                                        첫 번째 지점을 추가해보세요
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>