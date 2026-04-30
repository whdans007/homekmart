<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '출고 관리 - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

$is_logistics = is_logistics_department();
if (!$is_logistics && !has_permission('logistics_outbound_management')) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => t('messages.permission_denied')];
    header('Location: index.php');
    exit;
}

$conn = get_db_connection();

// 검색
$search_store = trim($_GET['store'] ?? '');
$search_date  = trim($_GET['date'] ?? '');
$search_status = trim($_GET['status'] ?? '');

$where_conds = [];
$params = [];
$param_types = '';

if ($search_store) {
    $where_conds[] = "st.name LIKE ?";
    $params[] = "%{$search_store}%";
    $param_types .= 's';
}
if ($search_date) {
    $where_conds[] = "lo.outbound_date = ?";
    $params[] = $search_date;
    $param_types .= 's';
}
if ($search_status) {
    $where_conds[] = "lo.status = ?";
    $params[] = $search_status;
    $param_types .= 's';
}

$where = $where_conds ? 'WHERE ' . implode(' AND ', $where_conds) : '';

// 출고 테이블 존재 확인
$tbl_check = $conn->query("SHOW TABLES LIKE 'logistics_outbound'");
$table_exists = $tbl_check && $tbl_check->num_rows > 0;

$total_count = 0;
$result = null;

if ($table_exists) {
    $count_sql = "SELECT COUNT(*) as cnt FROM logistics_outbound lo
                  LEFT JOIN stores st ON lo.dest_store_id = st.id
                  LEFT JOIN products pr ON lo.product_id = pr.id
                  {$where}";
    if ($params) {
        $cs = $conn->prepare($count_sql);
        $cs->bind_param($param_types, ...$params);
        $cs->execute();
        $total_count = $cs->get_result()->fetch_assoc()['cnt'];
    } else {
        $total_count = $conn->query($count_sql)->fetch_assoc()['cnt'];
    }

    $current_page  = max(1, (int)($_GET['page'] ?? 1));
    $items_per_page = 20;
    $offset = ($current_page - 1) * $items_per_page;
    $total_pages = max(1, ceil($total_count / $items_per_page));

    $sql = "SELECT lo.outbound_id, lo.outbound_date, lo.quantity, lo.box_quantity,
                   lo.unit_price, lo.total_amount, lo.status, lo.notes,
                   st.name AS dest_store_name,
                   pr.name_ko AS product_name, pr.sku
            FROM logistics_outbound lo
            LEFT JOIN stores st ON lo.dest_store_id = st.id
            LEFT JOIN products pr ON lo.product_id = pr.id
            {$where}
            ORDER BY lo.outbound_date DESC, lo.outbound_id DESC
            LIMIT {$items_per_page} OFFSET {$offset}";

    if ($params) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($param_types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($sql);
    }
} else {
    $current_page = 1;
    $total_pages  = 1;
}

// 지점 목록 (필터용)
$stores_res = $conn->query("SELECT id, name FROM stores WHERE name != 'WHEREHOUSE (물류센터)' ORDER BY name ASC");
$stores = $stores_res ? $stores_res->fetch_all(MYSQLI_ASSOC) : [];

$status_labels = [
    'pending'   => ['label' => '대기', 'color' => 'yellow'],
    'approved'  => ['label' => '승인', 'color' => 'blue'],
    'delivered' => ['label' => '출고완료', 'color' => 'green'],
    'cancelled' => ['label' => '취소', 'color' => 'red'],
];
?>

<div class="w-full px-4 py-6">

    <!-- 페이지 헤더 -->
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
                <i class="fas fa-dolly text-teal-600"></i> 출고 관리
            </h1>
            <p class="text-sm text-gray-500 mt-1">물류센터에서 각 지점으로의 출고 현황을 관리합니다.</p>
        </div>
        <a href="add_logistics_outbound.php"
           class="inline-flex items-center px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium rounded-md shadow-sm transition-colors">
            <i class="fas fa-plus mr-2"></i> 출고 등록
        </a>
    </div>

    <?php if (!$table_exists): ?>
    <div class="mb-6 bg-yellow-50 border border-yellow-200 rounded-lg p-4 flex items-start gap-3">
        <i class="fas fa-exclamation-triangle text-yellow-500 mt-0.5"></i>
        <div>
            <p class="font-medium text-yellow-800">출고 관리 테이블이 없습니다.</p>
            <p class="text-sm text-yellow-700 mt-1">
                <code>admin/sql/add_logistics_department.sql</code> 파일을 DB에서 실행해 주세요.
            </p>
        </div>
    </div>
    <?php endif; ?>

    <!-- 검색 -->
    <form method="GET" class="mb-4 flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">출고 지점</label>
            <select name="store" class="border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-teal-500 focus:border-teal-500">
                <option value="">전체</option>
                <?php foreach ($stores as $s): ?>
                <option value="<?php echo htmlspecialchars($s['name']); ?>"
                        <?php echo $search_store === $s['name'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($s['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">출고일</label>
            <input type="date" name="date" value="<?php echo htmlspecialchars($search_date); ?>"
                   class="border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-teal-500 focus:border-teal-500">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">상태</label>
            <select name="status" class="border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-teal-500 focus:border-teal-500">
                <option value="">전체</option>
                <?php foreach ($status_labels as $val => $info): ?>
                <option value="<?php echo $val; ?>" <?php echo $search_status === $val ? 'selected' : ''; ?>>
                    <?php echo $info['label']; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="px-4 py-2 bg-teal-600 text-white text-sm rounded-md hover:bg-teal-700">
            <i class="fas fa-search mr-1"></i> 검색
        </button>
        <a href="logistics_outbound.php"
           class="px-4 py-2 bg-gray-200 text-gray-700 text-sm rounded-md hover:bg-gray-300">초기화</a>
    </form>

    <!-- 테이블 -->
    <div class="bg-white shadow rounded-lg overflow-hidden ring-1 ring-gray-200">
        <div class="px-6 py-3 bg-teal-50 border-b border-teal-200">
            <span class="text-sm font-semibold text-teal-800">총 <?php echo number_format($total_count); ?>건</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">#</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">출고일</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">출고 지점</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">상품명</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">수량</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">금액</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">상태</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">액션</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if ($result && $result->num_rows > 0):
                        $row_num = ($current_page - 1) * $items_per_page + 1;
                        while ($row = $result->fetch_assoc()):
                            $s_info = $status_labels[$row['status']] ?? ['label' => $row['status'], 'color' => 'gray'];
                    ?>
                    <tr class="hover:bg-teal-50 transition-colors">
                        <td class="px-4 py-3 text-center text-gray-400"><?php echo $row_num++; ?></td>
                        <td class="px-4 py-3 text-gray-700"><?php echo htmlspecialchars($row['outbound_date']); ?></td>
                        <td class="px-4 py-3 font-medium text-gray-900"><?php echo htmlspecialchars($row['dest_store_name'] ?? '-'); ?></td>
                        <td class="px-4 py-3 text-gray-700">
                            <?php echo htmlspecialchars($row['product_name']); ?>
                            <span class="text-xs text-gray-400 ml-1"><?php echo htmlspecialchars($row['sku']); ?></span>
                        </td>
                        <td class="px-4 py-3 text-right text-gray-700">
                            <?php echo number_format($row['quantity']); ?>개
                            <?php if ($row['box_quantity']): ?>
                            <span class="text-xs text-gray-400">(<?php echo $row['box_quantity']; ?>박스)</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-right font-semibold text-gray-900"><?php echo number_format($row['total_amount'], 2); ?></td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-1 text-xs font-medium rounded-full
                                bg-<?php echo $s_info['color']; ?>-100 text-<?php echo $s_info['color']; ?>-800">
                                <?php echo $s_info['label']; ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <a href="edit_logistics_outbound.php?id=<?php echo $row['outbound_id']; ?>"
                               class="inline-flex items-center px-3 py-1 text-xs font-medium text-teal-700 bg-teal-50 border border-teal-200 rounded-md hover:bg-teal-100">
                                <i class="fas fa-edit mr-1"></i> 수정
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr>
                        <td colspan="8" class="px-6 py-12 text-center text-gray-400">
                            <i class="fas fa-dolly text-4xl mb-3 block"></i>
                            출고 내역이 없습니다.
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
            <a href="?page=<?php echo $i; ?>&store=<?php echo urlencode($search_store); ?>&date=<?php echo urlencode($search_date); ?>&status=<?php echo urlencode($search_status); ?>"
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
