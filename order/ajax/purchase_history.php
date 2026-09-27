<?php
// Design Ref: §5.1 확장 — 검색어 기준 전 점포 입고(매입) 히스토리 조회
require_once dirname(__DIR__) . '/lib/auth.php';
ord_require_manager();
header('Content-Type: application/json; charset=utf-8');

$keyword = trim($_POST['keyword'] ?? '');
$limit   = 50;

if ($keyword === '') {
    echo json_encode(['success' => true, 'data' => []]);
    exit;
}

try {
    $conn = get_ord_db();

    // deleted_at(소프트 삭제) 컬럼 존재 여부 확인 (purchase_management.php 와 동일 패턴)
    $has_deleted_at = false;
    $col_chk = $conn->query("SHOW COLUMNS FROM purchases LIKE 'deleted_at'");
    if ($col_chk && $col_chk->num_rows > 0) $has_deleted_at = true;

    $deleted_cond = $has_deleted_at ? "AND p.deleted_at IS NULL" : "";

    // 상품명(한/영) 또는 바코드 일치 기준으로 전 점포 매입 이력 조회 (거래처/업체명 기준 표시)
    $sql = "
        SELECT p.purchase_date, sup.name AS vendor_name,
               pr.id, pr.name_ko, pr.name_en, pr.sku, pr.pieces_per_box,
               pi.unit_price, pi.purchase_type, pi.quantity
        FROM purchase_items pi
        JOIN purchases p ON pi.purchase_id = p.purchase_id
        JOIN products pr ON pi.product_id = pr.id
        LEFT JOIN suppliers sup ON p.supplier_id = sup.id
        WHERE (pr.name_ko LIKE ? OR pr.name_en LIKE ? OR pr.sku = ?)
        {$deleted_cond}
        ORDER BY p.purchase_date DESC, p.purchase_id DESC
        LIMIT ?
    ";
    $like = '%' . $keyword . '%';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sssi', $like, $like, $keyword, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // 현재 재고 조회 (inventory 테이블 존재 시에만)
    $stock_map = [];
    try {
        $table_check = $conn->query("SHOW TABLES LIKE 'inventory'");
        if ($table_check && $table_check->num_rows > 0) {
            $stock_sql = "
                SELECT product_id, SUM(quantity) as total_stock
                FROM inventory
                GROUP BY product_id
            ";
            $stock_stmt = $conn->prepare($stock_sql);
            if ($stock_stmt) {
                $stock_stmt->execute();
                $stock_rows = $stock_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stock_stmt->close();
                foreach ($stock_rows as $sr) {
                    $stock_map[(int)$sr['product_id']] = (int)$sr['total_stock'];
                }
            }
        }
    } catch (Exception $e) {
        // inventory 조회 실패해도 계속 진행
        error_log('Stock query error: ' . $e->getMessage());
    }
    $conn->close();

    $data = array_map(function ($r) use ($stock_map) {
        $ppb = (int)($r['pieces_per_box'] ?? 1);
        $alt_price = null;
        if ($ppb > 0) {
            $alt_price = $r['purchase_type'] === 'box'
                ? round((float)$r['unit_price'] / $ppb, 2)
                : round((float)$r['unit_price'] * $ppb, 2);
        }

        return [
            'purchase_date' => $r['purchase_date'],
            'sku'           => $r['sku'] ?? '',
            'vendor_name'   => $r['vendor_name'] ?? '미지정',
            'product_name'  => $r['name_ko'] ?: $r['name_en'],
            'unit_price'    => (float)$r['unit_price'],
            'purchase_type' => $r['purchase_type'], // 'box' | 'piece'
            'quantity'      => (int)$r['quantity'],
            'alt_price'     => $alt_price,
            'total_stock'   => $stock_map[(int)$r['id']] ?? 0,
        ];
    }, $rows);

    echo json_encode(['success' => true, 'data' => $data]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
