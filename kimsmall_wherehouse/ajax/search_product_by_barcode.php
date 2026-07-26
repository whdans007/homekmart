<?php
require_once __DIR__ . '/../lib/auth.php';
header('Content-Type: application/json; charset=utf-8');

kw_require_staff();

$q = trim($_GET['barcode'] ?? '');
if ($q === '') {
    echo json_encode(['success' => false, 'message' => 'Please enter a barcode or product name.']);
    exit;
}

try {
    $conn = get_lc_db();

    // 바코드: 부분 일치(LIKE) 검색 — 바코드 일부만 입력해도 매칭 (예: 8809925 → 8809925123456)
    //   정렬 우선순위: ① 바코드 정확 일치  ② 바코드 부분 일치  ③ 이름만 일치
    // 이름: 한글/영문 LIKE 부분 검색
    $like = '%' . $q . '%';
    // 단위 자동 결정용: 단위별 재고 보유량 동봉 (BOX 재고만 → BOX, PCS 재고만 → PCS)
    $st = $conn->prepare(
        "SELECT p.id, p.name_en, p.name_ko, p.unit, p.requires_expiry, p.capacity,
                p.pieces_per_box,
                p.barcode_unit, p.barcode_box, p.barcode_logistics,
                b.name_en AS brand_en, b.name_ko AS brand_ko,
                (SELECT COALESCE(SUM(quantity_remain), 0) FROM kw_inventory
                  WHERE product_id = p.id AND unit = 'BOX') AS box_stock,
                (SELECT COALESCE(SUM(quantity_remain), 0) FROM kw_inventory
                  WHERE product_id = p.id AND unit = 'PACK') AS pack_stock,
                (SELECT COALESCE(SUM(quantity_remain), 0) FROM kw_inventory
                  WHERE product_id = p.id AND unit = 'PCS') AS pcs_stock
         FROM kw_products p
         LEFT JOIN kw_brands b ON p.brand_id = b.id
         WHERE p.is_active = 1
           AND (p.barcode_unit LIKE ? OR p.barcode_box LIKE ? OR p.barcode_logistics LIKE ?
                OR p.name_en LIKE ? OR p.name_ko LIKE ?)
         ORDER BY
             CASE WHEN p.barcode_unit = ? OR p.barcode_box = ? OR p.barcode_logistics = ? THEN 0
                  WHEN p.barcode_unit LIKE ? OR p.barcode_box LIKE ? OR p.barcode_logistics LIKE ? THEN 1
                  ELSE 2 END,
             p.name_en ASC
         LIMIT 20"
    );
    $st->bind_param('sssssssssss',
        $like, $like, $like, $like, $like,   // WHERE: 바코드 3 + 이름 2
        $q, $q, $q,                          // ORDER: 바코드 정확 일치
        $like, $like, $like);                // ORDER: 바코드 부분 일치
    $st->execute();
    $products = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    $conn->close();

    if (empty($products)) {
        echo json_encode(['success' => false, 'message' => "No products match '{$q}'."]);
    } else {
        // 스캔 시 출고 단위 자동 결정 신호: 보유 단위가 하나뿐이면 그 단위, 공존/무재고면 null(→ 프론트 토글 폴백)
        // Design Ref: pack-unit §5 — BOX/PACK/PCS 중 재고 보유 단위가 정확히 하나면 그 단위 신호
        foreach ($products as &$p) {
            $held = [];
            if ((int)$p['box_stock']  > 0) $held[] = 'BOX';
            if ((int)$p['pack_stock'] > 0) $held[] = 'PACK';
            if ((int)$p['pcs_stock']  > 0) $held[] = 'PCS';
            $p['stock_unit'] = (count($held) === 1) ? $held[0] : null;
        }
        unset($p);
        echo json_encode(['success' => true, 'products' => $products]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]);
}
