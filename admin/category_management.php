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
        SELECT c.id, c.name, c.name_en, c.parent_id, 
               p.name as parent_name, p.name_en as parent_name_en, 
               c.created_at
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
                <button onclick="openAddModal()" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors duration-200">
                    <i class="fas fa-plus mr-2"></i><?php echo t('category.add'); ?>
                </button>
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
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <div class="flex flex-col">
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
                            <?php foreach ($categories as $cat): ?>
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
    
    categories.forEach(cat => {
        if (cat.id != excludeId) { // 자기 자신 제외
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