<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '배달 지역 설정 - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';

// 관리자 권한 확인
require_permission('admin_access');

$zone_id = $_GET['id'] ?? 0;
$errors = [];
$zone = null;

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 수정 모드: 기존 데이터 불러오기
    if ($zone_id) {
        $stmt = $pdo->prepare("SELECT * FROM delivery_zones WHERE id = ?");
        $stmt->execute([$zone_id]);
        $zone = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$zone) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => '배달 지역을 찾을 수 없습니다.'];
            header('Location: delivery_zone_management.php');
            exit;
        }
    }

} catch (PDOException $e) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '오류: ' . $e->getMessage()];
    header('Location: delivery_zone_management.php');
    exit;
}

// POST 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $zone_name = trim($_POST['zone_name'] ?? '');
    $barangay = trim($_POST['barangay'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $delivery_fee = trim($_POST['delivery_fee'] ?? '');
    $min_order_amount = trim($_POST['min_order_amount'] ?? '');
    $free_delivery_threshold = trim($_POST['free_delivery_threshold'] ?? '');
    $estimated_delivery_time = trim($_POST['estimated_delivery_time'] ?? '');
    $max_delivery_time = trim($_POST['max_delivery_time'] ?? '');
    $service_start_time = trim($_POST['service_start_time'] ?? '');
    $service_end_time = trim($_POST['service_end_time'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    // 유효성 검사
    if (empty($zone_name)) {
        $errors[] = '지역명을 입력해주세요.';
    }
    if (empty($city)) {
        $errors[] = '시/도시를 입력해주세요.';
    }
    if (empty($province)) {
        $errors[] = '주/지방을 입력해주세요.';
    }
    if (empty($delivery_fee) || !is_numeric($delivery_fee) || $delivery_fee < 0) {
        $errors[] = '올바른 배달비를 입력해주세요.';
    }
    if (!empty($min_order_amount) && (!is_numeric($min_order_amount) || $min_order_amount < 0)) {
        $errors[] = '올바른 최소 주문 금액을 입력해주세요.';
    }
    if (!empty($free_delivery_threshold) && (!is_numeric($free_delivery_threshold) || $free_delivery_threshold < 0)) {
        $errors[] = '올바른 무료 배달 임계값을 입력해주세요.';
    }
    if (empty($estimated_delivery_time) || !is_numeric($estimated_delivery_time) || $estimated_delivery_time < 0) {
        $errors[] = '올바른 예상 배달 시간을 입력해주세요.';
    }

    if (empty($errors)) {
        try {
            if ($zone_id) {
                // 업데이트
                $update_sql = "
                    UPDATE delivery_zones SET
                        zone_name = ?,
                        barangay = ?,
                        city = ?,
                        province = ?,
                        delivery_fee = ?,
                        min_order_amount = ?,
                        free_delivery_threshold = ?,
                        estimated_delivery_time = ?,
                        max_delivery_time = ?,
                        service_start_time = ?,
                        service_end_time = ?,
                        is_active = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ";
                $stmt = $pdo->prepare($update_sql);
                $stmt->execute([
                    $zone_name,
                    $barangay ?: null,
                    $city,
                    $province,
                    $delivery_fee,
                    $min_order_amount ?: 0,
                    $free_delivery_threshold ?: null,
                    $estimated_delivery_time,
                    $max_delivery_time ?: null,
                    $service_start_time ?: '08:00:00',
                    $service_end_time ?: '22:00:00',
                    $is_active,
                    $zone_id
                ]);

                $_SESSION['flash'] = ['type' => 'success', 'message' => '배달 지역이 수정되었습니다.'];
            } else {
                // 신규 등록
                $insert_sql = "
                    INSERT INTO delivery_zones
                    (zone_name, barangay, city, province, delivery_fee, min_order_amount,
                     free_delivery_threshold, estimated_delivery_time, max_delivery_time,
                     service_start_time, service_end_time, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ";
                $stmt = $pdo->prepare($insert_sql);
                $stmt->execute([
                    $zone_name,
                    $barangay ?: null,
                    $city,
                    $province,
                    $delivery_fee,
                    $min_order_amount ?: 0,
                    $free_delivery_threshold ?: null,
                    $estimated_delivery_time,
                    $max_delivery_time ?: null,
                    $service_start_time ?: '08:00:00',
                    $service_end_time ?: '22:00:00',
                    $is_active
                ]);

                $_SESSION['flash'] = ['type' => 'success', 'message' => '배달 지역이 등록되었습니다.'];
            }

            header('Location: delivery_zone_management.php');
            exit;

        } catch (PDOException $e) {
            $errors[] = '저장 실패: ' . $e->getMessage();
        }
    }

    // POST 데이터로 폼 값 업데이트
    if ($zone) {
        $zone['zone_name'] = $zone_name;
        $zone['barangay'] = $barangay;
        $zone['city'] = $city;
        $zone['province'] = $province;
        $zone['delivery_fee'] = $delivery_fee;
        $zone['min_order_amount'] = $min_order_amount;
        $zone['free_delivery_threshold'] = $free_delivery_threshold;
        $zone['estimated_delivery_time'] = $estimated_delivery_time;
        $zone['max_delivery_time'] = $max_delivery_time;
        $zone['service_start_time'] = $service_start_time;
        $zone['service_end_time'] = $service_end_time;
        $zone['is_active'] = $is_active;
    } else {
        $zone = compact('zone_name', 'barangay', 'city', 'province', 'delivery_fee', 'min_order_amount',
                       'free_delivery_threshold', 'estimated_delivery_time', 'max_delivery_time',
                       'service_start_time', 'service_end_time', 'is_active');
    }
}
?>

<div class="w-full px-2 sm:px-3 md:px-4 py-8">

    <!-- 헤더 -->
    <div class="mb-6">
        <div class="flex items-center mb-2">
            <a href="delivery_zone_management.php" class="text-blue-600 hover:text-blue-800 mr-3">
                <i class="fas fa-arrow-left"></i> 목록으로
            </a>
            <h1 class="text-2xl font-bold text-gray-900">
                <i class="fas fa-map-marker-alt mr-2 text-green-600"></i>
                <?php echo $zone_id ? '배달 지역 수정' : '배달 지역 추가'; ?>
            </h1>
        </div>
        <p class="text-sm text-gray-600">배달 가능 지역의 정보와 배달비를 설정합니다</p>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="bg-red-50 border border-red-200 rounded-md p-4 mb-6">
        <div class="flex">
            <i class="fas fa-exclamation-triangle text-red-400 mr-3 mt-1"></i>
            <div>
                <h3 class="text-sm font-medium text-red-800">입력 오류</h3>
                <ul class="mt-2 text-sm text-red-700 list-disc list-inside">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <form method="POST" class="space-y-6">

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- 기본 정보 -->
            <div class="lg:col-span-2">
                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900">
                            <i class="fas fa-info-circle mr-2 text-blue-600"></i>기본 정보
                        </h2>
                    </div>
                    <div class="p-6 space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    지역명 <span class="text-red-500">*</span>
                                </label>
                                <input type="text" name="zone_name" required
                                       value="<?php echo htmlspecialchars($zone['zone_name'] ?? ''); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500"
                                       placeholder="예: Angeles City Center">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    바랑가이 (Barangay)
                                </label>
                                <input type="text" name="barangay"
                                       value="<?php echo htmlspecialchars($zone['barangay'] ?? ''); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500"
                                       placeholder="예: Santo Rosario">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    시/도시 <span class="text-red-500">*</span>
                                </label>
                                <input type="text" name="city" required
                                       value="<?php echo htmlspecialchars($zone['city'] ?? ''); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500"
                                       placeholder="예: Angeles City">
                            </div>

                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    주/지방 <span class="text-red-500">*</span>
                                </label>
                                <input type="text" name="province" required
                                       value="<?php echo htmlspecialchars($zone['province'] ?? ''); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500"
                                       placeholder="예: Pampanga">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 배달 설정 -->
                <div class="bg-white shadow rounded-lg mt-6">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900">
                            <i class="fas fa-cog mr-2 text-orange-600"></i>배달 설정
                        </h2>
                    </div>
                    <div class="p-6 space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    배달비 (PHP) <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <span class="absolute left-3 top-2 text-gray-500">₱</span>
                                    <input type="number" name="delivery_fee" step="0.01" required
                                           value="<?php echo htmlspecialchars($zone['delivery_fee'] ?? '50.00'); ?>"
                                           class="w-full pl-8 pr-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500"
                                           placeholder="50.00">
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    최소 주문 금액 (PHP)
                                </label>
                                <div class="relative">
                                    <span class="absolute left-3 top-2 text-gray-500">₱</span>
                                    <input type="number" name="min_order_amount" step="0.01"
                                           value="<?php echo htmlspecialchars($zone['min_order_amount'] ?? '0.00'); ?>"
                                           class="w-full pl-8 pr-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500"
                                           placeholder="200.00">
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    무료 배달 임계값 (PHP)
                                </label>
                                <div class="relative">
                                    <span class="absolute left-3 top-2 text-gray-500">₱</span>
                                    <input type="number" name="free_delivery_threshold" step="0.01"
                                           value="<?php echo htmlspecialchars($zone['free_delivery_threshold'] ?? ''); ?>"
                                           class="w-full pl-8 pr-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500"
                                           placeholder="1000.00">
                                </div>
                                <p class="mt-1 text-xs text-gray-500">이 금액 이상 주문 시 배달비 무료</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    예상 배달 시간 (분) <span class="text-red-500">*</span>
                                </label>
                                <input type="number" name="estimated_delivery_time" required
                                       value="<?php echo htmlspecialchars($zone['estimated_delivery_time'] ?? '60'); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500"
                                       placeholder="60">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    최대 배달 시간 (분)
                                </label>
                                <input type="number" name="max_delivery_time"
                                       value="<?php echo htmlspecialchars($zone['max_delivery_time'] ?? '120'); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500"
                                       placeholder="120">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 서비스 시간 -->
                <div class="bg-white shadow rounded-lg mt-6">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900">
                            <i class="fas fa-clock mr-2 text-purple-600"></i>서비스 시간
                        </h2>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    서비스 시작 시간
                                </label>
                                <input type="time" name="service_start_time"
                                       value="<?php echo htmlspecialchars($zone['service_start_time'] ?? '08:00'); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    서비스 종료 시간
                                </label>
                                <input type="time" name="service_end_time"
                                       value="<?php echo htmlspecialchars($zone['service_end_time'] ?? '22:00'); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500">
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- 오른쪽 사이드바 -->
            <div>
                <!-- 상태 설정 -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900">
                            <i class="fas fa-toggle-on mr-2 text-blue-600"></i>상태 설정
                        </h2>
                    </div>
                    <div class="p-6">
                        <label class="flex items-center cursor-pointer">
                            <input type="checkbox" name="is_active" value="1"
                                   <?php echo (!isset($zone['is_active']) || $zone['is_active']) ? 'checked' : ''; ?>
                                   class="w-4 h-4 text-green-600 border-gray-300 rounded focus:ring-green-500">
                            <span class="ml-2 text-sm font-medium text-gray-700">서비스 활성화</span>
                        </label>
                        <p class="mt-2 text-xs text-gray-500">
                            비활성화하면 고객이 이 지역으로 주문할 수 없습니다.
                        </p>
                    </div>
                </div>

                <!-- 저장 버튼 -->
                <div class="bg-white shadow rounded-lg mt-6">
                    <div class="p-6 space-y-3">
                        <button type="submit" class="w-full px-4 py-3 bg-green-600 text-white rounded-md hover:bg-green-700 font-semibold">
                            <i class="fas fa-save mr-2"></i>
                            <?php echo $zone_id ? '변경사항 저장' : '배달 지역 등록'; ?>
                        </button>
                        <a href="delivery_zone_management.php" class="block w-full px-4 py-3 bg-gray-500 text-white text-center rounded-md hover:bg-gray-600 font-semibold">
                            <i class="fas fa-times mr-2"></i>취소
                        </a>
                    </div>
                </div>

                <!-- 도움말 -->
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mt-6">
                    <h3 class="text-sm font-medium text-blue-800 mb-2">
                        <i class="fas fa-lightbulb mr-1"></i>입력 가이드
                    </h3>
                    <ul class="text-xs text-blue-700 space-y-1">
                        <li>• 지역명은 고객이 선택할 때 표시됩니다</li>
                        <li>• 바랑가이는 선택사항입니다</li>
                        <li>• 배달비는 PHP(페소) 단위입니다</li>
                        <li>• 무료 배달 금액을 비워두면 항상 배달비가 부과됩니다</li>
                    </ul>
                </div>
            </div>

        </div>

    </form>

</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
