<?php
/**
 * 상품 상세 조회 API
 * GET /api/products/detail?id={product_id}&store_id={store_id}
 */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiError(405, 'Method not allowed');
}

if (!isset($_GET['id']) || !isset($_GET['store_id'])) {
    apiError(400, 'Product ID and store ID are required');
}

$product_id = intval($_GET['id']);
$store_id = intval($_GET['store_id']);

try {
    $pdo = getApiDbConnection();

    $sql = "
        SELECT
            p.id,
            p.name_ko,
            p.name_en,
            p.sku,
            p.description,
            p.category_id,
            c.name as category_name,
            p.brand_id,
            b.name_ko as brand_name,
            i.selling_price,
            i.cost_price,
            i.quantity,
            p.image_url,
            p.is_active,
            p.created_at,
            p.updated_at
        FROM products p
        INNER JOIN inventory i ON p.id = i.product_id AND i.store_id = ?
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        WHERE p.id = ?
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$store_id, $product_id]);
    $product = $stmt->fetch();

    if (!$product) {
        apiError(404, 'Product not found');
    }

    // 타입 변환
    $product['selling_price'] = floatval($product['selling_price']);
    $product['cost_price'] = floatval($product['cost_price']);
    $product['quantity'] = intval($product['quantity']);
    $product['is_active'] = (bool) $product['is_active'];

    apiSuccess($product);

} catch (PDOException $e) {
    error_log("Product detail fetch error: " . $e->getMessage());
    apiError(500, 'Failed to fetch product details');
}
?>
