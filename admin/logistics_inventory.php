<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '재고 현황 - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

$is_logistics = is_logistics_department();
if (!$is_logistics && !has_permission('logistics_inventory_management')) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => t('messages.permission_denied')];
    header('Location: index.php');
    exit;
}

$conn = get_db_connection();

// 물류센터 store_id 조회
$logi_res  = $conn->query("SELECT id FROM stores WHERE name = 'WHEREHOUSE (물류센터)' LIMIT 1");
$logi_row  = $logi_res ? $logi_res->fetch_assoc() : null;
$logi_store_id = $logi_row['id'] ?? null;

// 출고 테이블 존재 여부
$tbl_check = $conn->query("SHOW TABLES LIKE 'logistics_outbound'");
$has_outbound_table = $tbl_check && $tbl_check->num_rows > 0;

$search_name = trim($_GET['name'] ?? '');
$search_sku  = trim($_GET['sku'] ?? '');

$inventory = [];

if ($logi_store_id) {
    // 매입 수량 (물류센터 점포)
    // 출고 수량 (logistics_outbound 테이블, cancelled 제외)
    $outbound_sub = $has_outbound_table
        ? "COALESCE((SELECT SUM(lo.quantity) FROM logistics_outbound lo WHERE lo.product_id = p.id AND lo.status != 'cancelled'), 0)"
        : "0";

    $name_cond = '';
    $sku_cond  = '';
    $params = [];
    $param_types = '';

    if ($search_name) {
        $name_cond = " AND p.name_ko LIKE ?";
        $params[] = "%{$search_name}%";
        $param_types .= 's';
    }
    if ($search_sku) {
        $sku_cond = " AND p.sku LIKE ?";
        $params[] = "%{$search_sku}%";
        $param_types .= 's';
    }

    $sql = "SELECT
              p.id AS product_id,
              p.name_ko,
              p.sku,
              COALESCE(SUM(
                CASE
                  WHEN pi.purchase_type = 'box' THEN pi.quantity * COALESCE(p.pieces_per_box, 1)
                  ELSE pi.quantity
                END
              ), 0) AS total_purchased,
              {$outbound_sub} AS total_outbound,
              COALESCE(SUM(
                CASE
                  WHEN pi.purchase_type = 'box' THEN pi.quantity * COALESCE(p.pieces_per_box, 1)
                  ELSE pi.quantity
                END
              ), 0) - {$outbound_sub} AS current_stock
            FROM products p
            LEFT JOIN purchase_items pi ON pi.product_id = p.id
            LEFT JOIN purchases pur ON pi.purchase_id = pur.purchase_id
              AND pur.store_id = {$logi_store_id}
            WHERE (pur.purchase_id IS NOT NULL AND pur.store_id = {$logi_store_id}) OR pur.purchase_id IS NULL
            {$name_cond}{$sku_cond}
            GROUP BY p.id, p.name_ko, p.sku
            HAVING total_purchased > 0
            ORDER BY current_stock ASC, p.name_ko ASC";

    if ($params) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($param_types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $conn->query($sql);
    }

    if ($res) {
        $inventory = $res->fetch_all(MYSQLI_ASSOC);
    }
}
?>

<div class="w-full px-4 py-6">

    <!-- 헤더 -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
            <i class="fas fa-warehouse text-teal-600"></i> 물류센터 재고 현황
        </h1>
        <p class="text-sm text-gray-500 mt-1">물류센터 매입 수량에서 출고 수량을 제외한 현재 재고입니다.</p>
    </div>

    <?php if (!$logi_store_id): ?>
    <div class="mb-6 bg-yellow-50 border border-yellow-200 rounded-lg p-4 flex items-start gap-3">
        <i class="fas fa-exclamation-triangle text-yellow-500 mt-0.5"></i>
        <div>
            <p class="font-medium text-yellow-800">'물류센터' 지점이 등록되어 있지 않습니다.</p>
            <p class="text-sm text-yellow-700 mt-1">
                <code>admin/sql/add_logistics_department.sql</code>을 실행하거나, 지점 관리에서 '물류센터' 지점을 추가해 주세요.
            </p>
        </div>
    </div>
    <?php endif; ?>

    <!-- 검색 -->
    <form method="GET" class="mb-4 flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">상품명</label>
            <input type="text" name="name" value="<?php echo htmlspecialchars($search_name); ?>"
                   class="border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-teal-500 focus:border-teal-500"
                   placeholder="상품명 검색">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">SKU</label>
            <input type="text" name="sku" value="<?php echo htmlspecialchars($search_sku); ?>"
                   class="border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-teal-500 focus:border-teal-500"
                   placeholder="SKU 검색">
        </div>
        <button type="submit" class="px-4 py-2 bg-teal-600 text-white text-sm rounded-md hover:bg-teal-700">
            <i class="fas fa-search mr-1"></i> 검색
        </button>
        <a href="logistics_inventory.php" class="px-4 py-2 bg-gray-200 text-gray-700 text-sm rounded-md hover:bg-gray-300">초기화</a>
    </form>

    <!-- 요약 카드 -->
    <?php if ($inventory): ?>
    <?php
        $total_items   = count($inventory);
        $low_stock     = array_filter($inventory, fn($r) => $r['current_stock'] > 0 && $r['current_stock'] <= 10);
        $zero_stock    = array_filter($inventory, fn($r) => $r['current_stock'] <= 0);
    ?>
    <div class="flex flex-wrap gap-4 mb-6">
        <div class="bg-white rounded-lg border border-gray-200 shadow-sm p-4 flex items-center gap-3" style="min-width:160px">
            <div class="w-10 h-10 bg-teal-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-boxes text-teal-600"></i>
            </div>
            <div>
                <p class="text-xs text-gray-500">전체 품목</p>
                <p class="text-2xl font-bold text-gray-900"><?php echo number_format($total_items); ?></p>
            </div>
        </div>
        <div class="bg-white rounded-lg border border-yellow-200 shadow-sm p-4 flex items-center gap-3" style="min-width:160px">
            <div class="w-10 h-10 bg-yellow-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-exclamation-triangle text-yellow-600"></i>
            </div>
            <div>
                <p class="text-xs text-gray-500">재고 부족 (≤10)</p>
                <p class="text-2xl font-bold text-yellow-600"><?php echo count($low_stock); ?></p>
            </div>
        </div>
        <div class="bg-white rounded-lg border border-red-200 shadow-sm p-4 flex items-center gap-3" style="min-width:160px">
            <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-times-circle text-red-600"></i>
            </div>
            <div>
                <p class="text-xs text-gray-500">재고 없음</p>
                <p class="text-2xl font-bold text-red-600"><?php echo count($zero_stock); ?></p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 재고 테이블 -->
    <div class="bg-white shadow rounded-lg overflow-hidden ring-1 ring-gray-200">
        <div class="px-6 py-3 bg-teal-50 border-b border-teal-200">
            <span class="text-sm font-semibold text-teal-800">총 <?php echo number_format(count($inventory)); ?>개 품목</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">#</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">상품명</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">SKU</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">총 매입</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">총 출고</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">현재고</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">상태</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if ($inventory):
                        $row_num = 1;
                        foreach ($inventory as $row):
                            $stock = (int)$row['current_stock'];
                            $stock_class = $stock <= 0 ? 'text-red-600 font-bold' : ($stock <= 10 ? 'text-yellow-600 font-semibold' : 'text-gray-900');
                    ?>
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="px-4 py-3 text-center text-gray-400"><?php echo $row_num++; ?></td>
                        <td class="px-4 py-3 font-medium text-gray-900"><?php echo htmlspecialchars($row['name_ko']); ?></td>
                        <td class="px-4 py-3 text-gray-500 font-mono text-xs"><?php echo htmlspecialchars($row['sku'] ?? '-'); ?></td>
                        <td class="px-4 py-3 text-right text-gray-700"><?php echo number_format($row['total_purchased']); ?>개</td>
                        <td class="px-4 py-3 text-right text-gray-700"><?php echo number_format($row['total_outbound']); ?>개</td>
                        <td class="px-4 py-3 text-right <?php echo $stock_class; ?>"><?php echo number_format($stock); ?>개</td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($stock <= 0): ?>
                            <span class="px-2 py-1 text-xs font-medium bg-red-100 text-red-800 rounded-full">재고없음</span>
                            <?php elseif ($stock <= 10): ?>
                            <span class="px-2 py-1 text-xs font-medium bg-yellow-100 text-yellow-800 rounded-full">부족</span>
                            <?php else: ?>
                            <span class="px-2 py-1 text-xs font-medium bg-green-100 text-green-800 rounded-full">정상</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr>
                        <td colspan="7" class="px-6 py-12 text-center text-gray-400">
                            <i class="fas fa-warehouse text-4xl mb-3 block"></i>
                            <?php echo $logi_store_id ? '물류센터 재고가 없습니다.' : "'물류센터' 지점을 먼저 설정해주세요."; ?>
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
