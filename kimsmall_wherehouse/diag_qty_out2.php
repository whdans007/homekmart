<?php
require_once __DIR__ . '/config/db.php';

// 웹 접근 시 KIMS MALL WHEREHOUSE(킴스몰 창고) 소속/슈퍼관리자만 허용 (CLI 실행은 예외)
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/lib/auth.php';
    kw_require_staff();
}

header('Content-Type: text/plain; charset=utf-8');

$conn = get_lc_db();

$st = $conn->prepare(
    "SELECT i.id AS inventory_id, i.unit, i.lot_number, i.expiry_date, i.storage_location,
            i.quantity_in, i.quantity_out, i.quantity_remain,
            COALESCE(b.inbound_date, ib.inbound_date) AS inbound_date,
            COALESCE(b.id, ib.id) AS inbound_id,
            COALESCE(s.name, '-') AS supplier_name,
            ib.inbound_unit, ib.cost_price, ib.cost_price_pcs,
            DATEDIFF(i.expiry_date, CURDATE()) AS days_left
     FROM kw_inventory i
     JOIN kw_inbound ib ON i.inbound_id = ib.id
     LEFT JOIN kw_inbound_batches b ON ib.batch_id = b.id
     LEFT JOIN kw_suppliers s ON b.supplier_id = s.id
     WHERE i.product_id = ?
     ORDER BY i.expiry_date ASC, i.id ASC"
);
$pid = 101;
$st->bind_param('i', $pid);
$st->execute();
print_r($st->get_result()->fetch_all(MYSQLI_ASSOC));
$st->close();
$conn->close();
