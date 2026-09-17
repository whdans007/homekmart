<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/xlsx_export.php';
require_once __DIR__ . '/lib/inbound_helper.php';

lc_session_start();
lc_require_staff();

$filters = [
    'search' => trim($_GET['search'] ?? ''),
    'date_from' => trim($_GET['date_from'] ?? ''),
    'date_to' => trim($_GET['date_to'] ?? ''),
];

$result = getAllFilteredInboundItems($filters);
$items  = $result['items'] ?? [];

$headers = [
    t('logistics.export_inbound_items.category'), t('logistics.export_inbound_items.brand'), t('logistics.export_inbound_items.product_name'), t('logistics.export_inbound_items.capacity'), t('logistics.export_inbound_items.unit'), t('logistics.export_inbound_items.pkg'), t('logistics.export_inbound_items.unit_barcode'),
    t('logistics.export_inbound_items.supplier'), t('logistics.export_inbound_items.cost_price'), t('logistics.export_inbound_items.quantity'), t('logistics.export_inbound_items.inbound_unit'), t('logistics.export_inbound_items.inbound_date'),
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

lc_export_xlsx('inbound_items', $headers, $rows, [7]);
