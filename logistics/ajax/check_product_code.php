<?php
require_once __DIR__ . '/../lib/auth.php';
header('Content-Type: application/json; charset=utf-8');

lc_require_staff();

$value      = trim($_GET['value'] ?? '');
$exclude_id = (int)($_GET['exclude_id'] ?? 0);

if ($value === '') {
    echo json_encode(['success' => true, 'duplicate' => false]);
    exit;
}

try {
    $conn = get_lc_db();

    $sql = "SELECT id, name_en, name_ko, barcode_unit, barcode_box, barcode_logistics
            FROM lc_products
            WHERE (barcode_unit = ? OR barcode_box = ? OR barcode_logistics = ?)";
    $params = [$value, $value, $value];
    $types  = 'sss';
    if ($exclude_id) {
        $sql .= " AND id != ?";
        $params[] = $exclude_id;
        $types .= 'i';
    }
    $sql .= " LIMIT 1";

    $st = $conn->prepare($sql);
    $st->bind_param($types, ...$params);
    $st->execute();
    $product = $st->get_result()->fetch_assoc();
    $st->close();
    $conn->close();

    if (!$product) {
        echo json_encode(['success' => true, 'duplicate' => false]);
        exit;
    }

    $matched_fields = [];
    if ($product['barcode_unit'] === $value)      $matched_fields[] = 'Barcode';
    if ($product['barcode_box'] === $value)       $matched_fields[] = 'Box Code';
    if ($product['barcode_logistics'] === $value) $matched_fields[] = 'Logistics Code';

    echo json_encode([
        'success'   => true,
        'duplicate' => true,
        'product'   => [
            'id'      => $product['id'],
            'name'    => $product['name_en'] . ($product['name_ko'] ? ' (' . $product['name_ko'] . ')' : ''),
            'fields'  => $matched_fields,
        ],
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]);
}
