<?php
require_once __DIR__ . '/../lib/auth.php';
header('Content-Type: application/json; charset=utf-8');

lc_require_staff();

$query = trim((string)($_GET['q'] ?? ''));
if ($query === '') {
    echo json_encode(['success' => true, 'products' => []]);
    exit;
}

try {
    $conn = get_lc_db();
    $like = '%' . $query . '%';
    $sql = "SELECT i.id AS inventory_id,
                   p.id AS product_id,
                   p.name_en,
                   p.name_ko,
                   p.capacity,
                   p.unit AS product_unit,
                   COALESCE(p.barcode_unit, p.barcode_box, p.barcode_logistics) AS barcode,
                   b.name_en AS brand_name_en,
                   b.name_ko AS brand_name_ko,
                   c.name_en AS category_name_en,
                   c.name_ko AS category_name_ko,
                   i.lot_number,
                   i.expiry_date,
                   i.quantity_remain,
                   i.unit,
                   ib.cost_price AS base_price
            FROM lc_inventory i
            JOIN lc_products p ON p.id = i.product_id
            LEFT JOIN lc_brands b ON b.id = p.brand_id
            LEFT JOIN lc_categories c ON c.id = p.category_id
            LEFT JOIN lc_inbound ib ON ib.id = i.inbound_id
            LEFT JOIN lc_lot_promotions lp ON lp.inventory_id = i.id AND lp.status = 'active'
            WHERE p.is_active = 1
              AND i.quantity_remain > 0
              AND lp.id IS NULL
              AND (p.name_en LIKE ? OR p.name_ko LIKE ?
                   OR p.barcode_unit LIKE ? OR p.barcode_box LIKE ? OR p.barcode_logistics LIKE ?
                   OR i.lot_number LIKE ?)
            ORDER BY p.name_en ASC, i.expiry_date ASC, i.id ASC
            LIMIT 50";
    $st = $conn->prepare($sql);
    $st->bind_param('ssssss', $like, $like, $like, $like, $like, $like);
    $st->execute();
    $products = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    $conn->close();

    echo json_encode(['success' => true, 'products' => $products], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => t('logistics.promo_products.db_error')]);
}
