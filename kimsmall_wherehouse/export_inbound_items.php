<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/xlsx_export.php';
require_once __DIR__ . '/lib/inbound_helper.php';

kw_session_start();
kw_require_staff();

$filters = [
    'search' => trim($_GET['search'] ?? ''),
    'date_from' => trim($_GET['date_from'] ?? ''),
    'date_to' => trim($_GET['date_to'] ?? ''),
];

$result = getAllFilteredInboundItems($filters);
$items  = $result['items'] ?? [];

$headers = [
    'Category', 'Brand', 'Product Name', 'Capacity', 'Unit', 'PKG', 'Unit Barcode',
    'Supplier', 'Cost Price', 'Qty', 'Inbound Unit', 'Inbound Date',
];

$rows = [];
foreach ($items as $row) {
    $rows[] = [
        $row['category_name'] ?: '-',
        $row['brand_name'] ?: '-',
        $row['product_name'] ?: '-',
        $row['capacity'] ?: '-',
        $row['product_unit'] ?: '-',
        (string)(int)($row['pieces_per_box'] ?? 1),
        $row['barcode'] ?: '-',
        $row['supplier_name'] ?? '-',
        number_format((float)$row['cost_price'], 2, '.', ''),
        (string)(int)$row['quantity'],
        $row['inbound_unit'] ?? 'PCS',
        $row['inbound_date'],
    ];
}

kw_export_xlsx('inbound_items', $headers, $rows, [7]);
