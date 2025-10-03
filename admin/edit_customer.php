<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('customer.edit') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';

// 고객 관리 권한 확인
require_permission('customer_management');

$customer_id = $_GET['id'] ?? 0;
$errors = [];
$customer = null;
$stores = [];

// 고객 정보 불러오기
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 점포 목록 가져오기
    $stores = $pdo->query("SELECT id, name FROM stores ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => t('customer.not_found')
        ];
        header('Location: customer_management.php');
        exit;
    }

    // 권한 확인: admin은 자기 점포 고객만 수정 가능
    if ($_SESSION['role'] !== 'super_admin' && !empty($_SESSION['store_id'])) {
        if ($customer['preferred_store_id'] != $_SESSION['store_id'] && !empty($customer['preferred_store_id'])) {
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => t('messages.permission_denied')
            ];
            header('Location: customer_management.php');
            exit;
        }
    }

} catch (PDOException $e) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => t('common.error') . ': ' . $e->getMessage()
    ];
    header('Location: customer_management.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 입력값 검증
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $birth_date = trim($_POST['birth_date'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $preferred_store_id = $_POST['preferred_store_id'] ?? null;
    $status = $_POST['status'] ?? 'active';
    $latitude = trim($_POST['latitude'] ?? '');
    $longitude = trim($_POST['longitude'] ?? '');

    // 유효성 검사
    if (empty($name)) {
        $errors[] = t('customer.name_required');
    }

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = t('customer.valid_email_required');
    }

    // 좌표 유효성 검사 (있을 경우만)
    if (!empty($latitude) && !is_numeric($latitude)) {
        $errors[] = t('customer.invalid_latitude');
    }
    if (!empty($longitude) && !is_numeric($longitude)) {
        $errors[] = t('customer.invalid_longitude');
    }

    if (empty($errors)) {
        try {
            // 이메일 중복 확인 (자기 제외)
            $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE email = ? AND id != ?");
            $check_stmt->execute([$email, $customer_id]);

            if ($check_stmt->fetchColumn() > 0) {
                $errors[] = t('customer.email_already_exists');
            } else {
                // 고객 정보 수정
                $stmt = $pdo->prepare("
                    UPDATE customers
                    SET name = ?,
                        email = ?,
                        phone = ?,
                        address = ?,
                        birth_date = ?,
                        gender = ?,
                        preferred_store_id = ?,
                        status = ?,
                        latitude = ?,
                        longitude = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");

                // NULL 처리
                $birth_date = !empty($birth_date) ? $birth_date : null;
                $gender = !empty($gender) ? $gender : null;
                $preferred_store_id = !empty($preferred_store_id) ? $preferred_store_id : null;
                $latitude = !empty($latitude) ? $latitude : null;
                $longitude = !empty($longitude) ? $longitude : null;

                if ($stmt->execute([
                    $name,
                    $email,
                    $phone,
                    $address,
                    $birth_date,
                    $gender,
                    $preferred_store_id,
                    $status,
                    $latitude,
                    $longitude,
                    $customer_id
                ])) {
                    $_SESSION['flash'] = [
                        'type' => 'success',
                        'message' => t('customer.updated_successfully')
                    ];
                    header('Location: customer_management.php');
                    exit;
                } else {
                    $errors[] = t('customer.update_failed');
                }
            }
        } catch (PDOException $e) {
            $errors[] = t('common.error') . ': ' . $e->getMessage();
        }
    }

    // POST 데이터로 폼 값 업데이트
    $customer['name'] = $name;
    $customer['email'] = $email;
    $customer['phone'] = $phone;
    $customer['address'] = $address;
    $customer['birth_date'] = $birth_date;
    $customer['gender'] = $gender;
    $customer['preferred_store_id'] = $preferred_store_id;
    $customer['status'] = $status;
    $customer['latitude'] = $latitude;
    $customer['longitude'] = $longitude;
}

// 플래시 메시지 표시
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="max-w-4xl mx-auto">
        <div class="mb-6">
            <nav class="flex" aria-label="Breadcrumb">
                <ol class="flex items-center space-x-2">
                    <li>
                        <a href="customer_management.php" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-users mr-1"></i>
                            <?php echo t('navigation.customer_management'); ?>
                        </a>
                    </li>
                    <li>
                        <div class="flex items-center">
                            <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                            <span class="text-gray-600"><?php echo t('customer.edit'); ?></span>
                        </div>
                    </li>
                </ol>
            </nav>
        </div>

        <div class="bg-white shadow-sm rounded-lg border">
            <div class="px-6 py-4 border-b border-gray-200">
                <h1 class="text-xl font-semibold text-gray-900">
                    <i class="fas fa-user-edit mr-2 text-primary-500"></i>
                    <?php echo t('customer.edit'); ?>
                </h1>
                <p class="mt-1 text-sm text-gray-600"><?php echo t('customer.edit_description'); ?></p>
            </div>

            <div class="px-6 py-4">
                <?php if (isset($flash)): ?>
                    <div class="mb-6 p-4 rounded-md <?php echo $flash['type'] === 'error' ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200'; ?>">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas <?php echo $flash['type'] === 'error' ? 'fa-exclamation-triangle text-red-400' : 'fa-check-circle text-green-400'; ?>"></i>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm <?php echo $flash['type'] === 'error' ? 'text-red-700' : 'text-green-700'; ?>">
                                    <?php echo htmlspecialchars($flash['message']); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-md">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-triangle text-red-400"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-red-800"><?php echo t('forms.errors_occurred'); ?></h3>
                                <ul class="mt-2 text-sm text-red-700 list-disc list-inside">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-8">
                    <!-- 기본 정보 섹션 -->
                    <div class="border-b border-gray-200 pb-8">
                        <h2 class="text-lg font-medium text-gray-900 mb-4">
                            <i class="fas fa-info-circle mr-2 text-blue-500"></i>
                            <?php echo t('customer.basic_info'); ?>
                        </h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700">
                                    <?php echo t('customer.name'); ?> <span class="text-red-500">*</span>
                                </label>
                                <input type="text" name="name" id="name" required
                                       value="<?php echo htmlspecialchars($customer['name']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                       placeholder="<?php echo t('customer.name_placeholder'); ?>">
                            </div>

                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700">
                                    <?php echo t('customer.email'); ?> <span class="text-red-500">*</span>
                                </label>
                                <input type="email" name="email" id="email" required
                                       value="<?php echo htmlspecialchars($customer['email']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                       placeholder="example@email.com">
                            </div>

                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700">
                                    <?php echo t('customer.phone'); ?>
                                </label>
                                <input type="tel" name="phone" id="phone"
                                       value="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                       placeholder="+63 917 123 4567">
                            </div>

                            <div>
                                <label for="birth_date" class="block text-sm font-medium text-gray-700">
                                    <?php echo t('customer.birth_date'); ?>
                                </label>
                                <input type="date" name="birth_date" id="birth_date"
                                       value="<?php echo htmlspecialchars($customer['birth_date'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                            </div>

                            <div>
                                <label for="gender" class="block text-sm font-medium text-gray-700">
                                    <?php echo t('customer.gender'); ?>
                                </label>
                                <select name="gender" id="gender"
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                                    <option value=""><?php echo t('customer.select_gender'); ?></option>
                                    <option value="M" <?php echo ($customer['gender'] ?? '') === 'M' ? 'selected' : ''; ?>><?php echo t('customer.gender_male'); ?></option>
                                    <option value="F" <?php echo ($customer['gender'] ?? '') === 'F' ? 'selected' : ''; ?>><?php echo t('customer.gender_female'); ?></option>
                                    <option value="O" <?php echo ($customer['gender'] ?? '') === 'O' ? 'selected' : ''; ?>><?php echo t('customer.gender_other'); ?></option>
                                </select>
                            </div>

                            <div>
                                <label for="status" class="block text-sm font-medium text-gray-700">
                                    <?php echo t('customer.status'); ?>
                                </label>
                                <select name="status" id="status"
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                                    <option value="active" <?php echo $customer['status'] === 'active' ? 'selected' : ''; ?>><?php echo t('customer.status_active'); ?></option>
                                    <option value="inactive" <?php echo $customer['status'] === 'inactive' ? 'selected' : ''; ?>><?php echo t('customer.status_inactive'); ?></option>
                                    <option value="suspended" <?php echo $customer['status'] === 'suspended' ? 'selected' : ''; ?>><?php echo t('customer.status_suspended'); ?></option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- 주소 정보 섹션 (필리핀 주소 체계) -->
                    <div class="border-b border-gray-200 pb-8">
                        <h2 class="text-lg font-medium text-gray-900 mb-4">
                            <i class="fas fa-map-marker-alt mr-2 text-red-500"></i>
                            <?php echo t('customer.address'); ?>
                        </h2>
                        <div class="space-y-6">
                            <div>
                                <label for="address" class="block text-sm font-medium text-gray-700">
                                    <?php echo t('customer.full_address'); ?>
                                </label>
                                <textarea name="address" id="address" rows="3"
                                          class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                          placeholder="Enter complete address (e.g., 123 Main Street, Barangay Santo Rosario, Angeles City, Pampanga 2009)"><?php echo htmlspecialchars($customer['address'] ?? ''); ?></textarea>
                                <p class="mt-1 text-xs text-gray-500"><?php echo t('customer.address_help'); ?></p>
                            </div>

                            <!-- 좌표 정보 (읽기 전용 - 향후 구글맵 연동) -->
                            <div class="bg-gray-50 p-4 rounded-md">
                                <h3 class="text-sm font-medium text-gray-700 mb-3">
                                    <i class="fas fa-map mr-1"></i>
                                    <?php echo t('customer.coordinates'); ?>
                                </h3>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label for="latitude" class="block text-xs font-medium text-gray-600">
                                            <?php echo t('customer.latitude'); ?>
                                        </label>
                                        <input type="text" name="latitude" id="latitude" readonly
                                               value="<?php echo htmlspecialchars($customer['latitude'] ?? ''); ?>"
                                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100 text-sm"
                                               placeholder="15.1456">
                                    </div>
                                    <div>
                                        <label for="longitude" class="block text-xs font-medium text-gray-600">
                                            <?php echo t('customer.longitude'); ?>
                                        </label>
                                        <input type="text" name="longitude" id="longitude" readonly
                                               value="<?php echo htmlspecialchars($customer['longitude'] ?? ''); ?>"
                                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100 text-sm"
                                               placeholder="120.5892">
                                    </div>
                                </div>
                                <p class="mt-2 text-xs text-gray-500">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    <?php echo t('customer.coordinates_help'); ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- 점포 정보 섹션 -->
                    <div class="border-b border-gray-200 pb-8">
                        <h2 class="text-lg font-medium text-gray-900 mb-4">
                            <i class="fas fa-store mr-2 text-green-500"></i>
                            <?php echo t('customer.preferred_store'); ?>
                        </h2>
                        <div>
                            <label for="preferred_store_id" class="block text-sm font-medium text-gray-700">
                                <?php echo t('customer.preferred_store'); ?>
                            </label>
                            <select name="preferred_store_id" id="preferred_store_id"
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                                <option value=""><?php echo t('customer.no_preferred_store'); ?></option>
                                <?php foreach ($stores as $store): ?>
                                    <option value="<?php echo $store['id']; ?>" <?php echo ($customer['preferred_store_id'] ?? '') == $store['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($store['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="mt-1 text-xs text-gray-500"><?php echo t('customer.preferred_store_help'); ?></p>
                        </div>
                    </div>

                    <!-- 계정 정보 -->
                    <div class="bg-gray-50 rounded-md p-4">
                        <h3 class="text-sm font-medium text-gray-700 mb-3"><?php echo t('customer.account_info'); ?></h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm text-gray-600">
                            <div>
                                <strong><?php echo t('customer.created_at'); ?>:</strong>
                                <?php echo date('Y-m-d H:i:s', strtotime($customer['created_at'])); ?>
                            </div>
                            <?php if ($customer['updated_at']): ?>
                            <div>
                                <strong><?php echo t('customer.updated_at'); ?>:</strong>
                                <?php echo date('Y-m-d H:i:s', strtotime($customer['updated_at'])); ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($customer['last_login_at']): ?>
                            <div>
                                <strong><?php echo t('customer.last_login'); ?>:</strong>
                                <?php echo date('Y-m-d H:i:s', strtotime($customer['last_login_at'])); ?>
                            </div>
                            <?php endif; ?>
                            <div>
                                <strong><?php echo t('customer.email_verified'); ?>:</strong>
                                <?php echo $customer['email_verified'] ? t('common.yes') : t('common.no'); ?>
                            </div>
                        </div>
                    </div>

                    <!-- 버튼 -->
                    <div class="flex justify-end space-x-4 pt-4">
                        <a href="customer_management.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            <i class="fas fa-arrow-left mr-2"></i>
                            <?php echo t('common.back_to_list'); ?>
                        </a>
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            <i class="fas fa-save mr-2"></i>
                            <?php echo t('common.save'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
