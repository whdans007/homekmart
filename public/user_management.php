<?php
$page_title = "회원관리 - HOME K MART";
require_once __DIR__ . '/partials/header.php';

// 회원관리 권한 확인
if (!has_permission('user_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => '회원관리에 접근할 권한이 없습니다.'
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
    $error_message = "데이터베이스에서 사용자 목록을 불러오는 데 실패했습니다.";
    // error_log($e->getMessage()); // 실제 운영 환경에서는 로그 파일에 기록합니다.
}
?>

<!-- Page header -->
<div class="mb-8 sm:flex sm:items-center sm:justify-between">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">회원 목록</h1>
        <p class="mt-2 text-sm text-gray-700">시스템에 등록된 모든 회원을 관리합니다.</p>
    </div>
    <div class="mt-4 sm:mt-0">
        <a href="add_user.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors duration-200">
            <i class="fas fa-user-plus mr-2"></i>
            회원 추가
        </a>
    </div>
</div>

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
    <!-- Users Table -->
    <div class="bg-white shadow overflow-hidden sm:rounded-md border border-gray-300">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 border-collapse border border-gray-300">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">ID</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">아이디</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">이름</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">이메일</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">핸드폰</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">권한</th>
                        <?php if ($has_permissions_column): ?>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">세부 권한</th>
                        <?php endif; ?>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">소속 지점</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">가입일</th>
                        <th scope="col" class="relative px-6 py-3 border border-gray-300">
                            <span class="sr-only">작업</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($users as $user): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 border border-gray-300"><?php echo htmlspecialchars($user['id']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 border border-gray-300"><?php echo htmlspecialchars($user['username']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 border border-gray-300"><?php echo htmlspecialchars($user['full_name']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 border border-gray-300"><?php echo htmlspecialchars($user['email']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 border border-gray-300">
                                <?php echo $has_phone_column && !empty($user['phone']) ? htmlspecialchars($user['phone']) : '<span class="text-gray-400">정보없음</span>'; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap border border-gray-300">
                                <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full 
                                    <?php 
                                    switch($user['role']) {
                                        case 'super_admin': echo 'bg-purple-100 text-purple-800'; break;
                                        case 'admin': echo 'bg-blue-100 text-blue-800'; break;
                                        case 'staff': echo 'bg-green-100 text-green-800'; break;
                                        case 'office_staff': echo 'bg-yellow-100 text-yellow-800'; break;
                                        default: echo 'bg-gray-100 text-gray-800'; break;
                                    }
                                    $role_labels = [
                                        'user' => '일반 사용자',
                                        'staff' => '직원',
                                        'office_staff' => '오피스 스텝',
                                        'admin' => '관리자',
                                        'super_admin' => '총괄 관리자'
                                    ];
                                    ?>">
                                    <?php echo htmlspecialchars($role_labels[$user['role']] ?? $user['role']); ?>
                                </span>
                            </td>
                            <?php if ($has_permissions_column): ?>
                            <td class="px-6 py-4 border border-gray-300">
                                <?php 
                                $permissions_info = '';
                                if ($user['role'] === 'super_admin') {
                                    $permissions_info = '<span class="text-xs text-purple-600">모든 권한</span>';
                                } else if (!empty($user['permissions'])) {
                                    $permissions = json_decode($user['permissions'], true);
                                    if (is_array($permissions)) {
                                        $active_permissions = array_filter($permissions);
                                        $permission_labels = [
                                            'admin_access' => '관리자',
                                            'user_management' => '회원',
                                            'store_management' => '지점',
                                            'product_management' => '상품',
                                            'purchase_management' => '매입',
                                            'brand_management' => '브랜드',
                                            'category_management' => '카테고리',
                                            'supplier_management' => '공급처',
                                            'settings' => '설정',
                                            'shop_access' => '쇼핑',
                                            'barcode_management' => '바코드',
                                            'accounting_management' => '회계'
                                        ];
                                        
                                        $permission_names = [];
                                        foreach ($active_permissions as $perm => $value) {
                                            if ($value && isset($permission_labels[$perm])) {
                                                $permission_names[] = $permission_labels[$perm];
                                            }
                                        }
                                        
                                        if (!empty($permission_names)) {
                                            $permissions_info = '<div class="flex flex-wrap gap-1">';
                                            foreach (array_slice($permission_names, 0, 3) as $name) {
                                                $permissions_info .= '<span class="inline-block px-1 py-0.5 text-xs bg-gray-100 text-gray-700 rounded">' . $name . '</span>';
                                            }
                                            if (count($permission_names) > 3) {
                                                $permissions_info .= '<span class="text-xs text-gray-500">+' . (count($permission_names) - 3) . '</span>';
                                            }
                                            $permissions_info .= '</div>';
                                        } else {
                                            $permissions_info = '<span class="text-xs text-gray-400">권한 없음</span>';
                                        }
                                    } else {
                                        $permissions_info = '<span class="text-xs text-gray-400">기본 권한</span>';
                                    }
                                } else {
                                    $permissions_info = '<span class="text-xs text-gray-400">기본 권한</span>';
                                }
                                echo $permissions_info;
                                ?>
                            </td>
                            <?php endif; ?>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 border border-gray-300">
                                <?php echo htmlspecialchars($user['store_name'] ?? '미지정'); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 border border-gray-300">
                                <?php echo date('Y-m-d', strtotime($user['created_at'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium border border-gray-300">
                                <div class="flex space-x-2">
                                    <a href="edit_user.php?id=<?php echo $user['id']; ?>" 
                                       class="text-primary-600 hover:text-primary-900 transition-colors duration-200">
                                        <i class="fas fa-edit mr-1"></i>수정
                                    </a>
                                    <a href="delete_user.php?id=<?php echo $user['id']; ?>" 
                                       class="text-red-600 hover:text-red-900 transition-colors duration-200"
                                       onclick="return confirm('정말로 이 회원을 삭제하시겠습니까?');">
                                        <i class="fas fa-trash mr-1"></i>삭제
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="<?php echo $has_permissions_column ? ($has_phone_column ? '10' : '9') : ($has_phone_column ? '9' : '8'); ?>" class="px-6 py-12 text-center text-sm text-gray-500 border border-gray-300">
                                <div class="flex flex-col items-center">
                                    <i class="fas fa-users text-4xl text-gray-300 mb-4"></i>
                                    <p>등록된 회원이 없습니다.</p>
                                    <a href="add_user.php" class="mt-2 text-primary-600 hover:text-primary-500">
                                        첫 번째 회원을 추가해보세요
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