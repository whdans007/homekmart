<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('user.profile') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../lib/store_change_request_helper.php';

$errors = [];
$success_message = '';
$user_data = [];

// 현재 사용자 정보 가져오기
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // phone 컬럼 존재 여부 확인
    $phone_check = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'phone'");
    $phone_check->execute();
    $has_phone_column = $phone_check->fetch();

    // 현재 사용자 정보 조회
    $select_fields = "id, username, full_name, email, role, store_id";
    if ($has_phone_column) {
        $select_fields .= ", phone";
    }
    
    $stmt = $pdo->prepare("SELECT {$select_fields} FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user_data) {
        throw new Exception(t('messages.user_not_found'));
    }

    // 지점 목록 가져오기
    $stores_stmt = $pdo->query("SELECT id, name FROM stores ORDER BY name ASC");
    $stores = $stores_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $errors[] = t('messages.database_error') . ': ' . $e->getMessage();
}

// 폼 처리
$original_store_id = $user_data['store_id'] ?? null;
$form_action = ($_SERVER["REQUEST_METHOD"] == "POST") ? ($_POST['form_action'] ?? 'profile') : '';

// [발령] 지점 변경 요청 처리 (CEO 미만 사용자)
if ($_SERVER["REQUEST_METHOD"] == "POST" && !empty($user_data) && $form_action === 'store_change_request') {
    $to_store_id = $_POST['to_store_id'] ?? '';
    $reason = trim($_POST['reason'] ?? '');
    if (can_change_store_directly()) {
        $errors[] = 'CEO 이상은 요청 없이 직접 변경할 수 있습니다.';
    } elseif (empty($to_store_id) || !filter_var($to_store_id, FILTER_VALIDATE_INT)) {
        $errors[] = t('user.invalid_store');
    } else {
        $res = create_store_change_request($_SESSION['user_id'], (int)$to_store_id, $reason, $_SESSION['user_id']);
        if ($res === true) {
            $success_message = '지점 변경 요청이 접수되었습니다. 도착 지점 점장(또는 CEO 이상) 승인 후 반영됩니다.';
        } else {
            $errors[] = $res;
        }
    }
}

// 프로필 저장 처리 (지점 변경 요청이 아닌 경우)
if ($_SERVER["REQUEST_METHOD"] == "POST" && !empty($user_data) && $form_action !== 'store_change_request') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $store_id = $_POST['store_id'] ?? '';
    // 소속 지점 직접 변경은 CEO 이상만 가능 (그 외에는 변경 무시)
    if (!can_change_store_directly()) {
        $store_id = $original_store_id;
    }
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $new_password_confirm = $_POST['new_password_confirm'] ?? '';

    // 유효성 검사
    if (empty($full_name)) $errors[] = t('forms.required_field');
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = t('forms.invalid_email');
    
    // 지점 유효성 검사
    if (!empty($store_id) && !filter_var($store_id, FILTER_VALIDATE_INT)) {
        $errors[] = t('user.invalid_store');
    }
    
    // 비밀번호 변경 시 유효성 검사
    if (!empty($new_password)) {
        if (empty($current_password)) $errors[] = t('forms.current_password_required');
        if (strlen($new_password) < 8) $errors[] = t('forms.password_min_length');
        if ($new_password !== $new_password_confirm) $errors[] = t('forms.password_mismatch');
    }

    if (empty($errors)) {
        try {
            // 현재 비밀번호 확인 (비밀번호 변경시에만)
            if (!empty($new_password)) {
                $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $stored_password = $stmt->fetchColumn();
                
                if (!password_verify($current_password, $stored_password)) {
                    $errors[] = t('forms.incorrect_current_password');
                }
            }

            if (empty($errors)) {
                // 업데이트 쿼리 준비
                $update_fields = ["full_name = ?", "email = ?", "store_id = ?"];
                $params = [$full_name, $email, $store_id ?: null];
                
                if ($has_phone_column) {
                    $update_fields[] = "phone = ?";
                    $params[] = $phone;
                }
                
                if (!empty($new_password)) {
                    $update_fields[] = "password = ?";
                    $params[] = password_hash($new_password, PASSWORD_DEFAULT);
                }
                
                $params[] = $_SESSION['user_id'];
                
                $update_query = "UPDATE users SET " . implode(", ", $update_fields) . " WHERE id = ?";
                $stmt = $pdo->prepare($update_query);
                $stmt->execute($params);

                // 세션 정보 업데이트
                $_SESSION['full_name'] = $full_name;
                $_SESSION['store_id'] = $store_id;

                $success_message = t('user.profile_updated');
                
                // 업데이트된 정보 다시 가져오기
                $select_fields = "id, username, full_name, email, role, store_id";
                if ($has_phone_column) {
                    $select_fields .= ", phone";
                }
                
                $stmt = $pdo->prepare("SELECT {$select_fields} FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $user_data = $stmt->fetch(PDO::FETCH_ASSOC);

            }
        } catch (PDOException $e) {
            $errors[] = t('user.profile_update_error') . ': ' . $e->getMessage();
        }
    }
}

// 지점 변경 UI 표시용 데이터
$stores = $stores ?? [];
$can_direct_store = can_change_store_directly();
$pending_request = !empty($user_data) ? get_user_pending_store_change($_SESSION['user_id']) : null;

$current_store_name = '';
$pending_to_name = '';
foreach ($stores as $s) {
    if (!empty($user_data) && $s['id'] == $user_data['store_id']) {
        $current_store_name = $s['name'];
    }
    if ($pending_request && $s['id'] == $pending_request['to_store_id']) {
        $pending_to_name = $s['name'];
    }
}
?>

<!-- Page header -->
<div class="mb-8">
    <h1 class="text-3xl font-bold text-gray-900"><?php echo t('user.profile'); ?></h1>
    <p class="mt-2 text-sm text-gray-700"><?php echo t('user.profile_desc'); ?></p>
</div>

<?php if (!empty($errors)): ?>
    <div class="mb-6 bg-red-50 border border-red-200 rounded-md p-4">
        <div class="flex">
            <div class="flex-shrink-0">
                <i class="fas fa-exclamation-circle text-red-400"></i>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-red-800"><?php echo t('common.error'); ?></h3>
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

<?php if ($success_message): ?>
    <div class="mb-6 bg-green-50 border border-green-200 rounded-md p-4">
        <div class="flex">
            <div class="flex-shrink-0">
                <i class="fas fa-check-circle text-green-400"></i>
            </div>
            <div class="ml-3">
                <p class="text-sm text-green-800"><?php echo htmlspecialchars($success_message); ?></p>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($user_data)): ?>
<!-- Profile Form -->
<form action="user_profile.php" method="post" class="space-y-8 bg-white shadow sm:rounded-lg p-6">
    <!-- 기본 정보 Section -->
    <div>
        <h2 class="text-lg font-medium leading-6 text-gray-900"><?php echo t('user.basic_info'); ?></h2>
        <p class="mt-1 text-sm text-gray-500"><?php echo t('user.edit_personal_info'); ?></p>
        <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-3">
                <label for="username" class="block text-sm font-medium text-gray-700"><?php echo t('auth.username'); ?></label>
                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($user_data['username']); ?>" readonly class="mt-1 block w-full border-gray-300 rounded-md shadow-sm bg-gray-50 sm:text-sm cursor-not-allowed">
                <p class="mt-1 text-xs text-gray-500"><?php echo t('user.username_readonly'); ?></p>
            </div>
            <div class="sm:col-span-3">
                <label for="full_name" class="block text-sm font-medium text-gray-700"><?php echo t('user.full_name'); ?></label>
                <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($user_data['full_name']); ?>" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            </div>
            <div class="sm:col-span-3">
                <label for="email" class="block text-sm font-medium text-gray-700"><?php echo t('auth.email'); ?></label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user_data['email']); ?>" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            </div>
            <?php if ($has_phone_column): ?>
            <div class="sm:col-span-3">
                <label for="phone" class="block text-sm font-medium text-gray-700"><?php echo t('user.phone'); ?></label>
                <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($user_data['phone'] ?? ''); ?>" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" placeholder="010-1234-5678">
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 계정 정보 Section -->
    <div class="border-t border-gray-200 pt-8">
        <h2 class="text-lg font-medium leading-6 text-gray-900"><?php echo t('user.account_info'); ?></h2>
        <p class="mt-1 text-sm text-gray-500"><?php echo t('user.account_info_desc'); ?></p>
        <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-3">
                <label for="role_display" class="block text-sm font-medium text-gray-700"><?php echo t('user.role'); ?></label>
                <input type="text" id="role_display" value="<?php 
                    $role_labels = [
                        'user' => t('roles.user'),
                        'staff' => t('roles.staff'),
                        'office_staff' => t('roles.office_staff'),
                        'admin' => t('roles.admin'),
                        'super_admin' => t('roles.super_admin')
                    ];
                    echo htmlspecialchars($role_labels[$user_data['role']] ?? $user_data['role']); 
                ?>" readonly class="mt-1 block w-full border-gray-300 rounded-md shadow-sm bg-gray-50 sm:text-sm cursor-not-allowed">
                <p class="mt-1 text-xs text-gray-500"><?php echo t('user.role_readonly'); ?></p>
            </div>
            <div class="sm:col-span-3">
                <label for="store_id" class="block text-sm font-medium text-gray-700"><?php echo t('user.store'); ?></label>
                <?php if ($can_direct_store): ?>
                <select id="store_id" name="store_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                    <option value=""><?php echo t('store.select_store'); ?></option>
                    <?php foreach ($stores as $store): ?>
                        <option value="<?php echo $store['id']; ?>" <?php echo ($user_data['store_id'] == $store['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($store['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="mt-1 text-xs text-gray-500"><i class="fas fa-crown mr-1 text-yellow-500"></i>CEO 권한: 소속 지점을 직접 변경할 수 있습니다.</p>
                <?php else: ?>
                <input type="text" value="<?php echo htmlspecialchars($current_store_name !== '' ? $current_store_name : '미지정'); ?>" readonly class="mt-1 block w-full border-gray-300 rounded-md shadow-sm bg-gray-50 sm:text-sm cursor-not-allowed">
                <?php if ($pending_request): ?>
                <p class="mt-1 text-xs text-yellow-600"><i class="fas fa-clock mr-1"></i>변경 요청 대기중: <?php echo htmlspecialchars($current_store_name ?: '미지정'); ?> → <?php echo htmlspecialchars($pending_to_name ?: '-'); ?> (점장 승인 대기)</p>
                <?php else: ?>
                <p class="mt-1 text-xs text-gray-500"><i class="fas fa-lock mr-1"></i>소속 지점 변경은 점장 승인이 필요합니다. 아래 '지점 변경(발령) 요청'에서 신청하세요.</p>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 비밀번호 변경 Section -->
    <div class="border-t border-gray-200 pt-8">
        <h2 class="text-lg font-medium leading-6 text-gray-900"><?php echo t('user.change_password'); ?></h2>
        <p class="mt-1 text-sm text-gray-500"><?php echo t('user.password_change_desc'); ?></p>
        <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-6">
                <label for="current_password" class="block text-sm font-medium text-gray-700"><?php echo t('user.current_password'); ?></label>
                <input type="password" id="current_password" name="current_password" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                <p class="mt-1 text-xs text-gray-500"><?php echo t('user.password_change_hint'); ?></p>
            </div>
            <div class="sm:col-span-3">
                <label for="new_password" class="block text-sm font-medium text-gray-700"><?php echo t('user.new_password'); ?></label>
                <input type="password" id="new_password" name="new_password" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                <p class="mt-1 text-xs text-gray-500"><?php echo t('user.password_requirements'); ?></p>
            </div>
            <div class="sm:col-span-3">
                <label for="new_password_confirm" class="block text-sm font-medium text-gray-700"><?php echo t('auth.password_confirm'); ?></label>
                <input type="password" id="new_password_confirm" name="new_password_confirm" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            </div>
        </div>
    </div>

    <!-- Form Actions -->
    <div class="pt-8 border-t border-gray-200 flex justify-end gap-x-3">
        <a href="index.php" class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
            <?php echo t('common.cancel'); ?>
        </a>
        <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
            <i class="fas fa-save mr-2"></i>
            <?php echo t('user.save_profile'); ?>
        </button>
    </div>
</form>

<?php if (!$can_direct_store && !$pending_request): ?>
<!-- 지점 변경(발령) 요청 -->
<form action="user_profile.php" method="post" class="mt-6 space-y-6 bg-white shadow sm:rounded-lg p-6">
    <input type="hidden" name="form_action" value="store_change_request">
    <div>
        <h2 class="text-lg font-medium leading-6 text-gray-900"><i class="fas fa-people-arrows mr-2 text-primary-600"></i>지점 변경(발령) 요청</h2>
        <p class="mt-1 text-sm text-gray-500">발령으로 소속 지점이 바뀌는 경우 요청하세요. 도착 지점의 점장(또는 CEO 이상) 승인 후 반영됩니다.</p>
    </div>
    <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
        <div class="sm:col-span-3">
            <label for="to_store_id" class="block text-sm font-medium text-gray-700">발령 지점</label>
            <select id="to_store_id" name="to_store_id" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                <option value=""><?php echo t('store.select_store'); ?></option>
                <?php foreach ($stores as $store): ?>
                    <?php if (!empty($user_data) && $store['id'] == $user_data['store_id']) continue; ?>
                    <option value="<?php echo $store['id']; ?>"><?php echo htmlspecialchars($store['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="sm:col-span-3">
            <label for="reason" class="block text-sm font-medium text-gray-700">사유 <span class="text-gray-500">(선택)</span></label>
            <input type="text" id="reason" name="reason" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" placeholder="예: 2026-07 인사발령">
        </div>
    </div>
    <div class="flex justify-end">
        <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
            <i class="fas fa-paper-plane mr-2"></i>요청 제출
        </button>
    </div>
</form>
<?php endif; ?>

<?php else: ?>
<div class="bg-red-50 border border-red-200 rounded-md p-4">
    <div class="flex">
        <div class="flex-shrink-0">
            <i class="fas fa-exclamation-circle text-red-400"></i>
        </div>
        <div class="ml-3">
            <p class="text-sm text-red-800"><?php echo t('user.profile_load_error'); ?></p>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const currentPasswordField = document.getElementById('current_password');
    const newPasswordField = document.getElementById('new_password');
    const newPasswordConfirmField = document.getElementById('new_password_confirm');

    // 새 비밀번호 입력 시 현재 비밀번호 필수로 만들기
    function updatePasswordRequirements() {
        const hasNewPassword = newPasswordField.value.length > 0 || newPasswordConfirmField.value.length > 0;
        currentPasswordField.required = hasNewPassword;
        newPasswordField.required = hasNewPassword;
        newPasswordConfirmField.required = hasNewPassword;
    }

    newPasswordField.addEventListener('input', updatePasswordRequirements);
    newPasswordConfirmField.addEventListener('input', updatePasswordRequirements);
    
    // 폼 제출 시 추가 검증
    document.querySelector('form').addEventListener('submit', function(e) {
        const newPassword = newPasswordField.value;
        const newPasswordConfirm = newPasswordConfirmField.value;
        const currentPassword = currentPasswordField.value;
        
        if ((newPassword || newPasswordConfirm) && !currentPassword) {
            e.preventDefault();
            alert('<?php echo t('forms.current_password_required'); ?>');
            currentPasswordField.focus();
            return false;
        }
        
        if (newPassword && newPassword !== newPasswordConfirm) {
            e.preventDefault();
            alert('<?php echo t('forms.password_mismatch'); ?>');
            newPasswordConfirmField.focus();
            return false;
        }
    });
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>