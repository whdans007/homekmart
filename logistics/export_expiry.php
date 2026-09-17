<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/xlsx_export.php';

lc_session_start();
// Design Ref: role-permission-management - 물류센터 대시보드는 물류센터 직원/관리자만 접근
lc_require_staff();

$conn = get_lc_db();

// index.php 대시보드의 "Expiry Approaching/Expired (within D-90)" 섹션과 동일 조건.
// 화면은 LIMIT 50이지만 다운로드는 전체 목록을 내려준다.
$expiry_sql = "SELECT CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS name,
                      p.capacity,
                      COALESCE(NULLIF(p.barcode_unit,''), NULLIF(p.barcode_box,''), NULLIF(p.barcode_logistics,'')) AS barcode,
                      i.lot_number, i.expiry_date,
                      SUM(i.quantity_remain) AS stock,
                      DATEDIFF(i.expiry_date, CURDATE()) AS days_left
               FROM lc_inventory i
               JOIN lc_products p ON i.product_id = p.id
               JOIN lc_inbound ib ON i.inbound_id = ib.id
               WHERE i.expiry_date IS NOT NULL
                 AND i.expiry_date > '1971-01-01'
                 AND i.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
                 AND i.quantity_remain > 0
               GROUP BY p.id, i.lot_number, i.expiry_date
               ORDER BY i.expiry_date ASC";
$list = $conn->query($expiry_sql)->fetch_all(MYSQLI_ASSOC);
$conn->close();

$headers = [t('logistics.export_expiry.product_name'), t('logistics.export_expiry.capacity'), t('logistics.export_expiry.barcode'), t('logistics.export_expiry.lot_number'), t('logistics.export_expiry.expiry_date'), t('logistics.export_expiry.stock'), t('logistics.export_expiry.status')];
$textCols = [3, 4]; // Barcode, Lot Number

$rows = [];
foreach ($list as $row) {
    $d = (int)$row['days_left'];
    if ($d < 0)       { $status = t('logistics.export_expiry.expired'); }
    elseif ($d === 0) { $status = t('logistics.export_expiry.d_day', ['days' => 0]); }
    else              { $status = t('logistics.export_expiry.d_day', ['days' => $d]); }

    $rows[] = [
        $row['name'],
        $row['capacity'] ?? '',
        $row['barcode'] ?? '',
        $row['lot_number'] ?? '',
        date('Y-m-d', strtotime($row['expiry_date'])),
        (string)$row['stock'],
        $status,
    ];
}

lc_export_xlsx('expiry_approaching', $headers, $rows, $textCols);
