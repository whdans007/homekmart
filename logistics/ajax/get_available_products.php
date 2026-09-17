<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../config/db.php';

// Design Ref: role-permission-management - 재고 현황은 물류센터 직원/관리자만 접근
lc_require_staff();

try {
    $conn = get_lc_db();
    $rows = $conn->query(
        "SELECT p.id, CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS name, p.unit, 0 AS selling_price,
                SUM(i.quantity_remain) AS stock
         FROM lc_inventory i
         JOIN lc_products p ON i.product_id = p.id
         WHERE i.quantity_remain > 0 AND p.is_active = 1
         GROUP BY p.id
         ORDER BY p.name_en ASC"
    )->fetch_all(MYSQLI_ASSOC);
    $conn->close();
    echo json_encode(['success' => true, 'data' => $rows]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => t('logistics.ajax_get_available_products.error', ['error' => $e->getMessage()])]);
}
