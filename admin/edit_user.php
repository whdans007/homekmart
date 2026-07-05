<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('user.edit') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/system_header.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 회원 관리 권한 확인
require_permission('user_management');

require_once __DIR__ . '/../config/db_config.php';

$user_id = $_GET['id'] ?? null;
if (!$user_id || !filter_var($user_id, FILTER_VALIDATE_INT)) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => t('user.invalid_user_id')];
    header('Location: user_management.php');
    exit;
}

$errors = [];
$user = null;

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 수정할 회원 정보 가져오기 (permissions, phone 컬럼 존재 여부 확인)
    $column_check = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'permissions'");
    $column_check->execute();
    $has_permissions_column = $column_check->fetch();
    
    $phone_check = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'phone'");
    $phone_check->execute();
    $has_phone_column = $phone_check->fetch();
    
    // 동적 쿼리 구성
    $select_fields = "id, username, full_name, email, role, store_id, created_at, updated_at";
    if ($has_permissions_column) {
        $select_fields .= ", permissions";
    }
    if ($has_phone_column) {
        $select_fields .= ", phone";
    }
    
    $stmt = $pdo->prepare("SELECT {$select_fields} FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => t('user.user_not_found')];
        header('Location: user_management.php');
        exit;
    }

    // 권한 확인: admin은 super_admin을 수정할 수 없음
    if ($_SESSION['role'] === 'admin' && $user['role'] === 'super_admin') {
        $_SESSION['flash'] = ['type' => 'error', 'message' => t('user.cannot_edit_super_admin')];
        header('Location: user_management.php');
        exit;
    }

    // 지점 목록 가져오기
    $stores = $pdo->query("SELECT id, name FROM stores ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>" . t('messages.database_connection_failed') . ": " . htmlspecialchars($e->getMessage()) . "</p></div></div></div>";
    require_once __DIR__ . '/partials/system_footer.php';
    exit;
}

// 현재 로그인한 사용자의 level보다 낮은 역할만 부여 가능 (super_admin은 전체)
// Design Ref: role-permission-management §5.4 - get_all_roles() 기반 동적 역할 선택
$assignable_roles = get_assignable_roles($_SESSION['role']);
$editable_roles = array_column($assignable_roles, 'role_key');

// 변경 전 원본 값 (직접 변경 권한이 없을 때 되돌리기 위함)
$original_store_id = $user['store_id'];
$original_role = $user['role'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // POST 요청 시 폼 데이터로 변수 업데이트
    $user['full_name'] = trim($_POST['full_name'] ?? '');
    $user['email'] = trim($_POST['email'] ?? '');
    $user['role'] = $_POST['role'] ?? $user['role'];
    $user['store_id'] = $_POST['store_id'] ?? $user['store_id'];
    $user['phone'] = trim($_POST['phone'] ?? '');
    $permissions = $_POST['permissions'] ?? [];
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    // 유효성 검사
    if (empty($user['full_name'])) $errors[] = t('user.name_required');
    if (empty($user['email']) || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) $errors[] = t('user.valid_email_required');
    
    // 자기 자신의 권한 변경 시도 방지
    if ($user['id'] == $_SESSION['user_id'] && isset($_POST['role']) && $_POST['role'] != $_SESSION['role']) {
        $errors[] = t('user.cannot_change_own_role');
        $user['role'] = $_SESSION['role']; // 원래 권한으로 되돌림
    } elseif (isset($_POST['role']) && !in_array($_POST['role'], $editable_roles)) {
        // Design Ref: role-permission-management §7 - level 기반 역할 부여 서버측 거부
        $errors[] = t('user.invalid_role');
    }

    // 점장(branch_manager) 역할 부여는 CEO 이상만 가능
    if (isset($_POST['role']) && $_POST['role'] === 'branch_manager' && !can_appoint_branch_manager()) {
        $errors[] = '점장(branch_manager) 역할은 CEO 이상만 부여할 수 있습니다.';
        $user['role'] = $original_role;
    }

    // 소속 지점 직접 변경은 CEO 이상만 가능 (그 외에는 변경 무시)
    if (!can_change_store_directly()) {
        $user['store_id'] = $original_store_id;
    }

    if (!empty($user['store_id']) && !filter_var($user['store_id'], FILTER_VALIDATE_INT)) {
        $errors[] = t('user.invalid_store');
    }

    if (!empty($password)) {
        if (strlen($password) < 8) $errors[] = t('user.password_min_length');
        if ($password !== $password_confirm) $errors[] = t('user.password_mismatch');
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$user['email'], $user_id]);
            if ($stmt->fetch()) {
                $errors[] = t('user.email_already_exists');
            } else {
                // 권한 JSON 생성
                $permissions_json = null;
                // 동적 UPDATE 쿼리 구성
                $update_fields = ["full_name = ?", "email = ?", "role = ?", "store_id = ?"];
                $params = [$user['full_name'], $user['email'], $user['role'], $user['store_id'] ?: null];
                
                if ($has_phone_column) {
                    $update_fields[] = "phone = ?";
                    $params[] = $user['phone'];
                }
                
                if ($has_permissions_column) {
                    $permissions_json = null;
                    if (!empty($permissions) && is_array($permissions)) {
                        // 체크된 권한들을 true로, 나머지는 false로 설정
                        // Design Ref: role-permission-management - 19개 권한 키 전체 반영 (get_all_permission_keys 기준)
                        $final_permissions = [];
                        foreach (get_all_permission_keys() as $perm) {
                            $final_permissions[$perm] = in_array($perm, $permissions);
                        }
                        $permissions_json = json_encode($final_permissions);
                    }
                    $update_fields[] = "permissions = ?";
                    $params[] = $permissions_json;
                }
                
                $sql = "UPDATE users SET " . implode(", ", $update_fields);

                if (!empty($password)) {
                    $sql .= ", password = ?";
                    $params[] = password_hash($password, PASSWORD_DEFAULT);
                }

                $sql .= " WHERE id = ?";
                $params[] = $user_id;

                $update_stmt = $pdo->prepare($sql);
                $update_stmt->execute($params);

                $_SESSION['flash'] = ['type' => 'success', 'message' => t('user.user_updated_successfully', ['name' => $user['username']])];
                header("Location: user_management.php");
                exit();
            }
        } catch (PDOException $e) {
            $errors[] = t('messages.database_error') . ": " . $e->getMessage();
        }
    }
}
?>

<!-- Page header -->
<div class="mb-8">
    <a href="user_management.php" class="inline-flex items-center text-sm font-medium text-gray-500 hover:text-gray-700 mb-4">
        <i class="fas fa-arrow-left mr-2"></i>
        <?php echo t('user.back_to_list'); ?>
    </a>
    <h1 class="text-3xl font-bold text-gray-900"><?php echo t('user.edit_user'); ?></h1>
</div>

<!-- Form -->
<form action="edit_user.php?id=<?php echo $user_id; ?>" method="post" class="space-y-8 bg-white shadow sm:rounded-lg p-6">

    <?php if (!empty($errors)): ?>
        <div class="rounded-md bg-red-50 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-times-circle text-red-400"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-red-800"><?php echo t('messages.problems_occurred'); ?></h3>
                    <div class="mt-2 text-sm text-red-700">
                        <ul role="list" class="list-disc pl-5 space-y-1">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- User Information Section -->
    <div>
        <h2 class="text-lg font-medium leading-6 text-gray-900"><?php echo t('user.basic_info'); ?></h2>
        <p class="mt-1 text-sm text-gray-500"><?php echo t('user.basic_info_desc'); ?></p>
        <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-3">
                <label for="username" class="block text-sm font-medium text-gray-700"><?php echo t('auth.username'); ?></label>
                <input type="text" id="username" value="<?php echo htmlspecialchars($user['username']); ?>" readonly class="mt-1 block w-full border-gray-300 rounded-md shadow-sm bg-gray-100 cursor-not-allowed sm:text-sm">
            </div>
            <div class="sm:col-span-3">
                <label for="full_name" class="block text-sm font-medium text-gray-700"><?php echo t('user.full_name'); ?></label>
                <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            </div>
            <div class="sm:col-span-3">
                <label for="email" class="block text-sm font-medium text-gray-700"><?php echo t('auth.email'); ?></label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            </div>
            <?php if ($has_phone_column): ?>
            <div class="sm:col-span-3">
                <label for="phone" class="block text-sm font-medium text-gray-700"><?php echo t('user.phone'); ?> <span class="text-gray-500">(<?php echo t('common.optional'); ?>)</span></label>
                <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" placeholder="010-1234-5678">
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Role & Store Section -->
    <div class="border-t border-gray-200 pt-8">
        <h2 class="text-lg font-medium leading-6 text-gray-900"><?php echo t('user.role_and_store'); ?></h2>
        <p class="mt-1 text-sm text-gray-500"><?php echo t('user.role_and_store_desc'); ?></p>
        <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-3">
                <label for="role" class="block text-sm font-medium text-gray-700"><?php echo t('user.role'); ?></label>
                <select id="role" name="role" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm <?php if ($user['id'] == $_SESSION['user_id'] || $user['role'] === 'super_admin') echo 'bg-gray-100 cursor-not-allowed'; ?>" <?php if ($user['id'] == $_SESSION['user_id'] || $user['role'] === 'super_admin') echo 'disabled'; ?>>
                    <?php if ($user['role'] === 'super_admin'): ?>
                        <option value="super_admin" selected><?php echo htmlspecialchars(get_role_label('super_admin')); ?></option>
                    <?php else: ?>
                        <?php foreach ($assignable_roles as $role_option): ?>
                            <option value="<?php echo htmlspecialchars($role_option['role_key']); ?>" <?php echo ($user['role'] === $role_option['role_key']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($role_option['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <?php if ($user['id'] == $_SESSION['user_id']): ?>
                    <p class="mt-2 text-sm text-gray-500"><?php echo t('user.cannot_change_own_role'); ?></p>
                <?php elseif ($user['role'] === 'super_admin'): ?>
                    <p class="mt-2 text-sm text-gray-500"><?php echo t('user.super_admin_role_cannot_change'); ?></p>
                <?php endif; ?>
            </div>
            <div class="sm:col-span-3">
                <label for="store_id" class="block text-sm font-medium text-gray-700"><?php echo t('user.store'); ?></label>
                <select id="store_id" name="store_id" <?php echo can_change_store_directly() ? '' : 'disabled'; ?> class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm <?php echo can_change_store_directly() ? '' : 'bg-gray-100 cursor-not-allowed'; ?>">
                    <option value=""><?php echo t('store.select_store'); ?></option>
                    <?php foreach ($stores as $store): ?>
                        <option value="<?php echo $store['id']; ?>" <?php echo ($user['store_id'] == $store['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($store['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!can_change_store_directly()): ?>
                <p class="mt-2 text-sm text-gray-500"><i class="fas fa-lock mr-1"></i>소속 지점 변경은 CEO 이상만 가능합니다. (발령은 본인이 프로필에서 요청 → 점장 승인)</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Permissions Section -->
    <div class="border-t border-gray-200 pt-8">
        <h2 class="text-lg font-medium leading-6 text-gray-900"><?php echo t('user.detailed_permissions'); ?></h2>
        <p class="mt-1 text-sm text-gray-500"><?php echo t('user.detailed_permissions_desc'); ?></p>
        
        <div class="mt-6">
            <div id="permissions-container" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <?php
                // Design Ref: role-permission-management - 19개 권한 키 전체 표시 (get_all_permission_keys 기준)
                $all_permissions = [];
                foreach (get_all_permission_keys() as $perm_key) {
                    $all_permissions[$perm_key] = get_permission_label($perm_key);
                }

                // 현재 사용자의 권한 가져오기
                $current_permissions = [];
                if ($has_permissions_column && !empty($user['permissions'])) {
                    $current_permissions = json_decode($user['permissions'], true) ?? [];
                } else {
                    // permissions 컬럼이 없거나 비어있으면 기본 권한 사용
                    $current_permissions = get_default_permissions($user['role']);
                }
                
                // 권한 변경 가능 여부 체크
                $is_disabled = ($user['role'] === 'super_admin') || ($user['id'] == $_SESSION['user_id']);
                
                foreach ($all_permissions as $perm_key => $perm_label): 
                    $is_checked = isset($current_permissions[$perm_key]) ? $current_permissions[$perm_key] : false;
                    
                    // super_admin은 모든 권한이 체크되고 비활성화
                    if ($user['role'] === 'super_admin') {
                        $is_checked = true;
                    }
                ?>
                <div class="relative flex items-start">
                    <div class="flex items-center h-5">
                        <input id="perm_<?php echo $perm_key; ?>" 
                               name="permissions[]" 
                               type="checkbox" 
                               value="<?php echo $perm_key; ?>"
                               <?php echo $is_checked ? 'checked' : ''; ?>
                               <?php echo $is_disabled ? 'disabled' : ''; ?>
                               class="focus:ring-primary-500 h-4 w-4 text-primary-600 border-gray-300 rounded">
                    </div>
                    <div class="ml-3 text-sm">
                        <label for="perm_<?php echo $perm_key; ?>" class="font-medium text-gray-700">
                            <?php echo htmlspecialchars($perm_label); ?>
                        </label>
                        <?php if ($perm_key === 'shop_access'): ?>
                        <p class="text-gray-500"><?php echo t('permissions.shop_access_desc'); ?></p>
                        <?php elseif ($perm_key === 'admin_access'): ?>
                        <p class="text-gray-500"><?php echo t('permissions.admin_access_desc'); ?></p>
                        <?php elseif ($perm_key === 'barcode_management'): ?>
                        <p class="text-gray-500"><?php echo t('permissions.barcode_management_desc'); ?></p>
                        <?php elseif ($perm_key === 'accounting_management'): ?>
                        <p class="text-gray-500"><?php echo t('permissions.accounting_management_desc'); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <?php if (!$is_disabled): ?>
            <div class="mt-4 flex space-x-2">
                <button type="button" id="preset-role-default" class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <i class="fas fa-rotate mr-2"></i><?php echo t('user.reset_to_role_permissions'); ?>
                </button>
                <button type="button" id="clear-all" class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <i class="fas fa-times mr-2"></i><?php echo t('user.clear_all'); ?>
                </button>
            </div>
            <?php endif; ?>
            <?php
            // Design Ref: role-permission-management §5.4 - 역할별 권한맵을 JS에 전달하여 "역할 기본 권한으로 초기화" 버튼에서 사용
            $role_permissions_map = [];
            foreach ($assignable_roles as $role_option) {
                $role_permissions_map[$role_option['role_key']] = get_role_permissions($role_option['role_key']);
            }
            ?>
            <script>
                const rolePermissionsMap = <?php echo json_encode($role_permissions_map); ?>;
            </script>
            
            <?php if ($user['role'] === 'super_admin'): ?>
            <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-md">
                <p class="text-sm text-blue-800">
                    <i class="fas fa-info-circle mr-1"></i>
                    <?php echo t('user.super_admin_all_permissions'); ?>
                </p>
            </div>
            <?php elseif ($user['id'] == $_SESSION['user_id']): ?>
            <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded-md">
                <p class="text-sm text-yellow-800">
                    <i class="fas fa-exclamation-triangle mr-1"></i>
                    <?php echo t('user.cannot_change_own_permissions'); ?>
                </p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Password Section -->
    <div class="border-t border-gray-200 pt-8">
        <h2 class="text-lg font-medium leading-6 text-gray-900"><?php echo t('user.change_password'); ?></h2>
        <p class="mt-1 text-sm text-gray-500"><?php echo t('user.change_password_desc'); ?></p>
        <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-3">
                <label for="password" class="block text-sm font-medium text-gray-700"><?php echo t('user.new_password_min_8'); ?></label>
                <input type="password" id="password" name="password" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            </div>
            <div class="sm:col-span-3">
                <label for="password_confirm" class="block text-sm font-medium text-gray-700"><?php echo t('user.new_password_confirm'); ?></label>
                <input type="password" id="password_confirm" name="password_confirm" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            </div>
        </div>
    </div>
    
    <!-- Form Actions -->
    <div class="pt-8 border-t border-gray-200 flex justify-end gap-x-3">
        <a href="user_management.php" class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
            <?php echo t('common.cancel'); ?>
        </a>
        <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
            <i class="fas fa-save mr-2"></i>
            <?php echo t('user.save_info'); ?>
        </button>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 모든 체크박스 요소
    const checkboxes = document.querySelectorAll('input[name="permissions[]"]');

    // 권한 설정 함수
    function setPermissions(permissionMap) {
        checkboxes.forEach(checkbox => {
            if (!checkbox.disabled) {
                checkbox.checked = !!permissionMap[checkbox.value];
            }
        });
    }

    // Design Ref: role-permission-management §5.4 - 선택된 역할의 role_permissions 값으로 초기화
    document.getElementById('preset-role-default')?.addEventListener('click', function() {
        const role = document.getElementById('role').value;
        setPermissions(rolePermissionsMap[role] || {});
    });

    document.getElementById('clear-all')?.addEventListener('click', function() {
        setPermissions({});
    });
});
</script>

<?php require_once __DIR__ . '/partials/system_footer.php'; ?>