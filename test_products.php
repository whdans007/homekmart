<?php
require_once __DIR__ . '/config/db_config.php';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 전체 상품 개수 확인
    $count_stmt = $pdo->query("SELECT COUNT(*) FROM products WHERE is_active = 1");
    $total_products = $count_stmt->fetchColumn();
    
    echo "활성 상품 총 개수: $total_products<br><br>";
    
    // 처음 10개 상품 샘플
    $stmt = $pdo->query("
        SELECT p.id, p.sku, p.name_ko, p.name_en, b.name as brand_name 
        FROM products p 
        LEFT JOIN brands b ON p.brand_id = b.id 
        WHERE p.is_active = 1 
        LIMIT 10
    ");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "첫 10개 상품 샘플:<br>";
    foreach ($products as $product) {
        echo "ID: {$product['id']}, SKU: {$product['sku']}, 한글명: {$product['name_ko']}, 영문명: {$product['name_en']}, 브랜드: {$product['brand_name']}<br>";
    }
    
} catch (Exception $e) {
    echo "오류: " . $e->getMessage();
}
?>