<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('category.management') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';

// 카테고리 관리 권한 확인
if (!has_permission('category_management') && $_SESSION['role'] !== 'super_admin') {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>" . t('messages.permission_denied') . "</p></div></div></div>";
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

$categories = [];
$error_message = '';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 카테고리 목록을 가져옵니다 (부모 카테고리 이름 포함).
    $stmt = $pdo->query("
        SELECT c.id, c.name, c.parent_id, p.name as parent_name, c.created_at
        FROM categories c
        LEFT JOIN categories p ON c.parent_id = p.id
        ORDER BY c.id DESC
    ");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = t('category.load_error');
}
?>

<div class="w-full px-2 sm:px-3 md:px-4 py-8">

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
        <!-- Categories Table -->
        <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400">
            <div class="px-6 py-4 border-b border-gray-200 bg-white flex justify-between items-center">
                <h3 class="text-lg leading-6 font-semibold text-gray-900">
                    <?php echo t('category.list'); ?> 
                    <span class="text-sm font-normal text-gray-500">(총 <?php echo count($categories); ?>개)</span>
                </h3>
                <a href="add_category.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors duration-200">
                    <i class="fas fa-plus mr-2"></i><?php echo t('category.add'); ?>
                </a>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">ID</th>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('category.name'); ?></th>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('category.parent_category'); ?></th>
                            <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('category.created_at'); ?></th>
                            <th scope="col" class="relative px-6 py-4">
                                <span class="sr-only">작업</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white">
                        <?php foreach ($categories as $category): ?>
                            <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($category['id']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($category['name']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php if ($category['parent_name']): ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">
                                            <?php echo htmlspecialchars($category['parent_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-400 italic"><?php echo t('common.none'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('Y-m-d', strtotime($category['created_at'])); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex space-x-2 justify-end">
                                    <a href="edit_category.php?id=<?php echo $category['id']; ?>" class="text-primary-600 hover:text-primary-900 transition-colors duration-200">
                                        <i class="fas fa-edit mr-1"></i><?php echo t('common.edit'); ?>
                                    </a>
                                    <a href="delete_category.php?id=<?php echo $category['id']; ?>" class="text-red-600 hover:text-red-900 transition-colors duration-200" onclick="return confirm('<?php echo addslashes(t('category.confirm_delete')); ?>');"> 
                                        <i class="fas fa-trash mr-1"></i><?php echo t('common.delete'); ?>
                                    </a>
                                </div>
                            </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($categories)): ?>
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center text-sm text-gray-500">
                                    <div class="flex flex-col items-center">
                                        <i class="fas fa-sitemap text-4xl text-gray-300 mb-4"></i>
                                        <p><?php echo t('category.no_categories'); ?></p>
                                        <a href="add_category.php" class="mt-2 text-primary-600 hover:text-primary-500">
                                            <?php echo t('category.add_first_category'); ?>
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
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>