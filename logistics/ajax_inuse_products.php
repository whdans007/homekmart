<?php
// "In Use" 배지 클릭 시 — 해당 브랜드/카테고리를 사용 중인 상품 목록을 JSON으로 반환
// brand_manage.php / category_manage.php 의 In Use 모달에서 호출
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/config/db.php';

lc_require_staff();

header('Content-Type: application/json; charset=utf-8');

$type = ($_GET['type'] ?? '') === 'category' ? 'category' : 'brand';
$id   = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid id']);
    exit;
}

$column = $type === 'category' ? 'category_id' : 'brand_id';

try {
    $conn = get_lc_db();
    $sql = "SELECT id, name_en, name_ko, barcode_unit, is_active
            FROM lc_products
            WHERE {$column} = ?
            ORDER BY is_active DESC, name_en ASC";
    $st = $conn->prepare($sql);
    $st->bind_param('i', $id);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    $conn->close();

    echo json_encode([
        'ok'       => true,
        'count'    => count($rows),
        'products' => $rows,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}
