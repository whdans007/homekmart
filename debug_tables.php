<?php
/**
 * 테이블 구조 디버깅
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    require_once __DIR__ . '/config/db_config.php';
    
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $debug_info = [];
    
    // 1. 모든 테이블 목록
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $debug_info['all_tables'] = $tables;
    
    // 2. products 테이블이 존재하는지 확인
    if (in_array('products', $tables)) {
        // products 테이블 구조
        $stmt = $pdo->query("DESCRIBE products");
        $products_structure = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $debug_info['products_structure'] = $products_structure;
        
        // products 테이블 데이터 개수
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM products");
        $products_count = $stmt->fetch(PDO::FETCH_ASSOC);
        $debug_info['products_count'] = $products_count['count'];
        
        // 첫 번째 상품 데이터 (모든 컬럼)
        $stmt = $pdo->query("SELECT * FROM products LIMIT 1");
        $first_product = $stmt->fetch(PDO::FETCH_ASSOC);
        $debug_info['first_product'] = $first_product;
        
        // 컬럼명만 추출
        if ($first_product) {
            $debug_info['available_columns'] = array_keys($first_product);
        }
    } else {
        $debug_info['products_table_exists'] = false;
    }
    
    // 3. categories 테이블 확인
    if (in_array('categories', $tables)) {
        $stmt = $pdo->query("DESCRIBE categories");
        $categories_structure = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $debug_info['categories_structure'] = $categories_structure;
        
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM categories");
        $categories_count = $stmt->fetch(PDO::FETCH_ASSOC);
        $debug_info['categories_count'] = $categories_count['count'];
    }
    
    // 4. inventory 테이블 확인
    if (in_array('inventory', $tables)) {
        $stmt = $pdo->query("DESCRIBE inventory");
        $inventory_structure = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $debug_info['inventory_structure'] = $inventory_structure;
        
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM inventory");
        $inventory_count = $stmt->fetch(PDO::FETCH_ASSOC);
        $debug_info['inventory_count'] = $inventory_count['count'];
    }
    
    // 5. 간단한 테스트 쿼리
    if ($debug_info['products_count'] > 0) {
        // 사용 가능한 컬럼으로 안전한 쿼리 시도
        $columns = $debug_info['available_columns'];
        $safe_columns = ['id'];
        
        // 일반적인 컬럼명들 확인
        $common_name_columns = ['product_name', 'name', 'title'];
        foreach ($common_name_columns as $col) {
            if (in_array($col, $columns)) {
                $safe_columns[] = $col;
                break;
            }
        }
        
        $common_desc_columns = ['description', 'desc', 'details'];
        foreach ($common_desc_columns as $col) {
            if (in_array($col, $columns)) {
                $safe_columns[] = $col;
                break;
            }
        }
        
        $select_columns = implode(', ', $safe_columns);
        $stmt = $pdo->query("SELECT $select_columns FROM products LIMIT 3");
        $sample_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $debug_info['sample_products'] = $sample_products;
        $debug_info['safe_query'] = "SELECT $select_columns FROM products LIMIT 3";
    }
    
    $response = [
        'success' => true,
        'message' => 'Database structure debugging completed',
        'data' => $debug_info,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>