<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('price_change.history') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 매입관리 권한 확인 (가격변경 이력도 매입관리 권한으로 제한)
if (!has_permission('purchase_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('price_change.no_permission')
    ];
    header('Location: shop.php');
    exit;
}

$pdo = null;
$price_changes = [];
$error_message = '';

// 검색 및 페이징 변수
$search_term = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$change_type = $_GET['change_type'] ?? '';
$page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
$limit = in_array($per_page, [10, 20, 50, 100]) ? (int)$per_page : 20;
$offset = max(0, ($page - 1) * $limit);

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 가격변경 이력 테이블이 존재하는지 확인
    $table_check = $pdo->prepare("SHOW TABLES LIKE 'price_change_history'");
    $table_check->execute();
    
    if (!$table_check->fetch()) {
        $error_message = t('price_change.table_not_exists');
    } else {
        // 검색 조건 구성
        $where_conditions = [];
        $params = [];
        
        if (!empty($search_term)) {
            $where_conditions[] = "(p.name_ko LIKE ? OR p.sku LIKE ? OR u.username LIKE ?)";
            $params[] = "%$search_term%";
            $params[] = "%$search_term%";
            $params[] = "%$search_term%";
        }
        
        if (!empty($date_from)) {
            $where_conditions[] = "pch.changed_at >= ?";
            $params[] = $date_from . ' 00:00:00';
        }
        
        if (!empty($date_to)) {
            $where_conditions[] = "pch.changed_at <= ?";
            $params[] = $date_to . ' 23:59:59';
        }
        
        if (!empty($change_type)) {
            $where_conditions[] = "pch.change_type = ?";
            $params[] = $change_type;
        }

        // 점포별 필터링 (super_admin이 아닌 경우)
        if ($_SESSION['role'] !== 'super_admin' && !empty($current_store_id)) {
            $where_conditions[] = "(pch.store_id = ? OR pch.store_id IS NULL)";
            $params[] = $current_store_id;
        }

        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

        // 전체 레코드 수 계산
        $count_sql = "
            SELECT COUNT(*) 
            FROM price_change_history pch
            LEFT JOIN products p ON pch.product_id = p.id
            LEFT JOIN users u ON pch.changed_by_user_id = u.id
            LEFT JOIN stores s ON pch.store_id = s.id
            $where_clause
        ";
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);

        // 가격변경 이력 조회
        $sql = "
            SELECT 
                pch.*,
                p.name_ko as product_name,
                p.sku,
                u.username as changed_by,
                s.name as store_name
            FROM price_change_history pch
            LEFT JOIN products p ON pch.product_id = p.id
            LEFT JOIN users u ON pch.changed_by_user_id = u.id
            LEFT JOIN stores s ON pch.store_id = s.id
            $where_clause
            ORDER BY pch.changed_at DESC
            LIMIT $limit OFFSET $offset
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $price_changes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    $error_message = t('price_change.load_error') . ": " . $e->getMessage();
}
?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900"><?php echo t('price_change.history'); ?></h1>
            <p class="text-sm text-gray-600 mt-1"><?php echo str_replace('{store}', htmlspecialchars($current_store_name), t('price_change.store_info')); ?></p>
            <?php if (!$error_message && isset($total_records)): ?>
                <p class="text-sm text-gray-600"><?php echo str_replace('{count}', number_format($total_records), t('price_change.total_records')); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Flash messages -->
    <?php if (isset($_SESSION['flash'])): ?>
        <div class="mb-6">
            <?php 
            $flash = $_SESSION['flash'];
            $alert_class = $flash['type'] === 'success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800';
            $icon_class = $flash['type'] === 'success' ? 'fa-check-circle text-green-400' : 'fa-exclamation-circle text-red-400';
            ?>
            <div class="<?php echo $alert_class; ?> border rounded-md p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas <?php echo $icon_class; ?>"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm"><?php echo htmlspecialchars($flash['message']); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <!-- 검색 및 필터 -->
    <div class="mb-6 bg-white p-4 rounded-lg shadow">
        <form action="price_change_history.php" method="get" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label for="search" class="block text-sm font-medium text-gray-700 mb-1"><?php echo t('price_change.product_sku_user'); ?></label>
                    <input type="search" name="search" id="search" placeholder="<?php echo t('price_change.search_placeholder'); ?>" 
                           class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm" 
                           value="<?php echo htmlspecialchars($search_term); ?>">
                </div>
                <div>
                    <label for="date_from" class="block text-sm font-medium text-gray-700 mb-1"><?php echo t('price_change.start_date'); ?></label>
                    <input type="date" name="date_from" id="date_from" 
                           class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm" 
                           value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div>
                    <label for="date_to" class="block text-sm font-medium text-gray-700 mb-1"><?php echo t('price_change.end_date'); ?></label>
                    <input type="date" name="date_to" id="date_to" 
                           class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm" 
                           value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div>
                    <label for="change_type" class="block text-sm font-medium text-gray-700 mb-1"><?php echo t('price_change.change_type'); ?></label>
                    <select name="change_type" id="change_type" 
                            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                        <option value=""><?php echo t('price_change.all_types'); ?></option>
                        <option value="both" <?php echo $change_type === 'both' ? 'selected' : ''; ?>><?php echo t('price_change.both_price'); ?></option>
                        <option value="cost_only" <?php echo $change_type === 'cost_only' ? 'selected' : ''; ?>><?php echo t('price_change.cost_only'); ?></option>
                        <option value="selling_only" <?php echo $change_type === 'selling_only' ? 'selected' : ''; ?>><?php echo t('price_change.selling_only'); ?></option>
                        <option value="margin_adjust" <?php echo $change_type === 'margin_adjust' ? 'selected' : ''; ?>><?php echo t('price_change.margin_adjust'); ?></option>
                    </select>
                </div>
            </div>
            <div class="flex items-center space-x-4">
                <div class="flex items-center space-x-2">
                    <label for="per_page" class="text-sm text-gray-700 whitespace-nowrap"><?php echo t('price_change.display_count'); ?>:</label>
                    <select name="per_page" id="per_page" class="rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                        <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>><?php echo t('price_change.items_10'); ?></option>
                        <option value="20" <?php echo $per_page == 20 ? 'selected' : ''; ?>><?php echo t('price_change.items_20'); ?></option>
                        <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>><?php echo t('price_change.items_50'); ?></option>
                        <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>><?php echo t('price_change.items_100'); ?></option>
                    </select>
                </div>
                <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                    <i class="fas fa-search mr-2"></i>
                    <?php echo t('price_change.search'); ?>
                </button>
                <a href="price_change_history.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                    <i class="fas fa-redo mr-2"></i>
                    <?php echo t('price_change.reset'); ?>
                </a>
            </div>
        </form>
    </div>

    <?php if ($error_message): ?>
        <div class="bg-red-50 border border-red-200 rounded-md p-4">
            <p class="text-sm text-red-800"><?php echo htmlspecialchars($error_message); ?></p>
        </div>
    <?php elseif (empty($price_changes)): ?>
        <div class="text-center py-12">
            <i class="fas fa-chart-line text-5xl text-gray-400"></i>
            <h2 class="mt-4 text-lg font-medium text-gray-900"><?php echo t('price_change.no_history'); ?></h2>
            <p class="mt-1 text-sm text-gray-500"><?php echo t('price_change.no_history_desc'); ?></p>
        </div>
    <?php else: ?>
        <div class="bg-white shadow-lg rounded-lg overflow-hidden border border-gray-300">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 border-collapse border border-gray-300">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('price_change.changed_at'); ?></th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('price_change.product_name'); ?></th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('price_change.store'); ?></th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('price_change.old_cost_price'); ?></th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('price_change.new_cost_price'); ?></th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('price_change.old_selling_price'); ?></th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('price_change.new_selling_price'); ?></th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('price_change.change_type_col'); ?></th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('price_change.changed_by'); ?></th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300"><?php echo t('price_change.change_reason'); ?></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($price_changes as $change): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 border border-gray-300">
                                    <?php echo date('Y-m-d H:i', strtotime($change['changed_at'])); ?>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap border border-gray-300">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($change['product_name'] ?? 'N/A'); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($change['sku'] ?? 'N/A'); ?></div>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 border border-gray-300">
                                    <?php echo htmlspecialchars($change['store_name'] ?? t('common.all')); ?>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-right border border-gray-300">
                                    <?php if ($change['old_cost_price']): ?>
                                        <span class="text-gray-900"><?php echo number_format($change['old_cost_price']); ?></span>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-right border border-gray-300">
                                    <?php if ($change['new_cost_price']): ?>
                                        <span class="text-green-600 font-semibold"><?php echo number_format($change['new_cost_price']); ?></span>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-right border border-gray-300">
                                    <?php if ($change['old_selling_price']): ?>
                                        <span class="text-gray-900"><?php echo number_format($change['old_selling_price']); ?></span>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-right border border-gray-300">
                                    <?php if ($change['new_selling_price']): ?>
                                        <span class="text-blue-600 font-semibold"><?php echo number_format($change['new_selling_price']); ?></span>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-center border border-gray-300">
                                    <?php
                                    $type_colors = [
                                        'both' => 'bg-purple-100 text-purple-800',
                                        'cost_only' => 'bg-green-100 text-green-800',
                                        'selling_only' => 'bg-blue-100 text-blue-800',
                                        'margin_adjust' => 'bg-orange-100 text-orange-800'
                                    ];
                                    $type_labels = [
                                        'both' => t('price_change.both_price'),
                                        'cost_only' => t('price_change.cost_only'),
                                        'selling_only' => t('price_change.selling_only'),
                                        'margin_adjust' => t('price_change.margin_adjust')
                                    ];
                                    $color_class = $type_colors[$change['change_type']] ?? 'bg-gray-100 text-gray-800';
                                    $label = $type_labels[$change['change_type']] ?? $change['change_type'];
                                    ?>
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $color_class; ?>">
                                        <?php echo $label; ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 border border-gray-300">
                                    <?php echo htmlspecialchars($change['changed_by'] ?? 'N/A'); ?>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 border border-gray-300">
                                    <?php echo htmlspecialchars($change['change_reason'] ?? 'N/A'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 페이징 -->
        <?php if ($total_pages > 1): ?>
            <nav class="mt-6 flex items-center justify-between border-t border-gray-200 px-4 sm:px-0">
                <div class="-mt-px flex w-0 flex-1">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                           class="inline-flex items-center border-t-2 border-transparent pt-4 pr-1 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700">
                            <i class="fas fa-arrow-left mr-3"></i> <?php echo t('price_change.previous'); ?>
                        </a>
                    <?php endif; ?>
                </div>
                <div class="hidden md:-mt-px md:flex">
                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    
                    for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                           class="<?php echo ($i == $page) ? 'border-primary-500 text-primary-600 bg-primary-50' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> inline-flex items-center border-t-2 px-4 pt-4 text-sm font-medium">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
                <div class="-mt-px flex w-0 flex-1 justify-end">
                    <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                           class="inline-flex items-center border-t-2 border-transparent pt-4 pl-1 text-sm font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700">
                            <?php echo t('price_change.next'); ?> <i class="fas fa-arrow-right ml-3"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>