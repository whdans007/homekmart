<?php
/**
 * 올바른 컬럼명을 사용한 상품 API
 * 기존 HOME K MART 시스템 패턴 기반
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    require_once __DIR__ . '/config/db_config.php';
    
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 10;
    
    // 먼저 테이블 구조를 동적으로 확인
    $stmt = $pdo->query("DESCRIBE products");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // 사용 가능한 컬럼들 중에서 선택
    $available_columns = [];
    $column_mapping = [
        'id' => 'id',
        'product_name' => 'name',  // HOME K MART에서 주로 사용하는 패턴
        'name' => 'name',
        'description' => 'description',
        'barcode' => 'barcode',
        'category_id' => 'category_id',
        'brand_id' => 'brand_id'
    ];
    
    foreach ($column_mapping as $db_col => $api_col) {
        if (in_array($db_col, $columns)) {
            $available_columns[$db_col] = $api_col;
        }
    }
    
    // 동적으로 SELECT 쿼리 구성
    $select_parts = [];
    foreach ($available_columns as $db_col => $api_col) {
        $select_parts[] = "p.$db_col";
    }
    
    if (empty($select_parts)) {
        throw new Exception("No compatible columns found in products table");
    }
    
    $sql = "SELECT " . implode(', ', $select_parts) . " 
            FROM products p 
            WHERE (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00' OR p.deleted_at = '') 
            ORDER BY p.id ASC 
            LIMIT " . $limit;
    
    $stmt = $pdo->query($sql);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 상품 데이터 가공
    $processed_products = [];
    foreach ($products as $product) {
        $item = [
            'id' => (int)$product['id']
        ];
        
        // 동적으로 필드 매핑
        foreach ($available_columns as $db_col => $api_col) {
            if (isset($product[$db_col]) && $api_col !== 'id') {
                $item[$api_col] = $product[$db_col];
            }
        }
        
        // 필리핀 배달 앱을 위한 추가 정보
        $item['currency'] = 'PHP';
        $item['available_for_delivery'] = true;
        $item['price_range'] = [
            'min' => 500.00,
            'max' => 5000.00,
            'currency' => 'PHP',
            'formatted' => '₱500 - ₱5,000'
        ];
        
        $processed_products[] = $item;
    }
    
    $response = [
        'success' => true,
        'message' => 'Products retrieved with correct column mapping',
        'data' => [
            'products' => $processed_products,
            'count' => count($processed_products),
            'limit' => $limit,
            'available_columns' => $available_columns,
            'currency' => [
                'code' => 'PHP',
                'symbol' => '₱',
                'name' => 'Philippine Peso'
            ]
        ],
        'debug_info' => [
            'sql_used' => $sql,
            'columns_detected' => $columns
        ],
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