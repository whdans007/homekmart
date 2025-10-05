<?php
/**
 * index.php 디버그 버전 - 에러 출력 활성화
 */

// 모든 에러 표시
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// CORS
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');

echo "1. Script started\n";

try {
    echo "2. Loading config...\n";
    require_once __DIR__ . '/../config.php';
    echo "3. Config loaded\n";

    echo "4. Getting DB connection...\n";
    $pdo = getApiDbConnection();
    echo "5. DB connected\n";

    $store_id = 1;
    $page = 1;
    $limit = 5;
    $offset = 0;

    echo "6. Preparing count query...\n";
    $count_sql = "
        SELECT COUNT(DISTINCT p.id)
        FROM products p
        INNER JOIN inventory i ON p.id = i.product_id
        WHERE i.store_id = ?
    ";
    $count_stmt = $pdo->prepare($count_sql);
    echo "7. Executing count query...\n";
    $count_stmt->execute([$store_id]);
    $total = $count_stmt->fetchColumn();
    echo "8. Total products: $total\n";

    echo "9. Preparing products query...\n";
    $sql = "
        SELECT
            p.id,
            p.name_ko,
            p.name_en,
            p.sku,
            p.description,
            p.category_id,
            c.name_ko as category_name,
            p.brand_id,
            b.name as brand_name,
            i.selling_price,
            i.cost_price,
            i.quantity,
            p.image_url,
            p.is_active
        FROM products p
        INNER JOIN inventory i ON p.id = i.product_id
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        WHERE i.store_id = ?
        ORDER BY p.name_ko ASC
        LIMIT ? OFFSET ?
    ";

    echo "10. Executing products query...\n";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$store_id, $limit, $offset]);
    echo "11. Fetching results...\n";
    $products = $stmt->fetchAll();
    echo "12. Found " . count($products) . " products\n";

    echo "13. Formatting data...\n";
    foreach ($products as &$product) {
        $product['selling_price'] = floatval($product['selling_price']);
        $product['cost_price'] = floatval($product['cost_price']);
        $product['quantity'] = intval($product['quantity']);
        $product['is_active'] = (bool) $product['is_active'];
    }

    echo "14. Creating response...\n";
    $response = paginatedResponse($products, $total, $page, $limit);

    echo "15. Sending response...\n";
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo "\n\nERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString();
}
?>
