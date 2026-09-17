<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../config/db.php';

// Design Ref: role-permission-management - 재고 현황은 물류센터 직원/관리자만 접근
lc_require_staff();

$days = max(1, min(90, (int)($_GET['days'] ?? 30)));

try {
    $conn = get_lc_db();
    $rows = $conn->query(
        "SELECT CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS name, i.lot_number, i.expiry_date,
                SUM(i.quantity_remain) AS stock,
                DATEDIFF(i.expiry_date, CURDATE()) AS days_left
         FROM lc_inventory i
         JOIN lc_products p ON i.product_id = p.id
         WHERE i.expiry_date IS NOT NULL
           AND i.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL $days DAY)
           AND i.quantity_remain > 0
         GROUP BY p.id, i.lot_number, i.expiry_date
         ORDER BY i.expiry_date ASC
         LIMIT 100"
    )->fetch_all(MYSQLI_ASSOC);
    $conn->close();
    echo json_encode(['success' => true, 'data' => $rows, 'days' => $days]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => t('logistics.ajax_get_expiry_alerts.error', ['error' => $e->getMessage()])]);
}
