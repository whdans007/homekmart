<?php
$page_title = "회원 수정 - HOME K MART";
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 회원 관리 권한 확인
require_permission('user_management');

require_once __DIR__ . '/../config/db_config.php';

$user_id = $_GET['id'] ?? null;
if (!$user_id || !filter_var($user_id, FILTER_VALIDATE_INT)) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => "유효하지 않은 회원 ID입니다."];
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
        $_SESSION['flash'] = ['type' => 'error', 'message' => "회원을 찾을 수 없습니다."];
        header('Location: user_management.php');
        exit;
    }

    // 권한 확인: admin은 super_admin을 수정할 수 없음
    if ($_SESSION['role'] === 'admin' && $user['role'] === 'super_admin') {
        $_SESSION['flash'] = ['type' => 'error', 'message' => "총괄 관리자 정보는 수정할 수 없습니다."];
        header('Location: user_management.php');
        exit;
    }

    // 지점 목록 가져오기
    $stores = $pdo->query("SELECT id, name FROM stores ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>데이터베이스 연결에 실패했습니다: " . htmlspecialchars($e->getMessage()) . "</p></div></div></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

// 현재 로그인한 사용자의 권한에 따라 변경 가능한 역할 정의
$editable_roles = ['user'];
if ($_SESSION['role'] === 'super_admin') {
    $editable_roles = array_merge($editable_roles, ['admin', 'staff', 'office_staff']);
} elseif ($_SESSION['role'] === 'admin') {
    $editable_roles = array_merge($editable_roles, ['staff', 'office_staff']);
}

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
    if (empty($user['full_name'])) $errors[] = "이름을 입력해주세요.";
    if (empty($user['email']) || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) $errors[] = "올바른 이메일을 입력해주세요.";
    
    // 자기 자신의 권한 변경 시도 방지
    if ($user['id'] == $_SESSION['user_id'] && isset($_POST['role']) && $_POST['role'] != $_SESSION['role']) {
        $errors[] = "자신의 권한은 변경할 수 없습니다.";
        $user['role'] = $_SESSION['role']; // 원래 권한으로 되돌림
    } elseif (isset($_POST['role']) && !in_array($_POST['role'], $editable_roles) && $user['role'] !== 'super_admin') {
        $errors[] = "유효하지 않은 권한입니다.";
    }

    if (!empty($user['store_id']) && !filter_var($user['store_id'], FILTER_VALIDATE_INT)) {
        $errors[] = "유효하지 않은 지점입니다.";
    }

    if (!empty($password)) {
        if (strlen($password) < 8) $errors[] = "새 비밀번호는 8자 이상이어야 합니다.";
        if ($password !== $password_confirm) $errors[] = "새 비밀번호가 일치하지 않습니다.";
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$user['email'], $user_id]);
            if ($stmt->fetch()) {
                $errors[] = "이미 사용 중인 이메일입니다.";
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

                $_SESSION['flash'] = ['type' => 'success', 'message' => "회원 '" . htmlspecialchars($user['username']) . "' 정보가 성공적으로 수정되었습니다."];
                header("Location: user_management.php");
                exit();
            }
        } catch (PDOException $e) {
            $errors[] = "데이터베이스 오류가 발생했습니다: " . $e->getMessage();
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
    <h1 class="text-3xl font-bold text-gray-900">회원 정보 수정</h1>
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
        <p class="mt-1 text-sm text-gray-500">회원의 기본 정보를 수정합니다. 아이디는 변경할 수 없습니다.</p>
        <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-3">
                <label for="username" class="block text-sm font-medium text-gray-700">아이디</label>
                <input type="text" id="username" value="<?php echo htmlspecialchars($user['username']); ?>" readonly class="mt-1 block w-full border-gray-300 rounded-md shadow-sm bg-gray-100 cursor-not-allowed sm:text-sm">
            </div>
            <div class="sm:col-span-3">
                <label for="full_name" class="block text-sm font-medium text-gray-700">이름</label>
                <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            </div>
            <div class="sm:col-span-3">
                <label for="email" class="block text-sm font-medium text-gray-700">이메일</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            </div>
            <?php if ($has_phone_column): ?>
            <div class="sm:col-span-3">
                <label for="phone" class="block text-sm font-medium text-gray-700">핸드폰 번호 <span class="text-gray-500">(선택 사항)</span></label>
                <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" placeholder="010-1234-5678">
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Role & Store Section -->
    <div class="border-t border-gray-200 pt-8">
        <h2 class="text-lg font-medium leading-6 text-gray-900">권한 및 소속</h2>
        <p class="mt-1 text-sm text-gray-500">회원의 권한과 소속 지점을 설정합니다.</p>
        <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-3">
                <label for="role" class="block text-sm font-medium text-gray-700">권한</label>
                <select id="role" name="role" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm <?php if ($user['id'] == $_SESSION['user_id'] || $user['role'] === 'super_admin') echo 'bg-gray-100 cursor-not-allowed'; ?>" <?php if ($user['id'] == $_SESSION['user_id'] || $user['role'] === 'super_admin') echo 'disabled'; ?>>
                    <?php if ($user['role'] === 'super_admin'): ?>
                        <option value="super_admin" selected>총괄 관리자</option>
                    <?php else: ?>
                        <?php 
                        $role_labels = [
                            'user' => '일반 사용자',
                            'staff' => '직원',
                            'office_staff' => '오피스 스텝',
                            'admin' => '관리자',
                            'super_admin' => '총괄 관리자'
                        ];
                        foreach ($editable_roles as $role_value): ?>
                            <option value="<?php echo $role_value; ?>" <?php echo ($user['role'] === $role_value) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($role_labels[$role_value] ?? ucfirst($role_value)); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <?php if ($user['id'] == $_SESSION['user_id']): ?>
                    <p class="mt-2 text-sm text-gray-500">자신의 권한은 변경할 수 없습니다.</p>
                <?php elseif ($user['role'] === 'super_admin'): ?>
                    <p class="mt-2 text-sm text-gray-500">총괄 관리자의 권한은 변경할 수 없습니다.</p>
                <?php endif; ?>
            </div>
            <div class="sm:col-span-3">
                <label for="store_id" class="block text-sm font-medium text-gray-700">소속 지점</label>
                <select id="store_id" name="store_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                    <option value="">-- 지점 선택 --</option>
                    <?php foreach ($stores as $store): ?>
                        <option value="<?php echo $store['id']; ?>" <?php echo ($user['store_id'] == $store['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($store['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
    
    <!-- Permissions Section -->
    <div class="border-t border-gray-200 pt-8">
        <h2 class="text-lg font-medium leading-6 text-gray-900">세부 권한 설정</h2>
        <p class="mt-1 text-sm text-gray-500">사용자가 접근할 수 있는 기능을 개별적으로 설정합니다.</p>
        
        <div class="mt-6">
            <div id="permissions-container" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <?php 
                $all_permissions = [
                    'admin_access' => '관리자 메뉴 접근',
                    'user_management' => '회원 관리',
                    'store_management' => '지점 관리',
                    'product_management' => '상품 관리',
                    'purchase_management' => '매입 관리',
                    'brand_management' => '브랜드 관리',
                    'category_management' => '카테고리 관리',
                    'supplier_management' => '공급처 관리',
                    'settings' => '환경 설정',
                    'shop_access' => '쇼핑몰 접근',
                    'barcode_management' => '바코드 관리',
                    'accounting_management' => '회계 관리'
                ];
                
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
                        <p class="text-gray-500">일반 사용자는 이 권한만 가지는 것을 권장합니다.</p>
                        <?php elseif ($perm_key === 'admin_access'): ?>
                        <p class="text-gray-500">관리자 메뉴에 접근하려면 필수입니다.</p>
                        <?php elseif ($perm_key === 'barcode_management'): ?>
                        <p class="text-gray-500">바코드 출력 및 관리 기능입니다.</p>
                        <?php elseif ($perm_key === 'accounting_management'): ?>
                        <p class="text-gray-500">회계 데이터 입력 및 출력 기능입니다.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <?php if (!$is_disabled): ?>
            <div class="mt-4 flex space-x-2">
                <button type="button" id="preset-user" class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <i class="fas fa-user mr-2"></i>일반 사용자
                </button>
                <button type="button" id="preset-staff" class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <i class="fas fa-id-badge mr-2"></i>직원
                </button>
                <button type="button" id="preset-office-staff" class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <i class="fas fa-calculator mr-2"></i>오피스 스텝
                </button>
                <button type="button" id="preset-admin" class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <i class="fas fa-user-cog mr-2"></i>관리자
                </button>
                <button type="button" id="preset-super-admin" class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <i class="fas fa-user-shield mr-2"></i>총괄 관리자
                </button>
                <button type="button" id="clear-all" class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <i class="fas fa-times mr-2"></i>모두 해제
                </button>
            </div>
            <?php endif; ?>
            
            <?php if ($user['role'] === 'super_admin'): ?>
            <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-md">
                <p class="text-sm text-blue-800">
                    <i class="fas fa-info-circle mr-1"></i>
                    총괄 관리자는 모든 권한을 자동으로 가지며 변경할 수 없습니다.
                </p>
            </div>
            <?php elseif ($user['id'] == $_SESSION['user_id']): ?>
            <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded-md">
                <p class="text-sm text-yellow-800">
                    <i class="fas fa-exclamation-triangle mr-1"></i>
                    자신의 권한은 변경할 수 없습니다.
                </p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Password Section -->
    <div class="border-t border-gray-200 pt-8">
        <h2 class="text-lg font-medium leading-6 text-gray-900">비밀번호 변경</h2>
        <p class="mt-1 text-sm text-gray-500">비밀번호를 변경하려면 아래에 새 비밀번호를 입력하세요. 비워두면 현재 비밀번호가 유지됩니다.</p>
        <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-3">
                <label for="password" class="block text-sm font-medium text-gray-700">새 비밀번호 (8자 이상)</label>
                <input type="password" id="password" name="password" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            </div>
            <div class="sm:col-span-3">
                <label for="password_confirm" class="block text-sm font-medium text-gray-700">새 비밀번호 확인</label>
                <input type="password" id="password_confirm" name="password_confirm" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
            </div>
        </div>
    </div>
    
    <!-- Form Actions -->
    <div class="pt-8 border-t border-gray-200 flex justify-end gap-x-3">
        <a href="user_management.php" class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
            취소
        </a>
        <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
            <i class="fas fa-save mr-2"></i>
            정보 저장
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
            if (!checkbox.disabled) {
                checkbox.checked = permissionList.includes(checkbox.value);
            }
        });
    }
    
    // 프리셋 버튼 이벤트 리스너
    document.getElementById('preset-user')?.addEventListener('click', function() {
        setPermissions(presets.user);
        document.getElementById('role').value = 'user';
    });
    
    document.getElementById('preset-staff')?.addEventListener('click', function() {
        setPermissions(presets.staff);
        document.getElementById('role').value = 'staff';
    });
    
    document.getElementById('preset-office-staff')?.addEventListener('click', function() {
        setPermissions(presets.office_staff);
        document.getElementById('role').value = 'office_staff';
    });
    
    document.getElementById('preset-admin')?.addEventListener('click', function() {
        setPermissions(presets.admin);
        document.getElementById('role').value = 'admin';
    });
    
    document.getElementById('preset-super-admin')?.addEventListener('click', function() {
        setPermissions(presets.super_admin);
        document.getElementById('role').value = 'super_admin';
    });
    
    document.getElementById('clear-all')?.addEventListener('click', function() {
        setPermissions([]);
    });
    
    // 역할 변경 시 자동으로 권한 설정
    document.getElementById('role')?.addEventListener('change', function() {
        const role = this.value;
        if (presets[role]) {
            setPermissions(presets[role]);
        }
    });
    
    // 관리자 메뉴 접근 권한이 없으면 다른 관리 권한들도 자동으로 해제
    const adminAccessCheckbox = document.getElementById('perm_admin_access');
    if (adminAccessCheckbox) {
        adminAccessCheckbox.addEventListener('change', function() {
            if (!this.checked && !this.disabled) {
                // 관리자 메뉴 접근이 해제되면 다른 관리 권한들도 해제
                const adminPermissions = ['user_management', 'store_management', 'product_management', 'purchase_management', 'brand_management', 'category_management', 'supplier_management', 'settings'];
                adminPermissions.forEach(perm => {
                    const checkbox = document.getElementById('perm_' + perm);
                    if (checkbox && !checkbox.disabled) {
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
                if (this.checked && !this.disabled && adminAccessCheckbox && !adminAccessCheckbox.disabled) {
                    adminAccessCheckbox.checked = true;
                }
            });
        }
    });
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>