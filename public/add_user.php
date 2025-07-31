<?php
$page_title = "회원 추가 - HOME K MART";
require_once __DIR__ . '/partials/header.php';

// 관리자/총괄관리자만 접근 가능
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>이 페이지에 접근할 권한이 없습니다.</p></div></div></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

$errors = [];
$username = '';
$full_name = '';
$email = '';
$role = 'user'; // 기본값
$store_id = null;
$stores = [];

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 지점 목록 가져오기
    $stores = $pdo->query("SELECT id, name FROM stores ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $errors[] = "데이터베이스 처리 중 오류가 발생했습니다: " . $e->getMessage();
}

// 현재 로그인한 사용자의 권한에 따라 생성 가능한 역할 정의
$allowed_roles = ['user'];
if ($_SESSION['role'] === 'super_admin') {
    $allowed_roles[] = 'admin';
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $role = $_POST['role'] ?? 'user';
    $store_id = $_POST['store_id'] ?? null;

    // 유효성 검사
    if (empty($username)) $errors[] = "아이디를 입력해주세요.";
    if (empty($full_name)) $errors[] = "이름을 입력해주세요.";
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "올바른 이메일을 입력해주세요.";
    if (empty($password) || strlen($password) < 8) $errors[] = "비밀번호는 8자 이상이어야 합니다.";
    if ($password !== $password_confirm) $errors[] = "비밀번호가 일치하지 않습니다.";
    if (!in_array($role, $allowed_roles)) $errors[] = "유효하지 않은 권한입니다.";
    if (!empty($store_id) && !filter_var($store_id, FILTER_VALIDATE_INT)) $errors[] = "유효하지 않은 지점입니다.";

    if (empty($errors) && isset($pdo)) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                $errors[] = "이미 사용 중인 아이디 또는 이메일입니다.";
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $insert_stmt = $pdo->prepare(
                    "INSERT INTO users (username, full_name, email, password, role, store_id) VALUES (?, ?, ?, ?, ?, ?)"
                );
                $insert_stmt->execute([$username, $full_name, $email, $hashed_password, $role, $store_id ?: null]);

                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' => "회원 '" . htmlspecialchars($username) . "'이(가) 성공적으로 추가되었습니다."
                ];
                header("Location: user_management.php");
                exit;
            }
        } catch (PDOException $e) {
            $errors[] = "회원 추가 중 데이터베이스 오류가 발생했습니다: " . $e->getMessage();
        }
    }
}
?>

<!-- Page header -->
<div class="mb-8">
    <a href="user_management.php" class="inline-flex items-center text-sm font-medium text-gray-500 hover:text-gray-700 mb-4">
        <i class="fas fa-arrow-left mr-2"></i>
        회원 목록으로 돌아가기
    </a>
    <h1 class="text-3xl font-bold text-gray-900">새 회원 추가</h1>
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

    <!-- User Information Section -->
    <div>
        <h2 class="text-lg font-medium leading-6 text-gray-900">기본 정보</h2>
        <p class="mt-1 text-sm text-gray-500">회원의 계정 정보와 개인 정보를 입력합니다.</p>
        <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-3">
                <label for="username" class="block text-sm font-medium text-gray-700">아이디</label>
                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            </div>
            <div class="sm:col-span-3">
                <label for="full_name" class="block text-sm font-medium text-gray-700">이름</label>
                <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($full_name); ?>" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            </div>
            <div class="sm:col-span-6">
                <label for="email" class="block text-sm font-medium text-gray-700">이메일</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            </div>
        </div>
    </div>

    <!-- Role & Store Section -->
    <div class="border-t border-gray-200 pt-8">
        <h2 class="text-lg font-medium leading-6 text-gray-900">권한 및 소속</h2>
        <p class="mt-1 text-sm text-gray-500">회원의 권한과 소속 지점을 설정합니다.</p>
        <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-3">
                <label for="role" class="block text-sm font-medium text-gray-700">권한</label>
                <select id="role" name="role" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                    <?php foreach ($allowed_roles as $role_value): ?>
                        <option value="<?php echo $role_value; ?>" <?php echo ($role === $role_value) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(ucfirst($role_value)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sm:col-span-3">
                <label for="store_id" class="block text-sm font-medium text-gray-700">소속 지점 <span class="text-gray-500">(선택 사항)</span></label>
                <select id="store_id" name="store_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                    <option value="">-- 지점 선택 --</option>
                    <?php foreach ($stores as $store): ?>
                        <option value="<?php echo $store['id']; ?>" <?php echo ($store_id == $store['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($store['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
    
    <!-- Password Section -->
    <div class="border-t border-gray-200 pt-8">
        <h2 class="text-lg font-medium leading-6 text-gray-900">비밀번호 설정</h2>
        <p class="mt-1 text-sm text-gray-500">회원이 사용할 초기 비밀번호를 설정합니다.</p>
        <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-3">
                <label for="password" class="block text-sm font-medium text-gray-700">비밀번호</label>
                <input type="password" id="password" name="password" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                 <p class="mt-2 text-xs text-gray-500">8자 이상의 영문, 숫자를 포함해야 합니다.</p>
            </div>
            <div class="sm:col-span-3">
                <label for="password_confirm" class="block text-sm font-medium text-gray-700">비밀번호 확인</label>
                <input type="password" id="password_confirm" name="password_confirm" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            </div>
        </div>
    </div>

    <!-- Form Actions -->
    <div class="pt-8 border-t border-gray-200 flex justify-end gap-x-3">
        <a href="user_management.php" class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
            취소
        </a>
        <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
            <i class="fas fa-user-plus mr-2"></i>
            회원 추가
        </button>
    </div>
</form>

<?php require_once __DIR__ . '/partials/footer.php'; ?>