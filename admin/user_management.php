<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('user.management') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';

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
?>

<!-- Page header -->
<div class="page-header">
    <div class="page-header-content">
        <h1 class="page-title"><?php echo t('user.list'); ?></h1>
        <p class="sub-title"><?php echo t('user.management_desc'); ?></p>
    </div>
    <div class="page-header-actions">
        <a href="add_user.php" class="btn-primary">
            <i class="fas fa-user-plus mr-2"></i>
            <?php echo t('user.add'); ?>
        </a>
    </div>
</div>

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
    <div class="table-container">
        <div class="overflow-x-auto">
            <table class="table-standard">
                <thead class="table-header">
                    <tr>
                        <th scope="col" class="w-32"><?php echo t('auth.username'); ?></th>
                        <th scope="col" class="w-32 px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('user.full_name'); ?></th>
                        <th scope="col" class="w-48 px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('auth.email'); ?></th>
                        <th scope="col" class="w-28 px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('user.phone'); ?></th>
                        <th scope="col" class="w-24 px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('user.role'); ?></th>
                        <?php if ($has_permissions_column): ?>
                        <th scope="col" class="w-40 px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('user.permissions'); ?></th>
                        <?php endif; ?>
                        <th scope="col" class="w-28 px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('user.store'); ?></th>
                        <th scope="col" class="w-24 px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('user.created_at'); ?></th>
                        <th scope="col" class="w-20 relative px-3 py-3 border border-gray-300">
                            <span class="sr-only"><?php echo t('common.actions'); ?></span>
                        </th>
                    </tr>
                </thead>
                <tbody class="table-body">
                    <?php foreach ($users as $user): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 py-4 text-sm text-gray-900 border border-gray-300 break-words font-medium"><?php echo htmlspecialchars($user['username']); ?></td>
                            <td class="px-3 py-4 text-sm text-gray-900 border border-gray-300 break-words"><?php echo htmlspecialchars($user['full_name']); ?></td>
                            <td class="px-3 py-4 text-sm text-gray-500 border border-gray-300 break-all"><?php echo htmlspecialchars($user['email']); ?></td>
                            <td class="px-3 py-4 text-sm text-gray-500 border border-gray-300 break-words">
                                <?php echo $has_phone_column && !empty($user['phone']) ? htmlspecialchars($user['phone']) : '<span class="text-gray-400">' . t('user.no_info') . '</span>'; ?>
                            </td>
                            <td class="px-3 py-4 border border-gray-300">
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
                            <td class="px-3 py-4 border border-gray-300">
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
                            <td class="px-3 py-4 text-sm text-gray-500 border border-gray-300 break-words">
                                <?php echo htmlspecialchars($user['store_name'] ?? t('user.unassigned')); ?>
                            </td>
                            <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-500 border border-gray-300">
                                <?php echo date('Y-m-d', strtotime($user['created_at'])); ?>
                            </td>
                            <td class="px-3 py-4 text-right text-sm font-medium border border-gray-300">
                                <div class="flex flex-col space-y-1">
                                    <a href="edit_user.php?id=<?php echo $user['id']; ?>" 
                                       class="text-primary-600 hover:text-primary-900 transition-colors duration-200 text-xs">
                                        <i class="fas fa-edit"></i> <?php echo t('common.edit'); ?>
                                    </a>
                                    <a href="delete_user.php?id=<?php echo $user['id']; ?>" 
                                       class="text-red-600 hover:text-red-900 transition-colors duration-200 text-xs"
                                       onclick="return confirm('<?php echo t('user.confirm_delete'); ?>');">
                                        <i class="fas fa-trash"></i> <?php echo t('common.delete'); ?>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="<?php echo $has_permissions_column ? ($has_phone_column ? '9' : '8') : ($has_phone_column ? '8' : '7'); ?>" class="px-6 py-12 text-center text-sm text-gray-500 border border-gray-300">
                                <div class="flex flex-col items-center">
                                    <i class="fas fa-users text-4xl text-gray-300 mb-4"></i>
                                    <p><?php echo t('user.no_users'); ?></p>
                                    <a href="add_user.php" class="mt-2 text-primary-600 hover:text-primary-500">
                                        <?php echo t('user.add_first_user'); ?>
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