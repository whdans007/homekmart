<?php
require_once __DIR__ . '/../lib/auth.php';
header('Content-Type: application/json; charset=utf-8');

lc_require_staff();

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode(['success' => false, 'message' => t('logistics.ajax_search_shop_product.query_required')]);
    exit;
}

try {
    $conn = get_lc_db();

    $like = '%' . $q . '%';
    $st = $conn->prepare(
        "SELECT sku, name_en, name_ko, pieces_per_box
         FROM products
         WHERE is_active = 1
           AND (sku = ? OR name_en LIKE ? OR name_ko LIKE ?)
         ORDER BY
             CASE WHEN sku = ? THEN 0 ELSE 1 END,
             name_en ASC
         LIMIT 20"
    );
    $st->bind_param('ssss', $q, $like, $like, $q);
    $st->execute();
    $products = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    $conn->close();

    if (empty($products)) {
        echo json_encode(['success' => false, 'message' => t('logistics.ajax_search_shop_product.no_match', ['query' => $q])]);
    } else {
        echo json_encode(['success' => true, 'products' => $products]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => t('logistics.ajax_search_shop_product.db_error', ['error' => $e->getMessage()])]);
}
