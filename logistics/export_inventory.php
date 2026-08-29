<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/xlsx_export.php';

lc_session_start();
// Design Ref: role-permission-management - 재고 현황은 물류센터 직원/관리자만 접근
lc_require_staff();

$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all'; // all | expiring | low | out

$conn = get_lc_db();

if ($filter === 'out') {
    // 재고 0 상품 — lc_inventory에 lot이 아예 없는 상품도 노출되도록 LEFT JOIN
    $conds  = ["p.is_active = 1", "p.min_stock > 0"];
    $params = [];
    $types  = '';

    if ($search) {
        $conds[] = "(p.name_en LIKE ? OR p.name_ko LIKE ? OR p.barcode_unit LIKE ? OR p.barcode_box LIKE ? OR p.barcode_logistics LIKE ?)";
        $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
        $types   .= 'sssss';
    }
    $where = 'WHERE ' . implode(' AND ', $conds);

    $sql = "SELECT p.id AS product_id,
                   CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS product_name,
                   p.unit, p.capacity, p.min_stock,
                   COALESCE(p.barcode_unit, p.barcode_box, p.barcode_logistics) AS barcode,
                   0 AS total_stock,
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
            $where
            GROUP BY p.id
            HAVING COALESCE(SUM(i.quantity_remain), 0) <= 0
            ORDER BY p.name_en ASC";
    $st = $conn->prepare($sql);
    if ($params) { $st->bind_param($types, ...$params); }
    $st->execute();
    $list = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
} else {
    $conds  = ["i.quantity_remain > 0"];
    $params = [];
    $types  = '';

    if ($search) {
        $conds[] = "(p.name_en LIKE ? OR p.name_ko LIKE ? OR p.barcode_unit LIKE ? OR p.barcode_box LIKE ? OR p.barcode_logistics LIKE ?)";
        $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
        $types   .= 'sssss';
    }
    if ($filter === 'expiring') {
        $conds[] = "MIN(i.expiry_date) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)";
    } elseif ($filter === 'low') {
        $conds[] = "SUM(i.quantity_remain) <= MIN(p.min_stock) AND MIN(p.min_stock) > 0";
    }

    $where = 'WHERE ' . implode(' AND ', array_filter($conds, fn($c) => !str_starts_with($c, 'MIN(') && !str_starts_with($c, 'SUM(')));
    $having = '';
    $havingConds = array_filter($conds, fn($c) => str_starts_with($c, 'MIN(') || str_starts_with($c, 'SUM('));
    if ($havingConds) $having = 'HAVING ' . implode(' AND ', $havingConds);

    $sql = "SELECT p.id AS product_id,
                   CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS product_name,
                   p.unit, p.capacity, p.min_stock,
                   COALESCE(p.barcode_unit, p.barcode_box, p.barcode_logistics) AS barcode,
                   SUM(i.quantity_remain) AS total_stock,
                   MIN(i.expiry_date)     AS earliest_expiry,
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
            $where
            GROUP BY p.id $having
            ORDER BY earliest_expiry ASC, p.name_en ASC";
    $st = $conn->prepare($sql);
    if ($params) { $st->bind_param($types, ...$params); }
    $st->execute();
    $list = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}
$conn->close();

$headers = [
    'Product Name', 'Capacity', 'Barcode', 'Unit',
    'Current Stock', 'Min Stock', 'Status', 'Supplier',
];
$textCols = [3]; // Barcode

$rows = [];
foreach ($list as $row) {
    $isOut = $row['total_stock'] <= 0;
    $isLow = !$isOut && $row['min_stock'] > 0 && $row['total_stock'] <= $row['min_stock'];
    $rows[] = [
        $row['product_name'],
        $row['capacity'] ?? '',
        $row['barcode'] ?? '',
        $row['unit'],
        (string)$row['total_stock'],
        (string)$row['min_stock'],
        $isOut ? 'Out of Stock' : ($isLow ? 'Low Stock' : 'Normal'),
        $row['latest_supplier'] ?? '',
    ];
}

lc_export_xlsx('inventory', $headers, $rows, $textCols);
