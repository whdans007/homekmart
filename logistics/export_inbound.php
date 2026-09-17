<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/xlsx_export.php';

lc_session_start();
lc_require_staff();

$search_supplier = trim($_GET['supplier'] ?? '');
$search_date     = trim($_GET['date']     ?? '');

$conn = get_lc_db();

$col_check = $conn->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lc_inbound_batches' AND COLUMN_NAME = 'is_confirmed'");
$has_confirmed = (bool)$col_check->fetch_row()[0];

$conds = []; $params = []; $types = '';
if ($search_supplier) {
    $conds[] = "s.name LIKE ?";
    $params[] = "%$search_supplier%"; $types .= 's';
}
if ($search_date) {
    $conds[] = "b.inbound_date = ?";
    $params[] = $search_date; $types .= 's';
}
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$confirmed_col = $has_confirmed ? 'b.is_confirmed,' : '0 AS is_confirmed,';
$sql = "SELECT b.id, b.inbound_date, b.created_at, b.notes, $confirmed_col
               s.name AS supplier_name,
               u.full_name AS created_by_name,
               COUNT(i.id) AS item_count,
               COALESCE(SUM(i.quantity * i.cost_price), 0) AS total_amount
        FROM lc_inbound_batches b
        LEFT JOIN lc_suppliers s ON b.supplier_id = s.id
        LEFT JOIN users u ON b.created_by = u.id
        LEFT JOIN lc_inbound i ON i.batch_id = b.id
        $where
        GROUP BY b.id
        ORDER BY b.inbound_date DESC, b.id DESC";
$st = $conn->prepare($sql);
if ($params) { $st->bind_param($types, ...$params); }
$st->execute();
$list = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
$conn->close();

$headers = [
    t('logistics.export_inbound.inbound_date'), t('logistics.export_inbound.registered_at'), t('logistics.export_inbound.supplier'), t('logistics.export_inbound.item_count'), t('logistics.export_inbound.total_amount'),
    t('logistics.export_inbound.registered_by'), t('logistics.export_inbound.status'), t('logistics.export_inbound.notes'),
];

$rows = [];
foreach ($list as $row) {
    $rows[] = [
        $row['inbound_date'],
        $row['created_at'],
        $row['supplier_name'] ?? '-',
        (string)$row['item_count'],
        number_format((float)$row['total_amount'], 2, '.', ''),
        $row['created_by_name'] ?? '-',
        $row['is_confirmed'] ? t('logistics.export_inbound.locked') : t('logistics.export_inbound.editable'),
        $row['notes'] ?? '',
    ];
}

lc_export_xlsx('inbound', $headers, $rows);
