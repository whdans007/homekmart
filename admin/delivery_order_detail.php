<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '배달 주문 상세 - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';

// 관리자 권한 확인
require_permission('admin_access');

$order_id = $_GET['id'] ?? 0;
$error_message = '';
$success_message = '';
$order = null;
$order_items = [];
$tracking_history = [];

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 주문 정보 가져오기
    $sql = "
        SELECT
            do.*,
            COALESCE(u.full_name, u.username, u.email) as customer_name,
            u.email as customer_email,
            u.phone as customer_phone,
            s.name as store_name,
            s.address as store_address,
            da.address_name,
            da.house_number,
            da.street,
            da.barangay,
            da.city,
            da.province,
            da.postal_code,
            da.landmark,
            da.delivery_notes,
            da.latitude,
            da.longitude,
            dz.zone_name,
            dz.estimated_delivery_time as zone_delivery_time
        FROM delivery_orders do
        LEFT JOIN users u ON do.user_id = u.id
        LEFT JOIN stores s ON do.store_id = s.id
        LEFT JOIN delivery_addresses da ON do.delivery_address_id = da.id
        LEFT JOIN delivery_zones dz ON do.delivery_zone_id = dz.id
        WHERE do.id = ?
    ";

    // admin은 자기 점포만 조회
    if ($_SESSION['role'] !== 'super_admin' && !empty($_SESSION['store_id'])) {
        $sql .= " AND do.store_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$order_id, $_SESSION['store_id']]);
    } else {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$order_id]);
    }

    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => '주문을 찾을 수 없습니다.'];
        header('Location: delivery_order_management.php');
        exit;
    }

    // 주문 상품 목록
    $items_sql = "
        SELECT
            doi.*,
            p.name as current_product_name,
            p.image_url
        FROM delivery_order_items doi
        LEFT JOIN products p ON doi.product_id = p.id
        WHERE doi.order_id = ?
        ORDER BY doi.id ASC
    ";
    $items_stmt = $pdo->prepare($items_sql);
    $items_stmt->execute([$order_id]);
    $order_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 배달 추적 기록
    $tracking_sql = "
        SELECT
            dt.*,
            COALESCE(u.full_name, u.username, u.email) as updated_by_name
        FROM delivery_tracking dt
        LEFT JOIN users u ON dt.updated_by_user_id = u.id
        WHERE dt.order_id = ?
        ORDER BY dt.timestamp DESC
    ";
    $tracking_stmt = $pdo->prepare($tracking_sql);
    $tracking_stmt->execute([$order_id]);
    $tracking_history = $tracking_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = "오류: " . $e->getMessage();
}

// POST 처리 - 주문 상태 변경
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    try {
        if ($action === 'update_status') {
            $new_status = $_POST['order_status'];
            $notes = $_POST['notes'] ?? '';

            // 주문 상태 업데이트
            $update_sql = "UPDATE delivery_orders SET order_status = ?, updated_at = NOW() WHERE id = ?";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([$new_status, $order_id]);

            // 배달 추적 기록 추가
            $tracking_insert = "
                INSERT INTO delivery_tracking
                (order_id, status, status_message, updated_by_user_id, notes, timestamp)
                VALUES (?, ?, ?, ?, ?, NOW())
            ";
            $status_messages = [
                'pending' => '주문이 접수되었습니다',
                'confirmed' => '주문이 확인되었습니다',
                'preparing' => '상품을 준비하고 있습니다',
                'ready_for_delivery' => '배달 준비가 완료되었습니다',
                'out_for_delivery' => '배달 중입니다',
                'delivered' => '배달이 완료되었습니다',
                'cancelled' => '주문이 취소되었습니다'
            ];

            $tracking_stmt = $pdo->prepare($tracking_insert);
            $tracking_stmt->execute([
                $order_id,
                str_replace('_', ' ', $new_status),
                $status_messages[$new_status] ?? '',
                $_SESSION['user_id'],
                $notes
            ]);

            $success_message = "주문 상태가 업데이트되었습니다.";

            // 페이지 새로고침
            header("Location: delivery_order_detail.php?id=" . $order_id . "&success=1");
            exit;
        }

        if ($action === 'update_payment') {
            $payment_status = $_POST['payment_status'];

            $update_sql = "UPDATE delivery_orders SET payment_status = ?, updated_at = NOW() WHERE id = ?";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([$payment_status, $order_id]);

            $success_message = "결제 상태가 업데이트되었습니다.";

            header("Location: delivery_order_detail.php?id=" . $order_id . "&success=1");
            exit;
        }

    } catch (PDOException $e) {
        $error_message = "업데이트 실패: " . $e->getMessage();
    }
}

if (isset($_GET['success'])) {
    $success_message = "업데이트가 완료되었습니다.";
}

function getStatusBadge($status) {
    $badges = [
        'pending' => ['class' => 'bg-warning text-dark', 'text' => '주문접수', 'icon' => 'clock'],
        'confirmed' => ['class' => 'bg-info text-white', 'text' => '주문확인', 'icon' => 'check'],
        'preparing' => ['class' => 'bg-primary text-white', 'text' => '상품준비중', 'icon' => 'box'],
        'ready_for_delivery' => ['class' => 'bg-purple-600 text-white', 'text' => '배달준비완료', 'icon' => 'box-open'],
        'out_for_delivery' => ['class' => 'bg-indigo-600 text-white', 'text' => '배달중', 'icon' => 'shipping-fast'],
        'delivered' => ['class' => 'bg-success text-white', 'text' => '배달완료', 'icon' => 'check-circle'],
        'cancelled' => ['class' => 'bg-secondary text-white', 'text' => '주문취소', 'icon' => 'times-circle']
    ];
    return $badges[$status] ?? ['class' => 'bg-gray-500 text-white', 'text' => $status, 'icon' => 'question'];
}
?>

<div class="w-full px-2 sm:px-3 md:px-4 py-8">

    <!-- 헤더 -->
    <div class="mb-6">
        <div class="flex items-center mb-2">
            <a href="delivery_order_management.php" class="text-blue-600 hover:text-blue-800 mr-3">
                <i class="fas fa-arrow-left"></i> 목록으로
            </a>
            <h1 class="text-2xl font-bold text-gray-900">
                <i class="fas fa-receipt mr-2 text-blue-600"></i>주문 상세
            </h1>
        </div>
        <p class="text-sm text-gray-600">주문번호: <span class="font-semibold text-blue-600"><?php echo htmlspecialchars($order['order_number']); ?></span></p>
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

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- 왼쪽 컬럼 -->
        <div class="lg:col-span-2 space-y-6">

            <!-- 주문 상품 -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-shopping-bag mr-2 text-blue-600"></i>주문 상품
                    </h2>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <?php foreach ($order_items as $item): ?>
                        <div class="flex items-start space-x-4 pb-4 border-b border-gray-100 last:border-0">
                            <div class="flex-shrink-0 w-16 h-16 bg-gray-100 rounded">
                                <?php if (!empty($item['image_url'])): ?>
                                <img src="<?php echo htmlspecialchars($item['image_url']); ?>"
                                     alt="<?php echo htmlspecialchars($item['product_name']); ?>"
                                     class="w-16 h-16 object-cover rounded">
                                <?php else: ?>
                                <div class="w-16 h-16 flex items-center justify-center text-gray-400">
                                    <i class="fas fa-image text-2xl"></i>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="flex-1">
                                <h3 class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['product_name']); ?></h3>
                                <div class="mt-1 text-sm text-gray-500">
                                    수량: <?php echo $item['quantity']; ?>개 × ₱<?php echo number_format($item['product_price'], 2); ?>
                                </div>
                                <?php if ($item['product_options']): ?>
                                <div class="mt-1 text-xs text-gray-400">
                                    옵션: <?php echo htmlspecialchars($item['product_options']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="text-right">
                                <div class="text-sm font-semibold text-gray-900">
                                    ₱<?php echo number_format($item['subtotal'], 2); ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- 금액 요약 -->
                    <div class="mt-6 pt-4 border-t border-gray-200">
                        <div class="space-y-2">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-600">상품 금액</span>
                                <span class="text-gray-900">₱<?php echo number_format($order['subtotal'], 2); ?></span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-600">배달비</span>
                                <span class="text-gray-900">₱<?php echo number_format($order['delivery_fee'], 2); ?></span>
                            </div>
                            <?php if ($order['discount_amount'] > 0): ?>
                            <div class="flex justify-between text-sm text-green-600">
                                <span>할인</span>
                                <span>-₱<?php echo number_format($order['discount_amount'], 2); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="flex justify-between text-lg font-bold border-t border-gray-200 pt-2">
                                <span class="text-gray-900">총 결제 금액</span>
                                <span class="text-blue-600">₱<?php echo number_format($order['total_amount'], 2); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 배달 추적 -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-route mr-2 text-green-600"></i>배달 추적
                    </h2>
                </div>
                <div class="p-6">
                    <?php if (empty($tracking_history)): ?>
                    <p class="text-gray-500 text-center py-4">추적 기록이 없습니다.</p>
                    <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($tracking_history as $index => $track): ?>
                        <div class="flex items-start space-x-4">
                            <div class="flex-shrink-0">
                                <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center">
                                    <i class="fas fa-<?php echo $index === 0 ? 'check' : 'circle'; ?> text-blue-600"></i>
                                </div>
                            </div>
                            <div class="flex-1 pb-4 <?php echo $index < count($tracking_history) - 1 ? 'border-l-2 border-gray-200 ml-5 pl-6' : ''; ?>">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <h3 class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($track['status_message']); ?></h3>
                                        <p class="text-xs text-gray-500 mt-1"><?php echo date('Y-m-d H:i:s', strtotime($track['timestamp'])); ?></p>
                                        <?php if ($track['notes']): ?>
                                        <p class="text-sm text-gray-600 mt-2"><?php echo htmlspecialchars($track['notes']); ?></p>
                                        <?php endif; ?>
                                        <?php if ($track['updated_by_name']): ?>
                                        <p class="text-xs text-gray-400 mt-1">담당자: <?php echo htmlspecialchars($track['updated_by_name']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- 오른쪽 컬럼 -->
        <div class="space-y-6">

            <!-- 주문 상태 변경 -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-tasks mr-2 text-purple-600"></i>주문 관리
                    </h2>
                </div>
                <div class="p-6">
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="update_status">

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">현재 상태</label>
                            <?php $status_badge = getStatusBadge($order['order_status']); ?>
                            <div class="flex items-center space-x-2 p-3 bg-gray-50 rounded">
                                <i class="fas fa-<?php echo $status_badge['icon']; ?> text-lg"></i>
                                <span class="font-semibold"><?php echo $status_badge['text']; ?></span>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">상태 변경</label>
                            <select name="order_status" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                                <option value="pending" <?php echo $order['order_status'] === 'pending' ? 'selected' : ''; ?>>주문접수</option>
                                <option value="confirmed" <?php echo $order['order_status'] === 'confirmed' ? 'selected' : ''; ?>>주문확인</option>
                                <option value="preparing" <?php echo $order['order_status'] === 'preparing' ? 'selected' : ''; ?>>상품준비중</option>
                                <option value="ready_for_delivery" <?php echo $order['order_status'] === 'ready_for_delivery' ? 'selected' : ''; ?>>배달준비완료</option>
                                <option value="out_for_delivery" <?php echo $order['order_status'] === 'out_for_delivery' ? 'selected' : ''; ?>>배달중</option>
                                <option value="delivered" <?php echo $order['order_status'] === 'delivered' ? 'selected' : ''; ?>>배달완료</option>
                                <option value="cancelled" <?php echo $order['order_status'] === 'cancelled' ? 'selected' : ''; ?>>주문취소</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">메모</label>
                            <textarea name="notes" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500"
                                      placeholder="상태 변경 사유나 메모를 입력하세요"></textarea>
                        </div>

                        <button type="submit" class="w-full px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 font-semibold">
                            <i class="fas fa-save mr-2"></i>상태 업데이트
                        </button>
                    </form>

                    <!-- 결제 상태 변경 -->
                    <form method="POST" class="mt-6 pt-6 border-t border-gray-200 space-y-4">
                        <input type="hidden" name="action" value="update_payment">

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">결제 상태</label>
                            <select name="payment_status" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                                <option value="pending" <?php echo $order['payment_status'] === 'pending' ? 'selected' : ''; ?>>결제대기</option>
                                <option value="paid" <?php echo $order['payment_status'] === 'paid' ? 'selected' : ''; ?>>결제완료</option>
                                <option value="failed" <?php echo $order['payment_status'] === 'failed' ? 'selected' : ''; ?>>결제실패</option>
                                <option value="refunded" <?php echo $order['payment_status'] === 'refunded' ? 'selected' : ''; ?>>환불완료</option>
                            </select>
                        </div>

                        <button type="submit" class="w-full px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 font-semibold">
                            <i class="fas fa-money-bill-wave mr-2"></i>결제 상태 변경
                        </button>
                    </form>
                </div>
            </div>

            <!-- 고객 정보 -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-user mr-2 text-blue-600"></i>고객 정보
                    </h2>
                </div>
                <div class="p-6 space-y-3">
                    <div>
                        <p class="text-xs text-gray-500">이름</p>
                        <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($order['customer_name']); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">연락처</p>
                        <p class="text-sm font-medium text-gray-900">
                            <i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($order['customer_phone'] ?? '-'); ?>
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">이메일</p>
                        <p class="text-sm font-medium text-gray-900">
                            <i class="fas fa-envelope mr-1"></i><?php echo htmlspecialchars($order['customer_email']); ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- 배달 주소 -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-map-marker-alt mr-2 text-red-600"></i>배달 주소
                    </h2>
                </div>
                <div class="p-6 space-y-3">
                    <?php if ($order['address_name']): ?>
                    <div>
                        <p class="text-xs text-gray-500">주소명</p>
                        <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($order['address_name']); ?></p>
                    </div>
                    <?php endif; ?>
                    <div>
                        <p class="text-xs text-gray-500">전체 주소</p>
                        <p class="text-sm text-gray-900">
                            <?php echo htmlspecialchars(trim(
                                ($order['house_number'] ? $order['house_number'] . ' ' : '') .
                                ($order['street'] ? $order['street'] . ', ' : '') .
                                ($order['barangay'] ? 'Barangay ' . $order['barangay'] . ', ' : '') .
                                ($order['city'] ? $order['city'] . ', ' : '') .
                                ($order['province'] ? $order['province'] . ' ' : '') .
                                ($order['postal_code'] ? $order['postal_code'] : '')
                            )); ?>
                        </p>
                    </div>
                    <?php if ($order['landmark']): ?>
                    <div>
                        <p class="text-xs text-gray-500">랜드마크</p>
                        <p class="text-sm text-gray-900">
                            <i class="fas fa-landmark mr-1 text-orange-500"></i><?php echo htmlspecialchars($order['landmark']); ?>
                        </p>
                    </div>
                    <?php endif; ?>
                    <?php if ($order['delivery_notes']): ?>
                    <div>
                        <p class="text-xs text-gray-500">배달 메모</p>
                        <p class="text-sm text-gray-900"><?php echo htmlspecialchars($order['delivery_notes']); ?></p>
                    </div>
                    <?php endif; ?>
                    <?php if ($order['latitude'] && $order['longitude']): ?>
                    <div class="pt-3 border-t border-gray-200">
                        <p class="text-xs text-gray-500 mb-2">GPS 좌표</p>
                        <p class="text-xs text-gray-600">
                            <i class="fas fa-map-pin mr-1"></i>
                            <?php echo $order['latitude']; ?>, <?php echo $order['longitude']; ?>
                        </p>
                        <a href="https://www.google.com/maps?q=<?php echo $order['latitude']; ?>,<?php echo $order['longitude']; ?>"
                           target="_blank"
                           class="inline-block mt-2 text-xs text-blue-600 hover:text-blue-800">
                            <i class="fas fa-external-link-alt mr-1"></i>Google Maps에서 보기
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 결제 정보 -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-credit-card mr-2 text-green-600"></i>결제 정보
                    </h2>
                </div>
                <div class="p-6 space-y-3">
                    <div>
                        <p class="text-xs text-gray-500">결제 방법</p>
                        <p class="text-sm font-medium text-gray-900">
                            <?php
                            $payment_methods = [
                                'cod' => 'COD (현금)',
                                'gcash' => 'GCash',
                                'paymaya' => 'PayMaya',
                                'online' => 'Online'
                            ];
                            echo $payment_methods[$order['payment_method']] ?? $order['payment_method'];
                            ?>
                        </p>
                    </div>
                    <?php if ($order['payment_method'] === 'cod'): ?>
                    <div>
                        <p class="text-xs text-gray-500">COD 결제 금액</p>
                        <p class="text-sm font-semibold text-gray-900">₱<?php echo number_format($order['cod_amount'] ?? $order['total_amount'], 2); ?></p>
                    </div>
                    <?php if ($order['change_amount']): ?>
                    <div>
                        <p class="text-xs text-gray-500">거스름돈</p>
                        <p class="text-sm font-semibold text-orange-600">₱<?php echo number_format($order['change_amount'], 2); ?></p>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                    <div>
                        <p class="text-xs text-gray-500">결제 상태</p>
                        <p class="text-sm font-medium">
                            <?php
                            $payment_statuses = [
                                'pending' => '<span class="text-yellow-600">결제대기</span>',
                                'paid' => '<span class="text-green-600">결제완료</span>',
                                'failed' => '<span class="text-red-600">결제실패</span>',
                                'refunded' => '<span class="text-gray-600">환불완료</span>'
                            ];
                            echo $payment_statuses[$order['payment_status']] ?? $order['payment_status'];
                            ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- 점포 정보 -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-store mr-2 text-purple-600"></i>점포 정보
                    </h2>
                </div>
                <div class="p-6 space-y-3">
                    <div>
                        <p class="text-xs text-gray-500">점포명</p>
                        <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($order['store_name']); ?></p>
                    </div>
                    <?php if ($order['zone_name']): ?>
                    <div>
                        <p class="text-xs text-gray-500">배달 지역</p>
                        <p class="text-sm text-gray-900"><?php echo htmlspecialchars($order['zone_name']); ?></p>
                    </div>
                    <?php endif; ?>
                    <?php if ($order['estimated_delivery_time']): ?>
                    <div>
                        <p class="text-xs text-gray-500">예상 배달 시간</p>
                        <p class="text-sm text-gray-900">
                            <i class="fas fa-clock mr-1"></i><?php echo date('Y-m-d H:i', strtotime($order['estimated_delivery_time'])); ?>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
