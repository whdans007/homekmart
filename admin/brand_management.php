<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('brand.management') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';

// 브랜드 관리 권한 확인
if (!has_permission('brand_management') && $_SESSION['role'] !== 'super_admin') {
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

$brands = [];
$error_message = '';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->query("SELECT id, name_ko, name_en, logo_url, created_at FROM brands ORDER BY id DESC");
    $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = t('brand.load_error');
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
        <!-- Brands Table -->
        <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400">
            <div class="px-6 py-4 border-b border-gray-200 bg-white flex justify-between items-center">
                <h3 class="text-lg leading-6 font-semibold text-gray-900">
                    <?php echo t('brand.list'); ?> 
                    <span class="text-sm font-normal text-gray-500">(총 <?php echo count($brands); ?>개)</span>
                </h3>
                <a href="add_brand.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors duration-200">
                    <i class="fas fa-plus mr-2"></i><?php echo t('brand.add'); ?>
                </a>
            </div>
                
            <?php if (empty($brands)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-tags text-gray-300 text-6xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2"><?php echo t('brand.no_brands'); ?></h3>
                    <p class="text-gray-500 mb-6">브랜드를 추가하여 제품 관리를 시작하세요.</p>
                    <a href="add_brand.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700">
                        <i class="fas fa-plus mr-2"></i>
                        <?php echo t('brand.add_first_brand'); ?>
                    </a>
                </div>
            <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">ID</th>
                                    <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('brand.logo'); ?></th>
                                    <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('brand.name_ko'); ?></th>
                                    <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('brand.name_en'); ?></th>
                                    <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('brand.created_at'); ?></th>
                                    <th scope="col" class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('common.actions'); ?></th>
                                </tr>
                            </thead>
                            <tbody class="bg-white">
                                <?php foreach ($brands as $brand): ?>
                                    <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($brand['id']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if (!empty($brand['logo_url'])): ?>
                                                <img src="<?php echo htmlspecialchars($brand['logo_url']); ?>" 
                                                     alt="<?php echo htmlspecialchars($brand['name_ko']); ?>" 
                                                     class="h-10 w-10 rounded-lg object-cover">
                                            <?php else: ?>
                                                <div class="h-10 w-10 rounded-lg bg-gray-200 flex items-center justify-center">
                                                    <i class="fas fa-image text-gray-400"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($brand['name_ko']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($brand['name_en'] ?? '-'); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('Y-m-d', strtotime($brand['created_at'])); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                                            <div class="flex justify-center space-x-1">
                                                <a href="edit_brand.php?id=<?php echo $brand['id']; ?>" 
                                                   class="text-blue-600 hover:text-blue-900" title="수정">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="delete_brand.php?id=<?php echo $brand['id']; ?>" 
                                                   class="text-red-600 hover:text-red-900" title="삭제"
                                                   onclick="return confirm('<?php echo addslashes(t('brand.confirm_delete')); ?>');"> 
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>