<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/xlsx_export.php';

lc_session_start();
// Design Ref: role-permission-management - 재고 현황은 물류센터 직원/관리자만 접근
lc_require_staff();

$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all'; // all | expiring | expired | low | negative | out

$conn = get_lc_db();

if ($filter === 'out') {
    // 재고 0 상품 — lc_inventory에 lot이 아예 없는 상품도 노출되도록 LEFT JOIN
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

    $sql = "SELECT p.id AS product_id,
                   CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS product_name,
                   p.name_en AS product_name_en, p.name_ko AS product_name_ko,
                   p.unit, p.capacity, p.min_stock,
                   b.name_en AS brand_name,
                   COALESCE(p.barcode_unit, p.barcode_box, p.barcode_logistics) AS barcode,
                   0 AS total_stock,
                   NULL AS earliest_expiry,
                   (SELECT s.name
                    FROM lc_inventory li
                    JOIN lc_inbound ib2 ON li.inbound_id = ib2.id
                    LEFT JOIN lc_inbound_batches bat2 ON ib2.batch_id = bat2.id
                    LEFT JOIN lc_suppliers s ON bat2.supplier_id = s.id
                    WHERE li.product_id = p.id
                    ORDER BY ib2.inbound_date DESC, li.inbound_id DESC
                    LIMIT 1) AS latest_supplier
            FROM lc_products p
            LEFT JOIN lc_inventory i ON i.product_id = p.id AND i.quantity_remain > 0
            LEFT JOIN lc_brands b ON p.brand_id = b.id
            $where
            GROUP BY p.id
            HAVING COALESCE(SUM(i.quantity_remain), 0) <= 0
            ORDER BY p.name_en ASC";
    $st = $conn->prepare($sql);
    if ($params) { $st->bind_param($types, ...$params); }
    $st->execute();
    $list = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
} elseif ($filter === 'all') {
    // inventory.php의 All 검색과 동일하게 재고 유무와 관계없이 전체 상품을 내보낸다.
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

    $sql = "SELECT p.id AS product_id,
                   CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS product_name,
                   p.name_en AS product_name_en, p.name_ko AS product_name_ko,
                   p.unit, p.capacity, p.min_stock,
                   b.name_en AS brand_name,
                   COALESCE(p.barcode_unit, p.barcode_box, p.barcode_logistics) AS barcode,
                   COALESCE(SUM(i.quantity_remain), 0) AS total_stock,
                   MIN(i.expiry_date) AS earliest_expiry,
                   MAX(ib.inbound_date) AS latest_inbound,
                   MAX(i.inbound_id) AS latest_inbound_id,
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
            ORDER BY latest_inbound DESC, latest_inbound_id DESC, p.name_en ASC";
    $st = $conn->prepare($sql);
    if ($params) { $st->bind_param($types, ...$params); }
    $st->execute();
    $list = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
} else {
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

    $sql = "SELECT p.id AS product_id,
                   CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS product_name,
                   p.name_en AS product_name_en, p.name_ko AS product_name_ko,
                   p.unit, p.capacity, p.min_stock,
                   b.name_en AS brand_name,
                   COALESCE(p.barcode_unit, p.barcode_box, p.barcode_logistics) AS barcode,
                   SUM(i.quantity_remain) AS total_stock,
                   MIN(i.expiry_date)     AS earliest_expiry,
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
            ORDER BY latest_inbound DESC, latest_inbound_id DESC, p.name_en ASC";
    $st = $conn->prepare($sql);
    if ($params) { $st->bind_param($types, ...$params); }
    $st->execute();
    $list = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}
$conn->close();

$headers = [
    t('logistics.export_inventory.brand'), t('logistics.export_inventory.product_name_ko'), t('logistics.export_inventory.product_name_en'), t('logistics.export_inventory.capacity'), t('logistics.export_inventory.barcode'), t('logistics.export_inventory.unit'),
    t('logistics.export_inventory.expiry_date'), t('logistics.export_inventory.current_stock'), t('logistics.export_inventory.min_stock'), t('logistics.export_inventory.supplier'),
];
$textCols = [5]; // Barcode

$titles = [
    'low'      => t('logistics.export_inventory.low_title'),
    'out'      => t('logistics.export_inventory.out_title'),
    'expiring' => t('logistics.export_inventory.expiring_title'),
];
$title = $titles[$filter] ?? t('logistics.export_inventory.title');

$rows = [];
foreach ($list as $row) {
    $rows[] = [
        $row['brand_name'] ?? '',
        $row['product_name_ko'] ?? '',
        $row['product_name_en'] ?? '',
        $row['capacity'] ?? '',
        $row['barcode'] ?? '',
        $row['unit'],
        $row['earliest_expiry'] ?? '',
        (string)$row['total_stock'],
        (string)$row['min_stock'],
        $row['latest_supplier'] ?? '',
    ];
}

lc_export_xlsx('inventory', $headers, $rows, $textCols, $title);
