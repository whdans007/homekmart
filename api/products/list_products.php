<?php
/**
 * 상품 목록 조회 API
 * 배달 가능한 상품만 필터링하여 반환
 */

// API 설정 파일 include
require_once __DIR__ . '/../config/api_config.php';

// GET 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Method not allowed', 405);
}

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 쿼리 파라미터 처리
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = max(1, min(50, (int)($_GET['per_page'] ?? 20)));
    $category_id = isset($_GET['category_id']) && is_numeric($_GET['category_id']) ? (int)$_GET['category_id'] : null;
    $brand_id = isset($_GET['brand_id']) && is_numeric($_GET['brand_id']) ? (int)$_GET['brand_id'] : null;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $store_id = isset($_GET['store_id']) && is_numeric($_GET['store_id']) ? (int)$_GET['store_id'] : null;
    
    // WHERE 조건 구성
    $where_conditions = ["(p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')"];
    $params = [];
    
    if ($category_id) {
        $where_conditions[] = "p.category_id = ?";
        $params[] = $category_id;
    }
    
    if ($brand_id) {
        $where_conditions[] = "p.brand_id = ?";
        $params[] = $brand_id;
    }
    
    if ($search) {
        $where_conditions[] = "(p.name LIKE ? OR p.description LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }
    
    // 재고가 있는 상품만 (선택적)
    $only_in_stock = isset($_GET['in_stock']) && $_GET['in_stock'] === 'true';
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // 총 개수 조회
    $count_sql = "
        SELECT COUNT(DISTINCT p.id) as total_count
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        LEFT JOIN inventory i ON p.id = i.product_id" . 
        ($store_id ? " AND i.store_id = {$store_id}" : "") . "
        WHERE {$where_clause}" .
        ($only_in_stock ? " AND i.quantity > 0" : "");
    
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_count = $count_stmt->fetch(PDO::FETCH_ASSOC)['total_count'];
    
    // 페이지네이션 정보
    $pagination = paginate($total_count, $page, $per_page);
    
    // 상품 목록 조회
    $sql = "
        SELECT 
            p.id, p.name, p.description, p.image_url, p.barcode,
            c.name as category_name, c.id as category_id,
            b.name_ko as brand_name, b.id as brand_id,
            COALESCE(SUM(i.quantity), 0) as total_stock
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        LEFT JOIN inventory i ON p.id = i.product_id" . 
        ($store_id ? " AND i.store_id = {$store_id}" : "") . "
        WHERE {$where_clause}" .
        ($only_in_stock ? " AND i.quantity > 0" : "") . "
        GROUP BY p.id, p.name, p.description, p.image_url, p.barcode, c.name, c.id, b.name_ko, b.id
        ORDER BY p.name ASC
        LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 상품별 가격 정보 조회 (점포별 가격)
    if (!empty($products)) {
        $product_ids = array_column($products, 'id');
        $placeholders = str_repeat('?,', count($product_ids) - 1) . '?';
        
        $price_sql = "
            SELECT 
                i.product_id,
                i.store_id,
                i.selling_price,
                i.quantity,
                s.name as store_name
            FROM inventory i
            LEFT JOIN stores s ON i.store_id = s.id
            WHERE i.product_id IN ({$placeholders})" .
            ($store_id ? " AND i.store_id = {$store_id}" : "") . "
            ORDER BY i.selling_price ASC
        ";
        
        $price_stmt = $pdo->prepare($price_sql);
        $price_stmt->execute($product_ids);
        $price_data = $price_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 가격 정보를 상품별로 그룹화
        $prices_by_product = [];
        foreach ($price_data as $price) {
            $prices_by_product[$price['product_id']][] = $price;
        }
        
        // 상품 데이터에 가격 정보 추가
        foreach ($products as &$product) {
            $product_prices = $prices_by_product[$product['id']] ?? [];
            
            if (!empty($product_prices)) {
                // 최저가 정보
                $min_price = min(array_column($product_prices, 'selling_price'));
                $max_price = max(array_column($product_prices, 'selling_price'));
                
                $product['price'] = [
                    'min_price' => (float)$min_price,
                    'max_price' => (float)$max_price,
                    'currency' => 'PHP',
                    'formatted_min' => format_php_currency($min_price),
                    'formatted_max' => format_php_currency($max_price)
                ];
                
                // 점포별 가격 정보 (선택적)
                if (isset($_GET['include_store_prices']) && $_GET['include_store_prices'] === 'true') {
                    $product['store_prices'] = array_map(function($price) {
                        return [
                            'store_id' => (int)$price['store_id'],
                            'store_name' => $price['store_name'],
                            'price' => (float)$price['selling_price'],
                            'formatted_price' => format_php_currency($price['selling_price']),
                            'quantity' => (int)$price['quantity']
                        ];
                    }, $product_prices);
                }
            } else {
                $product['price'] = null;
                if (isset($_GET['include_store_prices']) && $_GET['include_store_prices'] === 'true') {
                    $product['store_prices'] = [];
                }
            }
            
            // 기타 필드 타입 변환
            $product['id'] = (int)$product['id'];
            $product['category_id'] = $product['category_id'] ? (int)$product['category_id'] : null;
            $product['brand_id'] = $product['brand_id'] ? (int)$product['brand_id'] : null;
            $product['total_stock'] = (int)$product['total_stock'];
            $product['available_for_delivery'] = $product['total_stock'] > 0;
        }
    }
    
    // 응답 데이터 구성
    $response_data = [
        'products' => $products,
        'pagination' => $pagination,
        'filters' => [
            'category_id' => $category_id,
            'brand_id' => $brand_id,
            'search' => $search,
            'store_id' => $store_id,
            'in_stock_only' => $only_in_stock
        ],
        'currency' => [
            'code' => 'PHP',
            'symbol' => '₱'
        ]
    ];
    
    api_success($response_data, 'Products retrieved successfully');
    
} catch (PDOException $e) {
    log_api_error("Database error retrieving products", [
        'error' => $e->getMessage(),
        'filters' => $_GET
    ]);
    api_error('Failed to retrieve products', 500, 'DATABASE_ERROR');
    
} catch (Exception $e) {
    log_api_error("Unexpected error retrieving products", [
        'error' => $e->getMessage(),
        'filters' => $_GET
    ]);
    api_error('Failed to retrieve products', 500, 'UNEXPECTED_ERROR');
}
?>