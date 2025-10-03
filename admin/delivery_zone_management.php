<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '배달 지역 관리 - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';

// 관리자 권한 확인
require_permission('admin_access');

$pdo = null;
$zones = [];
$error_message = '';
$success_message = '';

// 삭제 처리
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $zone_id = $_GET['id'];

        // 해당 지역을 사용하는 주문이 있는지 확인
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM delivery_orders WHERE delivery_zone_id = ?");
        $check_stmt->execute([$zone_id]);
        $order_count = $check_stmt->fetchColumn();

        if ($order_count > 0) {
            $error_message = "이 배달 지역을 사용하는 주문이 {$order_count}건 있어 삭제할 수 없습니다.";
        } else {
            $delete_stmt = $pdo->prepare("DELETE FROM delivery_zones WHERE id = ?");
            $delete_stmt->execute([$zone_id]);
            $success_message = "배달 지역이 삭제되었습니다.";
        }
    } catch (PDOException $e) {
        $error_message = "삭제 실패: " . $e->getMessage();
    }
}

// 활성화/비활성화 토글
if (isset($_GET['action']) && $_GET['action'] === 'toggle' && isset($_GET['id'])) {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $zone_id = $_GET['id'];

        $toggle_stmt = $pdo->prepare("UPDATE delivery_zones SET is_active = NOT is_active WHERE id = ?");
        $toggle_stmt->execute([$zone_id]);
        $success_message = "배달 지역 상태가 변경되었습니다.";
    } catch (PDOException $e) {
        $error_message = "상태 변경 실패: " . $e->getMessage();
    }
}

try {
    if (!$pdo) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    // 배달 지역 목록 가져오기
    $sql = "
        SELECT
            dz.*,
            COUNT(DISTINCT do.id) as order_count,
            SUM(do.total_amount) as total_sales
        FROM delivery_zones dz
        LEFT JOIN delivery_orders do ON dz.id = do.delivery_zone_id
        GROUP BY dz.id
        ORDER BY dz.is_active DESC, dz.city ASC, dz.zone_name ASC
    ";
    $stmt = $pdo->query($sql);
    $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = "오류: " . $e->getMessage();
}
?>

<div class="w-full px-2 sm:px-3 md:px-4 py-8">

    <!-- Page Header -->
    <div class="mb-6">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">
                    <i class="fas fa-map-marked-alt mr-2 text-green-600"></i>배달 지역 관리
                </h1>
                <p class="text-sm text-gray-600 mt-1">배달 가능 지역과 배달비를 설정합니다</p>
            </div>
            <a href="edit_delivery_zone.php" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 font-semibold">
                <i class="fas fa-plus mr-2"></i>새 지역 추가
            </a>
        </div>
    </div>

    <?php if ($success_message): ?>
    <div class="bg-green-50 border border-green-200 rounded-md p-4 mb-6">
        <p class="text-green-700"><i class="fas fa-check-circle mr-2"></i><?php echo $success_message; ?></p>
    </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
    <div class="bg-red-50 border border-red-200 rounded-md p-4 mb-6">
        <p class="text-red-700"><i class="fas fa-exclamation-triangle mr-2"></i><?php echo htmlspecialchars($error_message); ?></p>
    </div>
    <?php endif; ?>

    <!-- 통계 카드 -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-green-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">전체 지역</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo count($zones); ?></p>
                </div>
                <div class="text-green-500">
                    <i class="fas fa-map text-3xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-blue-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">활성 지역</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo count(array_filter($zones, fn($z) => $z['is_active'])); ?></p>
                </div>
                <div class="text-blue-500">
                    <i class="fas fa-check-circle text-3xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-orange-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">평균 배달비</p>
                    <p class="text-2xl font-bold text-gray-900">
                        ₱<?php echo count($zones) > 0 ? number_format(array_sum(array_column($zones, 'delivery_fee')) / count($zones), 2) : '0.00'; ?>
                    </p>
                </div>
                <div class="text-orange-500">
                    <i class="fas fa-motorcycle text-3xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-purple-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">총 주문</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo number_format(array_sum(array_column($zones, 'order_count'))); ?></p>
                </div>
                <div class="text-purple-500">
                    <i class="fas fa-shopping-cart text-3xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- 배달 지역 테이블 -->
    <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400">
        <?php if (empty($zones)): ?>
        <div class="px-6 py-12 text-center">
            <i class="fas fa-map-marked text-gray-400 text-4xl mb-4"></i>
            <h3 class="text-lg font-medium text-gray-900 mb-2">등록된 배달 지역이 없습니다</h3>
            <p class="text-gray-600 mb-4">첫 번째 배달 지역을 추가해보세요</p>
            <a href="edit_delivery_zone.php" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                <i class="fas fa-plus mr-2"></i>배달 지역 추가
            </a>
        </div>
        <?php else: ?>
        <!-- 테이블 헤더 -->
        <div class="px-6 py-4 border-b border-gray-200 bg-white">
            <h3 class="text-lg leading-6 font-semibold text-gray-900">
                배달 지역 목록
                <span class="text-sm font-normal text-gray-600 ml-2">(<?php echo count($zones); ?>개)</span>
            </h3>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">상태</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">지역명</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">위치</th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-gray-700 uppercase">배달비</th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-gray-700 uppercase">최소주문금액</th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-gray-700 uppercase">무료배달</th>
                        <th class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase">예상시간</th>
                        <th class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase">주문수</th>
                        <th class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase">작업</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-100">
                    <?php foreach ($zones as $zone): ?>
                    <tr class="hover:bg-gray-50 transition-colors duration-150">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php if ($zone['is_active']): ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                <i class="fas fa-check-circle mr-1"></i>활성
                            </span>
                            <?php else: ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                <i class="fas fa-times-circle mr-1"></i>비활성
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($zone['zone_name']); ?></div>
                            <?php if ($zone['barangay']): ?>
                            <div class="text-xs text-gray-500">Barangay <?php echo htmlspecialchars($zone['barangay']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($zone['city']); ?></div>
                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($zone['province']); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <div class="text-sm font-semibold text-gray-900">₱<?php echo number_format($zone['delivery_fee'], 2); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <div class="text-sm text-gray-900">₱<?php echo number_format($zone['min_order_amount'], 2); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <?php if ($zone['free_delivery_threshold']): ?>
                            <div class="text-sm text-green-600">₱<?php echo number_format($zone['free_delivery_threshold'], 2); ?></div>
                            <?php else: ?>
                            <div class="text-sm text-gray-400">-</div>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <div class="text-sm text-gray-900">
                                <i class="fas fa-clock mr-1 text-blue-500"></i><?php echo $zone['estimated_delivery_time']; ?>분
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <div class="text-sm font-medium text-gray-900"><?php echo number_format($zone['order_count']); ?></div>
                            <?php if ($zone['total_sales']): ?>
                            <div class="text-xs text-gray-500">₱<?php echo number_format($zone['total_sales'], 2); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <div class="flex items-center justify-center space-x-2">
                                <a href="edit_delivery_zone.php?id=<?php echo $zone['id']; ?>"
                                   class="inline-flex items-center px-2 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700"
                                   title="수정">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="?action=toggle&id=<?php echo $zone['id']; ?>"
                                   class="inline-flex items-center px-2 py-1 <?php echo $zone['is_active'] ? 'bg-gray-500' : 'bg-green-500'; ?> text-white text-xs rounded hover:opacity-80"
                                   title="<?php echo $zone['is_active'] ? '비활성화' : '활성화'; ?>"
                                   onclick="return confirm('상태를 변경하시겠습니까?');">
                                    <i class="fas fa-<?php echo $zone['is_active'] ? 'toggle-on' : 'toggle-off'; ?>"></i>
                                </a>
                                <?php if ($zone['order_count'] == 0): ?>
                                <a href="?action=delete&id=<?php echo $zone['id']; ?>"
                                   class="inline-flex items-center px-2 py-1 bg-red-600 text-white text-xs rounded hover:bg-red-700"
                                   title="삭제"
                                   onclick="return confirm('정말 삭제하시겠습니까? 이 작업은 되돌릴 수 없습니다.');">
                                    <i class="fas fa-trash"></i>
                                </a>
                                <?php else: ?>
                                <span class="inline-flex items-center px-2 py-1 bg-gray-300 text-gray-500 text-xs rounded cursor-not-allowed"
                                      title="주문이 있어 삭제할 수 없습니다">
                                    <i class="fas fa-trash"></i>
                                </span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- 안내 정보 -->
    <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
        <div class="flex">
            <div class="flex-shrink-0">
                <i class="fas fa-info-circle text-blue-400 text-xl"></i>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-blue-800">배달 지역 설정 안내</h3>
                <div class="mt-2 text-sm text-blue-700">
                    <ul class="list-disc list-inside space-y-1">
                        <li>배달 지역별로 배달비와 최소 주문 금액을 다르게 설정할 수 있습니다</li>
                        <li>무료 배달 임계값을 설정하면 해당 금액 이상 주문 시 배달비가 무료가 됩니다</li>
                        <li>서비스 시간을 설정하여 지역별로 다른 운영 시간을 적용할 수 있습니다</li>
                        <li>주문이 있는 지역은 삭제할 수 없으며, 비활성화만 가능합니다</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
