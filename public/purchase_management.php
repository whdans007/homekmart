<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$page_title = "상품 매입 관리";
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

if (!is_logged_in() || !in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    echo "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative' role='alert'><strong class='font-bold'>접근 불가:</strong><span class='block sm:inline'> 이 페이지에 접근할 권한이 없습니다.</span></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

$conn = get_db_connection();

// 검색 필터 변수
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$supplier_filter = $_GET['supplier_id'] ?? '';

// 거래처 목록 가져오기 (필터용)
$suppliers_sql = "SELECT id, name FROM suppliers ORDER BY name";
$suppliers_result = $conn->query($suppliers_sql);

// 검색 조건 구성
$where_conditions = [];
$params = [];
$param_types = '';

// deleted_at 컬럼이 존재하는지 확인
$check_deleted_at_column = $conn->query("SHOW COLUMNS FROM purchases LIKE 'deleted_at'");
$has_deleted_at = $check_deleted_at_column->num_rows > 0;

// 삭제되지 않은 매입 내역만 조회 (soft delete 적용)
if ($has_deleted_at) {
    $where_conditions[] = "p.deleted_at IS NULL";
}

if (!empty($start_date)) {
    $where_conditions[] = "p.purchase_date >= ?";
    $params[] = $start_date;
    $param_types .= 's';
}

if (!empty($end_date)) {
    $where_conditions[] = "p.purchase_date <= ?";
    $params[] = $end_date;
    $param_types .= 's';
}

if (!empty($supplier_filter)) {
    $where_conditions[] = "p.supplier_id = ?";
    $params[] = $supplier_filter;
    $param_types .= 'i';
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = ' WHERE ' . implode(' AND ', $where_conditions);
}

// 매입 리스트 조회 (총 입고수량 낱개 환산 포함)
$sql = "SELECT 
    p.purchase_id, 
    p.purchase_date, 
    s.name AS supplier_name, 
    p.total_items, 
    p.total_amount,
    (
        SELECT SUM(
            CASE 
                WHEN pi.purchase_type = 'box' THEN pi.quantity * COALESCE(pr.pieces_per_box, 1)
                ELSE pi.quantity
            END
        ) 
        FROM purchase_items pi 
        JOIN products pr ON pi.product_id = pr.id 
        WHERE pi.purchase_id = p.purchase_id
    ) AS total_pieces
FROM purchases p
JOIN suppliers s ON p.supplier_id = s.id
{$where_clause}
ORDER BY p.purchase_date DESC, p.purchase_id DESC";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

// Check for query errors
if (!$result) {
    die("SQL Error: " . $conn->error);
}
?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-900">상품 매입 리스트</h1>
        <div class="flex space-x-3">
            <?php if (!$has_deleted_at): ?>
            <a href="setup_soft_delete_purchases.php" class="inline-flex items-center justify-center rounded-md border border-yellow-300 bg-yellow-50 px-4 py-2 text-sm font-medium text-yellow-700 shadow-sm hover:bg-yellow-100 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2">
                <i class="fas fa-database mr-2"></i> Soft Delete 설정
            </a>
            <?php endif; ?>
            <a href="add_purchase.php" class="inline-flex items-center justify-center rounded-md border border-transparent bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                <i class="fas fa-plus mr-2"></i> 신규 매입 추가
            </a>
        </div>
    </div>

    <!-- 검색 필터 폼 -->
    <div class="bg-white shadow rounded-lg mb-6 p-6">
        <form method="GET" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">시작 날짜</label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" 
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                </div>
                <div>
                    <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1">종료 날짜</label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" 
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                </div>
                <div>
                    <label for="supplier_id" class="block text-sm font-medium text-gray-700 mb-1">거래처</label>
                    <select id="supplier_id" name="supplier_id" 
                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                        <option value="">전체 거래처</option>
                        <?php if ($suppliers_result && $suppliers_result->num_rows > 0): ?>
                            <?php $suppliers_result->data_seek(0); // 결과 포인터 리셋 ?>
                            <?php while ($supplier = $suppliers_result->fetch_assoc()): ?>
                                <option value="<?php echo $supplier['id']; ?>" <?php echo ($supplier_filter == $supplier['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($supplier['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="flex items-end space-x-2">
                    <button type="submit" class="flex-1 bg-primary-600 text-white px-4 py-2 rounded-md hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                        <i class="fas fa-search mr-2"></i>검색
                    </button>
                    <a href="purchase_management.php" class="flex-1 bg-gray-500 text-white px-4 py-2 rounded-md hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 text-center">
                        <i class="fas fa-redo mr-2"></i>초기화
                    </a>
                </div>
            </div>
        </form>
    </div>

    <div class="bg-white shadow-lg rounded-lg overflow-hidden border border-gray-300">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 border-collapse border border-gray-300">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">순번</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">거래번호</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">날짜</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">거래처</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">총 품목 수</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">총 입고수량</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">매입금액</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">작업</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php $row_number = 1; ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center border border-gray-300"><?php echo $row_number++; ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 border border-gray-300"><?php echo htmlspecialchars($row['purchase_id']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 border border-gray-300"><?php echo htmlspecialchars($row['purchase_date']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 border border-gray-300"><?php echo htmlspecialchars($row['supplier_name']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right border border-gray-300"><?php echo htmlspecialchars($row['total_items']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right border border-gray-300">
                                    <span class="font-medium"><?php echo number_format($row['total_pieces'] ?? 0); ?></span>
                                    <span class="text-xs text-gray-400 ml-1">개</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right border border-gray-300"><?php echo number_format($row['total_amount'], 0); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium border border-gray-300">
                                    <a href="edit_purchase.php?id=<?php echo $row['purchase_id']; ?>" class="text-indigo-600 hover:text-indigo-900">상세보기</a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center text-sm text-gray-500 border border-gray-300">
                                <div class="flex flex-col items-center">
                                    <i class="fas fa-dolly-flatbed text-4xl text-gray-400"></i>
                                    <p class="mt-4">매입 내역이 없습니다.</p>
                                    <p class="text-xs text-gray-400">첫 매입 내역을 추가해보세요.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$conn->close();
require_once __DIR__ . '/partials/footer.php';
?>

