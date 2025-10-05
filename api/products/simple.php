<?php
/**
 * 간단한 상품 목록 조회 (최대 호환성)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');

try {
    require_once __DIR__ . '/../../config/db_config.php';
    $conn = get_db_connection();

    $store_id = isset($_GET['store_id']) ? intval($_GET['store_id']) : 1;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 5;

    // 간단한 쿼리 - bind 없이
    $sql = "
        SELECT
            p.id,
            p.name,
            p.barcode,
            i.selling_price,
            i.quantity
        FROM products p
        INNER JOIN inventory i ON p.id = i.product_id
        WHERE i.store_id = " . $store_id . "
        LIMIT " . $limit;

    $result = $conn->query($sql);

    if (!$result) {
        throw new Exception($conn->error);
    }

    $products = [];
    while ($row = $result->fetch_assoc()) {
        $products[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'barcode' => $row['barcode'],
            'selling_price' => (float)$row['selling_price'],
            'quantity' => (int)$row['quantity']
        ];
    }

    echo json_encode([
        'success' => true,
        'count' => count($products),
        'data' => $products
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}
?>
