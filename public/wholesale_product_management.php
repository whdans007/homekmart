<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('navigation.wholesale_product_management') . ' - ' . t('company.name');
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
$wholesale_products = [];
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

    // WHERE 절 구성 (현재 점포 기준 또는 super_admin은 모든 점포)
    $where_clause = " WHERE wp.is_active = 1";
    $params = [];
    
    // 점포 필터링
    if ($_SESSION['role'] !== 'super_admin' && !empty($current_store_id)) {
        $where_clause .= " AND wp.store_id = ?";
        $params[] = $current_store_id;
    }
    
    // 검색 조건 추가 (도매 전용 필드들도 포함)
    if (!empty($search_term)) {
        $where_clause .= " AND (p.name_ko LIKE ? OR p.name_en LIKE ? OR p.sku LIKE ? OR wp.wholesale_name_ko LIKE ? OR wp.wholesale_name_en LIKE ? OR wp.wholesale_skus LIKE ?)";
        $search_params = ["%$search_term%", "%$search_term%", "%$search_term%", "%$search_term%", "%$search_term%", "%$search_term%"];
        $params = array_merge($params, $search_params);
    }

    // 전체 도매상품 수 계산
    $total_sql = "
        SELECT COUNT(wp.id) 
        FROM wholesale_products wp
        LEFT JOIN products p ON wp.product_id = p.id
        LEFT JOIN stores s ON wp.store_id = s.id
        " . $where_clause;
    $total_stmt = $pdo->prepare($total_sql);
    $total_stmt->execute($params);
    $total_products = $total_stmt->fetchColumn();
    $total_pages = ceil($total_products / $limit);

    // 도매상품 목록 가져오기 (스키마 호환성 처리)
    try {
        // 새로운 컬럼들이 존재하는지 확인
        $check_columns = $pdo->query("SHOW COLUMNS FROM wholesale_products LIKE 'wholesale_name_ko'");
        $has_new_columns = $check_columns->rowCount() > 0;
        
        if ($has_new_columns) {
            $sql = "
                SELECT 
                    wp.id, wp.wholesale_price, wp.min_quantity, wp.created_at,
                    wp.wholesale_name_ko, wp.wholesale_name_en, wp.wholesale_skus, wp.wholesale_description,
                    p.id as product_id, p.sku, p.name_ko, p.name_en, p.barcode,
                    s.name as store_name
                FROM wholesale_products wp
                LEFT JOIN products p ON wp.product_id = p.id
                LEFT JOIN stores s ON wp.store_id = s.id
                " . $where_clause . "
                ORDER BY wp.created_at DESC
                LIMIT ? OFFSET ?
            ";
        } else {
            $sql = "
                SELECT 
                    wp.id, wp.wholesale_price, wp.min_quantity, wp.created_at,
                    NULL as wholesale_name_ko, NULL as wholesale_name_en, NULL as wholesale_skus, NULL as wholesale_description,
                    p.id as product_id, p.sku, p.name_ko, p.name_en, p.barcode,
                    s.name as store_name
                FROM wholesale_products wp
                LEFT JOIN products p ON wp.product_id = p.id
                LEFT JOIN stores s ON wp.store_id = s.id
                " . $where_clause . "
                ORDER BY wp.created_at DESC
                LIMIT ? OFFSET ?
            ";
        }
    } catch (PDOException $e) {
        // 오류 발생시 기본 쿼리 사용
        $sql = "
            SELECT 
                wp.id, wp.wholesale_price, wp.min_quantity, wp.created_at,
                NULL as wholesale_name_ko, NULL as wholesale_name_en, NULL as wholesale_skus, NULL as wholesale_description,
                p.id as product_id, p.sku, p.name_ko, p.name_en, p.barcode,
                s.name as store_name
            FROM wholesale_products wp
            LEFT JOIN products p ON wp.product_id = p.id
            LEFT JOIN stores s ON wp.store_id = s.id
            " . $where_clause . "
            ORDER BY wp.created_at DESC
            LIMIT ? OFFSET ?
        ";
    }
    $stmt = $pdo->prepare($sql);

    $current_param = 0;
    foreach ($params as $param) {
        $stmt->bindValue(++$current_param, $param, PDO::PARAM_STR);
    }
    $stmt->bindValue(++$current_param, $limit, PDO::PARAM_INT);
    $stmt->bindValue(++$current_param, $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $wholesale_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = t('common.error') . ": " . $e->getMessage();
}

?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900"><?php echo t('navigation.wholesale_product_management'); ?></h1>
            <p class="mt-2 text-sm text-gray-600"><?php echo t('wholesale.product_management'); ?></p>
            <?php if ($_SESSION['role'] !== 'super_admin'): ?>
                <p class="mt-1 text-xs text-gray-500"><?php echo $current_store_name; ?> 기준</p>
            <?php endif; ?>
        </div>
        <div class="flex space-x-4">
            <a href="wholesale_customer_management.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                <i class="fas fa-users mr-2"></i>
                <?php echo t('wholesale.customer_management'); ?>
            </a>
            <a href="add_wholesale_product.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                <i class="fas fa-plus mr-2"></i>
                <?php echo t('wholesale.add_product'); ?>
            </a>
        </div>
    </div>

    <!-- 검색 및 필터 -->
    <div class="bg-white shadow rounded-lg p-4 mb-6">
        <form method="GET" class="flex items-center space-x-3">
            <div class="flex-1">
                <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search_term); ?>" 
                       placeholder="상품명, SKU로 검색..." 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-primary-500 focus:border-primary-500 text-sm">
            </div>
            <button type="submit" class="px-3 py-2 bg-primary-600 text-white rounded-md hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 text-sm">
                <i class="fas fa-search mr-1"></i><?php echo t('common.search'); ?>
            </button>
            <a href="wholesale_product_management.php" class="px-3 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500 text-sm">
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

    <!-- 도매상품 테이블 -->
    <div class="bg-white shadow overflow-hidden rounded-lg border-2 border-gray-400">
        <?php if (empty($wholesale_products)): ?>
        <div class="px-6 py-12 text-center">
            <i class="fas fa-box text-gray-400 text-4xl mb-4"></i>
            <h3 class="text-lg font-medium text-gray-900 mb-2"><?php echo t('wholesale.no_products'); ?></h3>
            <p class="text-gray-600 mb-4"><?php echo t('wholesale.add_first_product'); ?></p>
            <a href="add_wholesale_product.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700">
                <i class="fas fa-plus mr-2"></i>
                <?php echo t('wholesale.add_product'); ?>
            </a>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            도매 SKU
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            도매 상품명
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            기준 상품
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <?php echo t('wholesale.wholesale_price'); ?>
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <?php echo t('wholesale.min_quantity'); ?>
                        </th>
                        <?php if ($_SESSION['role'] === 'super_admin'): ?>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            점포
                        </th>
                        <?php endif; ?>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            등록일
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <?php echo t('common.actions'); ?>
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($wholesale_products as $wp): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-900">
                            <?php
                            // 도매 SKU들 표시 (JSON에서 배열로 변환)
                            $wholesale_skus = '';
                            if (!empty($wp['wholesale_skus'])) {
                                $skus_array = json_decode($wp['wholesale_skus'], true);
                                if (is_array($skus_array)) {
                                    $wholesale_skus = implode(', ', $skus_array);
                                }
                            }
                            echo htmlspecialchars($wholesale_skus ?: $wp['sku']);
                            ?>
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-sm">
                                <?php 
                                // 도매 상품명 우선 표시
                                $display_name_en = $wp['wholesale_name_en'] ?: $wp['name_en'];
                                $display_name_ko = $wp['wholesale_name_ko'] ?: $wp['name_ko'];
                                ?>
                                <?php if (!empty($display_name_en)): ?>
                                <div class="font-medium text-gray-900 mb-1">
                                    <?php echo htmlspecialchars($display_name_en); ?>
                                    <?php if (!empty($wp['wholesale_name_en'])): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 ml-2">도매용</span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($display_name_ko)): ?>
                                <div class="text-gray-700 <?php echo empty($display_name_en) ? 'font-medium text-gray-900' : ''; ?>">
                                    <?php echo htmlspecialchars($display_name_ko); ?>
                                    <?php if (!empty($wp['wholesale_name_ko'])): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 ml-2">도매용</span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-600">
                            <div class="text-xs text-gray-500">기준 상품:</div>
                            <div><?php echo htmlspecialchars($wp['name_en'] ?: $wp['name_ko']); ?></div>
                            <div class="text-xs text-gray-500">SKU: <?php echo htmlspecialchars($wp['sku']); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-900">
                            <?php echo number_format($wp['wholesale_price']); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                            <?php echo number_format($wp['min_quantity']); ?>개
                        </td>
                        <?php if ($_SESSION['role'] === 'super_admin'): ?>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                            <?php echo htmlspecialchars($wp['store_name']); ?>
                        </td>
                        <?php endif; ?>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                            <?php echo date('Y-m-d', strtotime($wp['created_at'])); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <div class="flex space-x-2">
                                <a href="edit_wholesale_product.php?id=<?php echo $wp['id']; ?>" class="text-indigo-600 hover:text-indigo-900">
                                    <i class="fas fa-edit"></i> <?php echo t('common.edit'); ?>
                                </a>
                                <a href="delete_wholesale_product.php?id=<?php echo $wp['id']; ?>" 
                                   class="text-red-600 hover:text-red-900"
                                   onclick="return confirm('이 도매상품을 삭제하시겠습니까?');">
                                    <i class="fas fa-trash"></i> <?php echo t('common.delete'); ?>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- 페이징 -->
        <?php if ($total_pages > 1): ?>
        <div class="bg-white px-6 py-3 border-t border-gray-200 flex items-center justify-between">
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
                        $end = min($page * $limit, $total_products);
                        echo "총 {$total_products}개 중 {$start}-{$end}개 표시";
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