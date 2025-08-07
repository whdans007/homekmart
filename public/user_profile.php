<?php
$page_title = "내 프로필 - HOME K MART";
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

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
        throw new Exception("사용자 정보를 찾을 수 없습니다.");
    }

} catch (Exception $e) {
    $errors[] = "사용자 정보를 불러오는 데 실패했습니다: " . $e->getMessage();
}

// 폼 처리
if ($_SERVER["REQUEST_METHOD"] == "POST" && !empty($user_data)) {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $new_password_confirm = $_POST['new_password_confirm'] ?? '';

    // 유효성 검사
    if (empty($full_name)) $errors[] = "이름을 입력해주세요.";
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "올바른 이메일을 입력해주세요.";
    
    // 비밀번호 변경 시 유효성 검사
    if (!empty($new_password)) {
        if (empty($current_password)) $errors[] = "비밀번호 변경을 위해 현재 비밀번호를 입력해주세요.";
        if (strlen($new_password) < 8) $errors[] = "새 비밀번호는 8자 이상이어야 합니다.";
        if ($new_password !== $new_password_confirm) $errors[] = "새 비밀번호가 일치하지 않습니다.";
    }

    if (empty($errors)) {
        try {
            // 현재 비밀번호 확인 (비밀번호 변경시에만)
            if (!empty($new_password)) {
                $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $stored_password = $stmt->fetchColumn();
                
                if (!password_verify($current_password, $stored_password)) {
                    $errors[] = "현재 비밀번호가 올바르지 않습니다.";
                }
            }

            if (empty($errors)) {
                // 업데이트 쿼리 준비
                $update_fields = ["full_name = ?", "email = ?"];
                $params = [$full_name, $email];
                
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

                $success_message = "프로필이 성공적으로 업데이트되었습니다.";
                
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
            $errors[] = "프로필 업데이트 중 오류가 발생했습니다: " . $e->getMessage();
        }
    }
}
?>

<!-- Page header -->
<div class="mb-8">
    <h1 class="text-3xl font-bold text-gray-900">내 프로필</h1>
    <p class="mt-2 text-sm text-gray-700">개인 정보를 확인하고 수정할 수 있습니다.</p>
</div>

<?php if (!empty($errors)): ?>
    <div class="mb-6 bg-red-50 border border-red-200 rounded-md p-4">
        <div class="flex">
            <div class="flex-shrink-0">
                <i class="fas fa-exclamation-circle text-red-400"></i>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-red-800">문제가 발생했습니다.</h3>
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
        <h2 class="text-lg font-medium leading-6 text-gray-900">기본 정보</h2>
        <p class="mt-1 text-sm text-gray-500">개인 정보를 수정할 수 있습니다.</p>
        <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-3">
                <label for="username" class="block text-sm font-medium text-gray-700">아이디</label>
                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($user_data['username']); ?>" readonly class="mt-1 block w-full border-gray-300 rounded-md shadow-sm bg-gray-50 sm:text-sm cursor-not-allowed">
                <p class="mt-1 text-xs text-gray-500">아이디는 변경할 수 없습니다.</p>
            </div>
            <div class="sm:col-span-3">
                <label for="full_name" class="block text-sm font-medium text-gray-700">이름</label>
                <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($user_data['full_name']); ?>" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            </div>
            <div class="sm:col-span-3">
                <label for="email" class="block text-sm font-medium text-gray-700">이메일</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user_data['email']); ?>" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            </div>
            <?php if ($has_phone_column): ?>
            <div class="sm:col-span-3">
                <label for="phone" class="block text-sm font-medium text-gray-700">핸드폰 번호</label>
                <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($user_data['phone'] ?? ''); ?>" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" placeholder="010-1234-5678">
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 계정 정보 Section -->
    <div class="border-t border-gray-200 pt-8">
        <h2 class="text-lg font-medium leading-6 text-gray-900">계정 정보</h2>
        <p class="mt-1 text-sm text-gray-500">계정 권한과 소속 정보입니다.</p>
        <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-3">
                <label for="role_display" class="block text-sm font-medium text-gray-700">권한</label>
                <input type="text" id="role_display" value="<?php 
                    $role_labels = [
                        'user' => '일반 사용자',
                        'staff' => '직원',
                        'office_staff' => '오피스 스텝',
                        'admin' => '관리자',
                        'super_admin' => '총괄 관리자'
                    ];
                    echo htmlspecialchars($role_labels[$user_data['role']] ?? $user_data['role']); 
                ?>" readonly class="mt-1 block w-full border-gray-300 rounded-md shadow-sm bg-gray-50 sm:text-sm cursor-not-allowed">
                <p class="mt-1 text-xs text-gray-500">권한은 관리자만 변경할 수 있습니다.</p>
            </div>
            <div class="sm:col-span-3">
                <label for="store_display" class="block text-sm font-medium text-gray-700">소속 지점</label>
                <input type="text" id="store_display" value="<?php echo htmlspecialchars($current_store_name); ?>" readonly class="mt-1 block w-full border-gray-300 rounded-md shadow-sm bg-gray-50 sm:text-sm cursor-not-allowed">
                <p class="mt-1 text-xs text-gray-500">소속 지점은 관리자만 변경할 수 있습니다.</p>
            </div>
        </div>
    </div>

    <!-- 비밀번호 변경 Section -->
    <div class="border-t border-gray-200 pt-8">
        <h2 class="text-lg font-medium leading-6 text-gray-900">비밀번호 변경</h2>
        <p class="mt-1 text-sm text-gray-500">비밀번호를 변경하려면 아래 필드를 모두 입력해주세요.</p>
        <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-6">
                <label for="current_password" class="block text-sm font-medium text-gray-700">현재 비밀번호</label>
                <input type="password" id="current_password" name="current_password" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                <p class="mt-1 text-xs text-gray-500">비밀번호를 변경하지 않으려면 이 필드를 비워두세요.</p>
            </div>
            <div class="sm:col-span-3">
                <label for="new_password" class="block text-sm font-medium text-gray-700">새 비밀번호</label>
                <input type="password" id="new_password" name="new_password" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                <p class="mt-1 text-xs text-gray-500">8자 이상의 영문, 숫자를 포함해야 합니다.</p>
            </div>
            <div class="sm:col-span-3">
                <label for="new_password_confirm" class="block text-sm font-medium text-gray-700">새 비밀번호 확인</label>
                <input type="password" id="new_password_confirm" name="new_password_confirm" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            </div>
        </div>
    </div>

    <!-- Form Actions -->
    <div class="pt-8 border-t border-gray-200 flex justify-end gap-x-3">
        <a href="index.php" class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
            취소
        </a>
        <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
            <i class="fas fa-save mr-2"></i>
            프로필 저장
        </button>
    </div>
</form>

<?php else: ?>
<div class="bg-red-50 border border-red-200 rounded-md p-4">
    <div class="flex">
        <div class="flex-shrink-0">
            <i class="fas fa-exclamation-circle text-red-400"></i>
        </div>
        <div class="ml-3">
            <p class="text-sm text-red-800">사용자 정보를 불러올 수 없습니다.</p>
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
            alert('비밀번호를 변경하려면 현재 비밀번호를 입력해주세요.');
            currentPasswordField.focus();
            return false;
        }
        
        if (newPassword && newPassword !== newPasswordConfirm) {
            e.preventDefault();
            alert('새 비밀번호가 일치하지 않습니다.');
            newPasswordConfirmField.focus();
            return false;
        }
    });
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>