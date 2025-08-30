<?php
/**
 * 점포별 상품 조회 API
 * 선택된 점포의 상품만 표시하고, 점포별 가격 정보 제공
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// OPTIONS 요청 처리 (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();

try {
    require_once '../../config/db_config.php';
    $conn = get_db_connection();
    
    if (!$conn) {
        throw new Exception("데이터베이스 연결 실패");
    }
    
    // 파라미터 처리
    $store_id = $_GET['store_id'] ?? $_SESSION['selected_store_id'] ?? 1;
    $category_id = $_GET['category'] ?? null;
    $type_filter = $_GET['type'] ?? null; // deals, new, fresh, featured
    $limit = (int)($_GET['limit'] ?? 20);
    $offset = (int)($_GET['offset'] ?? 0);
    
    // 점포 존재 확인
    $store_check = "SELECT id, name, is_active FROM stores WHERE id = ? AND is_active = 1";
    $store_stmt = $conn->prepare($store_check);
    $store_stmt->bind_param("i", $store_id);
    $store_stmt->execute();
    $store_result = $store_stmt->get_result();
    
    if (!$store_info = $store_result->fetch_assoc()) {
        throw new Exception("존재하지 않거나 운영하지 않는 점포입니다.");
    }
    
    // 기본 쿼리
    $sql = "SELECT 
                p.id,
                p.name_kr,
                p.name_en,
                p.description,
                p.barcode,
                p.category_id,
                c.name as category_name,
                c.icon_class,
                i.cost_price,
                i.selling_price,
                i.quantity,
                i.margin_rate,
                sp.is_featured,
                sp.display_order,
                sp.notes as store_notes,
                p.image_path,
                p.status,
                CASE 
                    WHEN i.cost_price > 0 AND i.selling_price > i.cost_price 
                    THEN ROUND(((i.selling_price - i.cost_price) / i.cost_price * 100), 0)
                    ELSE 0 
                END as discount_rate,
                CASE 
                    WHEN i.cost_price > 0 AND i.selling_price > 0
                    THEN i.cost_price
                    ELSE 0
                END as original_price
            FROM products p
            INNER JOIN store_products sp ON p.id = sp.product_id 
                AND sp.store_id = ? AND sp.is_available = 1
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = ?
            WHERE p.status = 'active' OR p.status IS NULL";
    
    $params = [$store_id, $store_id];
    $param_types = "ii";
    
    // 카테고리 필터 추가
    if ($category_id) {
        $sql .= " AND p.category_id = ?";
        $params[] = $category_id;
        $param_types .= "i";
    }
    
    // 타입 필터 추가
    switch($type_filter) {
        case 'deals':
            $sql .= " AND i.cost_price > 0 AND i.selling_price > i.cost_price";
            break;
        case 'featured':
            $sql .= " AND sp.is_featured = 1";
            break;
        case 'new':
            $sql .= " AND p.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
        case 'fresh':
            // 신선식품 카테고리 (예: category_id = 1)
            $sql .= " AND c.name_kr LIKE '%신선%' OR c.name_kr LIKE '%과일%' OR c.name_kr LIKE '%채소%' OR c.name_kr LIKE '%육류%' OR c.name_kr LIKE '%해산물%'";
            break;
    }
    
    // 정렬
    if ($type_filter === 'featured') {
        $sql .= " ORDER BY sp.display_order ASC, p.name_kr ASC";
    } else {
        $sql .= " ORDER BY p.name_kr ASC";
    }
    
    // 페이징
    $sql .= " LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $param_types .= "ii";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $products = [];
    while ($row = $result->fetch_assoc()) {
        $products[] = [
            'id' => (int)$row['id'],
            'name_kr' => $row['name_kr'],
            'name_en' => $row['name_en'],
            'description' => $row['description'],
            'barcode' => $row['barcode'],
            'category_id' => (int)$row['category_id'],
            'category_name' => $row['category_name'],
            'category_icon' => $row['icon_class'],
            'cost_price' => (float)$row['cost_price'],
            'selling_price' => (float)$row['selling_price'],
            'original_price' => (float)$row['original_price'],
            'quantity' => (int)$row['quantity'],
            'margin_rate' => (float)$row['margin_rate'],
            'discount_rate' => (int)$row['discount_rate'],
            'is_featured' => (bool)$row['is_featured'],
            'display_order' => (int)$row['display_order'],
            'store_notes' => $row['store_notes'],
            'image_path' => $row['image_path'],
            'image_url' => $row['image_path'] ? '/homekmart/admin/uploads/' . $row['image_path'] : null,
            'status' => $row['status'],
            'store_id' => (int)$store_id,
            'store_name' => $store_info['name']
        ];
    }
    
    // 총 개수 조회
    $count_sql = "SELECT COUNT(*) as total 
                  FROM products p
                  INNER JOIN store_products sp ON p.id = sp.product_id 
                      AND sp.store_id = ? AND sp.is_available = 1
                  LEFT JOIN categories c ON p.category_id = c.id
                  LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = ?
                  WHERE p.status = 'active' OR p.status IS NULL";
    
    $count_params = [$store_id, $store_id];
    $count_param_types = "ii";
    
    if ($category_id) {
        $count_sql .= " AND p.category_id = ?";
        $count_params[] = $category_id;
        $count_param_types .= "i";
    }
    
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param($count_param_types, ...$count_params);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_count = $count_result->fetch_assoc()['total'];
    
    $response = [
        'success' => true,
        'store' => [
            'id' => (int)$store_info['id'],
            'name' => $store_info['name']
        ],
        'products' => $products,
        'total' => (int)$total_count,
        'limit' => $limit,
        'offset' => $offset,
        'filter' => [
            'category_id' => $category_id,
            'type' => $type_filter
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => '상품 조회 중 오류가 발생했습니다.',
        'details' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>