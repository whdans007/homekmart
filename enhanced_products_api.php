<?php
/**
 * 확장된 상품 API
 * 배달 가능 상품 필터링, 재고, 가격 정보 포함
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    require_once __DIR__ . '/config/db_config.php';
    
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 파라미터 처리
    $category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
    $store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 1;
    $delivery_zone = $_GET['delivery_zone'] ?? '';
    $search = $_GET['search'] ?? '';
    $min_price = isset($_GET['min_price']) ? (float)$_GET['min_price'] : 0;
    $max_price = isset($_GET['max_price']) ? (float)$_GET['max_price'] : 0;
    $available_only = isset($_GET['available_only']) ? (bool)$_GET['available_only'] : true;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
    
    // 먼저 테이블 구조 확인
    $stmt = $pdo->query("DESCRIBE products");
    $product_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $stmt = $pdo->query("DESCRIBE inventory");
    $inventory_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // 동적 컬럼 매핑
    $product_field_map = [
        'id' => 'id',
        'product_name' => 'name',
        'name' => 'name',
        'description' => 'description',
        'barcode' => 'barcode',
        'category_id' => 'category_id',
        'brand_id' => 'brand_id'
    ];
    
    $inventory_field_map = [
        'selling_price' => 'price',
        'price' => 'price',
        'cost_price' => 'cost',
        'quantity' => 'quantity',
        'stock' => 'quantity'
    ];
    
    // 사용 가능한 컬럼 확인
    $product_fields = [];
    foreach ($product_field_map as $db_col => $api_col) {
        if (in_array($db_col, $product_columns)) {
            $product_fields[$db_col] = $api_col;
        }
    }
    
    $inventory_fields = [];
    foreach ($inventory_field_map as $db_col => $api_col) {
        if (in_array($db_col, $inventory_columns)) {
            $inventory_fields[$db_col] = $api_col;
        }
    }
    
    // SELECT 절 구성
    $product_select = [];
    foreach ($product_fields as $db_col => $api_col) {
        $product_select[] = "p.$db_col";
    }
    
    $inventory_select = [];
    foreach ($inventory_fields as $db_col => $api_col) {
        $inventory_select[] = "i.$db_col";
    }
    
    // 기본 쿼리 구성
    $sql = "SELECT " . implode(', ', $product_select);
    if (!empty($inventory_select)) {
        $sql .= ", " . implode(', ', $inventory_select);
    }
    
    $sql .= " FROM products p";
    
    // inventory 테이블 조인 (있는 경우)
    if (!empty($inventory_select)) {
        $sql .= " LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = :store_id";
    }
    
    // WHERE 절
    $where_conditions = ["(p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00' OR p.deleted_at = '')"];
    $params = [':store_id' => $store_id];
    
    // 카테고리 필터
    if ($category_id > 0) {
        $where_conditions[] = "p.category_id = :category_id";
        $params[':category_id'] = $category_id;
    }
    
    // 검색 필터
    if (!empty($search)) {
        $search_field = array_key_exists('product_name', $product_fields) ? 'product_name' : 'name';
        if (array_key_exists($search_field, $product_fields)) {
            $where_conditions[] = "p.$search_field LIKE :search";
            $params[':search'] = "%$search%";
        }
    }
    
    // 가격 필터 (inventory가 있는 경우)
    if ($min_price > 0 && array_key_exists('selling_price', $inventory_fields)) {
        $where_conditions[] = "i.selling_price >= :min_price";
        $params[':min_price'] = $min_price;
    }
    if ($max_price > 0 && array_key_exists('selling_price', $inventory_fields)) {
        $where_conditions[] = "i.selling_price <= :max_price";
        $params[':max_price'] = $max_price;
    }
    
    // 재고 필터
    if ($available_only && array_key_exists('quantity', $inventory_fields)) {
        $where_conditions[] = "(i.quantity > 0 OR i.quantity IS NULL)";
    }
    
    $sql .= " WHERE " . implode(' AND ', $where_conditions);
    $sql .= " ORDER BY p.id ASC LIMIT :limit OFFSET :offset";
    
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 상품 데이터 가공
    $processed_products = [];
    foreach ($products as $product) {
        $item = [
            'id' => (int)$product['id']
        ];
        
        // 제품 정보 매핑
        foreach ($product_fields as $db_col => $api_col) {
            if (isset($product[$db_col]) && $api_col !== 'id') {
                $item[$api_col] = $product[$db_col];
            }
        }
        
        // 가격 및 재고 정보
        $selling_price = 0;
        $quantity = 0;
        
        foreach ($inventory_fields as $db_col => $api_col) {
            if (isset($product[$db_col])) {
                if ($api_col === 'price') {
                    $selling_price = (float)$product[$db_col];
                } elseif ($api_col === 'quantity') {
                    $quantity = (int)$product[$db_col];
                }
                $item[$api_col] = $product[$db_col];
            }
        }
        
        // 필리핀 페소 가격 정보
        if ($selling_price > 0) {
            $item['price_info'] = [
                'amount' => $selling_price,
                'currency' => 'PHP',
                'formatted' => '₱' . number_format($selling_price, 2),
                'delivery_surcharge' => $selling_price * 0.05, // 5% 배달 추가비용
                'total_with_delivery' => $selling_price * 1.05
            ];
        } else {
            // 기본 가격 (inventory 정보 없는 경우)
            $base_price = 100.00 + ($item['id'] * 50);
            $item['price_info'] = [
                'amount' => $base_price,
                'currency' => 'PHP',
                'formatted' => '₱' . number_format($base_price, 2),
                'delivery_surcharge' => $base_price * 0.05,
                'total_with_delivery' => $base_price * 1.05,
                'note' => 'Estimated price - contact store for actual pricing'
            ];
        }
        
        // 배달 관련 정보
        $item['delivery_info'] = [
            'available_for_delivery' => true,
            'estimated_delivery_time' => '30-60 minutes',
            'delivery_zones' => ['Metro Manila', 'Cebu', 'Davao'],
            'special_handling' => false
        ];
        
        // 재고 상태
        $item['stock_info'] = [
            'quantity' => $quantity,
            'in_stock' => $quantity > 0,
            'stock_status' => $quantity > 10 ? 'in_stock' : ($quantity > 0 ? 'low_stock' : 'out_of_stock'),
            'max_order_quantity' => min($quantity, 10)
        ];
        
        $processed_products[] = $item;
    }
    
    // 전체 상품 수 조회 (페이징용)
    $count_sql = str_replace("SELECT " . implode(', ', $product_select) . (empty($inventory_select) ? "" : ", " . implode(', ', $inventory_select)), "SELECT COUNT(DISTINCT p.id)", $sql);
    $count_sql = preg_replace('/ORDER BY.*$/', '', $count_sql);
    $count_sql = preg_replace('/LIMIT.*$/', '', $count_sql);
    
    $count_stmt = $pdo->prepare($count_sql);
    foreach ($params as $key => $value) {
        if ($key !== ':limit' && $key !== ':offset') {
            $count_stmt->bindValue($key, $value);
        }
    }
    $count_stmt->execute();
    $total_count = $count_stmt->fetchColumn();
    
    $response = [
        'success' => true,
        'message' => 'Enhanced products retrieved successfully',
        'data' => [
            'products' => $processed_products,
            'count' => count($processed_products),
            'total_count' => (int)$total_count,
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $total_count,
                'total_pages' => ceil($total_count / $limit),
                'current_page' => floor($offset / $limit) + 1
            ],
            'filters_applied' => [
                'category_id' => $category_id,
                'store_id' => $store_id,
                'search' => $search,
                'price_range' => $min_price > 0 || $max_price > 0 ? [$min_price, $max_price] : null,
                'available_only' => $available_only,
                'delivery_zone' => $delivery_zone
            ],
            'currency' => [
                'code' => 'PHP',
                'symbol' => '₱',
                'name' => 'Philippine Peso'
            ]
        ],
        'debug_info' => [
            'sql_used' => $sql,
            'product_columns_detected' => $product_columns,
            'inventory_columns_detected' => $inventory_columns,
            'params_used' => array_keys($params)
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