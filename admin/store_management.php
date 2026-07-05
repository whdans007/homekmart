<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('store.management') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/system_header.php';

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

    $stmt = $pdo->query("SELECT id, name, company_name, created_at FROM stores ORDER BY id DESC");
    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = t('store.load_error');
}
?>

<div class="w-full px-2 sm:px-3 md:px-4 py-8">

<?php if ($flash): ?>
    <div class="<?php echo $flash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>">
        <div class="alert-content">
            <div class="alert-icon-wrapper">
                <i class="fas <?php echo $flash['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> alert-icon"></i>
            </div>
            <div class="alert-message">
                <p class="alert-text"><?php echo htmlspecialchars($flash['message']); ?></p>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($error_message): ?>
    <div class="alert-error">
        <div class="alert-content">
            <div class="alert-icon-wrapper">
                <i class="fas fa-exclamation-circle alert-icon"></i>
            </div>
            <div class="alert-message">
                <p class="alert-text"><?php echo htmlspecialchars($error_message); ?></p>
            </div>
        </div>
    </div>
<?php else: ?>
    <!-- Stores Table -->
    <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400">
        <!-- 테이블 헤더 -->
        <div class="px-6 py-4 border-b border-gray-200 bg-white flex justify-between items-center">
            <h3 class="text-lg leading-6 font-semibold text-gray-900">
                <?php echo t('store.list'); ?> <span class="text-sm font-normal text-gray-500">(총 <?php echo count($stores); ?>건)</span>
            </h3>
            <div class="flex space-x-3">
                <a href="add_store.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <i class="fas fa-plus mr-2"></i>
                    <?php echo t('store.add'); ?>
                </a>
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">ID</th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('store.name'); ?></th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('store.company_name'); ?></th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('store.created_at'); ?></th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                            <?php echo t('common.actions'); ?>
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white">
                    <?php foreach ($stores as $store): ?>
                        <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150 cursor-pointer" onclick="window.location.href='edit_store.php?id=<?php echo $store['id']; ?>'">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($store['id']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($store['name']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $store['company_name'] !== null && $store['company_name'] !== '' ? htmlspecialchars($store['company_name']) : '<span class="text-gray-400">-</span>'; ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('Y-m-d', strtotime($store['created_at'])); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex space-x-2">
                                    <a href="edit_store.php?id=<?php echo $store['id']; ?>" 
                                       class="text-green-600 hover:text-green-900" onclick="event.stopPropagation();" title="<?php echo t('common.edit'); ?>">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="delete_store.php?id=<?php echo $store['id']; ?>" 
                                       class="text-red-600 hover:text-red-900" onclick="event.stopPropagation(); return confirm('<?php echo t('store.confirm_delete'); ?>');" title="<?php echo t('common.delete'); ?>">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($stores)): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center">
                                <i class="fas fa-store text-gray-400 text-4xl mb-4"></i>
                                <h3 class="text-lg font-medium text-gray-900 mb-2"><?php echo t('store.no_stores'); ?></h3>
                                <p class="text-gray-600 mb-4">새로운 매장을 등록하여 시작하세요.</p>
                                <a href="add_store.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700">
                                    <i class="fas fa-plus mr-2"></i>
                                    <?php echo t('store.add_first_store'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

</div>

<?php require_once __DIR__ . '/partials/system_footer.php'; ?>