<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/xlsx_export.php';

lc_session_start();
lc_require_staff();

$search   = trim($_GET['search'] ?? '');
$cat_id   = (int)($_GET['cat'] ?? 0);
$brand_id = (int)($_GET['brand'] ?? 0);
$status   = ($_GET['status'] ?? '') === 'inactive' ? 'inactive' : '';

$conn = get_lc_db();

$conds = []; $params = []; $types = '';
if ($search) {
    $conds[] = "(p.name_en LIKE ? OR p.name_ko LIKE ? OR p.barcode_unit LIKE ? OR p.barcode_box LIKE ? OR p.barcode_logistics LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
    $types .= 'sssss';
}
if ($cat_id)   { $conds[] = "p.category_id = ?"; $params[] = $cat_id;   $types .= 'i'; }
if ($brand_id) { $conds[] = "p.brand_id = ?";    $params[] = $brand_id; $types .= 'i'; }
if ($status === 'inactive') { $conds[] = "p.is_active = 0"; }
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$sql = "SELECT p.*, b.name_en AS brand_name, c.name_en AS category_name
        FROM lc_products p
        LEFT JOIN lc_brands b ON p.brand_id = b.id
        LEFT JOIN lc_categories c ON p.category_id = c.id
        $where ORDER BY p.is_active DESC, p.name_en ASC";
$st = $conn->prepare($sql);
if ($params) { $st->bind_param($types, ...$params); }
$st->execute();
$products = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
$conn->close();

$headers = [
    t('logistics.export_products.category'), t('logistics.export_products.brand'), t('logistics.export_products.product_name_en'), t('logistics.export_products.product_name_ko'), t('logistics.export_products.capacity'),
    t('logistics.export_products.unit'), t('logistics.export_products.units_per_box'), t('logistics.export_products.unit_barcode'), t('logistics.export_products.box_barcode'), t('logistics.export_products.logistics_code'),
    t('logistics.export_products.min_stock'), t('logistics.export_products.expiry_required'), t('logistics.export_products.status'),
];
$textCols = [8, 9, 10]; // Unit Barcode, Box Barcode, Logistics Code

$rows = [];
foreach ($products as $p) {
    $rows[] = [
        $p['category_name'] ?? '',
        $p['brand_name'] ?? '',
        $p['name_en'],
        $p['name_ko'] ?? '',
        $p['capacity'] ?? '',
        $p['unit'],
        (string)$p['pieces_per_box'],
        $p['barcode_unit'] ?? '',
        $p['barcode_box'] ?? '',
        $p['barcode_logistics'] ?? '',
        (string)$p['min_stock'],
        $p['requires_expiry'] ? t('logistics.export_products.required') : t('logistics.export_products.optional'),
        $p['is_active'] ? t('logistics.export_products.active') : t('logistics.export_products.inactive'),
    ];
}

lc_export_xlsx('products', $headers, $rows, $textCols);
