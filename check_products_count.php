<?php
require_once __DIR__ . '/config/db_config.php';

try {
    $conn = get_db_connection();
    
    // 전체 상품 수 확인
    $result = $conn->query('SELECT COUNT(*) as total FROM products');
    $row = $result->fetch_assoc();
    echo "Total products in database: " . $row['total'] . PHP_EOL;
    
    // 활성 상품 수 확인
    $result = $conn->query('SELECT COUNT(*) as active FROM products WHERE is_active = 1');
    $row = $result->fetch_assoc();
    echo "Active products: " . $row['active'] . PHP_EOL;
    
    // 최근 생성된 상품 몇 개 확인
    $result = $conn->query('SELECT id, sku, name_ko, created_at FROM products ORDER BY created_at DESC LIMIT 5');
    echo PHP_EOL . "Recent products:" . PHP_EOL;
    while ($product = $result->fetch_assoc()) {
        echo "- ID: {$product['id']}, SKU: {$product['sku']}, Name: {$product['name_ko']}, Created: {$product['created_at']}" . PHP_EOL;
    }
    
    $conn->close();
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
?>