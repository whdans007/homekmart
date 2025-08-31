<?php
/**
 * 상품 API - 간단한 버전
 * Updated: 2025-08-30 14:17
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    require_once '../../config/db_config.php';
    $conn = get_db_connection();
    
    if (!$conn) {
        throw new Exception("데이터베이스 연결 실패");
    }
    
    // 파라미터 처리
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
    $sort = isset($_GET['sort']) ? $_GET['sort'] : 'name';
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;
    
    // WHERE 조건 구성
    $where_conditions = ["(p.status = 'active' OR p.status IS NULL)"];
    $params = [];
    
    if (!empty($search)) {
        $where_conditions[] = "(p.name_kr LIKE ? OR p.name_en LIKE ? OR p.barcode LIKE ?)";
        $search_param = '%' . $search . '%';
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    if ($category_id > 0) {
        $where_conditions[] = "p.category_id = ?";
        $params[] = $category_id;
    }
    
    // ORDER BY 조건
    $order_by = "p.name_kr ASC";
    switch ($sort) {
        case 'name':
            $order_by = "p.name_kr ASC";
            break;
        case 'price_asc':
            $order_by = "i.selling_price ASC";
            break;
        case 'price_desc':
            $order_by = "i.selling_price DESC";
            break;
        case 'date_new':
            $order_by = "p.created_at DESC";
            break;
    }
    
    // 전체 카운트 쿼리
    $count_sql = "SELECT COUNT(DISTINCT p.id) as total
                  FROM products p
                  LEFT JOIN categories c ON p.category_id = c.id
                  LEFT JOIN inventory i ON p.id = i.product_id
                  WHERE " . implode(' AND ', $where_conditions);
    
    if (!empty($params)) {
        $count_stmt = $conn->prepare($count_sql);
        $count_stmt->bind_param(str_repeat('s', count($params)), ...$params);
        $count_stmt->execute();
        $count_result = $count_stmt->get_result();
        $total_count = $count_result->fetch_assoc()['total'];
        $count_stmt->close();
    } else {
        $count_result = $conn->query($count_sql);
        $total_count = $count_result->fetch_assoc()['total'];
    }
    
    // 메인 쿼리
    $sql = "SELECT 
                p.id,
                p.barcode,
                p.name_kr,
                p.name_en,
                p.description,
                p.category_id,
                c.name as category_name,
                i.cost_price,
                i.selling_price,
                i.quantity,
                p.image_path,
                p.status
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN inventory i ON p.id = i.product_id
            WHERE " . implode(' AND ', $where_conditions) . "
            ORDER BY {$order_by}
            LIMIT ? OFFSET ?";
    
    // prepared statement로 메인 쿼리 실행
    $all_params = array_merge($params, [$limit, $offset]);
    
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $param_types = str_repeat('s', count($params)) . 'ii'; // 검색 파라미터들 + limit, offset
        $stmt->bind_param($param_types, ...$all_params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
    }
    
    if (!$result) {
        throw new Exception("쿼리 실행 실패: " . $conn->error);
    }
    
    $products = [];
    while ($row = $result->fetch_assoc()) {
        $products[] = [
            'id' => (int)$row['id'],
            'barcode' => $row['barcode'],
            'name_kr' => $row['name_kr'],
            'name_en' => $row['name_en'],
            'description' => $row['description'],
            'category_id' => (int)$row['category_id'],
            'category_name' => $row['category_name'],
            'cost_price' => (float)$row['cost_price'],
            'selling_price' => (float)$row['selling_price'],
            'quantity' => (int)$row['quantity'],
            'image_path' => $row['image_path'],
            'image' => $row['image_path'] ? '/homekmart/admin/uploads/' . $row['image_path'] : null,
            'status' => $row['status']
        ];
    }
    
    $stmt->close();
    
    // 페이징 정보 계산
    $total_pages = ceil($total_count / $limit);
    
    echo json_encode([
        'success' => true,
        'products' => $products,
        'total' => (int)$total_count,
        'current_page' => $page,
        'total_pages' => $total_pages,
        'limit' => $limit,
        'search' => $search,
        'category_id' => $category_id,
        'sort' => $sort,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => '상품 조회 중 오류가 발생했습니다.',
        'details' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>