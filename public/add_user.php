<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('user.add') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 회원 관리 권한 확인
require_permission('user_management');

require_once __DIR__ . '/../config/db_config.php';

$errors = [];
$username = '';
$full_name = '';
$email = '';
$role = 'user'; // 기본값
$store_id = null;
$phone = '';
$stores = [];
$permissions = [];

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // phone 컬럼 존재 여부 확인
    $phone_check = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'phone'");
    $phone_check->execute();
    $has_phone_column = $phone_check->fetch();

    // 지점 목록 가져오기
    $stores = $pdo->query("SELECT id, name FROM stores ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $errors[] = t('messages.database_error') . ': ' . $e->getMessage();
}

// 현재 로그인한 사용자의 권한에 따라 생성 가능한 역할 정의
$allowed_roles = ['user'];
if ($_SESSION['role'] === 'super_admin') {
    $allowed_roles = array_merge($allowed_roles, ['admin', 'staff', 'office_staff']);
} elseif ($_SESSION['role'] === 'admin') {
    $allowed_roles = array_merge($allowed_roles, ['staff', 'office_staff']);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $role = $_POST['role'] ?? 'user';
    $store_id = $_POST['store_id'] ?? null;
    $phone = trim($_POST['phone'] ?? '');
    $permissions = $_POST['permissions'] ?? [];

    // 유효성 검사
    if (empty($username)) $errors[] = t('forms.username_required');
    if (empty($full_name)) $errors[] = t('forms.full_name_required');
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = t('forms.invalid_email');
    if (empty($password) || strlen($password) < 8) $errors[] = t('forms.password_min_length');
    if ($password !== $password_confirm) $errors[] = t('forms.password_mismatch');
    if (!in_array($role, $allowed_roles)) $errors[] = t('forms.invalid_role');
    if (!empty($store_id) && !filter_var($store_id, FILTER_VALIDATE_INT)) $errors[] = t('forms.invalid_store');

    if (empty($errors) && isset($pdo)) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                $errors[] = t('forms.duplicate_user');
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                // 권한 JSON 생성
                $permissions_json = null;
                if (!empty($permissions) && is_array($permissions)) {
                    // 체크된 권한들을 true로, 나머지는 false로 설정
                    $all_permissions = [
                        'admin_access', 'user_management', 'store_management', 
                        'product_management', 'purchase_management', 'brand_management',
                        'category_management', 'supplier_management', 'settings', 'shop_access',
                        'barcode_management', 'accounting_management'
                    ];
                    
                    $final_permissions = [];
                    foreach ($all_permissions as $perm) {
                        $final_permissions[$perm] = in_array($perm, $permissions);
                    }
                    $permissions_json = json_encode($final_permissions);
                }
                
                // 컬럼 존재 여부에 따라 INSERT 쿼리 구성
                if ($has_phone_column) {
                    $insert_stmt = $pdo->prepare(
                        "INSERT INTO users (username, full_name, email, phone, password, role, store_id) VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );
                    $insert_stmt->execute([$username, $full_name, $email, $phone, $hashed_password, $role, $store_id ?: null]);
                } else {
                    $insert_stmt = $pdo->prepare(
                        "INSERT INTO users (username, full_name, email, password, role, store_id) VALUES (?, ?, ?, ?, ?, ?)"
                    );
                    $insert_stmt->execute([$username, $full_name, $email, $hashed_password, $role, $store_id ?: null]);
                }

                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' => str_replace('{username}', htmlspecialchars($username), t('user.user_added_success'))
                ];
                header("Location: user_management.php");
                exit;
            }
        } catch (PDOException $e) {
            $errors[] = t('user.add_error') . ': ' . $e->getMessage();
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
    <h1 class="text-3xl font-bold text-gray-900"><?php echo t('user.add'); ?></h1>
</div>

<!-- Form -->
<form action="add_user.php" method="post" class="space-y-8 bg-white shadow sm:rounded-lg p-6">
    <?php if (!empty($errors)): ?>
        <div class="rounded-md bg-red-50 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-times-circle text-red-400"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-red-800"><?php echo t('forms.errors_occurred'); ?></h3>
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
                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            </div>
            <div class="sm:col-span-3">
                <label for="full_name" class="block text-sm font-medium text-gray-700"><?php echo t('user.full_name'); ?></label>
                <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($full_name); ?>" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            </div>
            <div class="sm:col-span-3">
                <label for="email" class="block text-sm font-medium text-gray-700"><?php echo t('auth.email'); ?></label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            </div>
            <?php if ($has_phone_column): ?>
            <div class="sm:col-span-3">
                <label for="phone" class="block text-sm font-medium text-gray-700"><?php echo t('user.phone'); ?> <span class="text-gray-500">(<?php echo t('forms.optional'); ?>)</span></label>
                <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" placeholder="010-1234-5678">
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
                <select id="role" name="role" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                    <?php 
                    $role_labels = [
                        'user' => t('roles.user'),
                        'staff' => t('roles.staff'),
                        'office_staff' => t('roles.office_staff'),
                        'admin' => t('roles.admin'),
                        'super_admin' => t('roles.super_admin')
                    ];
                    foreach ($allowed_roles as $role_value): ?>
                        <option value="<?php echo $role_value; ?>" <?php echo ($role === $role_value) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($role_labels[$role_value] ?? ucfirst($role_value)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sm:col-span-3">
                <label for="store_id" class="block text-sm font-medium text-gray-700"><?php echo t('user.store'); ?> <span class="text-gray-500">(<?php echo t('forms.optional'); ?>)</span></label>
                <select id="store_id" name="store_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                    <option value=""><?php echo t('store.select_store'); ?></option>
                    <?php foreach ($stores as $store): ?>
                        <option value="<?php echo $store['id']; ?>" <?php echo ($store_id == $store['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($store['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
    
    <!-- Permissions Section -->
    <div class="border-t border-gray-200 pt-8">
        <h2 class="text-lg font-medium leading-6 text-gray-900"><?php echo t('user.permissions_settings'); ?></h2>
        <p class="mt-1 text-sm text-gray-500"><?php echo t('user.permissions_settings_desc'); ?></p>
        
        <div class="mt-6">
            <div id="permissions-container" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <?php 
                $all_permissions = [
                    'admin_access' => t('permissions.admin_access'),
                    'user_management' => t('permissions.user_management'),
                    'store_management' => t('permissions.store_management'),
                    'product_management' => t('permissions.product_management'),
                    'purchase_management' => t('permissions.purchase_management'),
                    'brand_management' => t('permissions.brand_management'),
                    'category_management' => t('permissions.category_management'),
                    'supplier_management' => t('permissions.supplier_management'),
                    'settings' => t('permissions.settings'),
                    'shop_access' => t('permissions.shop_access'),
                    'barcode_management' => t('permissions.barcode_management'),
                    'accounting_management' => t('permissions.accounting_management')
                ];
                
                foreach ($all_permissions as $perm_key => $perm_label): 
                ?>
                <div class="relative flex items-start">
                    <div class="flex items-center h-5">
                        <input id="perm_<?php echo $perm_key; ?>" 
                               name="permissions[]" 
                               type="checkbox" 
                               value="<?php echo $perm_key; ?>"
                               <?php echo in_array($perm_key, $permissions) ? 'checked' : ''; ?>
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
            
            <div class="mt-4 flex space-x-2">
                <button type="button" id="preset-user" class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <i class="fas fa-user mr-2"></i><?php echo t('roles.user'); ?>
                </button>
                <button type="button" id="preset-staff" class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <i class="fas fa-id-badge mr-2"></i><?php echo t('roles.staff'); ?>
                </button>
                <button type="button" id="preset-office-staff" class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <i class="fas fa-calculator mr-2"></i><?php echo t('roles.office_staff'); ?>
                </button>
                <button type="button" id="preset-admin" class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <i class="fas fa-user-cog mr-2"></i><?php echo t('roles.admin'); ?>
                </button>
                <button type="button" id="preset-super-admin" class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <i class="fas fa-user-shield mr-2"></i><?php echo t('roles.super_admin'); ?>
                </button>
                <button type="button" id="clear-all" class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <i class="fas fa-times mr-2"></i><?php echo t('user.clear_all'); ?>
                </button>
            </div>
        </div>
    </div>
    
    <!-- Password Section -->
    <div class="border-t border-gray-200 pt-8">
        <h2 class="text-lg font-medium leading-6 text-gray-900"><?php echo t('user.password_settings'); ?></h2>
        <p class="mt-1 text-sm text-gray-500"><?php echo t('user.password_settings_desc'); ?></p>
        <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-3">
                <label for="password" class="block text-sm font-medium text-gray-700"><?php echo t('auth.password'); ?></label>
                <input type="password" id="password" name="password" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                 <p class="mt-2 text-xs text-gray-500"><?php echo t('user.password_requirements'); ?></p>
            </div>
            <div class="sm:col-span-3">
                <label for="password_confirm" class="block text-sm font-medium text-gray-700"><?php echo t('auth.password_confirm'); ?></label>
                <input type="password" id="password_confirm" name="password_confirm" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            </div>
        </div>
    </div>

    <!-- Form Actions -->
    <div class="pt-8 border-t border-gray-200 flex justify-end gap-x-3">
        <a href="user_management.php" class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
            <?php echo t('common.cancel'); ?>
        </a>
        <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
            <i class="fas fa-user-plus mr-2"></i>
            <?php echo t('user.add'); ?>
        </button>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 권한 프리셋 정의
    const presets = {
        user: ['shop_access'],
        staff: ['shop_access', 'barcode_management'],
        office_staff: ['shop_access', 'barcode_management', 'accounting_management'],
        admin: ['admin_access', 'user_management', 'product_management', 'purchase_management', 'brand_management', 'category_management', 'supplier_management', 'shop_access', 'barcode_management', 'accounting_management'],
        super_admin: ['admin_access', 'user_management', 'store_management', 'product_management', 'purchase_management', 'brand_management', 'category_management', 'supplier_management', 'settings', 'shop_access', 'barcode_management', 'accounting_management']
    };
    
    // 모든 체크박스 요소
    const checkboxes = document.querySelectorAll('input[name="permissions[]"]');
    
    // 권한 설정 함수
    function setPermissions(permissionList) {
        checkboxes.forEach(checkbox => {
            checkbox.checked = permissionList.includes(checkbox.value);
        });
    }
    
    // 프리셋 버튼 이벤트 리스너
    document.getElementById('preset-user').addEventListener('click', function() {
        setPermissions(presets.user);
        document.getElementById('role').value = 'user';
    });
    
    document.getElementById('preset-staff').addEventListener('click', function() {
        setPermissions(presets.staff);
        document.getElementById('role').value = 'staff';
    });
    
    document.getElementById('preset-office-staff').addEventListener('click', function() {
        setPermissions(presets.office_staff);
        document.getElementById('role').value = 'office_staff';
    });
    
    document.getElementById('preset-admin').addEventListener('click', function() {
        setPermissions(presets.admin);
        document.getElementById('role').value = 'admin';
    });
    
    document.getElementById('preset-super-admin').addEventListener('click', function() {
        setPermissions(presets.super_admin);
        document.getElementById('role').value = 'super_admin';
    });
    
    document.getElementById('clear-all').addEventListener('click', function() {
        setPermissions([]);
    });
    
    // 역할 변경 시 자동으로 권한 설정
    document.getElementById('role').addEventListener('change', function() {
        const role = this.value;
        if (presets[role]) {
            setPermissions(presets[role]);
        }
    });
    
    // 관리자 메뉴 접근 권한이 없으면 다른 관리 권한들도 자동으로 해제
    const adminAccessCheckbox = document.getElementById('perm_admin_access');
    if (adminAccessCheckbox) {
        adminAccessCheckbox.addEventListener('change', function() {
            if (!this.checked) {
                // 관리자 메뉴 접근이 해제되면 다른 관리 권한들도 해제
                const adminPermissions = ['user_management', 'store_management', 'product_management', 'purchase_management', 'brand_management', 'category_management', 'supplier_management', 'settings'];
                adminPermissions.forEach(perm => {
                    const checkbox = document.getElementById('perm_' + perm);
                    if (checkbox) {
                        checkbox.checked = false;
                    }
                });
                
                // 역할도 user로 변경
                document.getElementById('role').value = 'user';
            }
        });
    }
    
    // 다른 관리 권한이 체크되면 자동으로 관리자 메뉴 접근 권한도 체크
    const adminPermissions = ['user_management', 'store_management', 'product_management', 'purchase_management', 'brand_management', 'category_management', 'supplier_management', 'settings'];
    adminPermissions.forEach(perm => {
        const checkbox = document.getElementById('perm_' + perm);
        if (checkbox) {
            checkbox.addEventListener('change', function() {
                if (this.checked && adminAccessCheckbox) {
                    adminAccessCheckbox.checked = true;
                }
            });
        }
    });
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>