<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('user.management') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/system_header.php';

// 회원관리 권한 확인
if (!has_permission('user_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

// 작업 완료 후 결과 메시지를 표시하기 위한 플래시 메시지 시스템
$flash = null;
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

require_once __DIR__ . '/../lib/permission_helper.php';

// 회원 관리 권한 확인
require_permission('user_management');

require_once __DIR__ . '/../config/db_config.php';

$users = [];
$error_message = '';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 컬럼 존재 여부 확인
    $permissions_check = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'permissions'");
    $permissions_check->execute();
    $has_permissions_column = $permissions_check->fetch();
    
    $phone_check = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'phone'");
    $phone_check->execute();
    $has_phone_column = $phone_check->fetch();
    
    // 쿼리 구성
    $select_fields = "u.id, u.username, u.full_name, u.email, u.role, u.created_at, s.name as store_name";
    
    if ($has_permissions_column) {
        $select_fields .= ", u.permissions";
    }
    
    if ($has_phone_column) {
        $select_fields .= ", u.phone";
    }
    
    $stmt = $pdo->query("
        SELECT {$select_fields}
        FROM users u
        LEFT JOIN stores s ON u.store_id = s.id
        ORDER BY u.id DESC
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = t('messages.database_error');
    // error_log($e->getMessage()); // 실제 운영 환경에서는 로그 파일에 기록합니다.
}

$store_names = [];
foreach ($users as $user) {
    $store_name = trim((string)($user['store_name'] ?? ''));
    if ($store_name !== '') {
        $store_names[$store_name] = $store_name;
    }
}
$store_names = array_values($store_names);
natcasesort($store_names);
$store_names = array_values($store_names);
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
    <!-- Users Table -->
    <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400">
        <!-- 테이블 헤더 -->
        <div class="px-6 py-4 border-b border-gray-200 bg-white flex justify-between items-center">
            <h3 class="text-lg leading-6 font-semibold text-gray-900">
                <?php echo t('user.list'); ?> <span id="user-count" class="text-sm font-normal text-gray-500">(총 <?php echo count($users); ?>건)</span>
            </h3>
            <div class="flex space-x-3">
                <a href="add_user.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <i class="fas fa-user-plus mr-2"></i>
                    <?php echo t('user.add'); ?>
                </a>
            </div>
        </div>

        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 space-y-3">
            <div class="flex flex-col lg:flex-row gap-3">
                <div class="relative flex-1">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input id="user-search" type="search"
                           class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                           placeholder="Search users by username, name, email, phone..."
                           autocomplete="off">
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Filter by Store Name">
                <span class="text-sm font-semibold text-gray-700 mr-1">Store Name:</span>
                <button type="button" class="store-filter px-3 py-1.5 rounded-full text-xs font-semibold bg-primary-600 text-white" data-store="">
                    All Stores
                </button>
                <?php foreach ($store_names as $store_name): ?>
                <button type="button" class="store-filter px-3 py-1.5 rounded-full text-xs font-semibold bg-white text-gray-600 border border-gray-300 hover:bg-gray-100"
                        data-store="<?php echo htmlspecialchars($store_name, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($store_name); ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('auth.username'); ?></th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('user.full_name'); ?></th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('auth.email'); ?></th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('user.phone'); ?></th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('user.role'); ?></th>
                        <?php if ($has_permissions_column): ?>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('user.permissions'); ?></th>
                        <?php endif; ?>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('user.store'); ?></th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('user.created_at'); ?></th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('common.actions'); ?></th>
                    </tr>
                </thead>
                <tbody class="bg-white">
                    <?php foreach ($users as $user): ?>
                        <?php
                        $user_store_name = trim((string)($user['store_name'] ?? ''));
                        $user_search_text = implode(' ', [
                            $user['username'] ?? '',
                            $user['full_name'] ?? '',
                            $user['email'] ?? '',
                            $has_phone_column ? ($user['phone'] ?? '') : '',
                            $user['role'] ?? '',
                            $user_store_name
                        ]);
                        ?>
                        <tr class="user-row border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150 cursor-pointer"
                            data-search="<?php echo htmlspecialchars($user_search_text, ENT_QUOTES, 'UTF-8'); ?>"
                            data-store="<?php echo htmlspecialchars($user_store_name, ENT_QUOTES, 'UTF-8'); ?>"
                            onclick="window.location.href='edit_user.php?id=<?php echo $user['id']; ?>'">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($user['username']); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo htmlspecialchars($user['full_name']); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-500"><?php echo htmlspecialchars($user['email']); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-500">
                                <?php echo $has_phone_column && !empty($user['phone']) ? htmlspecialchars($user['phone']) : '<span class="text-gray-400">' . t('user.no_info') . '</span>'; ?>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex px-1.5 py-0.5 text-xs font-semibold rounded-full 
                                    <?php 
                                    switch($user['role']) {
                                        case 'super_admin': echo 'bg-purple-100 text-purple-800'; break;
                                        case 'admin': echo 'bg-blue-100 text-blue-800'; break;
                                        case 'staff': echo 'bg-green-100 text-green-800'; break;
                                        case 'office_staff': echo 'bg-yellow-100 text-yellow-800'; break;
                                        default: echo 'bg-gray-100 text-gray-800'; break;
                                    }
                                    $role_labels = [
                                        'user' => t('roles.user'),
                                        'staff' => t('roles.staff'),
                                        'office_staff' => t('roles.office_staff'),
                                        'admin' => t('roles.admin'),
                                        'super_admin' => t('roles.super_admin')
                                    ];
                                    ?>">
                                    <?php echo htmlspecialchars($role_labels[$user['role']] ?? $user['role']); ?>
                                </span>
                            </td>
                            <?php if ($has_permissions_column): ?>
                            <td class="px-6 py-4">
                                <?php 
                                $permissions_info = '';
                                if ($user['role'] === 'super_admin') {
                                    $permissions_info = '<span class="text-xs text-purple-600 font-semibold">' . t('user.all_permissions') . '</span>';
                                } else if (!empty($user['permissions'])) {
                                    $permissions = json_decode($user['permissions'], true);
                                    if (is_array($permissions)) {
                                        $active_permissions = array_filter($permissions);
                                        $permission_count = count($active_permissions);
                                        
                                        if ($permission_count > 0) {
                                            $permissions_info = '<div class="text-xs text-center">';
                                            $permissions_info .= '<span class="inline-block px-2 py-1 bg-blue-100 text-blue-800 rounded-full font-semibold">';
                                            $permissions_info .= $permission_count . ' ' . t('user.permissions_count');
                                            $permissions_info .= '</span></div>';
                                        } else {
                                            $permissions_info = '<span class="text-xs text-gray-400 text-center block">' . t('user.no_permissions') . '</span>';
                                        }
                                    } else {
                                        $permissions_info = '<span class="text-xs text-gray-400 text-center block">' . t('user.default_permissions') . '</span>';
                                    }
                                } else {
                                    $permissions_info = '<span class="text-xs text-gray-400 text-center block">' . t('user.default_permissions') . '</span>';
                                }
                                echo $permissions_info;
                                ?>
                            </td>
                            <?php endif; ?>
                            <td class="px-6 py-4 text-sm text-gray-500">
                                <?php echo htmlspecialchars($user['store_name'] ?? t('user.unassigned')); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo date('Y-m-d', strtotime($user['created_at'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex space-x-2">
                                    <a href="edit_user.php?id=<?php echo $user['id']; ?>" 
                                       class="text-green-600 hover:text-green-900" onclick="event.stopPropagation();" title="<?php echo t('common.edit'); ?>">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="delete_user.php?id=<?php echo $user['id']; ?>" 
                                       class="text-red-600 hover:text-red-900" onclick="event.stopPropagation(); return confirm('<?php echo t('user.confirm_delete'); ?>');" title="<?php echo t('common.delete'); ?>">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr id="no-filter-results" class="hidden">
                        <td colspan="<?php echo $has_permissions_column ? ($has_phone_column ? '9' : '8') : ($has_phone_column ? '8' : '7'); ?>" class="px-6 py-12 text-center">
                            <i class="fas fa-search text-gray-400 text-4xl mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">No matching users</h3>
                            <p class="text-gray-600">Try another search term or Store Name.</p>
                        </td>
                    </tr>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="<?php echo $has_permissions_column ? ($has_phone_column ? '9' : '8') : ($has_phone_column ? '8' : '7'); ?>" class="px-6 py-12 text-center">
                                <i class="fas fa-users text-gray-400 text-4xl mb-4"></i>
                                <h3 class="text-lg font-medium text-gray-900 mb-2"><?php echo t('user.no_users'); ?></h3>
                                <p class="text-gray-600 mb-4">새로운 사용자를 등록하여 시작하세요.</p>
                                <a href="add_user.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700">
                                    <i class="fas fa-plus mr-2"></i>
                                    <?php echo t('user.add_first_user'); ?>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('user-search');
    const countElement = document.getElementById('user-count');
    const noResultsRow = document.getElementById('no-filter-results');
    const rows = Array.from(document.querySelectorAll('.user-row'));
    const storeButtons = Array.from(document.querySelectorAll('.store-filter'));
    let selectedStore = '';

    function setActiveStoreButton(activeButton) {
        storeButtons.forEach(function (button) {
            const isActive = button === activeButton;
            button.classList.toggle('bg-primary-600', isActive);
            button.classList.toggle('text-white', isActive);
            button.classList.toggle('bg-white', !isActive);
            button.classList.toggle('text-gray-600', !isActive);
            button.classList.toggle('border', !isActive);
            button.classList.toggle('border-gray-300', !isActive);
        });
    }

    function applyFilters() {
        const searchTerm = (searchInput.value || '').trim().toLocaleLowerCase();
        let visibleCount = 0;

        rows.forEach(function (row) {
            const matchesSearch = !searchTerm || row.dataset.search.toLocaleLowerCase().includes(searchTerm);
            const matchesStore = !selectedStore || row.dataset.store === selectedStore;
            const isVisible = matchesSearch && matchesStore;
            row.classList.toggle('hidden', !isVisible);
            if (isVisible) visibleCount++;
        });

        countElement.textContent = '(총 ' + visibleCount + '건)';
        if (noResultsRow) noResultsRow.classList.toggle('hidden', visibleCount !== 0 || rows.length === 0);
    }

    searchInput.addEventListener('input', applyFilters);
    storeButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            selectedStore = button.dataset.store || '';
            setActiveStoreButton(button);
            applyFilters();
        });
    });
});
</script>

<?php require_once __DIR__ . '/partials/system_footer.php'; ?>
