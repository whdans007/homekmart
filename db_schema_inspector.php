<?php
/**
 * 데이터베이스 스키마 조사기
 * products 테이블 구조 확인
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    require_once __DIR__ . '/config/db_config.php';
    
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $schema_info = [];
    
    // 1. products 테이블 구조 확인
    $stmt = $pdo->query("DESCRIBE products");
    $products_columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $schema_info['products_table'] = $products_columns;
    
    // 2. categories 테이블 구조 확인
    $stmt = $pdo->query("DESCRIBE categories");
    $categories_columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $schema_info['categories_table'] = $categories_columns;
    
    // 3. 샘플 데이터 확인 (첫 번째 행)
    $stmt = $pdo->query("SELECT * FROM products LIMIT 1");
    $sample_product = $stmt->fetch(PDO::FETCH_ASSOC);
    $schema_info['sample_product'] = $sample_product;
    
    $stmt = $pdo->query("SELECT * FROM categories LIMIT 1");
    $sample_category = $stmt->fetch(PDO::FETCH_ASSOC);
    $schema_info['sample_category'] = $sample_category;
    
    // 4. 테이블 목록 확인
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $schema_info['all_tables'] = $tables;
    
    // 5. 데이터 개수 확인
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM products");
    $product_count = $stmt->fetch(PDO::FETCH_ASSOC);
    $schema_info['product_count'] = $product_count['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM categories");
    $category_count = $stmt->fetch(PDO::FETCH_ASSOC);
    $schema_info['category_count'] = $category_count['count'];
    
    $response = [
        'success' => true,
        'message' => 'Database schema inspection completed',
        'data' => $schema_info,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>