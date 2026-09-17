<?php
require_once __DIR__ . '/../lib/auth.php';
header('Content-Type: application/json; charset=utf-8');

// Design Ref: role-permission-management - 재고 현황은 물류센터 직원/관리자만 접근
lc_require_staff();

$product_id = (int)($_GET['product_id'] ?? 0);
if (!$product_id) {
    echo json_encode(['success' => false, 'message' => t('logistics.ajax_product_inbound_history.invalid_request')]);
    exit;
}

try {
    $conn = get_lc_db();

    // 상품 기본 정보
    $st = $conn->prepare(
        "SELECT name_en, name_ko, unit, capacity, pieces_per_box, barcode FROM lc_products WHERE id = ?"
    );
    $st->bind_param('i', $product_id);
    $st->execute();
    $product = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$product) {
        echo json_encode(['success' => false, 'message' => t('logistics.ajax_product_inbound_history.product_not_found')]);
        exit;
    }

    // 전체 lot (출고 완료 포함, 유통기한 짧은 순)
    $st = $conn->prepare(
        "SELECT i.id AS inventory_id, i.unit, i.lot_number, i.expiry_date, i.storage_location,
                i.quantity_in, i.quantity_out, i.quantity_remain,
                COALESCE(b.inbound_date, ib.inbound_date) AS inbound_date,
                COALESCE(b.id, ib.id) AS inbound_id,
                COALESCE(s.name, '-') AS supplier_name,
                ib.inbound_unit, ib.cost_price, ib.cost_price_pcs,
                DATEDIFF(i.expiry_date, CURDATE()) AS days_left
         FROM lc_inventory i
         JOIN lc_inbound ib ON i.inbound_id = ib.id
         LEFT JOIN lc_inbound_batches b ON ib.batch_id = b.id
         LEFT JOIN lc_suppliers s ON b.supplier_id = s.id
         WHERE i.product_id = ?
         ORDER BY i.expiry_date ASC, i.id ASC"
    );
    $st->bind_param('i', $product_id);
    $st->execute();
    $lots = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $conn->close();

    echo json_encode([
        'success' => true,
        'product' => $product,
        'lots'    => $lots,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => t('logistics.ajax_product_inbound_history.error', ['error' => $e->getMessage()])]);
}
