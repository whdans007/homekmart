<?php
/**
 * Inventory 테이블 확인
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');

try {
    require_once __DIR__ . '/../config/db_config.php';
    $conn = get_db_connection();

    $debug = [];

    // 1. inventory 테이블 존재 확인
    $result = $conn->query("SHOW TABLES LIKE 'inventory'");
    $debug['inventory_exists'] = ($result->num_rows > 0);

    // 2. inventory 테이블 구조 확인
    if ($debug['inventory_exists']) {
        $result = $conn->query("DESCRIBE inventory");
        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
        $debug['inventory_columns'] = $columns;

        // 3. inventory 테이블 데이터 개수
        $result = $conn->query("SELECT COUNT(*) as count FROM inventory");
        $debug['inventory_count'] = $result->fetch_assoc()['count'];

        // 4. store_id별 개수
        $result = $conn->query("SELECT store_id, COUNT(*) as count FROM inventory GROUP BY store_id");
        $stores = [];
        while ($row = $result->fetch_assoc()) {
            $stores[] = $row;
        }
        $debug['inventory_by_store'] = $stores;

        // 5. 샘플 데이터 (5개)
        $result = $conn->query("SELECT * FROM inventory LIMIT 5");
        $samples = [];
        while ($row = $result->fetch_assoc()) {
            $samples[] = $row;
        }
        $debug['inventory_samples'] = $samples;
    }

    // 6. products 테이블과 JOIN 테스트
    $result = $conn->query("
        SELECT COUNT(*) as count
        FROM products p
        INNER JOIN inventory i ON p.id = i.product_id
        WHERE i.store_id = 1
    ");
    $debug['products_with_inventory_store1'] = $result->fetch_assoc()['count'];

    echo json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}
?>
