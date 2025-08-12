<?php
require_once __DIR__ . '/../config/db_config.php';

header('Content-Type: application/json');

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $debug_info = [];
    
    // 1. 기본 테이블 구조 확인
    $debug_info['step1'] = "테이블 구조 확인";
    
    // products 테이블 구조
    $products_columns = $pdo->query("DESCRIBE products")->fetchAll(PDO::FETCH_ASSOC);
    $debug_info['products_columns'] = array_column($products_columns, 'Field');
    
    // inventory 테이블 구조
    $inventory_columns = $pdo->query("DESCRIBE inventory")->fetchAll(PDO::FETCH_ASSOC);
    $debug_info['inventory_columns'] = array_column($inventory_columns, 'Field');
    
    // brands 테이블 존재 확인
    $brands_exists = $pdo->query("SHOW TABLES LIKE 'brands'")->fetch();
    $debug_info['brands_exists'] = (bool)$brands_exists;
    
    if ($brands_exists) {
        $brands_columns = $pdo->query("DESCRIBE brands")->fetchAll(PDO::FETCH_ASSOC);
        $debug_info['brands_columns'] = array_column($brands_columns, 'Field');
    }
    
    // 2. 기본 쿼리 테스트
    $debug_info['step2'] = "기본 쿼리 테스트";
    
    // 가장 간단한 쿼리부터 시작
    $simple_sql = "SELECT COUNT(*) as total FROM products";
    $result = $pdo->query($simple_sql)->fetch();
    $debug_info['total_products'] = $result['total'];
    
    $simple_sql2 = "SELECT COUNT(*) as total FROM inventory WHERE store_id = 1";
    $result2 = $pdo->query($simple_sql2)->fetch();
    $debug_info['store1_inventory'] = $result2['total'];
    
    // 3. JOIN 테스트
    $debug_info['step3'] = "JOIN 테스트";
    
    $join_sql = "
        SELECT COUNT(*) as total
        FROM products p
        INNER JOIN inventory i ON p.id = i.product_id
        WHERE i.store_id = 1 AND i.quantity > 0
    ";
    $result3 = $pdo->query($join_sql)->fetch();
    $debug_info['join_count'] = $result3['total'];
    
    // 4. 실제 데이터 조회 (LIMIT 없이)
    $debug_info['step4'] = "실제 데이터 조회";
    
    $data_sql = "
        SELECT 
            p.id,
            p.sku,
            p.name_ko,
            p.name_en,
            i.cost_price,
            i.quantity
        FROM products p
        INNER JOIN inventory i ON p.id = i.product_id
        WHERE i.store_id = 1 AND i.quantity > 0 AND i.cost_price > 0
        ORDER BY p.id
    ";
    
    $stmt = $pdo->prepare($data_sql);
    $stmt->execute();
    $sample_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $debug_info['sample_count'] = count($sample_products);
    $debug_info['sample_products'] = array_slice($sample_products, 0, 3); // 처음 3개만
    
    // 5. LIMIT 테스트
    $debug_info['step5'] = "LIMIT 테스트";
    
    $limit_sql = "
        SELECT 
            p.id,
            p.sku,
            p.name_ko
        FROM products p
        INNER JOIN inventory i ON p.id = i.product_id
        WHERE i.store_id = 1 AND i.quantity > 0
        ORDER BY p.id
        LIMIT 5
    ";
    
    $stmt2 = $pdo->prepare($limit_sql);
    $stmt2->execute();
    $limit_test = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    
    $debug_info['limit_test_count'] = count($limit_test);
    $debug_info['limit_test_success'] = true;
    
    echo json_encode([
        'success' => true,
        'debug_info' => $debug_info
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug_info' => $debug_info ?? []
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
?>