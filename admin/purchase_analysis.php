<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '매입분석 - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 매입관리 권한 확인
if (!has_permission('purchase_management')) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

$conn = get_db_connection();

// 페이지네이션 변수
$current_page = max(1, (int)($_GET['page'] ?? 1));
$items_per_page = 30;
$offset = ($current_page - 1) * $items_per_page;

// 검색 조건
$search_sku = trim($_GET['search_sku'] ?? '');
$search_product = trim($_GET['search_product'] ?? '');

// WHERE 조건 구성
$where_conditions = ["p.deleted_at IS NULL"];
$params = [];
$param_types = '';

if (!empty($search_sku)) {
    $where_conditions[] = "pr.sku LIKE ?";
    $params[] = '%' . $search_sku . '%';
    $param_types .= 's';
}

if (!empty($search_product)) {
    $where_conditions[] = "pr.name_ko LIKE ?";
    $params[] = '%' . $search_product . '%';
    $param_types .= 's';
}

$where_clause = implode(' AND ', $where_conditions);

// 각 상품-거래처별 최신 매입정보 조회 (더 간단한 방식)
$query = "
    SELECT
        pr.sku AS sku,
        pr.name_ko AS product_name,
        s.name AS supplier_name,
        pi.unit_price,
        p.purchase_date
    FROM purchase_items pi
    JOIN purchases p ON pi.purchase_id = p.purchase_id
    JOIN products pr ON pi.product_id = pr.id
    JOIN suppliers s ON p.supplier_id = s.id
    WHERE p.deleted_at IS NULL
";

if (count($where_conditions) > 1) {
    // 검색 조건이 있는 경우 필터링
    $additional_conditions = str_replace('p.deleted_at IS NULL', '', $where_clause);
    $additional_conditions = trim($additional_conditions, ' AND ');
    if (!empty($additional_conditions)) {
        $query .= " AND " . $additional_conditions;
    }
}

$query .= " ORDER BY p.purchase_date DESC, pr.sku";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// 데이터를 상품-거래처별로 그룹화하여 최신 날짜만 유지
$temp_data = [];
while ($row = $result->fetch_assoc()) {
    $product_key = $row['sku'];
    $supplier_key = $row['supplier_name'];

    if (!isset($temp_data[$product_key])) {
        $temp_data[$product_key] = [];
    }

    if (!isset($temp_data[$product_key][$supplier_key]) ||
        $row['purchase_date'] > $temp_data[$product_key][$supplier_key]['purchase_date']) {
        $temp_data[$product_key][$supplier_key] = [
            'sku' => $row['sku'],
            'product_name' => $row['product_name'],
            'supplier_name' => $row['supplier_name'],
            'unit_price' => $row['unit_price'],
            'purchase_date' => $row['purchase_date']
        ];
    }
}

// 최종 데이터 구조로 변환
$products_data = [];
foreach ($temp_data as $product_key => $suppliers) {
    $products_data[$product_key] = [
        'sku' => '',
        'product_name' => '',
        'suppliers' => [],
        'dates' => [],
        'prices' => []
    ];

    foreach ($suppliers as $supplier_data) {
        $products_data[$product_key]['sku'] = $supplier_data['sku'];
        $products_data[$product_key]['product_name'] = $supplier_data['product_name'];
        $products_data[$product_key]['suppliers'][] = $supplier_data['supplier_name'];
        $products_data[$product_key]['dates'][] = $supplier_data['purchase_date'];
        $products_data[$product_key]['prices'][] = number_format($supplier_data['unit_price'], 2);
    }
}

// 각 상품의 최신 매입일자를 기준으로 정렬
uasort($products_data, function($a, $b) {
    // 각 상품의 가장 최근 매입일자 찾기
    $latest_date_a = max($a['dates']);
    $latest_date_b = max($b['dates']);

    // 최신 날짜부터 정렬 (내림차순)
    return strtotime($latest_date_b) - strtotime($latest_date_a);
});

// 페이지네이션을 위한 총 상품 수
$total_products = count($products_data);
$total_pages = ceil($total_products / $items_per_page);

// 현재 페이지에 해당하는 데이터만 추출
$products_data = array_slice($products_data, $offset, $items_per_page, true);

$conn->close();
?>

<div class="min-h-screen bg-gray-50 py-6">
    <div class="w-full px-4 sm:px-6 lg:px-8">
        <!-- 페이지 헤더 -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">매입분석</h1>
            <p class="mt-2 text-sm text-gray-600">상품별 거래처 단가 비교 분석</p>
        </div>

        <!-- 검색 폼 -->
        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4">
                <form method="GET" class="flex flex-wrap items-center gap-6">
                    <!-- SKU 입력 -->
                    <div class="flex items-center gap-3">
                        <label for="search_sku" class="text-base font-medium text-gray-700 whitespace-nowrap">SKU:</label>
                        <input type="text" name="search_sku" id="search_sku"
                               value="<?php echo htmlspecialchars($search_sku); ?>"
                               placeholder="SKU 입력"
                               class="w-36 h-12 px-4 text-base border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors duration-200">
                    </div>

                    <!-- 상품명 입력 -->
                    <div class="flex items-center gap-3">
                        <label for="search_product" class="text-base font-medium text-gray-700 whitespace-nowrap">상품명:</label>
                        <input type="text" name="search_product" id="search_product"
                               value="<?php echo htmlspecialchars($search_product); ?>"
                               placeholder="상품명 입력"
                               class="w-48 h-12 px-4 text-base border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors duration-200">
                    </div>

                    <!-- 버튼 그룹 -->
                    <div class="flex items-center gap-3 ml-auto">
                        <button type="submit" class="h-12 bg-blue-600 text-white px-6 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors duration-200 flex items-center font-medium text-base">
                            <i class="fas fa-search mr-2"></i>검색
                        </button>
                        <a href="purchase_analysis.php" class="h-12 bg-gray-500 text-white px-6 rounded-md hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-400 focus:ring-offset-2 transition-colors duration-200 flex items-center font-medium text-base no-underline">
                            <i class="fas fa-undo mr-2"></i>초기화
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- 결과 카운트 -->
        <div class="mb-4">
            <p class="text-sm text-gray-700">
                총 <span class="font-semibold"><?php echo $total_products; ?></span>개 상품
                <?php if (!empty($search_sku) || !empty($search_product)): ?>
                    <span class="text-blue-600">(검색 결과)</span>
                <?php endif; ?>
            </p>
        </div>

        <!-- 데이터 테이블 -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">SKU</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">상품명</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">입고내역</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($products_data)): ?>
                        <tr>
                            <td colspan="3" class="px-6 py-8 text-center text-gray-500">
                                <i class="fas fa-search text-4xl mb-2"></i>
                                <p>검색 결과가 없습니다.</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($products_data as $product): ?>
                        <tr class="border-b border-gray-200">
                            <!-- SKU -->
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 align-top border-r border-gray-200">
                                <?php echo htmlspecialchars($product['sku']); ?>
                            </td>

                            <!-- 상품명 -->
                            <td class="px-6 py-4 text-sm text-gray-900 align-top border-r border-gray-200">
                                <?php echo htmlspecialchars($product['product_name']); ?>
                            </td>

                            <!-- 입고내역 - 업체별 카드 형식 -->
                            <td class="px-6 py-4">
                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                    <?php for ($i = 0; $i < count($product['suppliers']); $i++): ?>
                                    <div class="bg-gray-50 rounded-lg border border-gray-200 p-3 hover:bg-gray-100 transition-colors duration-150">
                                        <!-- 거래처명 -->
                                        <div class="flex items-center mb-2">
                                            <i class="fas fa-building text-blue-500 mr-2 text-xs"></i>
                                            <span class="font-semibold text-gray-900 text-sm truncate" title="<?php echo htmlspecialchars($product['suppliers'][$i]); ?>">
                                                <?php echo htmlspecialchars($product['suppliers'][$i]); ?>
                                            </span>
                                        </div>

                                        <!-- 매입일자 -->
                                        <div class="flex items-center mb-2">
                                            <i class="fas fa-calendar-alt text-green-500 mr-2 text-xs"></i>
                                            <span class="text-xs text-gray-600">
                                                <?php echo htmlspecialchars($product['dates'][$i]); ?>
                                            </span>
                                        </div>

                                        <!-- 단가 -->
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs text-gray-500">단가:</span>
                                            <span class="font-bold text-blue-600 text-sm">
                                                ₩<?php echo $product['prices'][$i]; ?>
                                            </span>
                                        </div>
                                    </div>
                                    <?php endfor; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 페이지네이션 -->
        <?php if ($total_pages > 1): ?>
        <div class="mt-6 flex items-center justify-between">
            <div class="flex-1 flex justify-between sm:hidden">
                <?php if ($current_page > 1): ?>
                    <a href="?page=<?php echo $current_page - 1; ?><?php echo !empty($search_sku) ? '&search_sku=' . urlencode($search_sku) : ''; ?><?php echo !empty($search_product) ? '&search_product=' . urlencode($search_product) : ''; ?>"
                       class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        이전
                    </a>
                <?php endif; ?>
                <?php if ($current_page < $total_pages): ?>
                    <a href="?page=<?php echo $current_page + 1; ?><?php echo !empty($search_sku) ? '&search_sku=' . urlencode($search_sku) : ''; ?><?php echo !empty($search_product) ? '&search_product=' . urlencode($search_product) : ''; ?>"
                       class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        다음
                    </a>
                <?php endif; ?>
            </div>

            <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm text-gray-700">
                        전체 <span class="font-medium"><?php echo $total_products; ?></span>개 중
                        <span class="font-medium"><?php echo $offset + 1; ?></span>-<span class="font-medium"><?php echo min($offset + $items_per_page, $total_products); ?></span> 표시
                    </p>
                </div>
                <div>
                    <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                        <?php if ($current_page > 1): ?>
                            <a href="?page=<?php echo $current_page - 1; ?><?php echo !empty($search_sku) ? '&search_sku=' . urlencode($search_sku) : ''; ?><?php echo !empty($search_product) ? '&search_product=' . urlencode($search_product) : ''; ?>"
                               class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php endif; ?>

                        <?php
                        $start = max(1, $current_page - 2);
                        $end = min($total_pages, $current_page + 2);

                        for ($i = $start; $i <= $end; $i++):
                        ?>
                            <a href="?page=<?php echo $i; ?><?php echo !empty($search_sku) ? '&search_sku=' . urlencode($search_sku) : ''; ?><?php echo !empty($search_product) ? '&search_product=' . urlencode($search_product) : ''; ?>"
                               class="<?php echo $i == $current_page ? 'bg-indigo-50 border-indigo-500 text-indigo-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50'; ?> relative inline-flex items-center px-4 py-2 border text-sm font-medium">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($current_page < $total_pages): ?>
                            <a href="?page=<?php echo $current_page + 1; ?><?php echo !empty($search_sku) ? '&search_sku=' . urlencode($search_sku) : ''; ?><?php echo !empty($search_product) ? '&search_product=' . urlencode($search_product) : ''; ?>"
                               class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </nav>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>