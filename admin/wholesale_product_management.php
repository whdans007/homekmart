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

// 거래처별 가격 관리 관련 (DB 오류 시에도 템플릿에서 안전하게 참조)
$customers = [];
$has_cust_price_table = false;
$selected_customer_id = (int)($_GET['customer_id'] ?? 0);
$by_customer = false;
$selected_customer_name = '';

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

    // 컬럼 존재 감지 (스키마 버전 호환)
    $existing = [];
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM wholesale_products")->fetchAll(PDO::FETCH_COLUMN) as $colname) {
            $existing[$colname] = true;
        }
    } catch (PDOException $e) {
        // 감지 실패 시 기본값 사용
    }
    $has_name_cols = isset($existing['wholesale_name_ko']);

    // 컬럼이 있으면 wp.컬럼, 없으면 기본값을 select
    $colExpr = function ($name, $default) use ($existing) {
        return (isset($existing[$name]) ? "wp.$name" : $default) . " as $name";
    };

    // ── 거래처(업체)별 가격 관리 준비 ──────────────────────────────
    // 예외가 테이블 존재 감지 (마이그레이션 전 안전)
    $has_cust_price_table = false;
    try {
        $has_cust_price_table = (bool)$pdo->query("SHOW TABLES LIKE 'wholesale_customer_prices'")->fetchColumn();
    } catch (PDOException $e) { /* 무시 */ }

    // 거래처 store 스코프 컬럼 감지
    $cust_has_store = false;
    try {
        $cust_has_store = (bool)$pdo->query("SHOW COLUMNS FROM wholesale_customers LIKE 'store_id'")->fetchColumn();
    } catch (PDOException $e) { /* 무시 */ }

    // 거래처 목록 (선택기용)
    $customers = [];
    try {
        $cust_sql = "SELECT id, name FROM wholesale_customers WHERE is_active = 1";
        $cust_params = [];
        if ($cust_has_store && $_SESSION['role'] !== 'super_admin' && !empty($current_store_id)) {
            $cust_sql .= " AND (store_id = ? OR store_id IS NULL)";
            $cust_params[] = $current_store_id;
        }
        $cust_sql .= " ORDER BY name ASC";
        $cust_stmt = $pdo->prepare($cust_sql);
        $cust_stmt->execute($cust_params);
        $customers = $cust_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { /* 무시 */ }

    // 선택된 거래처 (0 = 공통/기본가 관리)
    $selected_customer_id = (int)($_GET['customer_id'] ?? 0);
    $by_customer = ($selected_customer_id > 0 && $has_cust_price_table);
    $selected_customer_name = '';
    if ($by_customer) {
        foreach ($customers as $c) { if ((int)$c['id'] === $selected_customer_id) { $selected_customer_name = $c['name']; break; } }
    }

    $sql = "
        SELECT
            wp.id, wp.wholesale_price, wp.created_at,
            " . ($has_name_cols
                ? "wp.wholesale_name_ko, wp.wholesale_name_en, wp.wholesale_skus,"
                : "NULL as wholesale_name_ko, NULL as wholesale_name_en, NULL as wholesale_skus,") . "
            " . $colExpr('cost_price', '0') . ",
            " . $colExpr('cost_price_piece', '0') . ",
            " . $colExpr('wholesale_price_piece', '0') . ",
            " . $colExpr('memo', 'NULL') . ",
            " . (isset($existing['sale_unit']) ? "wp.sale_unit" : "'box'") . " as sale_unit,
            " . (isset($existing['margin_rate']) ? "COALESCE(wp.margin_rate, 15.00)" : "15.00") . " as margin_rate,
            " . ($by_customer
                ? "wcp.wholesale_price as cust_price_box, wcp.wholesale_price_piece as cust_price_piece,"
                : "NULL as cust_price_box, NULL as cust_price_piece,") . "
            p.id as product_id, p.sku, p.name_ko, p.name_en, p.pieces_per_box,
            s.name as store_name
        FROM wholesale_products wp
        LEFT JOIN products p ON wp.product_id = p.id
        LEFT JOIN stores s ON wp.store_id = s.id
        " . ($by_customer ? "LEFT JOIN wholesale_customer_prices wcp ON wcp.wholesale_product_id = wp.id AND wcp.customer_id = ?" : "") . "
        " . $where_clause . "
        ORDER BY wp.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $stmt = $pdo->prepare($sql);

    $current_param = 0;
    if ($by_customer) {
        $stmt->bindValue(++$current_param, $selected_customer_id, PDO::PARAM_INT);
    }
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
            <?php if (!empty($customers) && $has_cust_price_table): ?>
            <div class="flex items-center space-x-2">
                <label for="customer_id" class="text-sm text-gray-700 whitespace-nowrap"><i class="fas fa-store mr-1 text-primary-600"></i><?php echo htmlspecialchars(t('wholesale_product_management.customer_filter_label')); ?></label>
                <select name="customer_id" id="customer_id" onchange="this.form.submit()" class="px-2 py-2 border border-gray-300 rounded-md focus:ring-primary-500 focus:border-primary-500 text-sm">
                    <option value="0"><?php echo htmlspecialchars(t('wholesale_product_management.common_base_option')); ?></option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?php echo (int)$c['id']; ?>" <?php echo $selected_customer_id === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
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

    <?php if ($selected_customer_id > 0 && !$has_cust_price_table): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-md p-4 mb-6 text-sm text-amber-800">
        <i class="fas fa-triangle-exclamation mr-1"></i>
        <?php echo htmlspecialchars(t('wholesale_product_management.migration_warning')); ?>
        <code class="bg-amber-100 px-1 rounded">admin/migrate_create_wholesale_customer_prices.php</code>
    </div>
    <?php elseif ($by_customer): ?>
    <div class="bg-primary-50 border border-primary-200 rounded-md p-3 mb-4 text-sm text-primary-800 flex items-center justify-between">
        <div>
            <i class="fas fa-store mr-1"></i>
            <strong><?php echo htmlspecialchars($selected_customer_name); ?></strong> <?php echo htmlspecialchars(t('wholesale_product_management.editing_prefix')); ?> <i class="fas fa-save"></i> <?php echo htmlspecialchars(t('wholesale_product_management.editing_suffix')); ?>
            <span class="text-primary-600"><?php echo htmlspecialchars(t('wholesale_product_management.editing_note')); ?></span>
        </div>
        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-amber-100 text-amber-700"><?php echo htmlspecialchars(t('wholesale_product_management.exception_price_badge')); ?></span>
    </div>
    <?php endif; ?>

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
                        <th scope="col" class="px-3 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo htmlspecialchars(t('wholesale_product_management.table_wholesale_sku')); ?></th>
                        <th scope="col" class="px-3 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo htmlspecialchars(t('wholesale_product_management.table_wholesale_name')); ?></th>
                        <th scope="col" class="px-3 py-3 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo htmlspecialchars(t('wholesale_product_management.table_box_quantity')); ?></th>
                        <th scope="col" class="px-3 py-3 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo htmlspecialchars(t('add_wholesale_product.sale_unit_label')); ?></th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo htmlspecialchars(t('add_wholesale_product.cost_price_piece')); ?></th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo htmlspecialchars(t('add_wholesale_product.cost_price_box')); ?></th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo htmlspecialchars(t('wholesale_product_management.table_margin_rate')); ?></th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo htmlspecialchars(t('add_wholesale_product.wholesale_price_piece')); ?></th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo htmlspecialchars(t('add_wholesale_product.wholesale_price_box')); ?></th>
                        <th scope="col" class="px-3 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo htmlspecialchars(t('add_wholesale_product.memo_label')); ?></th>
                    </tr>
                </thead>
                <tbody class="bg-white">
                    <?php foreach ($wholesale_products as $wp): ?>
                    <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150 <?php echo $by_customer ? '' : 'cursor-pointer'; ?>" <?php echo $by_customer ? '' : "onclick=\"window.location.href='edit_wholesale_product.php?id=" . (int)$wp['id'] . "'\""; ?>>
                        <!-- SKU -->
                        <td class="px-3 py-3 whitespace-nowrap text-sm font-mono text-gray-900">
                            <?php
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
                        <!-- 도매 상품명 -->
                        <td class="px-3 py-3">
                            <div class="text-sm">
                                <?php
                                $display_name_en = $wp['wholesale_name_en'] ?: $wp['name_en'];
                                $display_name_ko = $wp['wholesale_name_ko'] ?: $wp['name_ko'];
                                ?>
                                <?php if (!empty($display_name_en)): ?>
                                <div class="font-medium text-gray-900 mb-1"><?php echo htmlspecialchars($display_name_en); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($display_name_ko)): ?>
                                <div class="text-gray-700 <?php echo empty($display_name_en) ? 'font-medium text-gray-900' : ''; ?>"><?php echo htmlspecialchars($display_name_ko); ?></div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <!-- 박스포장갯수 -->
                        <td class="px-3 py-3 whitespace-nowrap text-center text-sm text-gray-900">
                            <?php echo $wp['pieces_per_box'] ? number_format($wp['pieces_per_box']) . t('wholesale_product_management.pieces_unit') : '-'; ?>
                        </td>
                        <!-- 판매단위 -->
                        <td class="px-3 py-3 whitespace-nowrap text-center">
                            <?php $is_box = ($wp['sale_unit'] ?? 'box') !== 'piece'; ?>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold <?php echo $is_box ? 'bg-amber-100 text-amber-700' : 'bg-blue-100 text-blue-700'; ?>">
                                <?php echo $is_box ? 'BOX' : 'PCS'; ?>
                            </span>
                        </td>
                        <!-- 원가(낱개) -->
                        <td class="px-3 py-3 whitespace-nowrap text-right text-sm font-mono text-gray-600">
                            <?php echo $wp['cost_price_piece'] > 0 ? number_format($wp['cost_price_piece'], 2) : '-'; ?>
                        </td>
                        <!-- 원가(박스) -->
                        <td class="px-3 py-3 whitespace-nowrap text-right text-sm font-mono text-gray-600">
                            <?php echo $wp['cost_price'] > 0 ? number_format($wp['cost_price']) : '-'; ?>
                        </td>
                        <!-- 마진율 -->
                        <td class="px-3 py-3 whitespace-nowrap text-right text-sm font-mono">
                            <?php
                            $marginRate = (float)($wp['margin_rate'] ?? 15.00);
                            $colorClass = 'text-gray-500';
                            if ($marginRate < 10) {
                                $colorClass = 'text-red-500';
                            } elseif ($marginRate < 20) {
                                $colorClass = 'text-yellow-600';
                            } else {
                                $colorClass = 'text-green-600';
                            }
                            ?>
                            <span class="<?php echo $colorClass; ?>"><?php echo number_format($marginRate, 1); ?>%</span>
                        </td>
                        <?php
                        $base_box   = (float)$wp['wholesale_price'];
                        $base_piece = (float)$wp['wholesale_price_piece'];
                        $ov_box     = $wp['cust_price_box'];     // null=예외 없음
                        $ov_piece   = $wp['cust_price_piece'];
                        $eff_box    = ($ov_box   !== null) ? (float)$ov_box   : $base_box;
                        $eff_piece  = ($ov_piece !== null) ? (float)$ov_piece : $base_piece;
                        ?>
                        <?php if ($by_customer): ?>
                        <!-- 도매가(낱개) - 거래처 예외가 편집 -->
                        <td class="px-2 py-2 text-right">
                            <input type="number" step="0.01" min="0"
                                   class="cp-piece border rounded px-2 py-1 text-sm <?php echo $ov_piece !== null ? 'border-amber-400 bg-amber-50' : 'border-gray-300'; ?>"
                                   data-wpid="<?php echo (int)$wp['id']; ?>"
                                   style="width:90px;text-align:right;font-family:monospace"
                                   value="<?php echo $eff_piece > 0 ? htmlspecialchars(rtrim(rtrim(number_format($eff_piece, 2, '.', ''), '0'), '.')) : ''; ?>">
                            <div style="font-size:10px" class="text-gray-400"><?php echo htmlspecialchars(t('wholesale_product_management.base_prefix')); ?> <?php echo $base_piece > 0 ? number_format($base_piece) : '-'; ?></div>
                        </td>
                        <!-- 도매가(박스) - 거래처 예외가 편집 + 저장 -->
                        <td class="px-2 py-2 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <input type="number" step="0.01" min="0" class="cp-box border rounded px-2 py-1 text-sm font-semibold <?php echo $ov_box !== null ? 'border-amber-400 bg-amber-50' : 'border-gray-300'; ?>"
                                       data-wpid="<?php echo (int)$wp['id']; ?>"
                                       style="width:90px;text-align:right;font-family:monospace"
                                       value="<?php echo $eff_box > 0 ? htmlspecialchars(rtrim(rtrim(number_format($eff_box, 2, '.', ''), '0'), '.')) : ''; ?>">
                                <button type="button" class="cp-save px-2 py-1 bg-primary-600 text-white rounded text-xs hover:bg-primary-700" data-wpid="<?php echo (int)$wp['id']; ?>" title="<?php echo htmlspecialchars(t('wholesale_product_management.save_exception_tooltip')); ?>">
                                    <i class="fas fa-save"></i>
                                </button>
                            </div>
                            <div style="font-size:10px" class="text-gray-400"><?php echo htmlspecialchars(t('wholesale_product_management.base_prefix')); ?> <?php echo number_format($base_box); ?></div>
                        </td>
                        <?php else: ?>
                        <!-- 도매가(낱개) -->
                        <td class="px-3 py-3 whitespace-nowrap text-right text-sm font-mono text-gray-900">
                            <?php echo $wp['wholesale_price_piece'] > 0 ? number_format($wp['wholesale_price_piece']) : '-'; ?>
                        </td>
                        <!-- 도매가(박스) -->
                        <td class="px-3 py-3 whitespace-nowrap text-right text-sm font-mono font-semibold text-gray-900">
                            <?php echo number_format($wp['wholesale_price']); ?>
                        </td>
                        <?php endif; ?>
                        <!-- 메모(특이사항) -->
                        <td class="px-3 py-3 text-sm text-gray-600">
                            <?php if (!empty($wp['memo'])): ?>
                            <div class="max-w-xs truncate" title="<?php echo htmlspecialchars($wp['memo']); ?>"><?php echo htmlspecialchars($wp['memo']); ?></div>
                            <?php else: ?>
                            <span class="text-gray-300">-</span>
                            <?php endif; ?>
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
                            <i class="fas fa-chevron-left mr-2"></i><?php echo htmlspecialchars(t('wholesale_product_management.prev')); ?>
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
                            <?php echo htmlspecialchars(t('wholesale_product_management.next')); ?><i class="fas fa-chevron-right ml-2"></i>
                        </a>
                    <?php endif; ?>
                </nav>
            </div>

            <!-- 하단 그룹 정보 -->
            <div class="text-center mt-3">
                <span class="text-sm text-gray-700">
                    <?php echo htmlspecialchars(str_replace(['{page}', '{total}'], [number_format($page), number_format($total_pages)], t('wholesale_product_management.page_x_of_y'))); ?>
                    <?php echo htmlspecialchars(str_replace('{count}', number_format($total_products), t('wholesale_product_management.total_items_label'))); ?>
                </span>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($by_customer): ?>
<script>
(function(){
  const CUSTOMER_ID = <?php echo (int)$selected_customer_id; ?>;
  document.querySelectorAll('.cp-save').forEach(function(btn){
    btn.addEventListener('click', function(e){
      e.stopPropagation();
      const row = this.closest('tr');
      const boxInp = row.querySelector('.cp-box');
      const pieceInp = row.querySelector('.cp-piece');
      const fd = new FormData();
      fd.append('customer_id', CUSTOMER_ID);
      fd.append('wholesale_product_id', this.dataset.wpid);
      fd.append('wholesale_price', boxInp.value.trim());
      fd.append('wholesale_price_piece', pieceInp.value.trim());
      const orig = this.innerHTML;
      this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
      this.disabled = true;
      const self = this;
      fetch('ajax_save_customer_price.php', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(d){
          self.disabled = false;
          if (d.success) {
            self.innerHTML = '<i class="fas fa-check"></i>';
            const hb = !!d.has_override_box, hp = !!d.has_override_piece;
            boxInp.classList.toggle('border-amber-400', hb); boxInp.classList.toggle('bg-amber-50', hb); boxInp.classList.toggle('border-gray-300', !hb);
            pieceInp.classList.toggle('border-amber-400', hp); pieceInp.classList.toggle('bg-amber-50', hp); pieceInp.classList.toggle('border-gray-300', !hp);
            setTimeout(function(){ self.innerHTML = orig; }, 1200);
          } else {
            self.innerHTML = '<i class="fas fa-xmark"></i>';
            alert(d.message || '<?php echo addslashes(t('wholesale_product_management.js_save_failed')); ?>');
            setTimeout(function(){ self.innerHTML = orig; }, 1200);
          }
        })
        .catch(function(){ self.disabled = false; self.innerHTML = orig; alert('<?php echo addslashes(t('wholesale_product_management.js_comm_error')); ?>'); });
    });
  });
  // Enter 로 저장
  document.querySelectorAll('.cp-box, .cp-piece').forEach(function(inp){
    inp.addEventListener('keydown', function(e){
      if (e.key === 'Enter') { e.preventDefault(); const b = this.closest('tr').querySelector('.cp-save'); if (b) b.click(); }
    });
  });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>