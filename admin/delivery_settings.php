<?php
/**
 * 배달 설정 관리
 *
 * 전역 배달 앱 설정 관리 페이지
 * - 기본 배달비, 무료 배달 최소금액, 최대 배달거리
 * - 서비스 시간, 결제 수단 활성화
 * - COD 최대 금액, 앱 수수료율
 */

require_once '../config/db_config.php';
require_once '../lib/permission_helper.php';

// 세션 시작 (header.php에서 처리되지만 안전을 위해)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 권한 확인 (super_admin만 접근 가능)
if ($_SESSION['role'] !== 'super_admin') {
    header("Location: index.php");
    exit();
}

$pdo = null;
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("데이터베이스 연결 실패: " . $e->getMessage());
}

$success_message = '';
$error_message = '';

// 설정 저장 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    try {
        $pdo->beginTransaction();

        $settings = [
            'default_delivery_fee' => $_POST['default_delivery_fee'],
            'free_delivery_threshold' => $_POST['free_delivery_threshold'],
            'max_delivery_distance_km' => $_POST['max_delivery_distance_km'],
            'service_start_time' => $_POST['service_start_time'],
            'service_end_time' => $_POST['service_end_time'],
            'cod_enabled' => isset($_POST['cod_enabled']) ? '1' : '0',
            'gcash_enabled' => isset($_POST['gcash_enabled']) ? '1' : '0',
            'paymaya_enabled' => isset($_POST['paymaya_enabled']) ? '1' : '0',
            'max_cod_amount' => $_POST['max_cod_amount'],
            'app_commission_rate' => $_POST['app_commission_rate'],
            'delivery_app_name' => $_POST['delivery_app_name'],
            'support_phone' => $_POST['support_phone']
        ];

        $update_sql = "INSERT INTO delivery_settings (setting_key, setting_value, updated_at)
                      VALUES (?, ?, NOW())
                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()";

        $stmt = $pdo->prepare($update_sql);

        foreach ($settings as $key => $value) {
            $stmt->execute([$key, $value]);
        }

        $pdo->commit();
        $success_message = "배달 설정이 성공적으로 저장되었습니다.";

    } catch (PDOException $e) {
        $pdo->rollBack();
        $error_message = "설정 저장 중 오류가 발생했습니다: " . $e->getMessage();
        error_log("Delivery settings save error: " . $e->getMessage());
    }
}

// 현재 설정 불러오기
$settings = [];
try {
    $settings_sql = "SELECT setting_key, setting_value FROM delivery_settings";
    $settings_stmt = $pdo->query($settings_sql);

    while ($row = $settings_stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    error_log("Failed to load delivery settings: " . $e->getMessage());
}

// 기본값 설정
$defaults = [
    'default_delivery_fee' => '50.00',
    'free_delivery_threshold' => '1000.00',
    'max_delivery_distance_km' => '10',
    'service_start_time' => '08:00',
    'service_end_time' => '22:00',
    'cod_enabled' => '1',
    'gcash_enabled' => '1',
    'paymaya_enabled' => '1',
    'max_cod_amount' => '10000.00',
    'app_commission_rate' => '5.00',
    'delivery_app_name' => 'HOME K MART 배달',
    'support_phone' => '+63-000-000-0000'
];

foreach ($defaults as $key => $value) {
    if (!isset($settings[$key])) {
        $settings[$key] = $value;
    }
}

require_once 'partials/header.php';
?>

<div class="container mx-auto px-4 py-6">
    <!-- 페이지 헤더 -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">
                <i class="fas fa-cog mr-2"></i>배달 설정 관리
            </h1>
            <p class="text-gray-600 mt-1">전역 배달 앱 설정을 관리합니다</p>
        </div>
    </div>

    <!-- 성공/오류 메시지 -->
    <?php if ($success_message): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded">
            <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($success_message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded">
            <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error_message) ?>
        </div>
    <?php endif; ?>

    <!-- 설정 폼 -->
    <form method="POST" class="space-y-6">
        <input type="hidden" name="action" value="save_settings">

        <!-- 앱 기본 정보 -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2">
                <i class="fas fa-mobile-alt mr-2 text-blue-600"></i>앱 기본 정보
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 font-medium mb-2">
                        앱 이름
                    </label>
                    <input type="text" name="delivery_app_name"
                           value="<?= htmlspecialchars($settings['delivery_app_name']) ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                           required>
                </div>

                <div>
                    <label class="block text-gray-700 font-medium mb-2">
                        고객 지원 전화번호
                    </label>
                    <input type="text" name="support_phone"
                           value="<?= htmlspecialchars($settings['support_phone']) ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                           required>
                    <p class="text-sm text-gray-500 mt-1">예: +63-917-123-4567</p>
                </div>
            </div>
        </div>

        <!-- 배달 기본 설정 -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2">
                <i class="fas fa-shipping-fast mr-2 text-indigo-600"></i>배달 기본 설정
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-gray-700 font-medium mb-2">
                        기본 배달비 (PHP)
                    </label>
                    <input type="number" name="default_delivery_fee"
                           value="<?= htmlspecialchars($settings['default_delivery_fee']) ?>"
                           step="0.01" min="0" max="1000"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                           required>
                    <p class="text-sm text-gray-500 mt-1">지역별 설정이 없을 때 적용</p>
                </div>

                <div>
                    <label class="block text-gray-700 font-medium mb-2">
                        무료 배달 최소금액 (PHP)
                    </label>
                    <input type="number" name="free_delivery_threshold"
                           value="<?= htmlspecialchars($settings['free_delivery_threshold']) ?>"
                           step="0.01" min="0" max="100000"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                           required>
                    <p class="text-sm text-gray-500 mt-1">이 금액 이상 주문 시 무료 배달</p>
                </div>

                <div>
                    <label class="block text-gray-700 font-medium mb-2">
                        최대 배달 거리 (km)
                    </label>
                    <input type="number" name="max_delivery_distance_km"
                           value="<?= htmlspecialchars($settings['max_delivery_distance_km']) ?>"
                           step="1" min="1" max="100"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                           required>
                    <p class="text-sm text-gray-500 mt-1">GPS 기준 최대 배달 가능 거리</p>
                </div>
            </div>
        </div>

        <!-- 서비스 시간 설정 -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2">
                <i class="fas fa-clock mr-2 text-orange-600"></i>서비스 시간 설정
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 font-medium mb-2">
                        서비스 시작 시간
                    </label>
                    <input type="time" name="service_start_time"
                           value="<?= htmlspecialchars($settings['service_start_time']) ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                           required>
                </div>

                <div>
                    <label class="block text-gray-700 font-medium mb-2">
                        서비스 종료 시간
                    </label>
                    <input type="time" name="service_end_time"
                           value="<?= htmlspecialchars($settings['service_end_time']) ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                           required>
                </div>
            </div>
            <p class="text-sm text-gray-500 mt-2">
                <i class="fas fa-info-circle mr-1"></i>
                지역별로 다른 시간을 설정하려면 배달 지역 관리에서 개별 설정하세요.
            </p>
        </div>

        <!-- 결제 수단 설정 -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2">
                <i class="fas fa-credit-card mr-2 text-green-600"></i>결제 수단 설정
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- 결제 수단 활성화 -->
                <div class="space-y-3">
                    <label class="flex items-center space-x-3 p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer">
                        <input type="checkbox" name="cod_enabled" value="1"
                               <?= $settings['cod_enabled'] === '1' ? 'checked' : '' ?>
                               class="w-5 h-5 text-blue-600 rounded focus:ring-2 focus:ring-blue-500">
                        <div>
                            <div class="font-medium text-gray-800">COD (Cash on Delivery)</div>
                            <div class="text-sm text-gray-500">현금 배달 결제</div>
                        </div>
                    </label>

                    <label class="flex items-center space-x-3 p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer">
                        <input type="checkbox" name="gcash_enabled" value="1"
                               <?= $settings['gcash_enabled'] === '1' ? 'checked' : '' ?>
                               class="w-5 h-5 text-blue-600 rounded focus:ring-2 focus:ring-blue-500">
                        <div>
                            <div class="font-medium text-gray-800">GCash</div>
                            <div class="text-sm text-gray-500">필리핀 모바일 결제</div>
                        </div>
                    </label>

                    <label class="flex items-center space-x-3 p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer">
                        <input type="checkbox" name="paymaya_enabled" value="1"
                               <?= $settings['paymaya_enabled'] === '1' ? 'checked' : '' ?>
                               class="w-5 h-5 text-blue-600 rounded focus:ring-2 focus:ring-blue-500">
                        <div>
                            <div class="font-medium text-gray-800">PayMaya</div>
                            <div class="text-sm text-gray-500">필리핀 디지털 결제</div>
                        </div>
                    </label>
                </div>

                <!-- COD 최대 금액 -->
                <div>
                    <label class="block text-gray-700 font-medium mb-2">
                        COD 최대 결제 금액 (PHP)
                    </label>
                    <input type="number" name="max_cod_amount"
                           value="<?= htmlspecialchars($settings['max_cod_amount']) ?>"
                           step="0.01" min="0" max="100000"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                           required>
                    <p class="text-sm text-gray-500 mt-1">이 금액을 초과하면 COD 결제 불가</p>

                    <div class="mt-4">
                        <label class="block text-gray-700 font-medium mb-2">
                            앱 수수료율 (%)
                        </label>
                        <input type="number" name="app_commission_rate"
                               value="<?= htmlspecialchars($settings['app_commission_rate']) ?>"
                               step="0.01" min="0" max="30"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                               required>
                        <p class="text-sm text-gray-500 mt-1">주문 금액에서 차감할 수수료 비율</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- 저장 버튼 -->
        <div class="flex justify-end space-x-3">
            <a href="index.php" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition">
                <i class="fas fa-times mr-2"></i>취소
            </a>
            <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                <i class="fas fa-save mr-2"></i>설정 저장
            </button>
        </div>
    </form>
</div>

<script>
// 폼 제출 시 확인
document.querySelector('form').addEventListener('submit', function(e) {
    if (!confirm('배달 설정을 저장하시겠습니까?')) {
        e.preventDefault();
    }
});
</script>

<?php require_once 'partials/footer.php'; ?>
