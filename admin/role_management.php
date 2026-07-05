<?php
// Design Ref: docs/02-design/features/role-permission-management.design.md §4.2, §5.1
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('role_management.title') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/system_header.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 역할 관리는 settings 권한(super_admin)만 접근 가능
require_permission('settings');

require_once __DIR__ . '/../config/db_config.php';

$flash = null;
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $role_key = trim($_POST['role_key'] ?? '');
        $label = trim($_POST['label'] ?? '');
        $level = (int)($_POST['level'] ?? 0);

        if ($role_key === '' || $label === '') {
            $_SESSION['flash'] = ['type' => 'error', 'message' => t('messages.problems_occurred')];
        } else {
            $result = create_role($role_key, $label, $level);
            if ($result === true) {
                $_SESSION['flash'] = ['type' => 'success', 'message' => t('role_management.role_created')];
            } else {
                $_SESSION['flash'] = ['type' => 'error', 'message' => $result];
            }
        }
    } elseif ($action === 'update') {
        $role_key = $_POST['role_key'] ?? '';
        $label = trim($_POST['label'] ?? '');
        $level = (int)($_POST['level'] ?? 0);

        if ($role_key === '' || $label === '') {
            $_SESSION['flash'] = ['type' => 'error', 'message' => t('messages.problems_occurred')];
        } else {
            $result = update_role($role_key, $label, $level);
            if ($result === true) {
                $_SESSION['flash'] = ['type' => 'success', 'message' => t('role_management.role_updated')];
            } else {
                $_SESSION['flash'] = ['type' => 'error', 'message' => $result];
            }
        }
    } elseif ($action === 'delete') {
        $role_key = $_POST['role_key'] ?? '';
        $result = delete_role($role_key);
        if ($result === true) {
            $_SESSION['flash'] = ['type' => 'success', 'message' => t('role_management.role_deleted')];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $result];
        }
    }

    header('Location: role_management.php');
    exit;
}

$roles = get_all_roles();

// 역할별 사용 중인 사용자 수 (삭제 가능 여부 판단용)
$role_user_counts = [];
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->query("SELECT role, COUNT(*) AS cnt FROM users GROUP BY role");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $role_user_counts[$row['role']] = (int)$row['cnt'];
    }
} catch (PDOException $e) {
    // 카운트 조회 실패 시 빈 배열로 진행 (삭제 버튼은 is_system 기준으로만 제어됨)
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

<div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400 mb-6">
    <div class="px-6 py-4 border-b border-gray-200 bg-white flex justify-between items-center">
        <h3 class="text-lg leading-6 font-semibold text-gray-900"><?php echo t('role_management.title'); ?></h3>
        <a href="role_permissions_matrix.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
            <i class="fas fa-table-cells mr-2"></i>
            <?php echo t('role_management.go_to_matrix'); ?>
        </a>
    </div>

    <!-- 새 역할 추가 폼 -->
    <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
        <form action="role_management.php" method="post" class="flex flex-wrap items-end gap-3">
            <input type="hidden" name="action" value="create">
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1"><?php echo t('role_management.role_key'); ?></label>
                <input type="text" name="role_key" required pattern="[a-z][a-z0-9_]{1,49}" placeholder="<?php echo t('role_management.role_key_placeholder'); ?>" class="block border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1"><?php echo t('role_management.label'); ?></label>
                <input type="text" name="label" required class="block border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1"><?php echo t('role_management.level'); ?></label>
                <input type="number" name="level" value="0" required class="block w-24 border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            </div>
            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                <i class="fas fa-plus mr-2"></i>
                <?php echo t('role_management.add_role'); ?>
            </button>
        </form>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('role_management.order'); ?></th>
                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('role_management.label'); ?></th>
                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('role_management.role_key'); ?></th>
                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('role_management.level'); ?></th>
                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"></th>
                    <th class="px-6 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('role_management.actions'); ?></th>
                </tr>
            </thead>
            <tbody class="bg-white">
                <?php foreach ($roles as $i => $role): ?>
                    <?php
                        $is_system = (bool)$role['is_system'];
                        $in_use = ($role_user_counts[$role['role_key']] ?? 0) > 0;
                        $can_delete = !$is_system && !$in_use;
                        $form_id = 'update-form-' . htmlspecialchars($role['role_key']);
                    ?>
                    <tr class="border-b border-gray-100">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo $i + 1; ?>
                            <form id="<?php echo $form_id; ?>" action="role_management.php" method="post" class="hidden">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="role_key" value="<?php echo htmlspecialchars($role['role_key']); ?>">
                            </form>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <input type="text" name="label" form="<?php echo $form_id; ?>" value="<?php echo htmlspecialchars($role['label']); ?>" class="block border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-500"><?php echo htmlspecialchars($role['role_key']); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <input type="number" name="level" form="<?php echo $form_id; ?>" value="<?php echo (int)$role['level']; ?>" class="block w-24 border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <?php if ($is_system): ?>
                                <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full bg-purple-100 text-purple-800">
                                    <i class="fas fa-lock mr-1"></i><?php echo t('role_management.system_badge'); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <button type="submit" form="<?php echo $form_id; ?>" class="text-green-600 hover:text-green-900 mr-3" title="<?php echo t('role_management.save'); ?>">
                                <i class="fas fa-save"></i> <?php echo t('role_management.save'); ?>
                            </button>
                            <?php if ($can_delete): ?>
                                <form action="role_management.php" method="post" class="inline" onsubmit="return confirm('<?php echo t('role_management.confirm_delete'); ?>');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="role_key" value="<?php echo htmlspecialchars($role['role_key']); ?>">
                                    <button type="submit" class="text-red-600 hover:text-red-900">
                                        <i class="fas fa-trash"></i> <?php echo t('role_management.delete'); ?>
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="text-gray-400 cursor-not-allowed" title="<?php echo $is_system ? t('role_management.delete_disabled_system') : t('role_management.delete_disabled_in_use'); ?>">
                                    <i class="fas fa-trash"></i> <?php echo t('role_management.delete'); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</div>

<?php require_once __DIR__ . '/partials/system_footer.php'; ?>
