<?php
// Design Ref: role-permission-management - 대시보드는 KIM'S MALL 창고 직원/관리자만 접근
require_once __DIR__ . '/lib/auth.php';
kw_require_staff();

// 대시보드는 물류센터(level 55) 이상만 열람 가능 (branch_manager=50 초과, ceo=60 미만)
if (current_user_level() < 55) {
    kw_set_flash('error', 'Dashboard access is limited to warehouse level and above.');
    header('Location: ' . LC_BASE . '/order_new.php');
    exit;
}

$page_title = "Dashboard - KIM'S MALL WAREHOUSE";
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/inventory_helper.php';

try {
    $conn = get_lc_db();

    // 만료됐거나 D-90 이내 임박 (재고 있는 lot) — 만료일 빠른 순 (가장 긴박한 것부터)
    $expiry_sql = "SELECT p.id AS product_id, CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS name,
                          COALESCE(NULLIF(p.barcode_unit,''), NULLIF(p.barcode_box,''), NULLIF(p.barcode_logistics,'')) AS barcode,
                          i.lot_number, i.expiry_date,
                          SUM(i.quantity_remain) AS stock,
                          DATEDIFF(i.expiry_date, CURDATE()) AS days_left
                   FROM kw_inventory i
                   JOIN kw_products p ON i.product_id = p.id
                   JOIN kw_inbound ib ON i.inbound_id = ib.id
                   WHERE i.expiry_date IS NOT NULL
                     AND i.expiry_date > '1971-01-01'
                     AND i.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
                     AND i.quantity_remain > 0
                   GROUP BY p.id, i.lot_number, i.expiry_date
                   ORDER BY i.expiry_date ASC
                   LIMIT 50";
    $expiry_list = $conn->query($expiry_sql)->fetch_all(MYSQLI_ASSOC);

    // 만료된 lot (재고 있음)
    $expired_count = (int)$conn->query(
        "SELECT COUNT(*) FROM kw_inventory i
         JOIN kw_inbound ib ON i.inbound_id = ib.id
         WHERE i.expiry_date > '1971-01-01' AND i.expiry_date < CURDATE() AND i.quantity_remain > 0"
    )->fetch_row()[0];

    // 미처리 주문 수
    $pending_count = (int)$conn->query(
        "SELECT COUNT(*) FROM kw_orders WHERE status = 'pending'"
    )->fetch_row()[0];

    // 출고 대기 주문 수 (승인됨)
    $approved_count = (int)$conn->query(
        "SELECT COUNT(*) FROM kw_orders WHERE status = 'approved'"
    )->fetch_row()[0];

    // 전체 상품 수 / 재고 있는 상품 수
    $total_products = (int)$conn->query("SELECT COUNT(*) FROM kw_products WHERE is_active = 1")->fetch_row()[0];
    $stocked_products = (int)$conn->query(
        "SELECT COUNT(DISTINCT i.product_id) FROM kw_inventory i
         JOIN kw_inbound ib ON i.inbound_id = ib.id
         WHERE i.quantity_remain > 0"
    )->fetch_row()[0];

    // 재고부족 상품 (min_stock 설정된 상품 중 현재고 <= min_stock)
    $low_stock_list = $conn->query(
        "SELECT CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS name,
                COALESCE(NULLIF(p.barcode_unit,''), NULLIF(p.barcode_box,''), NULLIF(p.barcode_logistics,'')) AS barcode,
                p.unit, p.min_stock,
                COALESCE(SUM(i.quantity_remain), 0) AS total_stock
         FROM kw_products p
         LEFT JOIN kw_inventory i ON i.product_id = p.id AND i.quantity_remain > 0
         WHERE p.is_active = 1 AND p.min_stock > 0
         GROUP BY p.id
         HAVING total_stock <= p.min_stock
         ORDER BY total_stock ASC, p.name_en ASC"
    )->fetch_all(MYSQLI_ASSOC);

    // 점포 담당자: 본인 점포 주문 현황
    $my_orders = [];
    if (kw_is_store_user()) {
        $sid = kw_current_store_id();
        $st = $conn->prepare(
            "SELECT o.id, o.order_date, o.status, o.total_amount,
                    COUNT(i.id) AS item_count
             FROM kw_orders o
             LEFT JOIN kw_order_items i ON o.id = i.order_id
             WHERE o.store_id = ?
             GROUP BY o.id ORDER BY o.created_at DESC LIMIT 5"
        );
        $st->bind_param('i', $sid);
        $st->execute();
        $my_orders = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    }

    $conn->close();
} catch (Exception $e) {
    $db_error = $e->getMessage();
}
?>

<div class="mb-6">
    <h2 class="text-xl font-bold text-gray-900">Dashboard</h2>
    <p class="text-sm text-gray-500 mt-1"><?php echo date('F j, Y'); ?></p>
</div>

<?php if (isset($db_error)): ?>
<div class="bg-red-50 border border-red-200 rounded-lg p-4 text-red-700 text-sm">DB Error: <?php echo htmlspecialchars($db_error); ?></div>
<?php else: ?>

<!-- 요약 카드 -->
<?php
$low_count = count($low_stock_list);
// 재고부족 목록을 "재고 있음"과 "재고 0" 으로 분리 (카드/리스트 공용)
$low_in_stock   = array_values(array_filter($low_stock_list, fn($r) => (float)$r['total_stock'] > 0));
$low_zero       = array_values(array_filter($low_stock_list, fn($r) => (float)$r['total_stock'] <= 0));
$low_in_count   = count($low_in_stock);
$low_zero_count = count($low_zero);
?>
<div style="display:grid;grid-template-columns:repeat(7,1fr);gap:0.5rem;margin-bottom:1.5rem;">
    <div class="bg-white rounded-lg border border-gray-200 px-3 py-2.5">
        <p class="text-xs text-gray-400">Products</p>
        <p class="text-xl font-bold text-gray-800 mt-0.5"><?php echo number_format($total_products); ?></p>
        <p class="text-xs text-teal-600">In stock: <?php echo $stocked_products; ?></p>
    </div>
    <div class="bg-white rounded-lg border border-<?php echo $pending_count > 0 ? 'blue' : 'gray'; ?>-200 px-3 py-2.5">
        <p class="text-xs text-gray-400">Pending Orders</p>
        <p class="text-xl font-bold text-<?php echo $pending_count > 0 ? 'blue' : 'gray'; ?>-700 mt-0.5"><?php echo $pending_count; ?></p>
        <?php if ($pending_count > 0): ?>
        <a href="<?php echo LC_BASE; ?>/orders.php?status=pending" class="text-xs text-blue-500 hover:underline">View →</a>
        <?php else: ?>
        <p class="text-xs text-gray-400">None</p>
        <?php endif; ?>
    </div>
    <div class="bg-white rounded-lg border border-<?php echo $low_in_count > 0 ? 'orange' : 'gray'; ?>-200 px-3 py-2.5">
        <p class="text-xs text-gray-400">Low Stock</p>
        <p class="text-xl font-bold text-<?php echo $low_in_count > 0 ? 'orange' : 'gray'; ?>-600 mt-0.5"><?php echo $low_in_count; ?></p>
        <p class="text-xs text-<?php echo $low_in_count > 0 ? 'orange' : 'gray'; ?>-500"><?php echo $low_in_count > 0 ? 'Reorder needed' : 'OK'; ?></p>
    </div>
    <div class="bg-white rounded-lg border border-<?php echo $low_zero_count > 0 ? 'red' : 'gray'; ?>-200 px-3 py-2.5">
        <p class="text-xs text-gray-400">Out of Stock</p>
        <p class="text-xl font-bold text-<?php echo $low_zero_count > 0 ? 'red' : 'gray'; ?>-600 mt-0.5"><?php echo $low_zero_count; ?></p>
        <p class="text-xs text-<?php echo $low_zero_count > 0 ? 'red' : 'gray'; ?>-500"><?php echo $low_zero_count > 0 ? 'Out of stock' : 'OK'; ?></p>
    </div>
    <div class="bg-white rounded-lg border border-<?php echo count($expiry_list) > 0 ? 'yellow' : 'gray'; ?>-200 px-3 py-2.5">
        <p class="text-xs text-gray-400">D-90 Approaching</p>
        <p class="text-xl font-bold text-<?php echo count($expiry_list) > 0 ? 'yellow' : 'gray'; ?>-700 mt-0.5"><?php echo count($expiry_list); ?></p>
        <p class="text-xs text-gray-400">By lot</p>
    </div>
    <div class="bg-white rounded-lg border border-<?php echo $expired_count > 0 ? 'red' : 'gray'; ?>-200 px-3 py-2.5">
        <p class="text-xs text-gray-400">Expired</p>
        <p class="text-xl font-bold text-<?php echo $expired_count > 0 ? 'red' : 'gray'; ?>-700 mt-0.5"><?php echo $expired_count; ?></p>
        <p class="text-xs text-<?php echo $expired_count > 0 ? 'red' : 'gray'; ?>-500"><?php echo $expired_count > 0 ? 'Immediate action needed' : 'None'; ?></p>
    </div>
    <div class="bg-white rounded-lg border border-<?php echo $approved_count > 0 ? 'purple' : 'gray'; ?>-200 px-3 py-2.5">
        <p class="text-xs text-gray-400">Pending Outbound</p>
        <p class="text-xl font-bold text-<?php echo $approved_count > 0 ? 'purple' : 'gray'; ?>-700 mt-0.5"><?php echo $approved_count; ?></p>
        <?php if ($approved_count > 0): ?>
        <a href="<?php echo LC_BASE; ?>/orders.php?status=approved" class="text-xs text-purple-500 hover:underline">View →</a>
        <?php else: ?>
        <p class="text-xs text-gray-400">None</p>
        <?php endif; ?>
    </div>
</div>

<!-- 재고부족 + 유통기한 임박 — 좌우 배치 -->
<?php if (!empty($low_stock_list) || !empty($expiry_list)): ?>
<style>
/* 대시보드 리스트(재고부족·유통기한) 셀 여백 축소 */
.dash-list th, .dash-list td { padding: 0.3rem 0.6rem; }
/* 스크롤 컨테이너 + 헤더 고정 */
.dash-scroll { overflow-y: auto; }
.dash-scroll thead th { position: sticky; top: 0; z-index: 1; }
.dash-scroll thead.bg-orange-50 th { background:#fff7ed; }
.dash-scroll thead.bg-red-50 th    { background:#fef2f2; }
.dash-scroll thead.bg-gray-50 th   { background:#f9fafb; }
.dash-scroll-low    { max-height: 16rem; }  /* 약 5행 */
.dash-scroll-expiry { max-height: 36rem; }  /* 약 12행 */
</style>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem;">

    <!-- 재고부족 (재고 있음 + 재고 0) -->
    <div style="display:flex;flex-direction:column;gap:1rem;">

        <!-- 재고부족: 재고 있는 상품만 -->
        <div class="bg-white rounded-lg border border-orange-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-orange-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-orange-800">
                    <i class="fas fa-exclamation-circle mr-2"></i>Low Stock Products
                    <span class="text-xs font-normal ml-1">(In stock, ≤ Min Stock)</span>
                </h3>
                <a href="<?php echo LC_BASE; ?>/inventory.php?filter=low" class="text-xs text-orange-600 hover:underline">View All →</a>
            </div>
            <?php if (!empty($low_in_stock)): ?>
            <div class="overflow-x-auto dash-scroll dash-scroll-low">
                <table class="w-full text-sm dash-list">
                    <thead class="bg-orange-50"><tr>
                        <th class="px-4 py-2 text-left text-xs text-orange-700 font-medium whitespace-nowrap">Product Name</th>
                        <th class="px-4 py-2 text-right text-xs text-orange-700 font-medium whitespace-nowrap">Current Stock</th>
                        <th class="px-4 py-2 text-right text-xs text-orange-700 font-medium whitespace-nowrap">Min Stock</th>
                        <th class="px-4 py-2 text-center text-xs text-orange-700 font-medium whitespace-nowrap">Shortage</th>
                    </tr></thead>
                    <tbody class="divide-y divide-orange-50">
                    <?php foreach ($low_in_stock as $row):
                        $shortage = $row['min_stock'] - $row['total_stock'];
                    ?>
                    <tr class="hover:bg-orange-50">
                        <td class="px-4 py-2 font-medium text-gray-900">
                            <?php echo htmlspecialchars($row['name']); ?>
                            <div class="text-xs text-gray-400 font-mono"><?php echo !empty($row['barcode']) ? htmlspecialchars($row['barcode']) : '-'; ?></div>
                        </td>
                        <td class="px-4 py-2 text-right font-bold text-orange-600">
                            <?php echo number_format($row['total_stock']); ?>
                            <span class="text-xs font-normal text-gray-400 ml-0.5"><?php echo htmlspecialchars($row['unit']); ?></span>
                        </td>
                        <td class="px-4 py-2 text-right text-gray-500"><?php echo number_format($row['min_stock']); ?></td>
                        <td class="px-4 py-2 text-center">
                            <span class="inline-block whitespace-nowrap text-xs px-2 py-0.5 rounded-full bg-orange-100 text-orange-700">
                                +<?php echo number_format($shortage); ?> needed
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="px-4 py-6 text-center text-sm text-gray-400">No low stock products in stock</div>
            <?php endif; ?>
        </div>

        <!-- 재고 0 상품 -->
        <div class="bg-white rounded-lg border border-red-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-red-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-red-800">
                    <i class="fas fa-ban mr-2"></i>Out of Stock Products
                    <span class="text-xs font-normal ml-1">(Stock 0)</span>
                </h3>
                <a href="<?php echo LC_BASE; ?>/inventory.php?filter=out" class="text-xs text-red-600 hover:underline">View All →</a>
            </div>
            <?php if (!empty($low_zero)): ?>
            <div class="overflow-x-auto dash-scroll dash-scroll-low">
                <table class="w-full text-sm dash-list">
                    <thead class="bg-red-50"><tr>
                        <th class="px-4 py-2 text-left text-xs text-red-700 font-medium whitespace-nowrap">Product Name</th>
                        <th class="px-4 py-2 text-right text-xs text-red-700 font-medium whitespace-nowrap">Current Stock</th>
                        <th class="px-4 py-2 text-right text-xs text-red-700 font-medium whitespace-nowrap">Min Stock</th>
                        <th class="px-4 py-2 text-center text-xs text-red-700 font-medium whitespace-nowrap">Status</th>
                    </tr></thead>
                    <tbody class="divide-y divide-red-50">
                    <?php foreach ($low_zero as $row): ?>
                    <tr class="bg-red-50">
                        <td class="px-4 py-2 font-medium text-gray-900">
                            <?php echo htmlspecialchars($row['name']); ?>
                            <div class="text-xs text-gray-400 font-mono"><?php echo !empty($row['barcode']) ? htmlspecialchars($row['barcode']) : '-'; ?></div>
                        </td>
                        <td class="px-4 py-2 text-right font-bold text-red-600">
                            0
                            <span class="text-xs font-normal text-gray-400 ml-0.5"><?php echo htmlspecialchars($row['unit']); ?></span>
                        </td>
                        <td class="px-4 py-2 text-right text-gray-500"><?php echo number_format($row['min_stock']); ?></td>
                        <td class="px-4 py-2 text-center">
                            <span class="inline-block whitespace-nowrap text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-700 font-bold">Out of stock</span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="px-4 py-6 text-center text-sm text-gray-400">No out-of-stock products</div>
            <?php endif; ?>
        </div>

    </div>

    <!-- 유통기한 임박/만료 -->
    <?php
        $has_expired   = !empty($expiry_list) && array_filter($expiry_list, fn($r) => $r['days_left'] < 0);
        $section_color = $has_expired ? 'red' : 'yellow';
    ?>
    <div class="bg-white rounded-lg border border-<?php echo $section_color; ?>-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-<?php echo $section_color; ?>-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-<?php echo $section_color; ?>-800">
                <i class="fas fa-exclamation-triangle mr-2"></i>Expiry Approaching/Expired
                <span class="text-xs font-normal ml-1">(within D-90)</span>
            </h3>
            <span class="text-xs text-gray-400"><?php echo count($expiry_list); ?> items</span>
        </div>
        <?php if (!empty($expiry_list)): ?>
        <div class="overflow-x-auto dash-scroll dash-scroll-expiry">
            <table class="w-full text-sm dash-list">
                <thead class="bg-gray-50"><tr>
                    <th class="px-4 py-2 text-left text-xs text-gray-500 font-medium whitespace-nowrap">Product Name</th>
                    <th class="px-4 py-2 text-left text-xs text-gray-500 font-medium whitespace-nowrap">Expiry</th>
                    <th class="px-4 py-2 text-right text-xs text-gray-500 font-medium whitespace-nowrap">Stock</th>
                    <th class="px-4 py-2 text-center text-xs text-gray-500 font-medium whitespace-nowrap" style="width:5.5rem;min-width:5.5rem">D-day</th>
                    <th class="px-4 py-2 text-center text-xs text-gray-500 font-medium whitespace-nowrap">Distribute</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                <?php foreach ($expiry_list as $row):
                    $d = (int)$row['days_left'];
                    if ($d < 0)       { $dday_label = 'Expired'; $dday_cls = 'bg-red-100 text-red-700 font-bold'; }
                    elseif ($d === 0) { $dday_label = 'D-0';     $dday_cls = 'bg-red-100 text-red-700 font-bold'; }
                    elseif ($d <= 30) { $dday_label = 'D-'.$d;   $dday_cls = 'bg-orange-100 text-orange-700 font-semibold'; }
                    else              { $dday_label = 'D-'.$d;   $dday_cls = 'bg-yellow-100 text-yellow-700'; }
                ?>
                <tr class="<?php echo kw_expiry_class($row['expiry_date']); ?>">
                    <td class="px-4 py-2 font-medium">
                        <?php echo htmlspecialchars($row['name']); ?>
                        <div class="text-xs text-gray-400 font-mono"><?php echo !empty($row['barcode']) ? htmlspecialchars($row['barcode']) : '-'; ?></div>
                    </td>
                    <td class="px-4 py-2 text-xs text-gray-600 whitespace-nowrap"><?php echo date('Y-m-d', strtotime($row['expiry_date'])); ?></td>
                    <td class="px-4 py-2 text-right font-semibold"><?php echo number_format($row['stock']); ?></td>
                    <td class="px-4 py-2 text-center whitespace-nowrap" style="width:5.5rem;min-width:5.5rem">
                        <span class="inline-block text-xs px-2 py-0.5 rounded-full whitespace-nowrap <?php echo $dday_cls; ?>"><?php echo $dday_label; ?></span>
                    </td>
                    <td class="px-4 py-2 text-center">
                        <button type="button"
                                onclick="openDistModal(<?php echo $row['product_id']; ?>, '<?php echo htmlspecialchars(addslashes($row['name'])); ?>', <?php echo (int)$row['stock']; ?>)"
                                class="text-xs px-2 py-1 bg-teal-600 hover:bg-teal-700 text-white rounded transition-colors">
                            <i class="fas fa-store mr-1"></i>Distribute
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="px-4 py-6 text-center text-sm text-gray-400">No products with approaching expiry</div>
        <?php endif; ?>
    </div>

</div>
<?php endif; ?>

<!-- 점포 담당자: 내 주문 현황 -->
<?php if (kw_is_store_user() && !empty($my_orders)): ?>
<div class="bg-white rounded-lg border border-gray-200">
    <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
        <h3 class="text-sm font-semibold text-gray-700">Recent Orders</h3>
        <a href="<?php echo LC_BASE; ?>/orders.php" class="text-xs text-teal-600 hover:underline">View All</a>
    </div>
    <div class="divide-y divide-gray-100">
    <?php foreach ($my_orders as $o): ?>
    <a href="<?php echo LC_BASE; ?>/order_detail.php?id=<?php echo $o['id']; ?>" class="flex items-center justify-between px-4 py-3 hover:bg-gray-50">
        <div>
            <span class="text-sm font-medium text-gray-800">Order #<?php echo $o['id']; ?></span>
            <span class="ml-2 text-xs text-gray-500"><?php echo $o['order_date']; ?> · <?php echo $o['item_count']; ?> items</span>
        </div>
        <span class="text-xs px-2 py-1 rounded-full font-medium <?php echo kw_status_class($o['status']); ?>">
            <?php echo kw_status_label($o['status']); ?>
        </span>
    </a>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<!-- 점포 배분 모달 -->
<div id="distModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg max-h-[85vh] flex flex-col">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
            <div>
                <h3 class="text-base font-bold text-gray-900">Store Distribution</h3>
                <p id="distProductName" class="text-xs text-gray-500 mt-0.5"></p>
            </div>
            <button onclick="closeDistModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <div class="px-5 py-3 border-b border-gray-100 flex items-center gap-4 text-sm">
            <div class="text-gray-600">Available Stock: <strong id="distStock" class="text-teal-700"></strong></div>
            <div class="flex items-center gap-2">
                <label class="text-gray-600 text-xs">Unit Price</label>
                <input type="number" id="distUnitPrice" step="0.01" min="0" placeholder="0.00"
                       class="w-28 border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-teal-500">
            </div>
        </div>
        <div id="distBody" class="overflow-y-auto flex-1 px-5 py-3">
            <div class="text-center text-gray-400 py-6"><i class="fas fa-spinner fa-spin"></i></div>
        </div>
        <div class="px-5 py-3 border-t border-gray-100">
            <div class="mb-2">
                <label class="text-xs text-gray-500">Notes</label>
                <input type="text" id="distNotes" placeholder="e.g. Expiry-driven distribution"
                       class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm mt-1 focus:outline-none focus:ring-1 focus:ring-teal-500">
            </div>
            <div class="flex items-center justify-between">
                <span class="text-xs text-gray-500">Distribution Total: <strong id="distTotal" class="text-teal-700">0</strong></span>
                <div class="flex gap-2">
                    <button onclick="closeDistModal()" class="px-4 py-2 text-sm bg-gray-100 text-gray-600 rounded-lg hover:bg-gray-200">Cancel</button>
                    <button onclick="submitDist()" id="distSubmitBtn"
                            class="px-4 py-2 text-sm bg-teal-600 text-white font-semibold rounded-lg hover:bg-teal-700 disabled:opacity-40"
                            disabled>
                        <i class="fas fa-paper-plane mr-1"></i>Execute Distribution
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var LC_BASE = '<?php echo LC_BASE; ?>';
    var CSRF    = '<?php echo htmlspecialchars(kw_csrf_token()); ?>';
    var currentProductId = 0;
    var stores = [];

    window.openDistModal = function(productId, productName, stock) {
        currentProductId = productId;
        document.getElementById('distProductName').textContent = productName;
        document.getElementById('distStock').textContent = stock.toLocaleString();
        document.getElementById('distNotes').value = '';
        document.getElementById('distTotal').textContent = '0';
        document.getElementById('distSubmitBtn').disabled = true;
        document.getElementById('distBody').innerHTML = '<div class="text-center text-gray-400 py-6"><i class="fas fa-spinner fa-spin"></i></div>';
        document.getElementById('distModal').classList.remove('hidden');

        // 단가 자동 조회
        fetch(LC_BASE + '/ajax/distribute_to_stores.php?action=get_product_stock&product_id=' + productId)
            .then(r => r.json())
            .then(d => {
                if (d.success && d.product) {
                    document.getElementById('distUnitPrice').value = parseFloat(d.product.avg_cost || 0).toFixed(2);
                }
            });

        // 점포 목록 조회 (캐시)
        if (stores.length) { renderStores(); return; }
        fetch(LC_BASE + '/ajax/distribute_to_stores.php?action=get_stores')
            .then(r => r.json())
            .then(d => {
                if (d.success) { stores = d.stores; renderStores(); }
                else { document.getElementById('distBody').innerHTML = '<p class="text-red-500 text-sm">Failed to load store list</p>'; }
            });
    };

    function renderStores() {
        if (!stores.length) {
            document.getElementById('distBody').innerHTML = '<p class="text-gray-400 text-sm text-center py-4">No stores registered</p>';
            return;
        }
        var html = '<table class="w-full text-sm"><thead class="bg-gray-50"><tr>'
            + '<th class="px-3 py-2 text-left text-xs text-gray-500">Store Name</th>'
            + '<th class="px-3 py-2 text-right text-xs text-gray-500 w-28">Distribution Qty</th>'
            + '</tr></thead><tbody class="divide-y divide-gray-100">';
        stores.forEach(function(s) {
            html += '<tr><td class="px-3 py-2 font-medium text-gray-800">' + esc(s.name) + '</td>'
                + '<td class="px-3 py-2 text-right">'
                + '<input type="number" min="0" step="1" value="0" data-store-id="' + s.id + '"'
                + ' oninput="updateDistTotal()" class="dist-qty w-24 border border-gray-300 rounded px-2 py-1 text-sm text-right focus:outline-none focus:ring-1 focus:ring-teal-500">'
                + '</td></tr>';
        });
        html += '</tbody></table>';
        document.getElementById('distBody').innerHTML = html;
    }

    window.updateDistTotal = function() {
        var total = 0;
        document.querySelectorAll('.dist-qty').forEach(function(inp) {
            total += parseInt(inp.value) || 0;
        });
        document.getElementById('distTotal').textContent = total.toLocaleString();
        document.getElementById('distSubmitBtn').disabled = total <= 0;
    };

    window.submitDist = function() {
        var fd = new FormData();
        fd.append('action', 'distribute');
        fd.append('csrf_token', CSRF);
        fd.append('product_id', currentProductId);
        fd.append('unit_price', document.getElementById('distUnitPrice').value || 0);
        fd.append('notes', document.getElementById('distNotes').value);

        document.querySelectorAll('.dist-qty').forEach(function(inp) {
            var qty = parseInt(inp.value) || 0;
            if (qty > 0) fd.append('dist[' + inp.dataset.storeId + ']', qty);
        });

        var btn = document.getElementById('distSubmitBtn');
        btn.disabled = true;
        btn.textContent = 'Processing…';

        fetch(LC_BASE + '/ajax/distribute_to_stores.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    alert('Distributed to ' + d.created + ' store(s). Orders created with approved status.');
                    closeDistModal();
                } else {
                    alert(d.message || 'An error occurred');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane mr-1"></i>Execute Distribution';
                }
            })
            .catch(() => { alert('Request failed'); btn.disabled = false; });
    };

    window.closeDistModal = function() {
        document.getElementById('distModal').classList.add('hidden');
    };

    document.getElementById('distModal').addEventListener('click', function(e) {
        if (e.target === this) closeDistModal();
    });

    function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
})();
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
