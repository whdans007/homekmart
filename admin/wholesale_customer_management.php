<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('navigation.wholesale_customer_management') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 도매판매 권한 확인
if (!has_permission('wholesale_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

$pdo = null;
$customers = [];
$error_message = '';

// 검색 및 페이징 변수
$search_term = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
$limit = in_array($per_page, [10, 25, 50, 100, 200]) ? $per_page : 10;
$offset = ($page - 1) * $limit;

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // WHERE 절 구성
    $where_clause = " WHERE is_active = 1";
    $params = [];

    // 점포 필터링 (super_admin이 아닌 경우 자신의 점포만 조회)
    if ($_SESSION['role'] !== 'super_admin') {
        if (!empty($current_store_id)) {
            $where_clause .= " AND store_id = ?";
            $params[] = $current_store_id;
        } else {
            // 점포가 지정되지 않은 경우 데이터 조회 불가
            $where_clause .= " AND 1 = 0";
        }
    }

    // 검색 조건 추가
    if (!empty($search_term)) {
        $where_clause .= " AND (name LIKE ? OR phone LIKE ? OR address LIKE ?)";
        $search_params = ["%$search_term%", "%$search_term%", "%$search_term%"];
        $params = array_merge($params, $search_params);
    }

    // 전체 거래처 수 계산
    $total_stmt = $pdo->prepare("SELECT COUNT(id) FROM wholesale_customers" . $where_clause);
    $total_stmt->execute($params);
    $total_customers = $total_stmt->fetchColumn();
    $total_pages = ceil($total_customers / $limit);

    // 거래처 목록 가져오기 (최신 등록순)
    $sql = "
        SELECT id, name, phone, address, memo, created_at
        FROM wholesale_customers
        " . $where_clause . "
        ORDER BY created_at DESC, id DESC
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

    <!-- 검색 및 필터 -->
    <div class="bg-white shadow rounded-lg p-4 mb-6">
        <form method="GET" class="flex items-center space-x-3">
            <div class="flex-1">
                <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search_term); ?>" 
                       placeholder="거래처명, 전화번호, 주소로 검색..." 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-primary-500 focus:border-primary-500 text-sm">
            </div>
            <button type="submit" class="px-3 py-2 bg-primary-600 text-white rounded-md hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 text-sm">
                <i class="fas fa-search mr-1"></i><?php echo t('common.search'); ?>
            </button>
            <a href="wholesale_customer_management.php" class="px-3 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500 text-sm">
                <i class="fas fa-times mr-1"></i><?php echo t('common.clear'); ?>
            </a>
            <div class="flex items-center space-x-2">
                <label for="per_page" class="text-sm text-gray-700 whitespace-nowrap"><?php echo t('product.display_count'); ?>:</label>
                <select name="per_page" id="per_page" onchange="this.form.submit()" class="px-2 py-2 border border-gray-300 rounded-md focus:ring-primary-500 focus:border-primary-500 text-sm">
                    <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>>10개</option>
                    <option value="25" <?php echo $per_page == 25 ? 'selected' : ''; ?>>25개</option>
                    <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50개</option>
                    <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100개</option>
                </select>
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

    <!-- 거래처 테이블 -->
    <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400">
        <?php if (empty($customers)): ?>
        <div class="px-6 py-12 text-center">
            <i class="fas fa-users text-gray-400 text-4xl mb-4"></i>
            <h3 class="text-lg font-medium text-gray-900 mb-2"><?php echo t('wholesale.no_customers'); ?></h3>
            <p class="text-gray-600 mb-4"><?php echo t('wholesale.add_first_customer'); ?></p>
            <a href="add_wholesale_customer.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700">
                <i class="fas fa-plus mr-2"></i>
                <?php echo t('wholesale.add_customer'); ?>
            </a>
        </div>
        <?php else: ?>
        <!-- 테이블 헤더 -->
        <div class="px-6 py-4 border-b border-gray-200 bg-white flex justify-between items-center">
            <h3 class="text-lg leading-6 font-semibold text-gray-900">
                <?php echo t('navigation.wholesale_customer_management'); ?>
            </h3>
            <div class="flex space-x-3">
                <a href="wholesale_product_management.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <i class="fas fa-box mr-2"></i>
                    <?php echo t('wholesale.product_management'); ?>
                </a>
                <a href="add_wholesale_customer.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    <i class="fas fa-plus mr-2"></i>
                    <?php echo t('wholesale.add_customer'); ?>
                </a>
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                            <?php echo t('wholesale.customer_name'); ?>
                        </th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                            <?php echo t('wholesale.customer_phone'); ?>
                        </th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                            <?php echo t('wholesale.customer_address'); ?>
                        </th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                            등록일
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white">
                    <?php foreach ($customers as $customer): ?>
                    <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150 cursor-pointer" onclick="window.location.href='edit_wholesale_customer.php?id=<?php echo $customer['id']; ?>';">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($customer['name']); ?>
                            </div>
                            <?php if (!empty($customer['memo'])): ?>
                            <div class="text-xs text-gray-500 mt-1">
                                <?php echo htmlspecialchars(mb_substr($customer['memo'], 0, 50)); ?>
                                <?php if (mb_strlen($customer['memo']) > 50) echo '...'; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo htmlspecialchars($customer['phone'] ?: '-'); ?>
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-sm text-gray-900">
                                <?php echo htmlspecialchars($customer['address'] ?: '-'); ?>
                            </div>
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
                        echo "총 {$total_customers}개 중 {$start}-{$end}개 표시";
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