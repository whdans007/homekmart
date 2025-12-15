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

// per_page 설정: GET 파라미터가 있으면 세션에 저장, 없으면 세션에서 가져오기
if (isset($_GET['per_page'])) {
    $per_page = (int)$_GET['per_page'];
    $_SESSION['wholesale_per_page'] = $per_page;
} else {
    $per_page = $_SESSION['wholesale_per_page'] ?? 10;
}

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
                    COALESCE(i.cost_price, 0) as cost_price,
                    COALESCE(wp.margin_rate, 15.00) as margin_rate,
                    p.id as product_id, p.sku, p.name_ko, p.name_en, p.pieces_per_box,
                    s.name as store_name
                FROM wholesale_products wp
                LEFT JOIN products p ON wp.product_id = p.id
                LEFT JOIN stores s ON wp.store_id = s.id
                LEFT JOIN inventory i ON wp.product_id = i.product_id AND wp.store_id = i.store_id
                " . $where_clause . "
                ORDER BY wp.created_at DESC
                LIMIT ? OFFSET ?
            ";
        } else {
            $sql = "
                SELECT 
                    wp.id, wp.wholesale_price, wp.min_quantity, wp.created_at,
                    NULL as wholesale_name_ko, NULL as wholesale_name_en, NULL as wholesale_skus, NULL as wholesale_description,
                    COALESCE(i.cost_price, 0) as cost_price,
                    COALESCE(wp.margin_rate, 15.00) as margin_rate,
                    p.id as product_id, p.sku, p.name_ko, p.name_en, p.pieces_per_box,
                    s.name as store_name
                FROM wholesale_products wp
                LEFT JOIN products p ON wp.product_id = p.id
                LEFT JOIN stores s ON wp.store_id = s.id
                LEFT JOIN inventory i ON wp.product_id = i.product_id AND wp.store_id = i.store_id
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
                COALESCE(i.cost_price, 0) as cost_price,
                COALESCE(wp.margin_rate, 15.00) as margin_rate,
                p.id as product_id, p.sku, p.name_ko, p.name_en, p.pieces_per_box,
                s.name as store_name
            FROM wholesale_products wp
            LEFT JOIN products p ON wp.product_id = p.id
            LEFT JOIN stores s ON wp.store_id = s.id
            LEFT JOIN inventory i ON wp.product_id = i.product_id AND wp.store_id = i.store_id
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

<div class="w-full px-2 sm:px-3 md:px-4 py-8">

    <!-- 검색 및 필터 -->
    <div class="bg-white shadow rounded-lg p-4 mb-6">
        <form method="GET" class="flex items-center space-x-3">
            <div class="flex-1">
                <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search_term); ?>" 
                       placeholder="<?php echo htmlspecialchars(t('wholesale_product_management.search_placeholder')); ?>" 
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
                    <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>><?php echo t('wholesale_product_management.display_count_10'); ?></option>
                    <option value="25" <?php echo $per_page == 25 ? 'selected' : ''; ?>><?php echo t('wholesale_product_management.display_count_25'); ?></option>
                    <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>><?php echo t('wholesale_product_management.display_count_50'); ?></option>
                    <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>><?php echo t('wholesale_product_management.display_count_100'); ?></option>
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
    <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400">
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
        <!-- 테이블 헤더 -->
        <div class="px-6 py-4 border-b border-gray-200 bg-white flex justify-between items-center">
            <h3 class="text-lg leading-6 font-semibold text-gray-900">
                <?php echo t('navigation.wholesale_product_management'); ?>
            </h3>
            <div class="flex space-x-3">
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
        
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                            <?php echo htmlspecialchars(t('wholesale_product_management.table_wholesale_sku')); ?>
                        </th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                            <?php echo htmlspecialchars(t('wholesale_product_management.table_wholesale_name')); ?>
                        </th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                            <?php echo htmlspecialchars(t('wholesale_product_management.table_box_quantity')); ?>
                        </th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                            <?php echo htmlspecialchars(t('wholesale_product_management.table_cost_price')); ?>
                        </th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                            <?php echo htmlspecialchars(t('wholesale_product_management.table_margin_rate')); ?>
                        </th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                            <?php echo t('wholesale.wholesale_price'); ?>
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white">
                    <?php foreach ($wholesale_products as $wp): ?>
                    <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150 cursor-pointer" onclick="window.location.href='edit_wholesale_product.php?id=<?php echo $wp['id']; ?>'">
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
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($display_name_ko)): ?>
                                <div class="text-gray-700 <?php echo empty($display_name_en) ? 'font-medium text-gray-900' : ''; ?>">
                                    <?php echo htmlspecialchars($display_name_ko); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo $wp['pieces_per_box'] ? number_format($wp['pieces_per_box']) . t('wholesale_product_management.pieces_unit') : '-'; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-600">
                            <?php echo number_format($wp['cost_price']); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-mono">
                            <?php 
                            $marginRate = $wp['margin_rate'] ?? 15.00;
                            $actualMarginRate = ($wp['cost_price'] > 0) ? (($wp['wholesale_price'] / $wp['cost_price'] - 1) * 100) : 0;
                            $colorClass = 'text-gray-500';
                            
                            // 실제 마진율에 따른 색상 결정
                            if ($actualMarginRate < 10) {
                                $colorClass = 'text-red-500';
                            } elseif ($actualMarginRate < 20) {
                                $colorClass = 'text-yellow-600';
                            } else {
                                $colorClass = 'text-green-600';
                            }
                            ?>
                            <div class="<?php echo $colorClass; ?>">
                                <?php echo number_format($actualMarginRate, 1); ?>%
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-900">
                            <?php echo number_format($wp['wholesale_price']); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- 페이징 -->
        <?php if ($total_pages > 1): ?>
        <div class="bg-white px-4 py-3 flex items-center justify-center border-t border-gray-200 sm:px-6">
            <div class="flex-1 flex justify-center">
                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                    <?php
                    // 현재 페이지가 속한 10페이지 그룹 계산
                    $current_group = ceil($page / 10);
                    $group_start = ($current_group - 1) * 10 + 1;
                    $group_end = min($current_group * 10, $total_pages);

                    // 이전 그룹이 있으면 이전 버튼 표시
                    if ($group_start > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $group_start - 1])); ?>"
                           class="relative inline-flex items-center px-4 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                            <i class="fas fa-chevron-left mr-2"></i>이전
                        </a>
                    <?php endif; ?>

                    <?php
                    // 현재 그룹의 페이지들 표시 (1-10, 11-20, ...)
                    for ($i = $group_start; $i <= $group_end; $i++):
                    ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                           class="<?php echo $i == $page ? 'bg-indigo-50 border-indigo-500 text-indigo-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50'; ?>
                                  relative inline-flex items-center px-4 py-2 border text-sm font-medium
                                  <?php echo ($i == $group_start && $group_start == 1) ? 'rounded-l-md' : ''; ?>
                                  <?php echo ($i == $group_end && $group_end == $total_pages) ? 'rounded-r-md' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php
                    // 다음 그룹이 있으면 다음 버튼 표시
                    if ($group_end < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $group_end + 1])); ?>"
                           class="relative inline-flex items-center px-4 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                            다음<i class="fas fa-chevron-right ml-2"></i>
                        </a>
                    <?php endif; ?>
                </nav>
            </div>

            <!-- 하단 그룹 정보 -->
            <div class="text-center mt-3">
                <span class="text-sm text-gray-700">
                    페이지 <?php echo $page; ?> / <?php echo $total_pages; ?>
                    (총 <?php echo number_format($total_products); ?>개 항목)
                </span>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>