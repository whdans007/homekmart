<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('navigation.customer_management') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';

// 고객 관리 권한 확인
require_permission('customer_management');

$pdo = null;
$customers = [];
$stores = [];
$error_message = '';

// 검색 및 필터링 변수
$search_term = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$store_filter = $_GET['store'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
$limit = in_array($per_page, [10, 25, 50, 100, 200]) ? $per_page : 25;
$offset = ($page - 1) * $limit;

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 점포 목록 가져오기 (필터링용)
    $stores = $pdo->query("SELECT id, name FROM stores ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

    // WHERE 절 구성
    $where_clause = " WHERE 1=1";
    $params = [];

    // 점포별 필터링 (admin은 자기 점포만 조회)
    if ($_SESSION['role'] !== 'super_admin' && !empty($_SESSION['store_id'])) {
        $where_clause .= " AND (c.preferred_store_id = ? OR c.preferred_store_id IS NULL)";
        $params[] = $_SESSION['store_id'];
    } elseif (!empty($store_filter)) {
        $where_clause .= " AND c.preferred_store_id = ?";
        $params[] = $store_filter;
    }

    // 상태 필터
    if (!empty($status_filter)) {
        $where_clause .= " AND c.status = ?";
        $params[] = $status_filter;
    }

    // 검색 조건 추가
    if (!empty($search_term)) {
        $where_clause .= " AND (c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.address LIKE ?)";
        $search_param = "%$search_term%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }

    // 전체 고객 수 계산
    $count_sql = "SELECT COUNT(c.id) FROM customers c" . $where_clause;
    $total_stmt = $pdo->prepare($count_sql);
    $total_stmt->execute($params);
    $total_customers = $total_stmt->fetchColumn();
    $total_pages = ceil($total_customers / $limit);

    // 고객 목록 가져오기
    $sql = "
        SELECT
            c.id,
            c.name,
            c.email,
            c.phone,
            c.address,
            c.status,
            c.preferred_store_id,
            c.created_at,
            c.last_login_at,
            s.name as store_name
        FROM customers c
        LEFT JOIN stores s ON c.preferred_store_id = s.id
        " . $where_clause . "
        ORDER BY c.created_at DESC
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
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = t('common.error') . ": " . $e->getMessage();
}

?>

<div class="w-full px-2 sm:px-3 md:px-4 py-8">

    <!-- Page Header -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900"><?php echo t('navigation.customer_management'); ?></h1>
        <p class="text-sm text-gray-600 mt-1"><?php echo t('customer.list_description'); ?></p>
    </div>

    <!-- 검색 및 필터 -->
    <div class="bg-white shadow rounded-lg p-4 mb-6">
        <form method="GET" class="space-y-4">
            <div class="flex flex-col md:flex-row md:items-center md:space-x-3 space-y-3 md:space-y-0">
                <!-- 검색 -->
                <div class="flex-1">
                    <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search_term); ?>"
                           placeholder="<?php echo t('customer.search_placeholder'); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-primary-500 focus:border-primary-500 text-sm">
                </div>

                <!-- 상태 필터 -->
                <div>
                    <select name="status" class="px-3 py-2 border border-gray-300 rounded-md focus:ring-primary-500 focus:border-primary-500 text-sm">
                        <option value=""><?php echo t('customer.all_status'); ?></option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>><?php echo t('customer.status_active'); ?></option>
                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>><?php echo t('customer.status_inactive'); ?></option>
                        <option value="suspended" <?php echo $status_filter === 'suspended' ? 'selected' : ''; ?>><?php echo t('customer.status_suspended'); ?></option>
                    </select>
                </div>

                <!-- 점포 필터 (super_admin만) -->
                <?php if ($_SESSION['role'] === 'super_admin'): ?>
                <div>
                    <select name="store" class="px-3 py-2 border border-gray-300 rounded-md focus:ring-primary-500 focus:border-primary-500 text-sm">
                        <option value=""><?php echo t('customer.all_stores'); ?></option>
                        <?php foreach ($stores as $store): ?>
                            <option value="<?php echo $store['id']; ?>" <?php echo $store_filter == $store['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($store['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <!-- 표시 개수 -->
                <div class="flex items-center space-x-2">
                    <label for="per_page" class="text-sm text-gray-700 whitespace-nowrap"><?php echo t('product.display_count'); ?>:</label>
                    <select name="per_page" id="per_page" onchange="this.form.submit()" class="px-2 py-2 border border-gray-300 rounded-md focus:ring-primary-500 focus:border-primary-500 text-sm">
                        <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>>10</option>
                        <option value="25" <?php echo $per_page == 25 ? 'selected' : ''; ?>>25</option>
                        <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100</option>
                        <option value="200" <?php echo $per_page == 200 ? 'selected' : ''; ?>>200</option>
                    </select>
                </div>

                <!-- 검색/초기화 버튼 -->
                <div class="flex space-x-2">
                    <button type="submit" class="px-3 py-2 bg-primary-600 text-white rounded-md hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 text-sm">
                        <i class="fas fa-search mr-1"></i><?php echo t('common.search'); ?>
                    </button>
                    <a href="customer_management.php" class="px-3 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500 text-sm">
                        <i class="fas fa-times mr-1"></i><?php echo t('common.clear'); ?>
                    </a>
                </div>
            </div>
        </form>
    </div>

    <?php if ($error_message): ?>
    <div class="bg-red-50 border border-red-200 rounded-md p-4 mb-6">
        <div class="flex">
            <div class="flex-shrink-0">
                <i class="fas fa-exclamation-triangle text-red-400"></i>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-red-800"><?php echo t('common.error'); ?></h3>
                <div class="mt-2 text-sm text-red-700">
                    <p><?php echo htmlspecialchars($error_message); ?></p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 고객 테이블 -->
    <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400">
        <?php if (empty($customers)): ?>
        <div class="px-6 py-12 text-center">
            <i class="fas fa-users text-gray-400 text-4xl mb-4"></i>
            <h3 class="text-lg font-medium text-gray-900 mb-2"><?php echo t('customer.no_customers'); ?></h3>
            <p class="text-gray-600"><?php echo t('customer.no_customers_found'); ?></p>
        </div>
        <?php else: ?>
        <!-- 테이블 헤더 -->
        <div class="px-6 py-4 border-b border-gray-200 bg-white flex justify-between items-center">
            <h3 class="text-lg leading-6 font-semibold text-gray-900">
                <?php echo t('customer.list'); ?>
                <span class="text-sm font-normal text-gray-600 ml-2">(<?php echo number_format($total_customers); ?>명)</span>
            </h3>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                            <?php echo t('customer.name'); ?>
                        </th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                            <?php echo t('customer.contact'); ?>
                        </th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                            <?php echo t('customer.address'); ?>
                        </th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                            <?php echo t('customer.preferred_store'); ?>
                        </th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                            <?php echo t('customer.status'); ?>
                        </th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                            <?php echo t('customer.created_at'); ?>
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white">
                    <?php foreach ($customers as $customer): ?>
                    <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150 cursor-pointer" onclick="window.location.href='edit_customer.php?id=<?php echo $customer['id']; ?>';">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($customer['name']); ?>
                            </div>
                            <div class="text-xs text-gray-500">
                                <?php echo htmlspecialchars($customer['email']); ?>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo htmlspecialchars($customer['phone'] ?: '-'); ?>
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-sm text-gray-900 max-w-xs truncate">
                                <?php echo htmlspecialchars($customer['address'] ?: '-'); ?>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo htmlspecialchars($customer['store_name'] ?: '-'); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php
                            $status_classes = [
                                'active' => 'bg-green-100 text-green-800',
                                'inactive' => 'bg-gray-100 text-gray-800',
                                'suspended' => 'bg-red-100 text-red-800'
                            ];
                            $status_class = $status_classes[$customer['status']] ?? 'bg-gray-100 text-gray-800';
                            ?>
                            <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $status_class; ?>">
                                <?php echo t('customer.status_' . $customer['status']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo date('Y-m-d', strtotime($customer['created_at'])); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- 페이징 -->
        <?php if ($total_pages > 1): ?>
        <div class="bg-gray-50 px-6 py-4 border-t border-gray-200 flex items-center justify-between">
            <div class="flex-1 flex justify-between sm:hidden">
                <?php if ($page > 1): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        <?php echo t('common.previous'); ?>
                    </a>
                <?php endif; ?>
                <?php if ($page < $total_pages): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        <?php echo t('common.next'); ?>
                    </a>
                <?php endif; ?>
            </div>
            <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm text-gray-700">
                        <?php
                        $start = ($page - 1) * $limit + 1;
                        $end = min($page * $limit, $total_customers);
                        echo sprintf(t('common.showing_x_to_y_of_z'), $start, $end, $total_customers);
                        ?>
                    </p>
                </div>
                <div>
                    <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);

                        if ($page > 1):
                        ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php endif; ?>

                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <?php if ($i == $page): ?>
                                <span class="relative inline-flex items-center px-4 py-2 border border-primary-500 bg-primary-50 text-sm font-medium text-primary-600">
                                    <?php echo $i; ?>
                                </span>
                            <?php else: ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">
                                    <?php echo $i; ?>
                                </a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </nav>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
