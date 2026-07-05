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

    // 상품명(한/영) 또는 바코드 일치 기준으로 전 점포 매입 이력 조회
    $sql = "
        SELECT p.purchase_date, s.name AS store_name,
               pr.name_ko, pr.name_en, pr.sku,
               pi.unit_price, pi.purchase_type, pi.quantity
        FROM purchase_items pi
        JOIN purchases p ON pi.purchase_id = p.purchase_id
        JOIN products pr ON pi.product_id = pr.id
        LEFT JOIN stores s ON p.store_id = s.id
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
    $conn->close();

    $data = array_map(function ($r) {
        return [
            'purchase_date' => $r['purchase_date'],
            'store_name'    => $r['store_name'] ?? '미지정',
            'product_name'  => $r['name_ko'] ?: $r['name_en'],
            'unit_price'    => (float)$r['unit_price'],
            'purchase_type' => $r['purchase_type'], // 'box' | 'piece'
            'quantity'      => (int)$r['quantity'],
        ];
    }, $rows);

    echo json_encode(['success' => true, 'data' => $data]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
