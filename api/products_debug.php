<?php
/**
 * 상품 API 디버그 버전
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    require_once __DIR__ . '/../config/db_config.php';
    
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $debug_info = [];
    
    // 1. 기본 상품 개수 확인
    $total_stmt = $pdo->query("SELECT COUNT(*) as total FROM products");
    $total_count = $total_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $debug_info['total_products'] = $total_count;
    
    // 2. 삭제되지 않은 상품 개수
    $active_stmt = $pdo->query("
        SELECT COUNT(*) as active 
        FROM products 
        WHERE deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00'
    ");
    $active_count = $active_stmt->fetch(PDO::FETCH_ASSOC)['active'];
    $debug_info['active_products'] = $active_count;
    
    // 3. 테이블 구조 확인
    $tables_check = [];
    
    // products 테이블 컬럼 확인
    $products_columns = $pdo->query("DESCRIBE products")->fetchAll(PDO::FETCH_COLUMN);
    $tables_check['products_columns'] = $products_columns;
    
    // categories 테이블 존재 확인
    $categories_exist = $pdo->query("SHOW TABLES LIKE 'categories'")->rowCount() > 0;
    $tables_check['categories_exist'] = $categories_exist;
    
    // brands 테이블 존재 확인
    $brands_exist = $pdo->query("SHOW TABLES LIKE 'brands'")->rowCount() > 0;
    $tables_check['brands_exist'] = $brands_exist;
    
    // inventory 테이블 존재 확인
    $inventory_exist = $pdo->query("SHOW TABLES LIKE 'inventory'")->rowCount() > 0;
    $tables_check['inventory_exist'] = $inventory_exist;
    
    $debug_info['tables_check'] = $tables_check;
    
    // 4. 간단한 상품 5개 조회 (조인 없이)
    $simple_products = [];
    try {
        $simple_stmt = $pdo->query("
            SELECT id, name, description 
            FROM products 
            WHERE deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00'
            LIMIT 5
        ");
        $simple_products = $simple_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $debug_info['simple_query_error'] = $e->getMessage();
    }
    
    $debug_info['sample_products'] = $simple_products;
    
    // 5. 카테고리 개수 확인
    if ($categories_exist) {
        $cat_count = $pdo->query("SELECT COUNT(*) as count FROM categories")->fetch(PDO::FETCH_ASSOC)['count'];
        $debug_info['categories_count'] = $cat_count;
    }
    
    // 6. 브랜드 개수 확인
    if ($brands_exist) {
        $brand_count = $pdo->query("SELECT COUNT(*) as count FROM brands")->fetch(PDO::FETCH_ASSOC)['count'];
        $debug_info['brands_count'] = $brand_count;
    }
    
    // 7. 인벤토리 개수 확인
    if ($inventory_exist) {
        $inventory_count = $pdo->query("SELECT COUNT(*) as count FROM inventory")->fetch(PDO::FETCH_ASSOC)['count'];
        $debug_info['inventory_count'] = $inventory_count;
        
        // 가격 범위 확인
        $price_range = $pdo->query("
            SELECT MIN(selling_price) as min_price, MAX(selling_price) as max_price 
            FROM inventory 
            WHERE selling_price > 0
        ")->fetch(PDO::FETCH_ASSOC);
        $debug_info['price_range'] = $price_range;
    }
    
    $response = [
        'success' => true,
        'message' => 'Debug information collected',
        'debug' => $debug_info,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    $error_response = [
        'success' => false,
        'message' => 'Debug failed',
        'error' => $e->getMessage(),
        'line' => $e->getLine(),
        'file' => $e->getFile(),
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    http_response_code(500);
    echo json_encode($error_response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>