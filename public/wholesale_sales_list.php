<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '도매 판매 내역' . ' - ' . t('company.name');
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
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

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
    
    // 검색 조건 (거래처명 또는 판매번호)
    if (!empty($search)) {
        $where_conditions[] = "(wc.name LIKE ? OR ws.id LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }
    
    // 상태 필터
    if (!empty($status_filter)) {
        $where_conditions[] = "ws.status = ?";
        $params[] = $status_filter;
    }
    
    // 날짜 범위 필터
    if (!empty($date_from)) {
        $where_conditions[] = "ws.sale_date >= ?";
        $params[] = $date_from;
    }
    
    if (!empty($date_to)) {
        $where_conditions[] = "ws.sale_date <= ?";
        $params[] = $date_to;
    }
    
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
        ORDER BY ws.created_at DESC, ws.id DESC
        LIMIT " . (int)$per_page . " OFFSET " . (int)$offset . "
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $errors[] = '데이터베이스 오류: ' . $e->getMessage();
    error_log("Wholesale sales list error: " . $e->getMessage());
}

// 플래시 메시지 표시
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="max-w-7xl mx-auto">
        <div class="mb-6">
            <nav class="flex" aria-label="Breadcrumb">
                <ol class="flex items-center space-x-2">
                    <li>
                        <a href="wholesale_customer_management.php" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-handshake mr-1"></i>
                            도매판매
                        </a>
                    </li>
                    <li>
                        <div class="flex items-center">
                            <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                            <span class="text-gray-600">판매 내역</span>
                        </div>
                    </li>
                </ol>
            </nav>
        </div>

        <div class="bg-white shadow-sm rounded-lg border">
            <div class="px-6 py-4 border-b border-gray-200">
                <h1 class="text-xl font-semibold text-gray-900">
                    <i class="fas fa-list mr-2 text-primary-500"></i>
                    도매 판매 내역
                </h1>
                <p class="mt-1 text-sm text-gray-600">도매 판매 거래 내역을 조회하고 관리하세요.</p>
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
                            <h3 class="text-sm font-medium text-red-800">다음 오류를 해결해주세요:</h3>
                            <ul class="mt-2 text-sm text-red-700 list-disc list-inside">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 검색 및 필터 -->
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <form method="GET" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <!-- 검색어 -->
                        <div>
                            <label for="search" class="block text-sm font-medium text-gray-700 mb-1">검색</label>
                            <input type="text" name="search" id="search" 
                                   value="<?php echo htmlspecialchars($search); ?>"
                                   placeholder="거래처명 또는 판매번호"
                                   class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                        </div>
                        
                        <!-- 상태 필터 -->
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">상태</label>
                            <select name="status" id="status" 
                                    class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                                <option value="">전체</option>
                                <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>임시저장</option>
                                <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>확정</option>
                                <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>취소</option>
                            </select>
                        </div>
                        
                        <!-- 시작 날짜 -->
                        <div>
                            <label for="date_from" class="block text-sm font-medium text-gray-700 mb-1">시작일</label>
                            <input type="date" name="date_from" id="date_from" 
                                   value="<?php echo htmlspecialchars($date_from); ?>"
                                   class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                        </div>
                        
                        <!-- 종료 날짜 -->
                        <div>
                            <label for="date_to" class="block text-sm font-medium text-gray-700 mb-1">종료일</label>
                            <input type="date" name="date_to" id="date_to" 
                                   value="<?php echo htmlspecialchars($date_to); ?>"
                                   class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                        </div>
                    </div>
                    
                    <div class="flex justify-between items-center">
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            <i class="fas fa-search mr-2"></i>
                            검색
                        </button>
                        
                        <a href="wholesale_sales.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700">
                            <i class="fas fa-plus mr-2"></i>
                            새 판매 등록
                        </a>
                    </div>
                </form>
            </div>

            <!-- 판매 목록 -->
            <div class="overflow-x-auto">
                <?php if (empty($sales)): ?>
                    <div class="px-6 py-8 text-center">
                        <div class="text-gray-500">
                            <i class="fas fa-inbox text-4xl mb-4"></i>
                            <p class="text-lg">판매 내역이 없습니다.</p>
                            <p class="text-sm mt-2">새로운 도매 판매를 등록해보세요.</p>
                        </div>
                        <div class="mt-4">
                            <a href="wholesale_sales.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700">
                                <i class="fas fa-plus mr-2"></i>
                                새 판매 등록
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">판매번호</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">거래처</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">판매일</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">상품수</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">판매금액</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">상태</th>
                                <?php if ($_SESSION['role'] === 'super_admin'): ?>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">점포</th>
                                <?php endif; ?>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">판매자</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">작업</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($sales as $sale): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        #<?php echo str_pad($sale['id'], 6, '0', STR_PAD_LEFT); ?>
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
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo date('Y-m-d', strtotime($sale['sale_date'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo number_format($sale['item_count']); ?>개
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right font-medium">
                                        <?php echo number_format($sale['final_amount']); ?>원
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center">
                                        <?php
                                        $status_class = '';
                                        $status_text = '';
                                        switch ($sale['status']) {
                                            case 'draft':
                                                $status_class = 'bg-yellow-100 text-yellow-800';
                                                $status_text = '임시저장';
                                                break;
                                            case 'confirmed':
                                                $status_class = 'bg-green-100 text-green-800';
                                                $status_text = '확정';
                                                break;
                                            case 'cancelled':
                                                $status_class = 'bg-red-100 text-red-800';
                                                $status_text = '취소';
                                                break;
                                        }
                                        ?>
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $status_class; ?>">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </td>
                                    <?php if ($_SESSION['role'] === 'super_admin'): ?>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo htmlspecialchars($sale['store_name']); ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo htmlspecialchars($sale['user_name']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                                        <a href="wholesale_sale_preview.php?id=<?php echo $sale['id']; ?>" 
                                           class="text-blue-600 hover:text-blue-900 mr-3" title="미리보기">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if ($sale['status'] !== 'cancelled'): ?>
                                            <button onclick="cancelSale(<?php echo $sale['id']; ?>)" 
                                                    class="text-red-600 hover:text-red-900" title="취소">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php endif; ?>
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
                                    전체 <?php echo number_format($total_sales); ?>개 중 
                                    <?php echo number_format(($page - 1) * $per_page + 1); ?>-<?php echo number_format(min($page * $per_page, $total_sales)); ?>개 표시
                                </div>
                                
                                <div class="flex items-center space-x-2">
                                    <?php if ($page > 1): ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                                           class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                            이전
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
    </div>
</div>

<script>
function cancelSale(saleId) {
    if (confirm('이 판매를 취소하시겠습니까? 취소된 판매는 복구할 수 없습니다.')) {
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
                alert('판매가 취소되었습니다.');
                location.reload();
            } else {
                alert('취소 중 오류가 발생했습니다: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('취소 중 오류가 발생했습니다.');
        });
    }
}
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>