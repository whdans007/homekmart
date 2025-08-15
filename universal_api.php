<?php
/**
 * 범용 API - 모든 테이블 구조에 대응
 * 필리핀 배달 앱용 완전 호환 API
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    require_once __DIR__ . '/config/db_config.php';
    
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // API 타입 결정
    $api_type = $_GET['type'] ?? 'products';
    $limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 10;
    
    $response = [];
    
    switch ($api_type) {
        case 'products':
            $response = handleProducts($pdo, $limit);
            break;
        case 'categories':
            $response = handleCategories($pdo, $limit);
            break;
        case 'status':
            $response = handleStatus($pdo);
            break;
        default:
            $response = handleProducts($pdo, $limit);
    }
    
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

function handleProducts($pdo, $limit) {
    // 테이블 존재 확인
    $stmt = $pdo->query("SHOW TABLES LIKE 'products'");
    if ($stmt->rowCount() === 0) {
        return getSampleProducts($limit);
    }
    
    // 컬럼 분석
    $stmt = $pdo->query("DESCRIBE products");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // 안전한 컬럼 선택
    $safe_columns = ['id'];
    $aliases = [];
    
    // 이름 컬럼
    foreach (['product_name', 'name', 'title', 'item_name'] as $candidate) {
        if (in_array($candidate, $columns)) {
            $safe_columns[] = $candidate;
            $aliases[$candidate] = 'name';
            break;
        }
    }
    
    // 설명 컬럼
    foreach (['description', 'desc', 'details'] as $candidate) {
        if (in_array($candidate, $columns)) {
            $safe_columns[] = $candidate;
            $aliases[$candidate] = 'description';
            break;
        }
    }
    
    // 기타 컬럼
    foreach (['barcode', 'category_id', 'brand_id', 'sku'] as $candidate) {
        if (in_array($candidate, $columns)) {
            $safe_columns[] = $candidate;
        }
    }
    
    // WHERE 절 구성
    $where_parts = ['1=1'];
    if (in_array('deleted_at', $columns)) {
        $where_parts[] = "(deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00' OR deleted_at = '')";
    }
    
    $select_clause = implode(', ', $safe_columns);
    $where_clause = implode(' AND ', $where_parts);
    
    $sql = "SELECT $select_clause FROM products WHERE $where_clause ORDER BY id ASC LIMIT $limit";
    
    $stmt = $pdo->query($sql);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 데이터 가공
    $processed = [];
    foreach ($products as $product) {
        $item = ['id' => (int)$product['id']];
        
        foreach ($product as $key => $value) {
            if ($key === 'id') continue;
            $alias = $aliases[$key] ?? $key;
            $item[$alias] = $value;
        }
        
        // 필리핀 배달 앱용 정보 추가
        $base_price = 150.00 + ((int)$product['id'] * 33.75);
        $item['price_info'] = [
            'amount' => $base_price,
            'currency' => 'PHP',
            'formatted' => '₱' . number_format($base_price, 2),
            'delivery_surcharge' => round($base_price * 0.05, 2)
        ];
        
        $item['delivery_info'] = [
            'available_for_delivery' => true,
            'estimated_delivery_time' => '30-60 minutes',
            'delivery_zones' => ['Metro Manila', 'Cebu', 'Davao']
        ];
        
        $item['stock_info'] = [
            'in_stock' => true,
            'quantity' => rand(10, 100)
        ];
        
        $processed[] = $item;
    }
    
    return [
        'success' => true,
        'message' => 'Products retrieved successfully',
        'data' => [
            'products' => $processed,
            'count' => count($processed),
            'api_type' => 'products',
            'currency' => ['code' => 'PHP', 'symbol' => '₱']
        ],
        'debug_info' => [
            'columns_detected' => $columns,
            'query_used' => $sql
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

function handleCategories($pdo, $limit) {
    // 테이블 존재 확인
    $stmt = $pdo->query("SHOW TABLES LIKE 'categories'");
    if ($stmt->rowCount() === 0) {
        return getSampleCategories();
    }
    
    // 컬럼 분석
    $stmt = $pdo->query("DESCRIBE categories");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $safe_columns = ['id'];
    $aliases = [];
    
    // 이름 컬럼
    foreach (['category_name', 'name', 'title'] as $candidate) {
        if (in_array($candidate, $columns)) {
            $safe_columns[] = $candidate;
            $aliases[$candidate] = 'name';
            break;
        }
    }
    
    // 설명 컬럼
    foreach (['description', 'desc'] as $candidate) {
        if (in_array($candidate, $columns)) {
            $safe_columns[] = $candidate;
            $aliases[$candidate] = 'description';
            break;
        }
    }
    
    $where_parts = ['1=1'];
    if (in_array('deleted_at', $columns)) {
        $where_parts[] = "(deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";
    }
    
    $select_clause = implode(', ', $safe_columns);
    $where_clause = implode(' AND ', $where_parts);
    
    $sql = "SELECT $select_clause FROM categories WHERE $where_clause ORDER BY id ASC LIMIT $limit";
    
    $stmt = $pdo->query($sql);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $processed = [];
    foreach ($categories as $category) {
        $item = ['id' => (int)$category['id']];
        
        foreach ($category as $key => $value) {
            if ($key === 'id') continue;
            $alias = $aliases[$key] ?? $key;
            $item[$alias] = $value;
        }
        
        $item['available_for_delivery'] = true;
        $item['product_count'] = rand(5, 45);
        
        $processed[] = $item;
    }
    
    return [
        'success' => true,
        'message' => 'Categories retrieved successfully',
        'data' => [
            'categories' => $processed,
            'count' => count($processed),
            'api_type' => 'categories'
        ],
        'debug_info' => [
            'columns_detected' => $columns,
            'query_used' => $sql
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

function handleStatus($pdo) {
    $tables = [];
    $stmt = $pdo->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $table_name = $row[0];
        
        // 각 테이블의 행 수 조회
        try {
            $count_stmt = $pdo->query("SELECT COUNT(*) FROM `$table_name`");
            $count = $count_stmt->fetchColumn();
            $tables[$table_name] = (int)$count;
        } catch (Exception $e) {
            $tables[$table_name] = 'error: ' . $e->getMessage();
        }
    }
    
    return [
        'success' => true,
        'message' => 'Database status retrieved',
        'data' => [
            'api_type' => 'status',
            'database_name' => 'min',
            'tables' => $tables,
            'connection_status' => 'connected',
            'php_version' => phpversion(),
            'server_time' => date('Y-m-d H:i:s')
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

function getSampleProducts($limit) {
    $samples = [
        ['id' => 1, 'name' => 'Samsung Galaxy A54', 'description' => 'Latest Android smartphone'],
        ['id' => 2, 'name' => 'iPhone 14', 'description' => 'Apple iPhone with advanced features'],
        ['id' => 3, 'name' => 'Xiaomi Redmi Note 12', 'description' => 'Budget-friendly smartphone'],
        ['id' => 4, 'name' => 'Sony WH-1000XM4', 'description' => 'Noise-canceling headphones'],
        ['id' => 5, 'name' => 'MacBook Air M2', 'description' => 'Apple laptop with M2 chip']
    ];
    
    $processed = [];
    for ($i = 0; $i < min($limit, count($samples)); $i++) {
        $sample = $samples[$i];
        $base_price = 1500.00 + ($sample['id'] * 500);
        
        $processed[] = [
            'id' => $sample['id'],
            'name' => $sample['name'],
            'description' => $sample['description'],
            'price_info' => [
                'amount' => $base_price,
                'currency' => 'PHP',
                'formatted' => '₱' . number_format($base_price, 2)
            ],
            'delivery_info' => [
                'available_for_delivery' => true,
                'estimated_delivery_time' => '30-60 minutes'
            ],
            'stock_info' => [
                'in_stock' => true,
                'quantity' => rand(10, 50)
            ]
        ];
    }
    
    return [
        'success' => true,
        'message' => 'Sample products (no database table)',
        'data' => [
            'products' => $processed,
            'count' => count($processed),
            'is_sample_data' => true,
            'currency' => ['code' => 'PHP', 'symbol' => '₱']
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

function getSampleCategories() {
    $samples = [
        ['id' => 1, 'name' => 'Electronics', 'description' => 'Electronic devices'],
        ['id' => 2, 'name' => 'Clothing', 'description' => 'Fashion and apparel'],
        ['id' => 3, 'name' => 'Home & Garden', 'description' => 'Home improvement'],
        ['id' => 4, 'name' => 'Books', 'description' => 'Books and media'],
        ['id' => 5, 'name' => 'Sports', 'description' => 'Sports equipment']
    ];
    
    $processed = [];
    foreach ($samples as $sample) {
        $processed[] = [
            'id' => $sample['id'],
            'name' => $sample['name'],
            'description' => $sample['description'],
            'available_for_delivery' => true,
            'product_count' => rand(10, 50)
        ];
    }
    
    return [
        'success' => true,
        'message' => 'Sample categories (no database table)',
        'data' => [
            'categories' => $processed,
            'count' => count($processed),
            'is_sample_data' => true
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
}
?>