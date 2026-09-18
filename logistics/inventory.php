<?php
require_once __DIR__ . '/lib/auth.php';
$page_title = t('logistics.inventory.page_title');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/inventory_helper.php';
require_once __DIR__ . '/lib/unit_helper.php';

// Design Ref: role-permission-management - 재고 현황은 물류센터 직원/관리자만 접근
lc_require_staff();

$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all'; // all | expiring | expired | low | negative | out
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 9;
$offset = ($page - 1) * $limit;
$is_searching = $search !== '';
$limit_clause = $is_searching ? '' : "LIMIT $limit OFFSET $offset";

try {
    $conn = get_lc_db();

    if ($filter === 'out') {
        // 재고 0 상품 (min_stock 설정된 상품 중 현재고 0 이하) — lc_inventory에 재고 lot이 없어도
        // 노출되도록 lc_products 기준 LEFT JOIN (다른 필터는 lc_inventory INNER JOIN 기반이라
        // 재고 lot이 아예 없는 상품은 집계에서 제외되어 여기서만 별도 처리)
        $conds  = ["p.is_active = 1", "p.min_stock > 0"];
        $params = [];
        $types  = '';

        if ($search) {
            $conds[] = "(p.name_en LIKE ? OR p.name_ko LIKE ? OR p.barcode_unit LIKE ? OR p.barcode_box LIKE ? OR p.barcode_logistics LIKE ?
                        OR EXISTS (
                            SELECT 1 FROM lc_brands search_brand
                            WHERE search_brand.id = p.brand_id
                              AND (search_brand.name_en LIKE ? OR search_brand.name_ko LIKE ?)
                        )
                        OR EXISTS (
                            SELECT 1 FROM lc_inventory search_inventory
                            JOIN lc_inbound search_inbound ON search_inventory.inbound_id = search_inbound.id
                            LEFT JOIN lc_inbound_batches search_batch ON search_inbound.batch_id = search_batch.id
                            LEFT JOIN lc_suppliers search_supplier ON search_batch.supplier_id = search_supplier.id
                            WHERE search_inventory.product_id = p.id AND search_supplier.name LIKE ?
                        ))";
            $params = array_fill(0, 8, "%$search%");
            $types  .= 'ssssssss';
        }
        $where = 'WHERE ' . implode(' AND ', $conds);

        $cnt_sql = "SELECT COUNT(*) FROM (
            SELECT p.id FROM lc_products p
            LEFT JOIN lc_inventory i ON i.product_id = p.id AND i.quantity_remain > 0
            $where
            GROUP BY p.id
            HAVING COALESCE(SUM(i.quantity_remain), 0) <= 0
        ) t";
        $cnt = $conn->prepare($cnt_sql);
        if ($params) { $cnt->bind_param($types, ...$params); }
        $cnt->execute();
        $total = (int)$cnt->get_result()->fetch_row()[0];
        $cnt->close();
        $total_pages = $is_searching ? 1 : max(1, (int)ceil($total / $limit));

        $sql = "SELECT p.id AS product_id,
                       CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS product_name,
                       b.name_en AS brand_name, b.name_ko AS brand_name_ko,
                       p.unit, p.capacity, p.pieces_per_box, p.min_stock,
                       COALESCE(p.barcode_unit, p.barcode_box, p.barcode_logistics) AS barcode,
                       0 AS total_stock, 0 AS box_stock, 0 AS pack_stock, 0 AS pcs_stock,
                       0 AS lot_count, NULL AS earliest_expiry, NULL AS days_left,
                       NULL AS latest_inbound, NULL AS latest_inbound_id,
                       (SELECT s.name
                        FROM lc_inventory li
                        JOIN lc_inbound ib2 ON li.inbound_id = ib2.id
                        LEFT JOIN lc_inbound_batches bat2 ON ib2.batch_id = bat2.id
                        LEFT JOIN lc_suppliers s ON bat2.supplier_id = s.id
                        WHERE li.product_id = p.id
                        ORDER BY ib2.inbound_date DESC, li.inbound_id DESC
                        LIMIT 1) AS latest_supplier
                FROM lc_products p
                LEFT JOIN lc_brands b ON p.brand_id = b.id
                LEFT JOIN lc_inventory i ON i.product_id = p.id AND i.quantity_remain > 0
                $where
                GROUP BY p.id
                HAVING COALESCE(SUM(i.quantity_remain), 0) <= 0
                ORDER BY p.name_en ASC
                $limit_clause";
        $st = $conn->prepare($sql);
        if ($params) { $st->bind_param($types, ...$params); }
        $st->execute();
        $list = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    } elseif ($filter === 'all') {
        // 전체 상품 목록 — 재고가 0(또는 lot 자체가 없는) 상품도 목록/검색에 노출되도록
        // lc_products 기준 LEFT JOIN (다른 필터는 실재고 lot 존재를 전제로 하므로 INNER JOIN 유지)
        $conds  = [];
        $params = [];
        $types  = '';

        if ($search) {
            $conds[] = "(p.name_en LIKE ? OR p.name_ko LIKE ? OR p.barcode_unit LIKE ? OR p.barcode_box LIKE ? OR p.barcode_logistics LIKE ?
                        OR EXISTS (
                            SELECT 1 FROM lc_brands search_brand
                            WHERE search_brand.id = p.brand_id
                              AND (search_brand.name_en LIKE ? OR search_brand.name_ko LIKE ?)
                        )
                        OR EXISTS (
                            SELECT 1 FROM lc_inventory search_inventory
                            JOIN lc_inbound search_inbound ON search_inventory.inbound_id = search_inbound.id
                            LEFT JOIN lc_inbound_batches search_batch ON search_inbound.batch_id = search_batch.id
                            LEFT JOIN lc_suppliers search_supplier ON search_batch.supplier_id = search_supplier.id
                            WHERE search_inventory.product_id = p.id AND search_supplier.name LIKE ?
                        ))";
            $params = array_fill(0, 8, "%$search%");
            $types  .= 'ssssssss';
        }
        $where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';

        $cnt_sql = "SELECT COUNT(*) FROM lc_products p $where";
        $cnt = $conn->prepare($cnt_sql);
        if ($params) { $cnt->bind_param($types, ...$params); }
        $cnt->execute();
        $total = (int)$cnt->get_result()->fetch_row()[0];
        $cnt->close();
        $total_pages = $is_searching ? 1 : max(1, (int)ceil($total / $limit));

        $sql = "SELECT p.id AS product_id,
                       CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS product_name,
                       b.name_en AS brand_name, b.name_ko AS brand_name_ko,
                       p.unit, p.capacity, p.pieces_per_box, p.min_stock,
                       COALESCE(p.barcode_unit, p.barcode_box, p.barcode_logistics) AS barcode,
                       COALESCE(SUM(i.quantity_remain), 0) AS total_stock,
                       COALESCE(SUM(CASE WHEN i.unit = 'BOX'  THEN i.quantity_remain ELSE 0 END), 0) AS box_stock,
                       COALESCE(SUM(CASE WHEN i.unit = 'PACK' THEN i.quantity_remain ELSE 0 END), 0) AS pack_stock,
                       COALESCE(SUM(CASE WHEN i.unit = 'PCS'  THEN i.quantity_remain ELSE 0 END), 0) AS pcs_stock,
                       COUNT(i.id)            AS lot_count,
                       MIN(i.expiry_date)     AS earliest_expiry,
                       DATEDIFF(MIN(i.expiry_date), CURDATE()) AS days_left,
                       MAX(ib.inbound_date)   AS latest_inbound,
                       MAX(i.inbound_id)      AS latest_inbound_id,
                       (SELECT s.name
                        FROM lc_inventory li
                        JOIN lc_inbound ib2 ON li.inbound_id = ib2.id
                        LEFT JOIN lc_inbound_batches bat2 ON ib2.batch_id = bat2.id
                        LEFT JOIN lc_suppliers s ON bat2.supplier_id = s.id
                        WHERE li.product_id = p.id
                        ORDER BY ib2.inbound_date DESC, li.inbound_id DESC
                        LIMIT 1) AS latest_supplier
                FROM lc_products p
                LEFT JOIN lc_inventory i ON i.product_id = p.id AND i.quantity_remain <> 0
                LEFT JOIN lc_inbound ib ON i.inbound_id = ib.id
                LEFT JOIN lc_brands b ON p.brand_id = b.id
                $where
                GROUP BY p.id
                ORDER BY latest_inbound DESC, latest_inbound_id DESC, p.name_en ASC
                $limit_clause";
        $st = $conn->prepare($sql);
        if ($params) { $st->bind_param($types, ...$params); }
        $st->execute();
        $list = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    } elseif ($filter === 'expiring') {
        $conds  = ["i.quantity_remain <> 0", "i.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)"];
        $params = [];
        $types  = '';

        if ($search) {
            $conds[] = "(p.name_en LIKE ? OR p.name_ko LIKE ? OR p.barcode_unit LIKE ? OR p.barcode_box LIKE ? OR p.barcode_logistics LIKE ?
                        OR EXISTS (
                            SELECT 1 FROM lc_brands search_brand
                            WHERE search_brand.id = p.brand_id
                              AND (search_brand.name_en LIKE ? OR search_brand.name_ko LIKE ?)
                        )
                        OR EXISTS (
                            SELECT 1 FROM lc_inventory search_inventory
                            JOIN lc_inbound search_inbound ON search_inventory.inbound_id = search_inbound.id
                            LEFT JOIN lc_inbound_batches search_batch ON search_inbound.batch_id = search_batch.id
                            LEFT JOIN lc_suppliers search_supplier ON search_batch.supplier_id = search_supplier.id
                            WHERE search_inventory.product_id = p.id AND search_supplier.name LIKE ?
                        ))";
            $params = array_fill(0, 8, "%$search%");
            $types  .= 'ssssssss';
        }
        $where = 'WHERE ' . implode(' AND ', $conds);

        $cnt_sql = "SELECT COUNT(*) FROM lc_inventory i
            JOIN lc_products p ON i.product_id = p.id
            $where";
        $cnt = $conn->prepare($cnt_sql);
        if ($params) { $cnt->bind_param($types, ...$params); }
        $cnt->execute();
        $total = (int)$cnt->get_result()->fetch_row()[0];
        $cnt->close();
        $total_pages = $is_searching ? 1 : max(1, (int)ceil($total / $limit));

        $sql = "SELECT i.id AS inventory_id,
                       p.id AS product_id,
                       CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS product_name,
                       b.name_en AS brand_name, b.name_ko AS brand_name_ko,
                       p.capacity, p.pieces_per_box, p.unit AS product_unit,
                       i.lot_number, i.expiry_date, i.quantity_remain, i.unit,
                       DATEDIFF(i.expiry_date, CURDATE()) AS days_left,
                       lp.id AS promotion_id, lp.discount_rate, lp.discounted_price
                FROM lc_inventory i
                JOIN lc_products p ON i.product_id = p.id
                LEFT JOIN lc_brands b ON p.brand_id = b.id
                LEFT JOIN lc_lot_promotions lp ON lp.inventory_id = i.id AND lp.status = 'active'
                $where
                ORDER BY i.expiry_date ASC, p.name_en ASC, i.id ASC
                $limit_clause";
        $st = $conn->prepare($sql);
        if ($params) { $st->bind_param($types, ...$params); }
        $st->execute();
        $list = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    } else {
        // 상품별 재고 집계 쿼리 (음수 재고 lot 포함 — ADJUST lot으로 마이너스 표시)
        $conds  = ["i.quantity_remain <> 0"];
        $params = [];
        $types  = '';

        if ($search) {
            $conds[] = "(p.name_en LIKE ? OR p.name_ko LIKE ? OR p.barcode_unit LIKE ? OR p.barcode_box LIKE ? OR p.barcode_logistics LIKE ?
                        OR EXISTS (
                            SELECT 1 FROM lc_brands search_brand
                            WHERE search_brand.id = p.brand_id
                              AND (search_brand.name_en LIKE ? OR search_brand.name_ko LIKE ?)
                        )
                        OR EXISTS (
                            SELECT 1 FROM lc_inventory search_inventory
                            JOIN lc_inbound search_inbound ON search_inventory.inbound_id = search_inbound.id
                            LEFT JOIN lc_inbound_batches search_batch ON search_inbound.batch_id = search_batch.id
                            LEFT JOIN lc_suppliers search_supplier ON search_batch.supplier_id = search_supplier.id
                            WHERE search_inventory.product_id = p.id AND search_supplier.name LIKE ?
                        ))";
            $params = array_fill(0, 8, "%$search%");
            $types  .= 'ssssssss';
        }
        if ($filter === 'expiring') {
            $conds[] = "MIN(i.expiry_date) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)";
        } elseif ($filter === 'expired') {
            $conds[] = "MIN(i.expiry_date) < CURDATE()";
        } elseif ($filter === 'low') {
            $conds[] = "SUM(i.quantity_remain) <= MIN(p.min_stock) AND MIN(p.min_stock) > 0";
        } elseif ($filter === 'negative') {
            $conds[] = "SUM(i.quantity_remain) < 0";
        }

        $where = 'WHERE ' . implode(' AND ', array_filter($conds, fn($c) => !str_starts_with($c, 'MIN(') && !str_starts_with($c, 'SUM(')));
        $having = '';
        $havingConds = array_filter($conds, fn($c) => str_starts_with($c, 'MIN(') || str_starts_with($c, 'SUM('));
        if ($havingConds) $having = 'HAVING ' . implode(' AND ', $havingConds);

        $cnt_sql = "SELECT COUNT(*) FROM (
            SELECT p.id FROM lc_inventory i
            JOIN lc_products p ON i.product_id = p.id
            JOIN lc_inbound ib ON i.inbound_id = ib.id
            $where
            GROUP BY p.id $having
        ) t";
        $cnt = $conn->prepare($cnt_sql);
        if ($params) { $cnt->bind_param($types, ...$params); }
        $cnt->execute();
        $total = (int)$cnt->get_result()->fetch_row()[0];
        $cnt->close();
        $total_pages = $is_searching ? 1 : max(1, (int)ceil($total / $limit));

        $sql = "SELECT p.id AS product_id,
                       CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS product_name,
                       b.name_en AS brand_name, b.name_ko AS brand_name_ko,
                       p.unit, p.capacity, p.pieces_per_box, p.min_stock,
                       COALESCE(p.barcode_unit, p.barcode_box, p.barcode_logistics) AS barcode,
                       SUM(i.quantity_remain) AS total_stock,
                       SUM(CASE WHEN i.unit = 'BOX'  THEN i.quantity_remain ELSE 0 END) AS box_stock,
                       SUM(CASE WHEN i.unit = 'PACK' THEN i.quantity_remain ELSE 0 END) AS pack_stock,
                       SUM(CASE WHEN i.unit = 'PCS'  THEN i.quantity_remain ELSE 0 END) AS pcs_stock,
                       COUNT(i.id)            AS lot_count,
                       MIN(i.expiry_date)     AS earliest_expiry,
                       DATEDIFF(MIN(i.expiry_date), CURDATE()) AS days_left,
                       MAX(ib.inbound_date)   AS latest_inbound,
                       MAX(i.inbound_id)      AS latest_inbound_id,
                       (SELECT s.name
                        FROM lc_inventory li
                        JOIN lc_inbound ib2 ON li.inbound_id = ib2.id
                        LEFT JOIN lc_inbound_batches bat2 ON ib2.batch_id = bat2.id
                        LEFT JOIN lc_suppliers s ON bat2.supplier_id = s.id
                        WHERE li.product_id = p.id
                        ORDER BY ib2.inbound_date DESC, li.inbound_id DESC
                        LIMIT 1) AS latest_supplier
                FROM lc_inventory i
                JOIN lc_products p ON i.product_id = p.id
                JOIN lc_inbound ib ON i.inbound_id = ib.id
                LEFT JOIN lc_brands b ON p.brand_id = b.id
                $where
                GROUP BY p.id $having
                ORDER BY latest_inbound DESC, latest_inbound_id DESC, p.name_en ASC
                $limit_clause";
        $st = $conn->prepare($sql);
        if ($params) { $st->bind_param($types, ...$params); }
        $st->execute();
        $list = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    }

    // 요약 통계
    $stats = $conn->query(
        "SELECT COUNT(DISTINCT p.id) AS total_products,
                SUM(CASE WHEN p.min_stock > 0 AND sub.stock <= p.min_stock THEN 1 ELSE 0 END) AS low_count,
                SUM(CASE WHEN sub.earliest < CURDATE() THEN 1 ELSE 0 END) AS expired_count,
                SUM(CASE WHEN sub.earliest BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN 1 ELSE 0 END) AS expiring_count,
                SUM(CASE WHEN sub.stock < 0 THEN 1 ELSE 0 END) AS negative_count
         FROM lc_products p
         JOIN (SELECT i.product_id, SUM(i.quantity_remain) AS stock, MIN(i.expiry_date) AS earliest
               FROM lc_inventory i
               JOIN lc_inbound ib ON i.inbound_id = ib.id
               WHERE i.quantity_remain <> 0 GROUP BY i.product_id) sub ON sub.product_id = p.id"
    )->fetch_assoc();

    // 재고 0 상품 수 (lc_inventory에 lot이 아예 없는 상품도 포함되도록 별도 집계)
    $stats['out_count'] = (int)$conn->query(
        "SELECT COUNT(*) FROM (
            SELECT p.id FROM lc_products p
            LEFT JOIN lc_inventory i ON i.product_id = p.id AND i.quantity_remain > 0
            WHERE p.is_active = 1 AND p.min_stock > 0
            GROUP BY p.id
            HAVING COALESCE(SUM(i.quantity_remain), 0) <= 0
        ) t"
    )->fetch_row()[0];

    $conn->close();
} catch (Exception $e) {
    $db_error = $e->getMessage();
    $list = []; $stats = []; $total = 0; $total_pages = 1;
}

$filter_labels = [
    'all' => t('logistics.inventory.filter_all'),
    'expiring' => t('logistics.inventory.filter_expiring'),
    'low' => t('logistics.inventory.filter_low'),
    'out' => t('logistics.inventory.filter_out'),
    'negative' => t('logistics.inventory.filter_negative'),
];
?>

<style>
main { overflow: hidden !important; }
</style>

<div class="flex flex-col h-full gap-2 overflow-hidden">

<!-- 페이지 헤더 -->
<div class="flex items-center justify-between shrink-0">
    <h2 class="text-xl font-bold text-gray-900"><?php echo t('logistics.inventory.title'); ?></h2>
</div>

<!-- 요약 카드 -->
<?php if (!empty($stats)): ?>
<div class="flex flex-wrap gap-2 shrink-0">
    <a href="?filter=all" class="bg-white rounded-lg border border-gray-200 px-3 py-1.5 flex items-center gap-2 min-w-[110px] hover:bg-gray-50 transition-colors">
        <div class="w-7 h-7 bg-teal-100 rounded-lg flex items-center justify-center"><i class="fas fa-boxes text-teal-600 text-xs"></i></div>
        <div><p class="text-xs text-gray-500"><?php echo t('logistics.inventory.product_types'); ?></p><p class="text-base font-bold text-gray-900"><?php echo number_format($stats['total_products']); ?></p></div>
    </a>
    <a href="?filter=expired" class="bg-white rounded-lg border border-red-200 px-3 py-1.5 flex items-center gap-2 min-w-[110px] hover:bg-red-50 transition-colors">
        <div class="w-7 h-7 bg-red-100 rounded-lg flex items-center justify-center"><i class="fas fa-calendar-times text-red-500 text-xs"></i></div>
        <div><p class="text-xs text-gray-500"><?php echo t('logistics.inventory.expired'); ?></p><p class="text-base font-bold text-red-600"><?php echo number_format($stats['expired_count']); ?></p></div>
    </a>
    <a href="?filter=expiring" class="bg-white rounded-lg border border-orange-200 px-3 py-1.5 flex items-center gap-2 min-w-[110px] hover:bg-orange-50 transition-colors">
        <div class="w-7 h-7 bg-orange-100 rounded-lg flex items-center justify-center"><i class="fas fa-exclamation-circle text-orange-500 text-xs"></i></div>
        <div><p class="text-xs text-gray-500"><?php echo t('logistics.inventory.expiring'); ?></p><p class="text-base font-bold text-orange-600"><?php echo number_format($stats['expiring_count']); ?></p></div>
    </a>
    <a href="?filter=low" class="bg-white rounded-lg border border-yellow-200 px-3 py-1.5 flex items-center gap-2 min-w-[110px] hover:bg-yellow-50 transition-colors">
        <div class="w-7 h-7 bg-yellow-100 rounded-lg flex items-center justify-center"><i class="fas fa-exclamation-triangle text-yellow-600 text-xs"></i></div>
        <div><p class="text-xs text-gray-500"><?php echo t('logistics.inventory.low_stock'); ?></p><p class="text-base font-bold text-yellow-600"><?php echo number_format($stats['low_count']); ?></p></div>
    </a>
    <a href="?filter=out" class="bg-white rounded-lg border border-red-200 px-3 py-1.5 flex items-center gap-2 min-w-[110px] hover:bg-red-50 transition-colors">
        <div class="w-7 h-7 bg-red-100 rounded-lg flex items-center justify-center"><i class="fas fa-ban text-red-600 text-xs"></i></div>
        <div><p class="text-xs text-gray-500"><?php echo t('logistics.inventory.out_of_stock'); ?></p><p class="text-base font-bold text-red-600"><?php echo number_format($stats['out_count'] ?? 0); ?></p></div>
    </a>
    <?php if (($stats['negative_count'] ?? 0) > 0): ?>
    <a href="?filter=negative" class="bg-red-50 rounded-lg border-2 border-red-300 px-3 py-1.5 flex items-center gap-2 min-w-[110px] hover:bg-red-100 transition-colors">
        <div class="w-7 h-7 bg-red-100 rounded-lg flex items-center justify-center"><i class="fas fa-minus-circle text-red-600 text-xs"></i></div>
        <div><p class="text-xs text-red-500 font-medium"><?php echo t('logistics.inventory.negative_stock'); ?></p><p class="text-base font-bold text-red-600"><?php echo number_format($stats['negative_count']); ?></p></div>
    </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- 검색 + 필터 -->
<form method="get" class="bg-white rounded-lg border border-gray-200 px-3 py-2 shrink-0">
    <div class="flex flex-wrap items-center gap-2">
        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
               placeholder="<?php echo htmlspecialchars(t('logistics.inventory.search_placeholder')); ?>"
               class="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500 w-56">
        <button type="submit" class="px-3 py-1.5 bg-teal-600 text-white text-sm rounded-md hover:bg-teal-700">
            <i class="fas fa-search mr-1"></i><?php echo t('logistics.inventory.search'); ?>
        </button>
        <a href="?" class="px-3 py-1.5 bg-gray-100 text-gray-600 text-sm rounded-md hover:bg-gray-200"><?php echo t('logistics.inventory.reset'); ?></a>
        <a href="<?php echo LC_BASE; ?>/export_inventory.php?<?php echo http_build_query(['search'=>$search,'filter'=>$filter]); ?>"
           class="px-3 py-1.5 bg-green-600 text-white text-sm rounded-md hover:bg-green-700"><i class="fas fa-file-excel mr-1"></i><?php echo t('logistics.inventory.excel_download'); ?></a>
        <button type="button" onclick="openPrintPreview()"
           class="px-3 py-1.5 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700"><i class="fas fa-print mr-1"></i><?php echo t('logistics.inventory.print'); ?></button>
        <div class="flex gap-1.5 ml-1">
            <?php foreach ($filter_labels as $key => $label): ?>
            <a href="?filter=<?php echo $key; ?>&search=<?php echo urlencode($search); ?>"
               class="px-3 py-1.5 text-xs rounded-full border transition-colors
                      <?php echo $filter === $key ? 'bg-teal-600 text-white border-teal-600' : 'bg-white text-gray-600 border-gray-300 hover:bg-teal-50'; ?>">
                <?php echo $label; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</form>

<?php if (isset($db_error)): ?>
<div class="bg-red-50 border border-red-200 rounded-lg p-4 text-red-700 text-sm shrink-0"><?php echo htmlspecialchars($db_error); ?></div>
<?php endif; ?>

<!-- 테이블 카드 -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden flex flex-col flex-1 min-h-0">
    <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between shrink-0">
        <span class="text-sm text-gray-500"><?php echo t('logistics.inventory.total_products', ['count' => number_format($total)]); ?></span>
        <span class="text-xs text-gray-400">
            <span class="inline-block w-3 h-3 bg-yellow-100 border border-yellow-300 rounded-sm mr-1"></span><?php echo t('logistics.inventory.d90'); ?>
            <span class="inline-block w-3 h-3 bg-orange-100 border border-orange-300 rounded-sm mx-1 ml-2"></span><?php echo t('logistics.inventory.d30'); ?>
            <span class="inline-block w-3 h-3 bg-red-100 border border-red-300 rounded-sm mx-1 ml-2"></span><?php echo t('logistics.inventory.expired'); ?>
        </span>
    </div>
    <div class="overflow-auto flex-1 min-h-0">
        <?php if ($filter === 'expiring'): ?>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 sticky top-0 z-10"><tr>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo t('logistics.inventory.product_name'); ?></th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo t('logistics.inventory.lot_number'); ?></th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo t('logistics.inventory.expiry_date'); ?></th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo t('logistics.inventory.unit'); ?></th>
                <th class="px-4 py-3 text-right text-xs text-pink-700 font-semibold bg-pink-100"><?php echo t('logistics.inventory.remaining_quantity'); ?></th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo t('logistics.inventory.promotion'); ?></th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php if (empty($list)): ?>
            <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400"><?php echo t('logistics.inventory.empty'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($list as $row):
                $days = (int)$row['days_left'];
                $rowCls = $days <= 30 ? 'bg-orange-50' : 'bg-yellow-50';
                $dayClass = $days <= 30 ? 'text-orange-600' : 'text-yellow-700';
            ?>
            <tr class="<?php echo $rowCls; ?> hover:bg-teal-50 transition-colors">
                <td class="px-4 py-3">
                    <div class="font-medium text-gray-900"><?php echo htmlspecialchars($row['product_name']); ?></div>
                    <?php if (!empty($row['brand_name']) || !empty($row['brand_name_ko'])): ?>
                    <div class="text-xs text-gray-400 mt-0.5"><?php echo htmlspecialchars(trim(($row['brand_name'] ?? '') . (!empty($row['brand_name_ko']) ? ' (' . $row['brand_name_ko'] . ')' : ''))); ?></div>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-xs font-mono text-gray-600"><?php echo htmlspecialchars($row['lot_number'] ?: '-'); ?></td>
                <td class="px-4 py-3 text-xs">
                    <span class="<?php echo $dayClass; ?> font-semibold"><?php echo htmlspecialchars($row['expiry_date']); ?></span>
                    <span class="ml-1 text-xs <?php echo $dayClass; ?>">(<?php echo t('logistics.inventory.d_day', ['days' => $days]); ?>)</span>
                </td>
                <td class="px-4 py-3 text-gray-600 text-xs"><?php echo htmlspecialchars($row['unit'] ?: $row['product_unit'] ?: '-'); ?></td>
                <td class="px-4 py-3 text-right font-bold bg-pink-50 text-gray-900"><?php echo number_format((float)$row['quantity_remain'], 2); ?></td>
                <td class="px-4 py-3" onclick="event.stopPropagation();">
                    <?php if (!empty($row['promotion_id'])): ?>
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="inline-flex items-center px-2 py-1 rounded-full bg-teal-100 text-teal-800 text-xs font-semibold"><?php echo t('logistics.inventory.discount_active', ['rate' => rtrim(rtrim(number_format((float)$row['discount_rate'], 2), '0'), '.')]); ?></span>
                        <span class="text-sm font-semibold text-pink-700"><?php echo number_format((float)$row['discounted_price'], 2); ?></span>
                        <button type="button" class="px-2 py-1 text-xs text-red-600 border border-red-200 rounded hover:bg-red-50" onclick="cancelPromotion(<?php echo (int)$row['promotion_id']; ?>)"><?php echo t('logistics.inventory.discount_cancel'); ?></button>
                    </div>
                    <?php else: ?>
                    <div class="flex items-center gap-2">
                        <input type="number" min="0.01" max="99.99" step="0.01" class="w-24 px-2 py-1 border border-gray-300 rounded text-sm" aria-label="<?php echo htmlspecialchars(t('logistics.inventory.discount_rate')); ?>" placeholder="<?php echo htmlspecialchars(t('logistics.inventory.discount_rate')); ?>">
                        <button type="button" class="px-2 py-1 text-xs text-white bg-pink-600 rounded hover:bg-pink-700" onclick="registerPromotion(this, <?php echo (int)$row['inventory_id']; ?>)"><?php echo t('logistics.inventory.discount_register'); ?></button>
                    </div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 sticky top-0 z-10"><tr>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo t('logistics.inventory.brand'); ?></th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo t('logistics.inventory.product_name'); ?></th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo t('logistics.inventory.capacity'); ?></th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo t('logistics.inventory.unit'); ?></th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium"><?php echo t('logistics.inventory.pkg'); ?></th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo t('logistics.inventory.expiry_date'); ?></th>
                <th class="px-4 py-3 text-right text-xs text-pink-700 font-semibold bg-pink-100"><?php echo t('logistics.inventory.current_stock'); ?></th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo t('logistics.inventory.supplier'); ?></th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php if (empty($list)): ?>
            <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400"><?php echo t('logistics.inventory.empty'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($list as $row):
                $days = $row['days_left'];
                $rowCls = '';
                if ($row['earliest_expiry']) {
                    if ($days === null)      { }
                    elseif ($days < 0)       { $rowCls = 'bg-red-50'; }
                    elseif ($days <= 30)     { $rowCls = 'bg-orange-50'; }
                    elseif ($days <= 90)     { $rowCls = 'bg-yellow-50'; }
                }
                // Design Ref: pack-unit §5 — 단위별(BOX/PACK/PCS) 분리 집계 표시
                $boxStock  = (int)$row['box_stock'];
                $packStock = (int)$row['pack_stock'];
                $pcsStock  = (int)$row['pcs_stock'];
                $stockDisplay = lc_format_stock([LC_UNIT_BOX => $boxStock, LC_UNIT_PACK => $packStock, LC_UNIT_PCS => $pcsStock]);
                $isNegative = $boxStock < 0 || $pcsStock < 0;
                $isOut  = !$isNegative && $row['total_stock'] <= 0;
                $isLow  = !$isNegative && !$isOut && $row['min_stock'] > 0 && $row['total_stock'] <= $row['min_stock'];
                if ($isNegative) $rowCls = 'bg-red-50';
                elseif ($isOut)  $rowCls = 'bg-red-50';
            ?>
            <tr class="hover:bg-teal-50 cursor-pointer transition-colors <?php echo $rowCls; ?>"
                onclick="showInboundHistory(<?php echo $row['product_id']; ?>)">
                <td class="px-4 py-3">
                    <?php if (!empty($row['brand_name']) || !empty($row['brand_name_ko'])): ?>
                    <div class="font-medium text-gray-900"><?php echo htmlspecialchars($row['brand_name'] ?? ''); ?></div>
                    <?php if (!empty($row['brand_name_ko'])): ?>
                    <div class="text-xs text-gray-400 mt-0.5"><?php echo htmlspecialchars($row['brand_name_ko']); ?></div>
                    <?php endif; ?>
                    <?php else: ?>
                    <span class="text-gray-400">-</span>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3">
                    <div class="font-medium text-gray-900">
                        <?php echo htmlspecialchars($row['product_name']); ?>
                        <?php if ($isOut): ?>
                        <span class="ml-1 text-xs text-red-500"><i class="fas fa-ban"></i></span>
                        <?php elseif ($isLow): ?>
                        <span class="ml-1 text-xs text-orange-500"><i class="fas fa-exclamation-triangle"></i></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($row['barcode']): ?>
                    <div class="text-xs text-gray-400 font-mono mt-0.5"><i class="fas fa-barcode mr-1 opacity-50"></i><?php echo htmlspecialchars($row['barcode']); ?></div>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-gray-600 text-xs"><?php echo htmlspecialchars($row['capacity'] ?: '—'); ?></td>
                <td class="px-4 py-3 text-gray-600 text-xs"><?php echo htmlspecialchars($row['unit'] ?: '—'); ?></td>
                <td class="px-4 py-3 text-right font-mono text-xs text-gray-500"><?php echo (int)($row['pieces_per_box'] ?? 1); ?></td>
                <td class="px-4 py-3 text-xs">
                    <?php if ($row['earliest_expiry']): ?>
                        <span class="<?php echo $isNegative ? '' : ($days < 0 ? 'text-red-600 font-semibold' : ($days <= 30 ? 'text-orange-600' : ($days <= 90 ? 'text-yellow-700' : 'text-gray-600'))); ?>">
                            <?php echo htmlspecialchars($row['earliest_expiry']); ?>
                        </span>
                        <?php if ($days !== null): ?>
                        <span class="ml-1 text-xs <?php echo $days < 0 ? 'text-red-500' : ($days <= 30 ? 'text-orange-500' : ($days <= 90 ? 'text-yellow-600' : 'text-gray-400')); ?>">
                            (<?php echo $days < 0 ? t('logistics.inventory.expired') : t('logistics.inventory.d_day', ['days' => $days]); ?>)
                        </span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-gray-300">—</span>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-right font-bold bg-pink-50 <?php echo $isNegative ? 'text-red-600' : 'text-gray-900'; ?>">
                    <?php if ($isNegative): ?><i class="fas fa-exclamation-circle mr-1"></i><?php endif; ?>
                    <?php echo htmlspecialchars($stockDisplay); ?>
                </td>
                <td class="px-4 py-3 text-gray-600 text-xs"><?php echo htmlspecialchars($row['latest_supplier'] ?: '—'); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <?php if ($total_pages > 1): ?>
    <?php
        $window = 10;
        $block_start = (int)(floor(($page - 1) / $window) * $window) + 1;
        $block_end   = min($total_pages, $block_start + $window - 1);
        $qs = ['filter' => $filter, 'search' => $search];
    ?>
    <div class="px-4 py-3 border-t border-gray-100 flex items-center justify-center gap-1 shrink-0">
        <?php if ($block_start > 1): ?>
        <a href="?page=<?php echo $block_start - $window; ?>&<?php echo http_build_query($qs); ?>"
           class="w-8 h-8 flex items-center justify-center rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 text-sm transition-colors" title="<?php echo htmlspecialchars(t('logistics.inventory.previous_pages')); ?>">
            <i class="fas fa-angle-double-left text-xs"></i>
        </a>
        <?php endif; ?>
        <?php if ($page > 1): ?>
        <a href="?page=<?php echo $page-1; ?>&<?php echo http_build_query($qs); ?>"
           class="w-8 h-8 flex items-center justify-center rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 text-sm transition-colors">
            <i class="fas fa-chevron-left text-xs"></i>
        </a>
        <?php endif; ?>
        <?php for ($i = $block_start; $i <= $block_end; $i++): ?>
        <a href="?page=<?php echo $i; ?>&<?php echo http_build_query($qs); ?>"
           class="flex items-center justify-center rounded font-medium transition-colors <?php echo $i === $page ? 'w-9 h-9 bg-teal-600 text-white text-base shadow-md ring-2 ring-teal-300' : 'w-8 h-8 text-sm text-gray-500 hover:bg-gray-100'; ?>">
            <?php echo $i; ?>
        </a>
        <?php endfor; ?>
        <?php if ($page < $total_pages): ?>
        <a href="?page=<?php echo $page+1; ?>&<?php echo http_build_query($qs); ?>"
           class="w-8 h-8 flex items-center justify-center rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 text-sm transition-colors">
            <i class="fas fa-chevron-right text-xs"></i>
        </a>
        <?php endif; ?>
        <?php if ($block_end < $total_pages): ?>
        <a href="?page=<?php echo $block_end + 1; ?>&<?php echo http_build_query($qs); ?>"
           class="w-8 h-8 flex items-center justify-center rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 text-sm transition-colors" title="<?php echo htmlspecialchars(t('logistics.inventory.next_pages')); ?>">
            <i class="fas fa-angle-double-right text-xs"></i>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

</div><!-- /.flex.flex-col.h-full -->

<!-- 프린트 미리보기 모달 -->
<div id="printPreviewModal" class="hidden fixed inset-0 z-50 flex items-center justify-center">
    <div class="absolute inset-0 bg-black bg-opacity-50"></div>
    <div class="relative bg-white rounded-xl shadow-xl w-full max-w-5xl mx-4 flex flex-col" style="height:90vh">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 shrink-0">
            <h3 class="text-base font-semibold text-gray-900"><i class="fas fa-print text-blue-600 mr-2"></i><?php echo t('logistics.inventory.print_preview'); ?></h3>
            <div class="flex items-center gap-2">
                <button type="button" onclick="printPreviewFrame()"
                        class="px-4 py-1.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700">
                    <i class="fas fa-print mr-1"></i><?php echo t('logistics.inventory.print'); ?>
                </button>
                <button type="button" onclick="closePrintPreview()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <div class="flex-1 min-h-0">
            <iframe id="printPreviewFrame" class="w-full h-full border-0"></iframe>
        </div>
    </div>
</div>

<!-- LOT 상세 모달 -->
<div id="inboundModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[85vh] flex flex-col">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
            <div>
                <h3 id="modalTitle" class="text-base font-bold text-gray-900"></h3>
                <p id="modalSub" class="text-xs text-gray-400 mt-0.5"></p>
            </div>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 text-lg"><i class="fas fa-times"></i></button>
        </div>
        <div id="modalBody" class="overflow-y-auto flex-1 px-5 py-4">
            <div class="text-center text-gray-400 py-8"><i class="fas fa-spinner fa-spin text-2xl"></i></div>
        </div>
    </div>
</div>

<script>
(function() {
    var LC_BASE = '<?php echo LC_BASE; ?>';
    var CSRF_TOKEN = '<?php echo htmlspecialchars($_SESSION['lc_csrf'] ?? '', ENT_QUOTES, 'UTF-8'); ?>';

    window.registerPromotion = function(button, inventoryId) {
        var input = button.parentNode.querySelector('input[type="number"]');
        var discountRate = input.value;
        if (!discountRate || parseFloat(discountRate) < 0.01 || parseFloat(discountRate) > 99.99) {
            alert(<?php echo json_encode(t('logistics.inventory.discount_rate_invalid')); ?>);
            return;
        }
        button.disabled = true;
        var formData = new FormData();
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('inventory_id', inventoryId);
        formData.append('discount_rate', discountRate);
        fetch(LC_BASE + '/ajax/promo_register.php', { method: 'POST', body: formData })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (!data.success) {
                    throw new Error(data.message || <?php echo json_encode(t('logistics.inventory.discount_register_failed')); ?>);
                }
                window.location.reload();
            })
            .catch(function(error) {
                alert(error.message || <?php echo json_encode(t('logistics.inventory.request_failed')); ?>);
                button.disabled = false;
            });
    };

    window.cancelPromotion = function(promotionId) {
        if (!confirm(<?php echo json_encode(t('logistics.inventory.discount_cancel_confirm')); ?>)) return;
        var formData = new FormData();
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('promotion_id', promotionId);
        fetch(LC_BASE + '/ajax/promo_cancel.php', { method: 'POST', body: formData })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (!data.success) {
                    throw new Error(data.message || <?php echo json_encode(t('logistics.inventory.discount_cancel_failed')); ?>);
                }
                window.location.reload();
            })
            .catch(function(error) {
                alert(error.message || <?php echo json_encode(t('logistics.inventory.request_failed')); ?>);
            });
    };

    window.showInboundHistory = function(productId) {
        var modal = document.getElementById('inboundModal');
        document.getElementById('modalTitle').textContent = <?php echo json_encode(t('logistics.inventory.loading')); ?>;
        document.getElementById('modalSub').textContent = '';
        document.getElementById('modalBody').innerHTML = '<div class="text-center text-gray-400 py-8"><i class="fas fa-spinner fa-spin text-2xl"></i></div>';
        modal.classList.remove('hidden');

        fetch(LC_BASE + '/ajax/product_inbound_history.php?product_id=' + productId)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) {
                    document.getElementById('modalBody').innerHTML = '<p class="text-red-500 text-sm">' + (data.message || <?php echo json_encode(t('logistics.inventory.error')); ?>) + '</p>';
                    return;
                }
                var p = data.product;
                var title = esc(p.name_en) + (p.name_ko ? ' (' + esc(p.name_ko) + ')' : '');
                var meta = [];
                if (p.capacity) meta.push(esc(p.capacity));
                meta.push((p.pieces_per_box ? p.pieces_per_box : 1) + '/' + esc(p.unit || 'EA'));
                title += ' <span class="text-sm font-normal text-gray-400 ml-1">' + meta.join(' · ') + '</span>';
                document.getElementById('modalTitle').innerHTML = title;
                var sub = <?php echo json_encode(t('logistics.inventory.lots_with_stock', ['count' => '__COUNT__'])); ?>.replace('__COUNT__', data.lots.length);
                if (p.barcode) sub += '  ·  ' + <?php echo json_encode(t('logistics.inventory.barcode')); ?> + ': ' + p.barcode;
                document.getElementById('modalSub').textContent = sub;
                renderLots(data.lots, productId);
            })
            .catch(function() {
                document.getElementById('modalBody').innerHTML = '<p class="text-red-500 text-sm">' + <?php echo json_encode(t('logistics.inventory.request_failed')); ?> + '</p>';
            });
    };

    function renderLots(lots, productId) {
        if (!lots.length) {
            document.getElementById('modalBody').innerHTML = '<p class="text-gray-400 text-sm text-center py-6">' + <?php echo json_encode(t('logistics.inventory.no_lots')); ?> + '</p>';
            return;
        }
        var html = '<table class="w-full text-sm">'
            + '<thead class="bg-gray-50 sticky top-0"><tr>'
            + '<th class="px-3 py-2 text-left text-xs text-gray-500 font-medium"><?php echo t('logistics.inventory.expiry'); ?></th>'
            + '<th class="px-3 py-2 text-left text-xs text-gray-500 font-medium"><?php echo t('logistics.inventory.supplier'); ?></th>'
            + '<th class="px-3 py-2 text-left text-xs text-gray-500 font-medium"><?php echo t('logistics.inventory.inbound_date'); ?></th>'
            + '<th class="px-3 py-2 text-right text-xs text-gray-500 font-medium"><?php echo t('logistics.inventory.cost_price'); ?></th>'
            + '<th class="px-3 py-2 text-right text-xs text-gray-500 font-medium"><?php echo t('logistics.inventory.in'); ?></th>'
            + '<th class="px-3 py-2 text-right text-xs text-gray-500 font-medium"><?php echo t('logistics.inventory.out'); ?></th>'
            + '<th class="px-3 py-2 text-right text-xs text-gray-500 font-medium"><?php echo t('logistics.inventory.remaining'); ?></th>'
            + '</tr></thead><tbody class="divide-y divide-gray-100">';

        lots.forEach(function(lot) {
            var days = parseInt(lot.days_left);
            var remain = parseInt(lot.quantity_remain);
            var isNegative = remain < 0;
            var isDone = remain === 0;
            var rowCls = isDone ? 'opacity-50' : (isNegative ? 'bg-red-50' : '');
            var daysTxt = '';
            if (!isDone && lot.expiry_date) {
                if (days < 0)        { rowCls = 'bg-red-50';    daysTxt = '<span class="text-xs font-bold text-red-600 ml-1"><?php echo t('logistics.inventory.expired'); ?></span>'; }
                else if (days <= 30) { rowCls = 'bg-orange-50'; daysTxt = '<span class="text-xs text-orange-600 ml-1"><?php echo t('logistics.inventory.d_day', ['days' => '__DAYS__']); ?></span>'.replace('__DAYS__', days); }
                else if (days <= 90) { rowCls = 'bg-yellow-50'; daysTxt = '<span class="text-xs text-yellow-600 ml-1"><?php echo t('logistics.inventory.d_day', ['days' => '__DAYS__']); ?></span>'.replace('__DAYS__', days); }
            }
            var locHtml = lot.storage_location
                ? '<span class="inline-flex items-center gap-1 text-xs font-mono text-teal-700 bg-teal-50 border border-teal-200 px-1.5 py-0.5 rounded"><i class="fas fa-map-marker-alt" style="font-size:0.6rem;"></i>' + esc(lot.storage_location) + '</span>'
                : '';
            var doneBadge = '';
            if (isDone) doneBadge = '<span class="ml-1 text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded"><?php echo t('logistics.inventory.depleted'); ?></span>';
            else if (isNegative) doneBadge = '<span class="ml-1 text-xs text-red-600 bg-red-100 px-1.5 py-0.5 rounded font-semibold"><?php echo t('logistics.inventory.negative'); ?></span>';
            var remainHtml = isDone
                ? '<span class="text-gray-400">0</span>'
                : (isNegative
                    ? '<strong class="text-red-600"><i class="fas fa-exclamation-circle mr-0.5"></i>' + remain.toLocaleString() + '</strong>'
                    : '<strong>' + remain.toLocaleString() + '</strong>');

            // Design Ref: pack-unit §5/§6 — lot 단위 배지 + 묶음(BOX/PACK) lot 개봉 바로가기
            var isBundleLot = (lot.unit === 'BOX' || lot.unit === 'PACK');
            var unitBadge;
            if (lot.unit === 'BOX') {
                unitBadge = '<span class="text-xs font-semibold text-amber-700 bg-amber-50 border border-amber-200 px-1 py-0.5 rounded">BOX</span>';
            } else if (lot.unit === 'PACK') {
                unitBadge = '<span class="text-xs font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 px-1 py-0.5 rounded">PACK</span>';
            } else {
                unitBadge = '<span class="text-xs font-normal text-gray-400">PCS</span>';
            }
            var breakLink = (isBundleLot && remain > 0)
                ? ' <a href="' + LC_BASE + '/box_break.php?product_id=' + productId + '" class="text-xs text-amber-700 hover:underline" title="<?php echo htmlspecialchars(t('logistics.inventory.open_bundle')); ?>"><i class="fas fa-box-open" style="font-size:0.6rem;"></i> <?php echo t('logistics.inventory.open'); ?></a>'
                : '';

            // 입고 단가 (묶음 입고 후 개봉된 lot은 PCS 단가도 함께 표시)
            var inboundUnit = lot.inbound_unit || 'PCS';
            var costHtml = parseFloat(lot.cost_price || 0).toLocaleString('en', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            if ((inboundUnit === 'BOX' || inboundUnit === 'PACK') && parseFloat(lot.cost_price_pcs || 0) > 0) {
                costHtml += '<br><span class="text-gray-400">PCS ' + parseFloat(lot.cost_price_pcs).toLocaleString('en', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</span>';
            }

            html += '<tr class="' + rowCls + '">'
                + '<td class="px-3 py-2.5 text-sm">' + (lot.expiry_date ? esc(lot.expiry_date) + daysTxt : '<span class="text-gray-400">-</span>') + doneBadge + '</td>'
                + '<td class="px-3 py-2.5 text-sm text-gray-700">' + esc(lot.supplier_name || '-') + '</td>'
                + '<td class="px-3 py-2.5 text-xs text-gray-500">' + esc(lot.inbound_date) + '</td>'
                + '<td class="px-3 py-2.5 text-right font-mono text-xs text-gray-900">' + costHtml + '</td>'
                + '<td class="px-3 py-2.5 text-right text-gray-500">' + parseInt(lot.quantity_in).toLocaleString() + '</td>'
                + '<td class="px-3 py-2.5 text-right text-orange-600">' + parseInt(lot.quantity_out).toLocaleString() + '</td>'
                + '<td class="px-3 py-2.5 text-right text-gray-900">' + remainHtml + ' ' + unitBadge + breakLink + '</td>'
                + '</tr>';
        });
        html += '</tbody></table>';
        document.getElementById('modalBody').innerHTML = html;
    }

    window.closeModal = function() {
        document.getElementById('inboundModal').classList.add('hidden');
    };

    document.getElementById('inboundModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeModal();
            closePrintPreview();
        }
    });

    // ─── 프린트 미리보기 ─────────────────────────────────────────────
    window.openPrintPreview = function() {
        var qs = new URLSearchParams({
            search: '<?php echo addslashes($search); ?>',
            filter: '<?php echo addslashes($filter); ?>'
        }).toString();
        document.getElementById('printPreviewFrame').src = LC_BASE + '/print_inventory.php?' + qs;
        document.getElementById('printPreviewModal').classList.remove('hidden');
    };

    window.closePrintPreview = function() {
        document.getElementById('printPreviewModal').classList.add('hidden');
        document.getElementById('printPreviewFrame').src = 'about:blank';
    };

    window.printPreviewFrame = function() {
        var frame = document.getElementById('printPreviewFrame');
        frame.contentWindow.focus();
        frame.contentWindow.print();
    };

    function esc(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
})();
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
