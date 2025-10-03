<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '배달 주문 관리 - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';

// 관리자 권한 확인
require_permission('admin_access');

$pdo = null;
$orders = [];
$error_message = '';

// 검색 및 필터링 변수
$search_term = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$payment_filter = $_GET['payment'] ?? '';
$store_filter = $_GET['store'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
$limit = in_array($per_page, [10, 25, 50, 100]) ? $per_page : 25;
$offset = ($page - 1) * $limit;

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 점포 목록 가져오기
    $stores = $pdo->query("SELECT id, name FROM stores ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

    // WHERE 절 구성
    $where_clause = " WHERE 1=1";
    $params = [];

    // 점포별 필터링 (admin은 자기 점포만 조회)
    if ($_SESSION['role'] !== 'super_admin' && !empty($_SESSION['store_id'])) {
        $where_clause .= " AND do.store_id = ?";
        $params[] = $_SESSION['store_id'];
    } elseif (!empty($store_filter)) {
        $where_clause .= " AND do.store_id = ?";
        $params[] = $store_filter;
    }

    // 상태 필터
    if (!empty($status_filter)) {
        $where_clause .= " AND do.order_status = ?";
        $params[] = $status_filter;
    }

    // 결제 방법 필터
    if (!empty($payment_filter)) {
        $where_clause .= " AND do.payment_method = ?";
        $params[] = $payment_filter;
    }

    // 날짜 필터
    if (!empty($date_from)) {
        $where_clause .= " AND DATE(do.created_at) >= ?";
        $params[] = $date_from;
    }
    if (!empty($date_to)) {
        $where_clause .= " AND DATE(do.created_at) <= ?";
        $params[] = $date_to;
    }

    // 검색 조건
    if (!empty($search_term)) {
        $where_clause .= " AND (do.order_number LIKE ? OR u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
        $search_param = "%$search_term%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }

    // 전체 주문 수 계산
    $count_sql = "SELECT COUNT(do.id) FROM delivery_orders do
                  LEFT JOIN users u ON do.user_id = u.id" . $where_clause;
    $total_stmt = $pdo->prepare($count_sql);
    $total_stmt->execute($params);
    $total_orders = $total_stmt->fetchColumn();
    $total_pages = ceil($total_orders / $limit);

    // 주문 목록 가져오기
    $sql = "
        SELECT
            do.id,
            do.order_number,
            do.subtotal,
            do.delivery_fee,
            do.total_amount,
            do.payment_method,
            do.payment_status,
            do.order_status,
            do.created_at,
            do.estimated_delivery_time,
            COALESCE(u.full_name, u.username, u.email) as customer_name,
            u.phone as customer_phone,
            u.email as customer_email,
            s.name as store_name,
            da.city,
            da.barangay
        FROM delivery_orders do
        LEFT JOIN users u ON do.user_id = u.id
        LEFT JOIN stores s ON do.store_id = s.id
        LEFT JOIN delivery_addresses da ON do.delivery_address_id = da.id
        " . $where_clause . "
        ORDER BY do.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $stmt = $pdo->prepare($sql);

    $current_param = 1;
    foreach ($params as $param) {
        $stmt->bindValue($current_param++, $param, PDO::PARAM_STR);
    }
    $stmt->bindValue($current_param++, $limit, PDO::PARAM_INT);
    $stmt->bindValue($current_param, $offset, PDO::PARAM_INT);

    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = "오류: " . $e->getMessage();
}

// 상태별 색상 및 텍스트
function getStatusBadge($status) {
    $badges = [
        'pending' => ['class' => 'bg-warning text-dark', 'text' => '주문접수'],
        'confirmed' => ['class' => 'bg-info text-white', 'text' => '주문확인'],
        'preparing' => ['class' => 'bg-primary text-white', 'text' => '상품준비중'],
        'ready_for_delivery' => ['class' => 'bg-purple-600 text-white', 'text' => '배달준비완료'],
        'out_for_delivery' => ['class' => 'bg-indigo-600 text-white', 'text' => '배달중'],
        'delivered' => ['class' => 'bg-success text-white', 'text' => '배달완료'],
        'cancelled' => ['class' => 'bg-secondary text-white', 'text' => '주문취소']
    ];
    return $badges[$status] ?? ['class' => 'bg-gray-500 text-white', 'text' => $status];
}

function getPaymentBadge($method) {
    $badges = [
        'cod' => ['class' => 'bg-orange-100 text-orange-800', 'text' => 'COD'],
        'gcash' => ['class' => 'bg-blue-100 text-blue-800', 'text' => 'GCash'],
        'paymaya' => ['class' => 'bg-green-100 text-green-800', 'text' => 'PayMaya'],
        'online' => ['class' => 'bg-purple-100 text-purple-800', 'text' => 'Online']
    ];
    return $badges[$method] ?? ['class' => 'bg-gray-100 text-gray-800', 'text' => $method];
}
?>

<div class="w-full px-2 sm:px-3 md:px-4 py-8">

    <!-- Page Header -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">
            <i class="fas fa-motorcycle mr-2 text-blue-600"></i>배달 주문 관리
        </h1>
        <p class="text-sm text-gray-600 mt-1">필리핀 배달 앱 주문을 관리합니다</p>
    </div>

    <!-- 통계 카드 -->
    <?php
    // 상태별 통계
    $stats_sql = "
        SELECT
            order_status,
            COUNT(*) as count,
            SUM(total_amount) as total
        FROM delivery_orders
        " . str_replace("do.", "", $where_clause) . "
        GROUP BY order_status
    ";
    $stats_stmt = $pdo->prepare($stats_sql);
    $stats_stmt->execute(array_slice($params, 0, count($params) - (empty($search_term) ? 0 : 4)));
    $stats = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats_map = [];
    foreach ($stats as $stat) {
        $stats_map[$stat['order_status']] = $stat;
    }
    ?>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-yellow-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">주문접수</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo $stats_map['pending']['count'] ?? 0; ?></p>
                </div>
                <div class="text-yellow-500">
                    <i class="fas fa-clock text-3xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-indigo-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">배달중</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo $stats_map['out_for_delivery']['count'] ?? 0; ?></p>
                </div>
                <div class="text-indigo-500">
                    <i class="fas fa-shipping-fast text-3xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-green-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">배달완료</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo $stats_map['delivered']['count'] ?? 0; ?></p>
                </div>
                <div class="text-green-500">
                    <i class="fas fa-check-circle text-3xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-blue-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">총 주문액</p>
                    <p class="text-2xl font-bold text-gray-900">₱<?php echo number_format(array_sum(array_column($stats, 'total')), 2); ?></p>
                </div>
                <div class="text-blue-500">
                    <i class="fas fa-money-bill-wave text-3xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- 검색 및 필터 -->
    <div class="bg-white shadow rounded-lg p-4 mb-6">
        <form method="GET" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
                <!-- 검색 -->
                <div class="md:col-span-2">
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search_term); ?>"
                           placeholder="주문번호, 고객명, 이메일, 전화번호"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 text-sm">
                </div>

                <!-- 주문 상태 -->
                <div>
                    <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 text-sm">
                        <option value="">전체 상태</option>
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>주문접수</option>
                        <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>주문확인</option>
                        <option value="preparing" <?php echo $status_filter === 'preparing' ? 'selected' : ''; ?>>상품준비중</option>
                        <option value="ready_for_delivery" <?php echo $status_filter === 'ready_for_delivery' ? 'selected' : ''; ?>>배달준비완료</option>
                        <option value="out_for_delivery" <?php echo $status_filter === 'out_for_delivery' ? 'selected' : ''; ?>>배달중</option>
                        <option value="delivered" <?php echo $status_filter === 'delivered' ? 'selected' : ''; ?>>배달완료</option>
                        <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>주문취소</option>
                    </select>
                </div>

                <!-- 결제 방법 -->
                <div>
                    <select name="payment" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 text-sm">
                        <option value="">전체 결제</option>
                        <option value="cod" <?php echo $payment_filter === 'cod' ? 'selected' : ''; ?>>COD</option>
                        <option value="gcash" <?php echo $payment_filter === 'gcash' ? 'selected' : ''; ?>>GCash</option>
                        <option value="paymaya" <?php echo $payment_filter === 'paymaya' ? 'selected' : ''; ?>>PayMaya</option>
                        <option value="online" <?php echo $payment_filter === 'online' ? 'selected' : ''; ?>>Online</option>
                    </select>
                </div>

                <!-- 점포 필터 (super_admin만) -->
                <?php if ($_SESSION['role'] === 'super_admin'): ?>
                <div>
                    <select name="store" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 text-sm">
                        <option value="">전체 점포</option>
                        <?php foreach ($stores as $store): ?>
                            <option value="<?php echo $store['id']; ?>" <?php echo $store_filter == $store['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($store['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <!-- 시작일 -->
                <div>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 text-sm">
                </div>

                <!-- 종료일 -->
                <div>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 text-sm">
                </div>

                <!-- 표시 개수 -->
                <div>
                    <select name="per_page" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 text-sm">
                        <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>>10개씩</option>
                        <option value="25" <?php echo $per_page == 25 ? 'selected' : ''; ?>>25개씩</option>
                        <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50개씩</option>
                        <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100개씩</option>
                    </select>
                </div>

                <!-- 검색/초기화 버튼 -->
                <div class="flex space-x-2">
                    <button type="submit" class="flex-1 px-3 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm">
                        <i class="fas fa-search mr-1"></i>검색
                    </button>
                    <a href="delivery_order_management.php" class="flex-1 px-3 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 text-center text-sm">
                        <i class="fas fa-times mr-1"></i>초기화
                    </a>
                </div>
            </div>
        </form>
    </div>

    <?php if ($error_message): ?>
    <div class="bg-red-50 border border-red-200 rounded-md p-4 mb-6">
        <p class="text-red-700"><?php echo htmlspecialchars($error_message); ?></p>
    </div>
    <?php endif; ?>

    <!-- 주문 테이블 -->
    <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400">
        <?php if (empty($orders)): ?>
        <div class="px-6 py-12 text-center">
            <i class="fas fa-inbox text-gray-400 text-4xl mb-4"></i>
            <h3 class="text-lg font-medium text-gray-900 mb-2">배달 주문이 없습니다</h3>
            <p class="text-gray-600">조건에 맞는 주문을 찾을 수 없습니다</p>
        </div>
        <?php else: ?>
        <!-- 테이블 헤더 -->
        <div class="px-6 py-4 border-b border-gray-200 bg-white flex justify-between items-center">
            <h3 class="text-lg leading-6 font-semibold text-gray-900">
                배달 주문 목록
                <span class="text-sm font-normal text-gray-600 ml-2">(<?php echo number_format($total_orders); ?>건)</span>
            </h3>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">주문번호</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">주문일시</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">고객정보</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">배달주소</th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-gray-700 uppercase">주문금액</th>
                        <th class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase">결제방법</th>
                        <th class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase">주문상태</th>
                        <th class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase">작업</th>
                    </tr>
                </thead>
                <tbody class="bg-white">
                    <?php foreach ($orders as $order):
                        $status_badge = getStatusBadge($order['order_status']);
                        $payment_badge = getPaymentBadge($order['payment_method']);
                    ?>
                    <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-blue-600">
                                <?php echo htmlspecialchars($order['order_number']); ?>
                            </div>
                            <div class="text-xs text-gray-500">
                                <?php echo htmlspecialchars($order['store_name']); ?>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo date('Y-m-d H:i', strtotime($order['created_at'])); ?>
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($order['customer_name']); ?>
                            </div>
                            <div class="text-xs text-gray-500">
                                <?php echo htmlspecialchars($order['customer_phone'] ?? '-'); ?>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-sm text-gray-900">
                                <?php echo htmlspecialchars($order['barangay'] ?? '-'); ?>
                            </div>
                            <div class="text-xs text-gray-500">
                                <?php echo htmlspecialchars($order['city'] ?? '-'); ?>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <div class="text-sm font-medium text-gray-900">
                                ₱<?php echo number_format($order['total_amount'], 2); ?>
                            </div>
                            <div class="text-xs text-gray-500">
                                상품 ₱<?php echo number_format($order['subtotal'], 2); ?> + 배달비 ₱<?php echo number_format($order['delivery_fee'], 2); ?>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $payment_badge['class']; ?>">
                                <?php echo $payment_badge['text']; ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $status_badge['class']; ?>">
                                <?php echo $status_badge['text']; ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <a href="delivery_order_detail.php?id=<?php echo $order['id']; ?>"
                               class="inline-flex items-center px-3 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700">
                                <i class="fas fa-eye mr-1"></i>상세보기
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- 페이징 -->
        <?php if ($total_pages > 1): ?>
        <div class="px-6 py-4 border-t border-gray-200 bg-gray-50">
            <div class="flex items-center justify-between">
                <div class="text-sm text-gray-700">
                    <?php echo ($offset + 1); ?>~<?php echo min($offset + $limit, $total_orders); ?>건 / 전체 <?php echo number_format($total_orders); ?>건
                </div>
                <div class="flex space-x-1">
                    <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&<?php echo http_build_query(array_filter($_GET, fn($k) => $k !== 'page', ARRAY_FILTER_USE_KEY)); ?>"
                       class="px-3 py-1 bg-white border border-gray-300 rounded text-sm hover:bg-gray-50">
                        이전
                    </a>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <a href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_filter($_GET, fn($k) => $k !== 'page', ARRAY_FILTER_USE_KEY)); ?>"
                       class="px-3 py-1 border rounded text-sm <?php echo $i === $page ? 'bg-blue-600 text-white border-blue-600' : 'bg-white border-gray-300 hover:bg-gray-50'; ?>">
                        <?php echo $i; ?>
                    </a>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&<?php echo http_build_query(array_filter($_GET, fn($k) => $k !== 'page', ARRAY_FILTER_USE_KEY)); ?>"
                       class="px-3 py-1 bg-white border border-gray-300 rounded text-sm hover:bg-gray-50">
                        다음
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
