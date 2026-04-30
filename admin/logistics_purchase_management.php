<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '물류 매입 관리 - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 물류센터 소속이거나 logistics_purchase_management 권한 필요
$is_logistics = is_logistics_department();
if (!$is_logistics && !has_permission('logistics_purchase_management')) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => t('messages.permission_denied')];
    header('Location: index.php');
    exit;
}

$conn = get_db_connection();

// 물류센터 store_id 조회
$logi_store_res = $conn->query("SELECT id FROM stores WHERE name = 'WHEREHOUSE (물류센터)' LIMIT 1");
$logi_store_row = $logi_store_res ? $logi_store_res->fetch_assoc() : null;
$logi_store_id  = $logi_store_row['id'] ?? null;

// 페이지네이션
$current_page  = max(1, (int)($_GET['page'] ?? 1));
$items_per_page = 20;
$offset         = ($current_page - 1) * $items_per_page;

// WHERE: 물류센터 점포만
$where = $logi_store_id ? "WHERE p.store_id = {$logi_store_id}" : "WHERE 1=0";

// 검색
$search_supplier = trim($_GET['supplier'] ?? '');
$search_date     = trim($_GET['date'] ?? '');
$extra_conds = [];
$params = [];
$param_types = '';

if ($search_supplier) {
    $extra_conds[] = "s.name LIKE ?";
    $params[] = "%{$search_supplier}%";
    $param_types .= 's';
}
if ($search_date) {
    $extra_conds[] = "p.purchase_date = ?";
    $params[] = $search_date;
    $param_types .= 's';
}

if ($extra_conds) {
    $where .= ' AND ' . implode(' AND ', $extra_conds);
}

// 총 건수
$count_sql = "SELECT COUNT(*) as cnt FROM purchases p JOIN suppliers s ON p.supplier_id = s.id {$where}";
if ($params) {
    $cs = $conn->prepare($count_sql);
    $cs->bind_param($param_types, ...$params);
    $cs->execute();
    $total_count = $cs->get_result()->fetch_assoc()['cnt'];
} else {
    $total_count = $conn->query($count_sql)->fetch_assoc()['cnt'];
}

$total_pages = max(1, ceil($total_count / $items_per_page));

// 목록 조회
$sql = "SELECT p.purchase_id, p.purchase_date, p.created_at, p.total_items, p.total_amount,
               COALESCE(p.is_confirmed, 0) AS is_confirmed,
               s.name AS supplier_name
        FROM purchases p
        JOIN suppliers s ON p.supplier_id = s.id
        {$where}
        ORDER BY p.purchase_date DESC, p.purchase_id DESC
        LIMIT {$items_per_page} OFFSET {$offset}";

if ($params) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}
?>

<div class="w-full px-4 py-6">

    <!-- 페이지 헤더 -->
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-truck-loading text-teal-600"></i> 물류 매입 관리
            </h1>
            <p class="text-sm text-gray-500 mt-1">물류센터에 입고된 매입 내역을 관리합니다.</p>
        </div>
        <a href="add_purchase.php" class="inline-flex items-center px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium rounded-md shadow-sm transition-colors">
            <i class="fas fa-plus mr-2"></i> 신규 매입 등록
        </a>
    </div>

    <!-- 검색 폼 -->
    <form method="GET" class="mb-4 flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">공급처</label>
            <input type="text" name="supplier" value="<?php echo htmlspecialchars($search_supplier); ?>"
                   class="border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-teal-500 focus:border-teal-500"
                   placeholder="공급처명 검색">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">매입일</label>
            <input type="date" name="date" value="<?php echo htmlspecialchars($search_date); ?>"
                   class="border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-teal-500 focus:border-teal-500">
        </div>
        <button type="submit" class="px-4 py-2 bg-teal-600 text-white text-sm rounded-md hover:bg-teal-700">
            <i class="fas fa-search mr-1"></i> 검색
        </button>
        <a href="logistics_purchase_management.php" class="px-4 py-2 bg-gray-200 text-gray-700 text-sm rounded-md hover:bg-gray-300">초기화</a>
    </form>

    <!-- 테이블 -->
    <div class="bg-white shadow rounded-lg overflow-hidden ring-1 ring-gray-200">
        <div class="px-6 py-3 bg-teal-50 border-b border-teal-200 flex items-center justify-between">
            <span class="text-sm font-semibold text-teal-800">총 <?php echo number_format($total_count); ?>건</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">#</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">매입일</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">공급처</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">품목 수</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">매입금액</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">확정상태</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">액션</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if ($result && $result->num_rows > 0):
                        $row_num = ($current_page - 1) * $items_per_page + 1;
                        while ($row = $result->fetch_assoc()): ?>
                    <tr class="hover:bg-teal-50 transition-colors cursor-pointer"
                        onclick="window.location='edit_purchase.php?id=<?php echo $row['purchase_id']; ?>'">
                        <td class="px-4 py-3 text-center text-gray-500"><?php echo $row_num++; ?></td>
                        <td class="px-4 py-3 text-gray-700"><?php echo htmlspecialchars($row['purchase_date']); ?></td>
                        <td class="px-4 py-3 font-medium text-gray-900"><?php echo htmlspecialchars($row['supplier_name']); ?></td>
                        <td class="px-4 py-3 text-right text-gray-700"><?php echo number_format($row['total_items']); ?>종</td>
                        <td class="px-4 py-3 text-right font-semibold text-gray-900"><?php echo number_format($row['total_amount'], 2); ?></td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($row['is_confirmed']): ?>
                            <span class="px-2 py-1 text-xs font-medium bg-green-100 text-green-800 rounded-full">
                                <i class="fas fa-check mr-1"></i>확정
                            </span>
                            <?php else: ?>
                            <span class="px-2 py-1 text-xs font-medium bg-yellow-100 text-yellow-800 rounded-full">
                                <i class="fas fa-clock mr-1"></i>미확정
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center" onclick="event.stopPropagation()">
                            <a href="edit_purchase.php?id=<?php echo $row['purchase_id']; ?>"
                               class="inline-flex items-center px-3 py-1 text-xs font-medium text-teal-700 bg-teal-50 border border-teal-200 rounded-md hover:bg-teal-100">
                                <i class="fas fa-eye mr-1"></i> 상세
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr>
                        <td colspan="7" class="px-6 py-12 text-center text-gray-400">
                            <i class="fas fa-dolly-flatbed text-4xl mb-3 block"></i>
                            물류센터 매입 내역이 없습니다.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 페이지네이션 -->
        <?php if ($total_pages > 1): ?>
        <div class="px-6 py-3 border-t border-gray-200 flex justify-center gap-1">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <a href="?page=<?php echo $i; ?>&supplier=<?php echo urlencode($search_supplier); ?>&date=<?php echo urlencode($search_date); ?>"
               class="px-3 py-1 text-sm rounded-md <?php echo $i == $current_page ? 'bg-teal-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                <?php echo $i; ?>
            </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
$conn->close();
require_once __DIR__ . '/partials/footer.php';
?>
