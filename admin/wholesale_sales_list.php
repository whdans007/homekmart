<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('wholesale_sales_list.title') . ' - ' . t('company.name');
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

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;

$sales = [];
$total_sales = 0;
$total_pages = 1;
$errors = [];

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // WHERE 조건 구성
    $where_conditions = ["1=1"];
    $params = [];
    
    // 취소된 판매 제외
    $where_conditions[] = "ws.status != 'cancelled'";
    
    // 점포 필터 (super_admin이 아닌 경우)
    if ($_SESSION['role'] !== 'super_admin') {
        $where_conditions[] = "ws.store_id = ?";
        $params[] = $current_store_id;
    }
    
    $where_clause = implode(" AND ", $where_conditions);
    
    // 총 개수 조회
    $count_sql = "
        SELECT COUNT(*) as total
        FROM wholesale_sales ws
        LEFT JOIN wholesale_customers wc ON ws.customer_id = wc.id
        LEFT JOIN stores s ON ws.store_id = s.id
        WHERE {$where_clause}
    ";
    
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_sales = $count_stmt->fetchColumn();
    $total_pages = max(1, ceil($total_sales / $per_page));
    
    // 현재 페이지가 전체 페이지를 벗어나면 조정
    if ($page > $total_pages) {
        $page = $total_pages;
    }
    
    $offset = max(0, ($page - 1) * $per_page);
    
    // 판매 목록 조회
    $sql = "
        SELECT 
            ws.id,
            ws.sale_date,
            ws.total_amount,
            ws.final_amount,
            ws.status,
            ws.created_at,
            wc.name as customer_name,
            wc.phone as customer_phone,
            s.name as store_name,
            u.full_name as user_name,
            COUNT(wsi.id) as item_count
        FROM wholesale_sales ws
        LEFT JOIN wholesale_customers wc ON ws.customer_id = wc.id
        LEFT JOIN stores s ON ws.store_id = s.id
        LEFT JOIN users u ON ws.user_id = u.id
        LEFT JOIN wholesale_sale_items wsi ON ws.id = wsi.sale_id
        WHERE {$where_clause}
        GROUP BY ws.id
        ORDER BY ws.id DESC
        LIMIT " . (int)$per_page . " OFFSET " . (int)$offset . "
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $errors[] = t('wholesale_sales_list.database_error') . $e->getMessage();
    error_log("Wholesale sales list error: " . $e->getMessage());
}

// 플래시 메시지 표시
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
?>

<div class="container-fluid px-4 py-6">
    <div class="max-w-full mx-auto">
        <div class="mb-6">
            <nav class="flex" aria-label="Breadcrumb">
                <ol class="flex items-center space-x-2">
                    <li>
                        <a href="wholesale_customer_management.php" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-handshake mr-1"></i>
                            <?php echo htmlspecialchars(t('wholesale_sales_list.breadcrumb_wholesale')); ?>
                        </a>
                    </li>
                    <li>
                        <div class="flex items-center">
                            <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                            <span class="text-gray-600"><?php echo htmlspecialchars(t('wholesale_sales_list.breadcrumb_sales_history')); ?></span>
                        </div>
                    </li>
                </ol>
            </nav>
        </div>

        <div class="bg-white shadow-sm rounded-lg border">
            <div class="px-6 py-4 border-b border-gray-200">
                <h1 class="text-xl font-semibold text-gray-900">
                    <i class="fas fa-list mr-2 text-primary-500"></i>
                    <?php echo htmlspecialchars(t('wholesale_sales_list.title')); ?>
                </h1>
                <p class="mt-1 text-sm text-gray-600"><?php echo htmlspecialchars(t('wholesale_sales_list.page_description')); ?></p>
            </div>

            <?php if (isset($flash)): ?>
                <div class="mx-6 mt-4 p-4 rounded-md <?php echo $flash['type'] === 'error' ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200'; ?>">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas <?php echo $flash['type'] === 'error' ? 'fa-exclamation-triangle text-red-400' : 'fa-check-circle text-green-400'; ?>"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm <?php echo $flash['type'] === 'error' ? 'text-red-700' : 'text-green-700'; ?>">
                                <?php echo htmlspecialchars($flash['message']); ?>
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="mx-6 mt-4 p-4 bg-red-50 border border-red-200 rounded-md">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-triangle text-red-400"></i>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-red-800"><?php echo htmlspecialchars(t('wholesale_sales_list.solve_errors')); ?></h3>
                            <ul class="mt-2 text-sm text-red-700 list-disc list-inside">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 액션 버튼 -->
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <div class="flex justify-end">
                    <a href="wholesale_sales.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700">
                        <i class="fas fa-plus mr-2"></i>
                        <?php echo htmlspecialchars(t('wholesale_sales_list.new_sale_button')); ?>
                    </a>
                </div>
            </div>

            <!-- 판매 목록 -->
            <div class="overflow-x-auto">
                <?php if (empty($sales)): ?>
                    <div class="px-6 py-8 text-center">
                        <div class="text-gray-500">
                            <i class="fas fa-inbox text-4xl mb-4"></i>
                            <p class="text-lg"><?php echo htmlspecialchars(t('wholesale_sales_list.no_sales_message')); ?></p>
                            <p class="text-sm mt-2"><?php echo htmlspecialchars(t('wholesale_sales_list.no_sales_description')); ?></p>
                        </div>
                        <div class="mt-4">
                            <a href="wholesale_sales.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700">
                                <i class="fas fa-plus mr-2"></i>
                                <?php echo htmlspecialchars(t('wholesale_sales_list.new_sale_button')); ?>
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <table class="min-w-full divide-y divide-gray-200 table-fixed">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider" style="width: 10%;">일자</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider" style="width: 45%;">고객명</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider" style="width: 10%;">수량</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider" style="width: 20%;">금액</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider" style="width: 15%;">판매자</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($sales as $sale): ?>
                                <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location.href='wholesale_sale_preview.php?id=<?php echo $sale['id']; ?>'">
                                    <td class="px-4 py-4 text-center text-sm text-gray-900 font-medium">
                                        <?php echo date('Y-m-d', strtotime($sale['sale_date'])); ?>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($sale['customer_name']); ?>
                                        </div>
                                        <?php if (!empty($sale['customer_phone'])): ?>
                                            <div class="text-xs text-gray-500">
                                                <?php echo htmlspecialchars($sale['customer_phone']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-4 text-center text-sm text-gray-900">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                            <?php echo number_format($sale['item_count']); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 text-right text-base text-gray-900 font-bold font-mono">
                                        <?php echo number_format($sale['final_amount']); ?>
                                    </td>
                                    <td class="px-4 py-4 text-center text-sm text-gray-900">
                                        <div class="truncate"><?php echo htmlspecialchars($sale['user_name']); ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- 페이지네이션 -->
                    <?php if ($total_pages > 1): ?>
                        <div class="px-6 py-3 border-t border-gray-200 bg-gray-50">
                            <div class="flex items-center justify-between">
                                <div class="text-sm text-gray-500">
                                    <?php echo str_replace(
                                        ['{total}', '{start}', '{end}'],
                                        [number_format($total_sales), number_format(($page - 1) * $per_page + 1), number_format(min($page * $per_page, $total_sales))],
                                        t('wholesale_sales_list.pagination_showing')
                                    ); ?>
                                </div>
                                
                                <div class="flex items-center space-x-2">
                                    <?php if ($page > 1): ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                                           class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                            <?php echo htmlspecialchars(t('wholesale_sales_list.pagination_previous')); ?>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                        <?php if ($i == $page): ?>
                                            <span class="px-3 py-2 text-sm font-medium text-white bg-primary-600 border border-primary-600 rounded-md">
                                                <?php echo $i; ?>
                                            </span>
                                        <?php else: ?>
                                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                               class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                                <?php echo $i; ?>
                                            </a>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $total_pages): ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                                           class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                            <?php echo htmlspecialchars(t('wholesale_sales_list.pagination_next')); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function cancelSale(saleId) {
    if (confirm('<?php echo addslashes(t('wholesale_sales_list.js_cancel_confirm')); ?>')) {
        fetch('ajax_cancel_wholesale_sale.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'sale_id=' + encodeURIComponent(saleId)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('<?php echo addslashes(t('wholesale_sales_list.js_cancel_success')); ?>');
                location.reload();
            } else {
                alert('<?php echo addslashes(t('wholesale_sales_list.js_cancel_error')); ?>' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('<?php echo addslashes(t('wholesale_sales_list.js_network_error')); ?>');
        });
    }
}
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>