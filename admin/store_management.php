<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('store.management') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';

// 지점관리 권한 확인
if (!has_permission('store_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
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
    $error_message = t('store.load_error');
}
?>

<!-- Page header -->
<div class="page-header">
    <div class="page-header-content">
        <h1 class="page-title"><?php echo t('store.list'); ?></h1>
        <p class="sub-title"><?php echo t('store.management_desc'); ?></p>
    </div>
    <div class="page-header-actions">
        <a href="add_store.php" class="btn-primary">
            <i class="fas fa-plus mr-2"></i>
            <?php echo t('store.add'); ?>
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
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('store.name'); ?></th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('store.created_at'); ?></th>
                        <th scope="col" class="relative px-6 py-3 border border-gray-300">
                            <span class="sr-only"><?php echo t('common.actions'); ?></span>
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
                                        <i class="fas fa-edit mr-1"></i><?php echo t('common.edit'); ?>
                                    </a>
                                    <a href="delete_store.php?id=<?php echo $store['id']; ?>" 
                                       class="text-red-600 hover:text-red-900 transition-colors duration-200"
                                       onclick="return confirm('<?php echo t('store.confirm_delete'); ?>');">
                                        <i class="fas fa-trash mr-1"></i><?php echo t('common.delete'); ?>
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
                                    <p><?php echo t('store.no_stores'); ?></p>
                                    <a href="add_store.php" class="mt-2 text-primary-600 hover:text-primary-500">
                                        <?php echo t('store.add_first_store'); ?>
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