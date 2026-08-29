<?php
// Design Ref: §7.1 확장 — 검색어 기준 물류센터 재고 조회
require_once dirname(__DIR__) . '/lib/auth.php';
ord_require_manager();
require_once dirname(__DIR__, 2) . '/logistics/config/db.php';
require_once dirname(__DIR__, 2) . '/logistics/lib/unit_helper.php';
header('Content-Type: application/json; charset=utf-8');

$keyword = trim($_POST['keyword'] ?? '');
$limit   = 50;

if ($keyword === '') {
    echo json_encode(['success' => true, 'data' => []]);
    exit;
}

try {
    $conn = get_lc_db();
    $like = '%' . $keyword . '%';

    $sql = "
        SELECT p.id AS product_id, p.name_en, p.name_ko,
               b.name_en AS brand_name, b.name_ko AS brand_name_ko,
               COALESCE(p.barcode_unit, p.barcode_box, p.barcode_logistics) AS barcode,
               SUM(CASE WHEN i.unit = 'BOX'  THEN i.quantity_remain ELSE 0 END) AS box_stock,
               SUM(CASE WHEN i.unit = 'PACK' THEN i.quantity_remain ELSE 0 END) AS pack_stock,
               SUM(CASE WHEN i.unit = 'PCS'  THEN i.quantity_remain ELSE 0 END) AS pcs_stock,
               MIN(i.expiry_date) AS earliest_expiry,
               DATEDIFF(MIN(i.expiry_date), CURDATE()) AS days_left
        FROM lc_inventory i
        JOIN lc_products p ON i.product_id = p.id
        LEFT JOIN lc_brands b ON p.brand_id = b.id
        WHERE i.quantity_remain > 0
          AND (p.name_en LIKE ? OR p.name_ko LIKE ? OR p.barcode_unit LIKE ? OR p.barcode_box LIKE ? OR p.barcode_logistics LIKE ?)
        GROUP BY p.id
        ORDER BY p.name_en ASC
        LIMIT ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sssssi', $like, $like, $like, $like, $like, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();

    $data = array_map(function ($r) {
        return [
            'product_id'      => (int)$r['product_id'],
            'product_name'    => $r['name_en'] . ($r['name_ko'] ? ' (' . $r['name_ko'] . ')' : ''),
            'brand_name'      => $r['brand_name'] ?: ($r['brand_name_ko'] ?: ''),
            'barcode'         => $r['barcode'],
            'stock_display'   => lc_format_stock([
                LC_UNIT_BOX  => (int)$r['box_stock'],
                LC_UNIT_PACK => (int)$r['pack_stock'],
                LC_UNIT_PCS  => (int)$r['pcs_stock'],
            ]),
            'earliest_expiry' => $r['earliest_expiry'],
            'days_left'       => $r['days_left'] !== null ? (int)$r['days_left'] : null,
        ];
    }, $rows);

    echo json_encode(['success' => true, 'data' => $data]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
