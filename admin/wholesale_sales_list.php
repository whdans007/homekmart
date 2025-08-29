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

<div class="w-full px-2 sm:px-3 md:px-4 py-8">
    <div class="w-full mx-auto">

        <?php if (isset($flash)): ?>
            <div class="mb-6 p-4 rounded-md <?php echo $flash['type'] === 'error' ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200'; ?>">
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
            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-md">
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

        <!-- 도매판매 목록 테이블 -->
        <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400">
            <div class="px-6 py-4 border-b border-gray-200 bg-white flex justify-between items-center">
                <h3 class="text-lg leading-6 font-semibold text-gray-900">
                    <?php echo htmlspecialchars(t('wholesale_sales_list.title')); ?> 
                    <span class="text-sm font-normal text-gray-500">(총 <?php echo number_format($total_sales); ?>건)</span>
                </h3>
                <a href="wholesale_sales.php" 
                   class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors duration-200">
                    <i class="fas fa-plus mr-2"></i>
                    <?php echo htmlspecialchars(t('wholesale_sales_list.new_sale_button')); ?>
                </a>
            </div>



            
            <?php if (empty($sales)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-shopping-cart text-gray-300 text-6xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2"><?php echo htmlspecialchars(t('wholesale_sales_list.no_sales_message')); ?></h3>
                    <p class="text-gray-500 mb-6"><?php echo htmlspecialchars(t('wholesale_sales_list.no_sales_description')); ?></p>
                    <a href="wholesale_sales.php" 
                       class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700">
                        <i class="fas fa-plus mr-2"></i>
                        <?php echo htmlspecialchars(t('wholesale_sales_list.new_sale_button')); ?>
                    </a>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th scope="col" class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    일자
                                </th>
                                <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    고객명
                                </th>
                                <th scope="col" class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    수량
                                </th>
                                <th scope="col" class="px-6 py-4 text-right text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    금액
                                </th>
                                <th scope="col" class="px-6 py-4 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    판매자
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white">
                            <?php foreach ($sales as $sale): ?>
                                <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150 cursor-pointer" onclick="window.location.href='wholesale_sale_preview.php?id=<?php echo $sale['id']; ?>'">
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900 font-medium">
                                        <?php echo date('Y-m-d', strtotime($sale['sale_date'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($sale['customer_name']); ?>
                                        </div>
                                        <?php if (!empty($sale['customer_phone'])): ?>
                                            <div class="text-xs text-gray-500">
                                                <?php echo htmlspecialchars($sale['customer_phone']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                            <?php echo number_format($sale['item_count']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-gray-900">
                                        <?php echo number_format($sale['final_amount']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                        <?php echo htmlspecialchars($sale['user_name']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            
            <?php if ($total_pages > 1): ?>
            <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
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