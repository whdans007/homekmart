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
require_once __DIR__ . '/../mall/lib/csrf.php';

$categories = [];
$error_message = '';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 카테고리 목록을 가져옵니다 (부모 카테고리 이름 포함).
    $stmt = $pdo->query("
        SELECT c.id, c.name, c.name_en, c.parent_id, c.image_url, c.sort_order,
               p.name as parent_name, p.name_en as parent_name_en, c.created_at,
               (SELECT COUNT(DISTINCT mp.id) FROM products pr JOIN mall_products mp ON mp.product_id = pr.id
                WHERE pr.category_id = c.id OR pr.category_id IN (SELECT sub.id FROM categories sub WHERE sub.parent_id = c.id)) +
               (SELECT COUNT(DISTINCT mf.id) FROM mall_fresh_products mf
                WHERE mf.category_id = c.id OR mf.category_id IN (SELECT sub.id FROM categories sub WHERE sub.parent_id = c.id)) AS product_count
        FROM categories c
        LEFT JOIN categories p ON c.parent_id = p.id
        ORDER BY COALESCE(c.parent_id, c.id), (c.parent_id IS NOT NULL), c.sort_order, c.name
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
                <div class="flex items-center gap-2">
                    <button type="button" onclick="openCategoryManageModal()" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"><i class="fas fa-sitemap mr-2"></i>Edit Categories</button>
                    <button onclick="openAddModal()" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors duration-200"><i class="fas fa-plus mr-2"></i><?php echo t('category.add'); ?></button>
                </div>
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
                            <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150 <?php echo $category['parent_id'] ? 'bg-gray-50/70' : 'bg-white'; ?>">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($category['id']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <div class="flex flex-col <?php echo $category['parent_id'] ? 'pl-7 relative' : ''; ?>">
                                        <?php if ($category['parent_id']): ?><span class="absolute left-2 text-gray-300">└</span><?php endif; ?>
                                        <span class="font-medium text-gray-900"><?php echo htmlspecialchars($category['name']); ?></span>
                                        <?php if (!empty($category['name_en'])): ?>
                                            <span class="text-xs text-gray-500"><?php echo htmlspecialchars($category['name_en']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php if ($category['parent_name']): ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">
                                            <div class="flex flex-col">
                                                <span><?php echo htmlspecialchars($category['parent_name']); ?></span>
                                                <?php if (!empty($category['parent_name_en'])): ?>
                                                    <span class="text-xs opacity-75"><?php echo htmlspecialchars($category['parent_name_en']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-400 italic"><?php echo t('common.none'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('Y-m-d', strtotime($category['created_at'])); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex space-x-2 justify-end">
                                    <button onclick="openEditModal(<?php echo $category['id']; ?>, '<?php echo addslashes($category['name']); ?>', '<?php echo addslashes($category['name_en'] ?? ''); ?>', <?php echo $category['parent_id'] ? $category['parent_id'] : 'null'; ?>)" class="text-primary-600 hover:text-primary-900 transition-colors duration-200">
                                        <i class="fas fa-edit mr-1"></i><?php echo t('common.edit'); ?>
                                    </button>
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

<!-- 카테고리 추가 모달 -->
<div id="flash-area"></div>
<div id="category-manage-modal" class="fixed inset-0 bg-gray-900 bg-opacity-50 hidden z-50 items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl max-h-[90vh] flex flex-col">
            <div class="flex items-center justify-between p-4 border-b border-gray-100">
                <h3 class="text-sm font-bold text-gray-800"><i class="fas fa-sitemap mr-1.5"></i><?php echo t('mall_admin.products.manage_categories'); ?></h3>
                <button type="button" id="close-category-manage-btn" class="text-gray-400 hover:text-gray-700"><i class="fas fa-xmark"></i></button>
            </div>
            <div class="p-4 overflow-y-auto flex-1">
                <ul id="modal-category-list" class="space-y-1">
                    <?php foreach ($categories as $cat): if (!empty($cat['parent_id'])) continue; ?>
                    <li class="category-item border border-gray-100 rounded-md" data-category-id="<?php echo (int)$cat['id']; ?>" draggable="true">
                        <div class="flex items-center gap-1 px-2 py-1.5">
                            <i class="fas fa-grip-vertical text-gray-300 cursor-grab" title="<?php echo htmlspecialchars(t('mall_admin.products.drag_to_reorder')); ?>"></i>
                            <button type="button" class="toggle-sub-btn text-gray-400 hover:text-gray-700 px-1" title="<?php echo htmlspecialchars(t('mall_admin.products.toggle_subcategory_title')); ?>">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                            <input type="text" class="edit-category-name border border-gray-300 rounded px-1.5 py-1 text-xs w-40 flex-shrink-0" data-category-id="<?php echo (int)$cat['id']; ?>" value="<?php echo htmlspecialchars($cat['name']); ?>">
                            <button type="button" class="translate-category-btn text-gray-400 hover:text-blue-600 px-1 flex-shrink-0" title="<?php echo htmlspecialchars(t('mall_admin.products.translate_category_name')); ?>"><i class="fas fa-language"></i></button>
                            <input type="text" class="edit-category-name-en border border-gray-300 rounded px-1.5 py-1 text-xs flex-1 min-w-0" value="<?php echo htmlspecialchars($cat['name_en'] ?? ''); ?>" placeholder="EN">
                            <span class="text-gray-400 text-[10px] flex-shrink-0">(<?php echo (int)$cat['product_count']; ?>)</span>
                            <button type="button" class="delete-category-btn text-gray-300 hover:text-red-500 px-1" data-category-id="<?php echo (int)$cat['id']; ?>" data-category-name="<?php echo htmlspecialchars($cat['name']); ?>" title="<?php echo htmlspecialchars(t('common.delete')); ?>"><i class="fas fa-xmark"></i></button>
                        </div>
                        <div class="category-image-controls flex items-center gap-2 px-8 pb-2" data-category-id="<?php echo (int)$cat['id']; ?>">
                            <div class="category-image-preview w-10 h-10 rounded border border-gray-200 overflow-hidden flex items-center justify-center bg-gray-50 flex-shrink-0">
                                <?php if (!empty($cat['image_url'])): ?><img src="/mall/<?php echo htmlspecialchars($cat['image_url']); ?>" alt="" class="w-full h-full object-cover"><?php else: ?><i class="fas fa-image text-gray-300"></i><?php endif; ?>
                            </div>
                            <label class="category-image-upload-btn px-2 py-1 text-[11px] font-semibold bg-gray-100 text-gray-600 rounded cursor-pointer hover:bg-gray-200">
                                <?php echo t('mall_admin.products.upload_category_image'); ?>
                                <input type="file" class="category-image-input hidden" accept="image/jpeg,image/png,image/webp,image/avif">
                            </label>
                            <button type="button" class="search-category-photo-btn px-2 py-1 text-[11px] font-semibold bg-gray-100 text-gray-600 rounded hover:bg-gray-200" title="<?php echo htmlspecialchars(t('mall_admin.products.search_category_photo_title')); ?>"><i class="fas fa-magnifying-glass mr-1"></i><?php echo t('mall_admin.products.search_photo'); ?></button>
                            <button type="button" class="remove-category-image-btn text-[11px] text-red-500 hover:text-red-700<?php echo empty($cat['image_url']) ? ' hidden' : ''; ?>"><?php echo t('mall_admin.products.remove_category_image'); ?></button>
                        </div>
                        <div class="subcategory-panel hidden pl-6 pr-2 pb-2">
                            <ul class="subcategory-list space-y-1 mb-2" data-parent-id="<?php echo (int)$cat['id']; ?>">
                                <?php foreach ($categories as $sub): if ((int)$sub['parent_id'] !== (int)$cat['id']) continue; ?>
                                <li class="category-item flex items-center gap-1" data-category-id="<?php echo (int)$sub['id']; ?>" draggable="true">
                                    <i class="fas fa-grip-vertical text-gray-300 cursor-grab" title="<?php echo htmlspecialchars(t('mall_admin.products.drag_to_reorder')); ?>"></i>
                                    <input type="text" class="edit-category-name border border-gray-300 rounded px-1.5 py-1 text-xs w-40 flex-shrink-0" data-category-id="<?php echo (int)$sub['id']; ?>" value="<?php echo htmlspecialchars($sub['name']); ?>">
                                    <button type="button" class="translate-category-btn text-gray-400 hover:text-blue-600 px-1 flex-shrink-0" title="<?php echo htmlspecialchars(t('mall_admin.products.translate_category_name')); ?>"><i class="fas fa-language"></i></button>
                                    <input type="text" class="edit-category-name-en border border-gray-300 rounded px-1.5 py-1 text-xs flex-1 min-w-0" value="<?php echo htmlspecialchars($sub['name_en'] ?? ''); ?>" placeholder="EN">
                                    <span class="text-gray-400 text-[10px] flex-shrink-0">(<?php echo (int)$sub['product_count']; ?>)</span>
                                    <button type="button" class="delete-category-btn text-gray-300 hover:text-red-500 px-1" data-category-id="<?php echo (int)$sub['id']; ?>" data-category-name="<?php echo htmlspecialchars($sub['name']); ?>" title="<?php echo htmlspecialchars(t('common.delete')); ?>"><i class="fas fa-xmark"></i></button>
                                </li>
                                <?php endforeach; ?>
                                <?php if (empty(array_filter($categories, fn($row) => (int)$row['parent_id'] === (int)$cat['id']))): ?>
                                <li class="text-[11px] text-gray-300"><?php echo t('mall_admin.products.no_subcategories'); ?></li>
                                <?php endif; ?>
                            </ul>
                            <form class="add-subcategory-form flex gap-1" data-parent-id="<?php echo (int)$cat['id']; ?>">
                                <input type="text" name="name" placeholder="<?php echo htmlspecialchars(t('mall_admin.products.subcategory_name_placeholder')); ?>" required class="border border-gray-300 rounded px-2 py-1 text-xs w-40 flex-shrink-0">
                                <button type="button" class="translate-category-btn text-gray-400 hover:text-blue-600 px-1 flex-shrink-0" title="<?php echo htmlspecialchars(t('mall_admin.products.translate_category_name')); ?>"><i class="fas fa-language"></i></button>
                                <input type="text" name="name_en" placeholder="EN" class="border border-gray-300 rounded px-2 py-1 text-xs flex-1 min-w-0">
                                <button type="submit" class="px-2 py-1 text-xs font-semibold bg-gray-500 text-white rounded flex-shrink-0"><?php echo t('common.add'); ?></button>
                            </form>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="p-4 border-t border-gray-100 space-y-2">
                <p class="text-[11px] text-gray-400"><?php echo t('mall_admin.products.new_badge_hint'); ?></p>
                <button type="button" id="apply-category-edits-btn" class="w-full px-3 py-1.5 text-xs font-semibold bg-blue-600 text-white rounded-md">
                    <i class="fas fa-check mr-1"></i><?php echo t('mall_admin.products.apply_all_changes'); ?>
                </button>
                <form id="add-category-form" class="flex gap-1">
                    <input type="text" name="name" placeholder="<?php echo htmlspecialchars(t('mall_admin.products.new_category_name_placeholder')); ?>" required class="border border-gray-300 rounded-md px-2 py-1 text-xs w-40 flex-shrink-0">
                    <button type="button" class="translate-category-btn text-gray-400 hover:text-blue-600 px-1 flex-shrink-0" title="<?php echo htmlspecialchars(t('mall_admin.products.translate_category_name')); ?>"><i class="fas fa-language"></i></button>
                    <input type="text" name="name_en" placeholder="EN" class="border border-gray-300 rounded-md px-2 py-1 text-xs flex-1 min-w-0">
                    <button type="submit" class="px-3 py-1 text-xs font-semibold bg-blue-600 text-white rounded-md flex-shrink-0 hover:bg-blue-700"><?php echo t('common.add'); ?></button>
                </form>
            </div>
        </div>
    </div>

    <div id="addCategoryModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 max-h-screen overflow-y-auto">
        <!-- 모달 헤더 -->
        <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <h3 class="text-lg leading-6 font-semibold text-gray-900"><?php echo t('category.new_category'); ?></h3>
                <button onclick="closeAddModal()" class="text-gray-400 hover:text-gray-600 transition-colors duration-200">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>
        
        <!-- 모달 내용 -->
        <div class="px-6 py-4">
            
            <form id="addCategoryForm" class="space-y-4">
                <div id="addModalErrors" class="bg-red-50 border border-red-200 rounded-md p-3 hidden">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-circle text-red-400"></i>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-red-800">오류가 발생했습니다</h3>
                            <div class="mt-1 text-sm text-red-700">
                                <ul id="addErrorList" role="list" class="list-disc pl-5 space-y-1"></ul>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <div>
                        <label for="add_name" class="block text-sm font-medium text-gray-700">카테고리명 (한글) *</label>
                        <input type="text" id="add_name" name="name" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" required placeholder="<?php echo t('category.name'); ?>">
                    </div>
                    <div>
                        <label for="add_name_en" class="block text-sm font-medium text-gray-700">카테고리명 (영문)</label>
                        <input type="text" id="add_name_en" name="name_en" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" placeholder="선택사항">
                    </div>
                    <div>
                        <label for="add_parent_id" class="block text-sm font-medium text-gray-700"><?php echo t('category.parent_category'); ?></label>
                        <select id="add_parent_id" name="parent_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                            <option value="">-- <?php echo t('category.no_parent'); ?> --</option>
                            <?php foreach ($categories as $cat): if (!empty($cat['parent_id'])) continue; ?>
                                <option value="<?php echo $cat['id']; ?>">
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                    <?php if (!empty($cat['name_en'])): ?>
                                        (<?php echo htmlspecialchars($cat['name_en']); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- 모달 푸터 -->
        <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 rounded-b-lg flex justify-end gap-x-3">
            <button type="button" onclick="closeAddModal()" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                취소
            </button>
            <button type="submit" form="addCategoryForm" class="px-4 py-2 text-sm font-medium text-white bg-primary-600 border border-transparent rounded-md hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                <i class="fas fa-plus mr-2"></i>추가
            </button>
        </div>
        </div>
    </div>
</div>

<!-- 카테고리 수정 모달 -->
<div id="editCategoryModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 max-h-screen overflow-y-auto">
        <!-- 모달 헤더 -->
        <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <h3 class="text-lg leading-6 font-semibold text-gray-900"><?php echo t('common.edit'); ?> <?php echo t('category.name'); ?></h3>
                <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600 transition-colors duration-200">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>
        
        <!-- 모달 내용 -->
        <div class="px-6 py-4">
            
            <form id="editCategoryForm" class="space-y-4">
                <input type="hidden" id="edit_category_id" name="category_id">
                
                <div id="editModalErrors" class="bg-red-50 border border-red-200 rounded-md p-3 hidden">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-circle text-red-400"></i>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-red-800">오류가 발생했습니다</h3>
                            <div class="mt-1 text-sm text-red-700">
                                <ul id="editErrorList" role="list" class="list-disc pl-5 space-y-1"></ul>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <div>
                        <label for="edit_name" class="block text-sm font-medium text-gray-700">카테고리명 (한글) *</label>
                        <input type="text" id="edit_name" name="name" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" required placeholder="<?php echo t('category.name'); ?>">
                    </div>
                    <div>
                        <label for="edit_name_en" class="block text-sm font-medium text-gray-700">카테고리명 (영문)</label>
                        <input type="text" id="edit_name_en" name="name_en" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" placeholder="선택사항">
                    </div>
                    <div>
                        <label for="edit_parent_id" class="block text-sm font-medium text-gray-700"><?php echo t('category.parent_category'); ?></label>
                        <select id="edit_parent_id" name="parent_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                            <option value="">-- <?php echo t('category.no_parent'); ?> --</option>
                            <!-- 동적으로 채워질 예정 -->
                        </select>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- 모달 푸터 -->
        <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 rounded-b-lg flex justify-end gap-x-3">
            <button type="button" onclick="closeEditModal()" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                취소
            </button>
            <button type="submit" form="editCategoryForm" class="px-4 py-2 text-sm font-medium text-white bg-primary-600 border border-transparent rounded-md hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                <i class="fas fa-save mr-2"></i>저장
            </button>
        </div>
        </div>
    </div>
</div>

<script>
window.MALL_CSRF_TOKEN = '<?php echo mall_csrf_token(); ?>';
function showFlash(message, type, duration) {
    const area = document.getElementById('flash-area');
    const color = type === 'error' ? 'bg-red-100 text-red-700 border-red-300' : 'bg-green-100 text-green-700 border-green-300';
    area.innerHTML = '<div class="fixed top-4 right-4 z-[70] max-w-lg px-3 py-2 text-xs rounded border shadow ' + color + '">' + message + '</div>';
    setTimeout(() => { area.innerHTML = ''; }, duration || 4000);
}
function openCategoryManageModal() {
    const modal = document.getElementById('category-manage-modal');
    modal.classList.remove('hidden'); modal.classList.add('flex');
}
function closeCategoryManageModal() {
    const modal = document.getElementById('category-manage-modal');
    modal.classList.add('hidden'); modal.classList.remove('flex');
}
document.getElementById('close-category-manage-btn').addEventListener('click', closeCategoryManageModal);
document.getElementById('category-manage-modal').addEventListener('click', function (e) { if (e.target === this) closeCategoryManageModal(); });

function addPendingCategoryRow(name, nameEn, parentId, targetList) {
    const li = document.createElement('li');
    li.className = 'pending-category-item category-item flex items-center gap-1' + (parentId ? '' : ' border border-dashed border-blue-300 rounded-md px-2 py-1.5');
    li.dataset.parentId = parentId || '';
    li.innerHTML =
        '<span class="text-blue-500 text-[10px] font-bold flex-shrink-0" title="<?php echo addslashes(t('mall_admin.products.not_saved_yet')); ?>">NEW</span>' +
        '<input type="text" class="pending-name border border-blue-300 rounded px-1.5 py-1 text-xs w-40 flex-shrink-0" value="' + escHtml(name) + '">' +
        '<button type="button" class="translate-category-btn text-gray-400 hover:text-blue-600 px-1 flex-shrink-0" title="<?php echo addslashes(t('mall_admin.products.translate_category_name')); ?>"><i class="fas fa-language"></i></button>' +
        '<input type="text" class="pending-name-en border border-blue-300 rounded px-1.5 py-1 text-xs flex-1 min-w-0" value="' + escHtml(nameEn) + '" placeholder="EN">' +
        '<button type="button" class="remove-pending-btn text-gray-300 hover:text-red-500 px-1" title="<?php echo addslashes(t('common.cancel')); ?>"><i class="fas fa-xmark"></i></button>';
    li.querySelector('.remove-pending-btn').addEventListener('click', function () { li.remove(); });
    targetList.appendChild(li);
}

const addCategoryForm = document.getElementById('add-category-form');
if (addCategoryForm) {
    addCategoryForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const name = addCategoryForm.name.value.trim();
        if (!name) return;
        addPendingCategoryRow(name, addCategoryForm.name_en.value.trim(), null, document.getElementById('modal-category-list'));
        addCategoryForm.reset();
        addCategoryForm.name.focus();
    });
}

document.querySelectorAll('.add-subcategory-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const name = form.name.value.trim();
        if (!name) return;
        const targetList = form.closest('.subcategory-panel').querySelector('.subcategory-list');
        addPendingCategoryRow(name, form.name_en.value.trim(), form.dataset.parentId, targetList);
        form.reset();
        form.name.focus();
    });
});

// 카테고리 관리 모달 열기/닫기 + 소분류 펼치기/접기
const categoryManageModal = document.getElementById('category-manage-modal');
const openCategoryManageBtn = document.getElementById('open-category-manage-btn');
const closeCategoryManageBtn = document.getElementById('close-category-manage-btn');
if (openCategoryManageBtn && categoryManageModal) {
    openCategoryManageBtn.addEventListener('click', function () {
        categoryManageModal.classList.remove('hidden');
        categoryManageModal.classList.add('flex');
    });
}
if (closeCategoryManageBtn && categoryManageModal) {
    closeCategoryManageBtn.addEventListener('click', function () {
        categoryManageModal.classList.add('hidden');
        categoryManageModal.classList.remove('flex');
    });
    categoryManageModal.addEventListener('click', function (e) {
        if (e.target === categoryManageModal) { closeCategoryManageBtn.click(); }
    });
}
document.querySelectorAll('.toggle-sub-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const panel = btn.closest('.category-item').querySelector('.subcategory-panel');
        panel.classList.toggle('hidden');
        btn.querySelector('i').classList.toggle('fa-chevron-right');
        btn.querySelector('i').classList.toggle('fa-chevron-down');
    });
});

// 정적 행과 아직 저장되지 않은 NEW 행 모두 현재 입력값을 기준으로 번역한다.
if (categoryManageModal) {
    categoryManageModal.addEventListener('click', function (e) {
        const btn = e.target.closest('.translate-category-btn');
        if (!btn || !categoryManageModal.contains(btn)) return;

        const container = btn.closest('.category-item, form');
        const nameInput = container?.querySelector('.edit-category-name, .pending-name, [name="name"]');
        const nameEnInput = container?.querySelector('.edit-category-name-en, .pending-name-en, [name="name_en"]');
        const text = nameInput?.value.trim() || '';
        if (!text || !nameEnInput) return;

        const params = new URLSearchParams();
        params.set('text', text);
        params.set('csrf_token', window.MALL_CSRF_TOKEN || '');
        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.classList.add('opacity-50');
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

        fetch('../mall/admin/ajax/translate_text.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: params.toString()
        })
            .then(response => response.json())
            .then(data => {
                if (!data.success || !data.data?.translated) {
                    throw new Error(data.error?.message || '<?php echo addslashes(t('mall_admin.products.category_translation_failed')); ?>');
                }
                nameEnInput.value = data.data.translated;
            })
            .catch(error => showFlash(error.message || '<?php echo addslashes(t('mall_admin.products.category_translation_failed')); ?>', 'error'))
            .finally(() => {
                btn.disabled = false;
                btn.classList.remove('opacity-50');
                btn.innerHTML = originalHtml;
            });
    });
}

document.querySelectorAll('.search-category-photo-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const categoryItem = btn.closest('.category-item');
        const nameEn = categoryItem?.querySelector('.edit-category-name-en')?.value.trim() || '';
        if (!nameEn) return;
        const query = encodeURIComponent(nameEn + ' 이모티콘 png');
        window.open('https://www.google.com/search?tbm=isch&q=' + query, '_blank');
    });
});

// 대분류 이미지는 선택 즉시 업로드하고 DB에 반영한다. 소분류에는 이 컨트롤을 렌더링하지 않는다.
document.querySelectorAll('.category-image-input').forEach(function (input) {
    input.addEventListener('change', function () {
        const file = input.files[0];
        if (!file) return;
        const controls = input.closest('.category-image-controls');
        const uploadLabel = input.closest('.category-image-upload-btn');
        const formData = new FormData();
        formData.append('action', 'upload_image');
        formData.append('category_id', controls.dataset.categoryId);
        formData.append('image', file);
        formData.append('csrf_token', window.MALL_CSRF_TOKEN);
        input.disabled = true;
        uploadLabel.classList.add('opacity-50');
        fetch('../mall/admin/ajax/save_category.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.products.category_image_upload_failed')); ?>', 'error');
                    return;
                }
                controls.querySelector('.category-image-preview').innerHTML = '<img src="/mall/' + escHtml(data.data.image_url) + '" alt="" class="w-full h-full object-cover">';
                controls.querySelector('.remove-category-image-btn').classList.remove('hidden');
                showFlash('<?php echo addslashes(t('mall_admin.products.category_image_uploaded')); ?>', 'success');
            })
            .catch(() => showFlash('<?php echo addslashes(t('mall_admin.products.category_image_upload_failed')); ?>', 'error'))
            .finally(() => { input.disabled = false; input.value = ''; uploadLabel.classList.remove('opacity-50'); });
    });
});

document.querySelectorAll('.remove-category-image-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        if (!confirm('<?php echo addslashes(t('mall_admin.products.remove_category_image_confirm')); ?>')) return;
        const controls = btn.closest('.category-image-controls');
        const params = new URLSearchParams();
        params.set('action', 'remove_image');
        params.set('category_id', controls.dataset.categoryId);
        params.set('csrf_token', window.MALL_CSRF_TOKEN);
        btn.disabled = true;
        fetch('../mall/admin/ajax/save_category.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.products.category_image_remove_failed')); ?>', 'error');
                    return;
                }
                controls.querySelector('.category-image-preview').innerHTML = '<i class="fas fa-image text-gray-300"></i>';
                btn.classList.add('hidden');
                showFlash('<?php echo addslashes(t('mall_admin.products.category_image_removed')); ?>', 'success');
            })
            .catch(() => showFlash('<?php echo addslashes(t('mall_admin.products.category_image_remove_failed')); ?>', 'error'))
            .finally(() => { btn.disabled = false; });
    });
});

// 카테고리/소분류 이름 수정 + "추가"로 쌓아둔 신규(NEW) 항목을 한번에 서버로 보낸다.
const applyCategoryEditsBtn = document.getElementById('apply-category-edits-btn');
if (applyCategoryEditsBtn) {
    applyCategoryEditsBtn.addEventListener('click', function () {
        const params = new URLSearchParams();
        params.set('action', 'bulk_edit');

        document.querySelectorAll('#category-manage-modal .edit-category-name').forEach(function (nameInput) {
            const nameEnInput = nameInput.parentElement.querySelector('.edit-category-name-en');
            params.append('category_id[]', nameInput.dataset.categoryId);
            params.append('name[]', nameInput.value.trim());
            params.append('name_en[]', nameEnInput ? nameEnInput.value.trim() : '');
        });

        let hasEmptyPending = false;
        document.querySelectorAll('#category-manage-modal .pending-category-item').forEach(function (li) {
            const name = li.querySelector('.pending-name').value.trim();
            const nameEn = li.querySelector('.pending-name-en').value.trim();
            if (!name) { hasEmptyPending = true; return; }
            params.append('new_name[]', name);
            params.append('new_name_en[]', nameEn);
            params.append('new_parent_id[]', li.dataset.parentId || '');
        });
        if (hasEmptyPending) {
            showFlash('<?php echo addslashes(t('mall_admin.products.empty_category_name_error')); ?>', 'error');
            return;
        }

        params.set('csrf_token', window.MALL_CSRF_TOKEN);

        applyCategoryEditsBtn.disabled = true;
        fetch('../mall/admin/ajax/save_category.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(r => r.json())
            .then(data => {
                if (data.success) { window.location.reload(); } else { showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.products.apply_changes_failed')); ?>', 'error'); applyCategoryEditsBtn.disabled = false; }
            });
    });
}

function escHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// 대분류를 지웠으면 그 필터를 통째로 해제하고, 소분류를 지웠으면 sub_id만 해제한 뒤 새로고침한다.
function goToProductsAfterCategoryDelete(deletedId) { window.location.reload(); }

document.querySelectorAll('.delete-category-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        if (!confirm(btn.dataset.categoryName + '<?php echo addslashes(t('mall_admin.products.delete_category_confirm')); ?>')) return;
        const params = new URLSearchParams();
        params.set('action', 'delete');
        params.set('category_id', btn.dataset.categoryId);
        params.set('csrf_token', window.MALL_CSRF_TOKEN);
        fetch('../mall/admin/ajax/save_category.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    const details = data.error?.details;
                    if (details && details.products && details.products.length) {
                        let list = '<ul style="margin:0.3rem 0 0;padding-left:1.1rem;">' +
                            details.products.map(p => '<li>' + escHtml(p.name) + (p.sku ? ' (' + escHtml(p.sku) + ')' : '') + '</li>').join('') +
                            (details.truncated ? '<li>' + '<?php echo addslashes(t('mall_admin.products.and_more_count')); ?>'.replace('{count}', details.product_count - details.products.length) + '</li>' : '') +
                            '</ul>';
                        const forceBtn = '<button type="button" class="force-clear-delete-btn" data-category-id="' + escHtml(btn.dataset.categoryId) +
                            '" data-category-name="' + escHtml(btn.dataset.categoryName) +
                            '" style="margin-top:0.5rem;padding:4px 10px;background:#dc2626;color:#fff;border-radius:4px;font-size:11px;cursor:pointer;border:none;"><?php echo addslashes(t('mall_admin.products.clear_and_delete_products')); ?></button>';
                        showFlash((data.error?.message || '<?php echo addslashes(t('mall_admin.products.delete_category_failed')); ?>') + list + forceBtn, 'error', 20000);
                    } else {
                        showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.products.delete_category_failed')); ?>', 'error');
                    }
                    return;
                }
                goToProductsAfterCategoryDelete(btn.dataset.categoryId);
            });
    });
});

// 위 "이 상품들 카테고리 비우고 삭제" 버튼은 flash-area에 나중에 삽입되므로 이벤트 위임으로 처리한다.
document.getElementById('flash-area').addEventListener('click', function (e) {
    const btn = e.target.closest('.force-clear-delete-btn');
    if (!btn) return;
    if (!confirm(btn.dataset.categoryName + '<?php echo addslashes(t('mall_admin.products.clear_and_delete_confirm')); ?>')) return;
    btn.disabled = true;
    const params = new URLSearchParams();
    params.set('action', 'clear_and_delete');
    params.set('category_id', btn.dataset.categoryId);
    params.set('csrf_token', window.MALL_CSRF_TOKEN);
    fetch('../mall/admin/ajax/save_category.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                goToProductsAfterCategoryDelete(btn.dataset.categoryId);
            } else {
                showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.products.delete_failed')); ?>', 'error');
                btn.disabled = false;
            }
        });
});

// 네이티브 HTML5 Drag & Drop으로 카테고리 순서 변경 — 대분류 목록/각 소분류 목록에 공용으로 쓴다.
// listEl의 "직계 자식" .category-item끼리만 순서를 바꾸므로 대분류 목록과 소분류 목록이 서로 섞이지 않는다.
function makeCategoryListSortable(listEl, onReordered) {
    let dragSrc = null;
    Array.from(listEl.children).forEach(function (item) {
        if (!item.classList.contains('category-item') || item.getAttribute('draggable') !== 'true') return;
        item.addEventListener('dragstart', function () { dragSrc = item; });
        item.addEventListener('dragover', function (e) { e.preventDefault(); item.classList.add('drag-over'); });
        item.addEventListener('dragleave', function () { item.classList.remove('drag-over'); });
        item.addEventListener('drop', function (e) {
            e.preventDefault();
            item.classList.remove('drag-over');
            if (dragSrc && dragSrc !== item && dragSrc.parentElement === listEl) {
                const items = Array.from(listEl.children);
                const srcIndex = items.indexOf(dragSrc);
                const dstIndex = items.indexOf(item);
                if (srcIndex < dstIndex) {
                    item.after(dragSrc);
                } else {
                    item.before(dragSrc);
                }
                onReordered();
            }
        });
    });
}

function saveCategoryOrder(listEl, parentId) {
    const ids = Array.from(listEl.children)
        .filter(el => el.classList.contains('category-item'))
        .map(el => el.dataset.categoryId);
    const params = new URLSearchParams();
    params.set('order', ids.join(','));
    if (parentId) { params.set('parent_id', parentId); }
    params.set('csrf_token', window.MALL_CSRF_TOKEN);
    fetch('../mall/admin/ajax/reorder_categories.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.products.order_save_failed')); ?>', 'error'); }
        });
}

const modalCategoryList = document.getElementById('modal-category-list');
if (modalCategoryList) {
    makeCategoryListSortable(modalCategoryList, function () { saveCategoryOrder(modalCategoryList, null); });
}
document.querySelectorAll('.subcategory-list').forEach(function (subList) {
    makeCategoryListSortable(subList, function () { saveCategoryOrder(subList, subList.dataset.parentId); });
});

// 네이티브 HTML5 Drag & Drop으로 큐레이션된 상품 순서 변경.
// 일반상품/신선상품은 서로 다른 정렬 공간(mall_products.display_order / mall_fresh_products.display_order)을
// 쓰므로, 종류가 다른 행끼리는 드롭해도 순서가 섞이지 않도록 무시한다.

function openAddModal() {
    document.getElementById('addCategoryModal').classList.remove('hidden');
    document.getElementById('addModalErrors').classList.add('hidden');
    document.getElementById('addCategoryForm').reset();
}

function closeAddModal() {
    document.getElementById('addCategoryModal').classList.add('hidden');
}

// 모달 배경 클릭 시 닫기
document.getElementById('addCategoryModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeAddModal();
    }
});

// 카테고리 추가 폼 제출
document.getElementById('addCategoryForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch('ajax_add_category.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload(); // 성공시 페이지 새로고침
        } else {
            showAddErrors(data.errors || ['알 수 없는 오류가 발생했습니다.']);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAddErrors(['서버 오류가 발생했습니다.']);
    });
});

function showAddErrors(errors) {
    const errorDiv = document.getElementById('addModalErrors');
    const errorList = document.getElementById('addErrorList');
    
    errorList.innerHTML = '';
    errors.forEach(error => {
        const li = document.createElement('li');
        li.textContent = error;
        errorList.appendChild(li);
    });
    
    errorDiv.classList.remove('hidden');
}

// 수정 모달 관련 함수들
function openEditModal(categoryId, name, nameEn, parentId) {
    document.getElementById('editCategoryModal').classList.remove('hidden');
    document.getElementById('editModalErrors').classList.add('hidden');
    
    // 폼 데이터 채우기
    document.getElementById('edit_category_id').value = categoryId;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_name_en').value = nameEn || '';
    
    // 상위 카테고리 목록 업데이트 (현재 카테고리 제외)
    updateParentCategoryOptions(categoryId, parentId);
}

function closeEditModal() {
    document.getElementById('editCategoryModal').classList.add('hidden');
}

// 상위 카테고리 선택 옵션 업데이트 (수정하는 카테고리 자신 제외)
function updateParentCategoryOptions(excludeId, selectedParentId) {
    const select = document.getElementById('edit_parent_id');
    const categories = <?php echo json_encode($categories); ?>;
    
    select.innerHTML = '<option value="">-- <?php echo t("category.no_parent"); ?> --</option>';
    
    const currentCategory = categories.find(cat => Number(cat.id) === Number(excludeId));
    // Match the mall editor's two-level tree: top-level categories can only
    // remain top-level, and subcategories can only select a top-level parent.
    if (currentCategory && currentCategory.parent_id) categories.forEach(cat => {
        if (!cat.parent_id && Number(cat.id) !== Number(excludeId)) {
            const option = document.createElement('option');
            option.value = cat.id;
            option.textContent = cat.name + (cat.name_en ? ' (' + cat.name_en + ')' : '');
            if (cat.id == selectedParentId) {
                option.selected = true;
            }
            select.appendChild(option);
        }
    });
}

// 수정 모달 배경 클릭 시 닫기
document.getElementById('editCategoryModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeEditModal();
    }
});

// 카테고리 수정 폼 제출
document.getElementById('editCategoryForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch('ajax_edit_category.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload(); // 성공시 페이지 새로고침
        } else {
            showEditErrors(data.errors || ['알 수 없는 오류가 발생했습니다.']);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showEditErrors(['서버 오류가 발생했습니다.']);
    });
});

function showEditErrors(errors) {
    const errorDiv = document.getElementById('editModalErrors');
    const errorList = document.getElementById('editErrorList');
    
    errorList.innerHTML = '';
    errors.forEach(error => {
        const li = document.createElement('li');
        li.textContent = error;
        errorList.appendChild(li);
    });
    
    errorDiv.classList.remove('hidden');
}
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
