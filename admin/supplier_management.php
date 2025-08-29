<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('supplier.management') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';

// 공급처 관리 권한 확인
if (!has_permission('supplier_management') && $_SESSION['role'] !== 'super_admin') {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'>
            <div class='flex'>
                <div class='flex-shrink-0'>
                    <i class='fas fa-exclamation-circle text-red-400'></i>
                </div>
                <div class='ml-3'>
                    <p class='text-sm text-red-800'><?php echo t('messages.permission_denied'); ?></p>
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

$suppliers = [];
$error_message = '';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->query("SELECT id, name, phone, memo, created_at FROM suppliers ORDER BY id DESC");
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = t('supplier.load_error') . ": " . $e->getMessage();
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
    <!-- Suppliers Table -->
    <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400">
        <div class="px-6 py-4 border-b border-gray-200 bg-white flex justify-between items-center">
            <h3 class="text-lg leading-6 font-semibold text-gray-900">
                <?php echo t('supplier.list'); ?> 
                <span class="text-sm font-normal text-gray-500">(총 <?php echo count($suppliers); ?>개)</span>
            </h3>
            <a href="add_supplier.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors duration-200">
                <i class="fas fa-plus mr-2"></i><?php echo t('supplier.add'); ?>
            </a>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">ID</th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('supplier.name'); ?></th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('supplier.phone'); ?></th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('supplier.memo'); ?></th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('supplier.created_at'); ?></th>
                        <th scope="col" class="relative px-6 py-4">
                            <span class="sr-only"><?php echo t('common.actions'); ?></span>
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white">
                    <?php foreach ($suppliers as $supplier): ?>
                        <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($supplier['id']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($supplier['name']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($supplier['phone'] ?? '-'); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo nl2br(htmlspecialchars($supplier['memo'] ?? '-')); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('Y-m-d', strtotime($supplier['created_at'])); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex space-x-2">
                                    <a href="edit_supplier.php?id=<?php echo $supplier['id']; ?>" 
                                       class="text-primary-600 hover:text-primary-900 transition-colors duration-200">
                                        <i class="fas fa-edit mr-1"></i><?php echo t('common.edit'); ?>
                                    </a>
                                    <a href="delete_supplier.php?id=<?php echo $supplier['id']; ?>" 
                                       class="text-red-600 hover:text-red-900 transition-colors duration-200"
                                       onclick="return confirm('<?php echo addslashes(t('supplier.confirm_delete')); ?>');"> 
                                        <i class="fas fa-trash mr-1"></i><?php echo t('common.delete'); ?>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($suppliers)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-sm text-gray-500">
                                <div class="flex flex-col items-center">
                                    <i class="fas fa-truck text-4xl text-gray-300 mb-4"></i>
                                    <p><?php echo t('supplier.no_suppliers'); ?></p>
                                    <a href="add_supplier.php" class="mt-2 text-primary-600 hover:text-primary-500">
                                        <?php echo t('supplier.add_first_supplier'); ?>
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