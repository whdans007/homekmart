<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/xlsx_export.php';

kw_session_start();
// Design Ref: role-permission-management - 재고 현황은 KIM'S MALL 창고 직원/관리자만 접근
kw_require_staff();

$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all'; // all | expiring | low | out

$conn = get_lc_db();

if ($filter === 'out') {
    // 재고 0 상품 — kw_inventory에 lot이 아예 없는 상품도 노출되도록 LEFT JOIN
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
                   p.unit, p.min_stock,
                   COALESCE(p.barcode_unit, p.barcode_box, p.barcode_logistics) AS barcode,
                   0 AS total_stock, 0 AS lot_count, NULL AS earliest_expiry, NULL AS days_left
            FROM kw_products p
            LEFT JOIN kw_inventory i ON i.product_id = p.id AND i.quantity_remain > 0
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
                   p.unit, p.min_stock,
                   COALESCE(p.barcode_unit, p.barcode_box, p.barcode_logistics) AS barcode,
                   SUM(i.quantity_remain) AS total_stock,
                   COUNT(i.id)            AS lot_count,
                   MIN(i.expiry_date)     AS earliest_expiry,
                   DATEDIFF(MIN(i.expiry_date), CURDATE()) AS days_left
            FROM kw_inventory i
            JOIN kw_products p ON i.product_id = p.id
            JOIN kw_inbound ib ON i.inbound_id = ib.id
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
    'Product Name', 'Barcode', 'Unit', 'Earliest Expiry', 'Days Left',
    'LOT Count', 'Current Stock', 'Min Stock', 'Status',
];
$textCols = [2]; // Barcode

$rows = [];
foreach ($list as $row) {
    $isOut = $row['total_stock'] <= 0;
    $isLow = !$isOut && $row['min_stock'] > 0 && $row['total_stock'] <= $row['min_stock'];
    $rows[] = [
        $row['product_name'],
        $row['barcode'] ?? '',
        $row['unit'],
        $row['earliest_expiry'] ?? '',
        $row['earliest_expiry'] !== null ? (string)$row['days_left'] : '',
        (string)$row['lot_count'],
        (string)$row['total_stock'],
        (string)$row['min_stock'],
        $isOut ? 'Out of Stock' : ($isLow ? 'Low Stock' : 'Normal'),
    ];
}

kw_export_xlsx('inventory', $headers, $rows, $textCols);
