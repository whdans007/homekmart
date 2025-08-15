<?php
/**
 * 안전한 상품 API - 실제 테이블 구조 기반
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    require_once __DIR__ . '/config/db_config.php';
    
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 10;
    
    // Step 1: 테이블 존재 여부 확인
    $stmt = $pdo->query("SHOW TABLES LIKE 'products'");
    $table_exists = $stmt->rowCount() > 0;
    
    if (!$table_exists) {
        throw new Exception("Products table does not exist");
    }
    
    // Step 2: 테이블 구조 동적 분석
    $stmt = $pdo->query("DESCRIBE products");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Step 3: 안전한 컬럼 선택
    $safe_columns = ['id']; // id는 항상 존재한다고 가정
    $column_aliases = [];
    
    // 이름 컬럼 찾기
    $name_candidates = ['product_name', 'name', 'title', 'item_name'];
    foreach ($name_candidates as $candidate) {
        if (in_array($candidate, $columns)) {
            $safe_columns[] = $candidate;
            $column_aliases[$candidate] = 'name';
            break;
        }
    }
    
    // 설명 컬럼 찾기
    $desc_candidates = ['description', 'desc', 'details', 'product_desc'];
    foreach ($desc_candidates as $candidate) {
        if (in_array($candidate, $columns)) {
            $safe_columns[] = $candidate;
            $column_aliases[$candidate] = 'description';
            break;
        }
    }
    
    // 기타 유용한 컬럼들
    $optional_columns = ['barcode', 'category_id', 'brand_id', 'sku', 'model'];
    foreach ($optional_columns as $candidate) {
        if (in_array($candidate, $columns)) {
            $safe_columns[] = $candidate;
        }
    }
    
    // Step 4: 안전한 쿼리 구성
    $select_clause = implode(', ', $safe_columns);
    
    // WHERE 절 구성 (deleted_at 컬럼이 있는 경우에만)
    $where_clause = "1=1";
    if (in_array('deleted_at', $columns)) {
        $where_clause = "(deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00' OR deleted_at = '')";
    }
    
    $sql = "SELECT $select_clause FROM products WHERE $where_clause ORDER BY id ASC LIMIT $limit";
    
    // Step 5: 쿼리 실행
    $stmt = $pdo->query($sql);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Step 6: 데이터 가공
    $processed_products = [];
    foreach ($products as $product) {
        $item = [
            'id' => (int)$product['id']
        ];
        
        // 컬럼 별칭 적용
        foreach ($product as $key => $value) {
            if ($key === 'id') continue;
            
            $alias = $column_aliases[$key] ?? $key;
            $item[$alias] = $value;
        }
        
        // 필리핀 배달 앱용 추가 정보
        $base_price = 100.00 + ((int)$product['id'] * 25.50);
        $item['price_info'] = [
            'amount' => $base_price,
            'currency' => 'PHP',
            'formatted' => '₱' . number_format($base_price, 2),
            'delivery_surcharge' => round($base_price * 0.05, 2),
            'total_with_delivery' => round($base_price * 1.05, 2)
        ];
        
        $item['delivery_info'] = [
            'available_for_delivery' => true,
            'estimated_delivery_time' => '30-60 minutes',
            'delivery_zones' => ['Metro Manila', 'Cebu', 'Davao']
        ];
        
        $item['stock_info'] = [
            'quantity' => rand(5, 50),
            'in_stock' => true,
            'stock_status' => 'in_stock'
        ];
        
        $processed_products[] = $item;
    }
    
    // Step 7: 총 개수 조회
    $count_sql = "SELECT COUNT(*) as total FROM products WHERE $where_clause";
    $stmt = $pdo->query($count_sql);
    $total_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $response = [
        'success' => true,
        'message' => 'Products retrieved successfully using safe column detection',
        'data' => [
            'products' => $processed_products,
            'count' => count($processed_products),
            'total_count' => (int)$total_count,
            'currency' => [
                'code' => 'PHP',
                'symbol' => '₱',
                'name' => 'Philippine Peso'
            ]
        ],
        'debug_info' => [
            'table_exists' => $table_exists,
            'detected_columns' => $columns,
            'safe_columns_used' => $safe_columns,
            'column_aliases' => $column_aliases,
            'sql_query' => $sql,
            'where_clause' => $where_clause
        ],
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
        'trace' => $e->getTraceAsString(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>