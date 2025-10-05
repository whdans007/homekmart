<?php
/**
 * 상품 목록 조회 API (수정 버전)
 * GET /api/products/index_fixed.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// CORS 헤더
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

// OPTIONS 요청 처리
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// GET 메서드만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

try {
    // 데이터베이스 연결
    require_once __DIR__ . '/../../config/db_config.php';
    $conn = get_db_connection();

    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    // 파라미터 받기
    $store_id = isset($_GET['store_id']) ? intval($_GET['store_id']) : null;
    $category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : null;
    $search = isset($_GET['search']) ? trim($_GET['search']) : null;
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;
    $offset = ($page - 1) * $limit;

    // store_id 필수
    if (!$store_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'store_id is required']);
        exit();
    }

    // WHERE 조건 구성
    $where_clauses = ["i.store_id = ?"];
    $params = [$store_id];
    $types = "i";

    if ($category_id) {
        $where_clauses[] = "p.category_id = ?";
        $params[] = $category_id;
        $types .= "i";
    }

    if ($search) {
        $where_clauses[] = "(p.name LIKE ? OR p.barcode LIKE ? OR p.sku LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= "sss";
    }

    $where_clause = implode(' AND ', $where_clauses);

    // 전체 개수 조회
    $count_sql = "
        SELECT COUNT(DISTINCT p.id) as total
        FROM products p
        INNER JOIN inventory i ON p.id = i.product_id
        WHERE $where_clause
    ";

    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param($types, ...$params);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total = $count_result->fetch_assoc()['total'];

    // 상품 목록 조회
    $sql = "
        SELECT
            p.id,
            p.name,
            p.barcode,
            p.sku,
            p.description,
            p.category_id,
            c.name as category_name,
            p.brand_id,
            b.name as brand_name,
            i.selling_price,
            i.cost_price,
            i.quantity,
            p.image_url,
            p.is_active
        FROM products p
        INNER JOIN inventory i ON p.id = i.product_id
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        WHERE $where_clause
        ORDER BY p.name ASC
        LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare($sql);
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $products = [];
    while ($row = $result->fetch_assoc()) {
        $products[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'barcode' => $row['barcode'],
            'sku' => $row['sku'],
            'description' => $row['description'],
            'category_id' => $row['category_id'] ? (int)$row['category_id'] : null,
            'category_name' => $row['category_name'],
            'brand_id' => $row['brand_id'] ? (int)$row['brand_id'] : null,
            'brand_name' => $row['brand_name'],
            'selling_price' => (float)$row['selling_price'],
            'cost_price' => $row['cost_price'] ? (float)$row['cost_price'] : null,
            'quantity' => (int)$row['quantity'],
            'image_url' => $row['image_url'],
            'is_active' => (bool)$row['is_active']
        ];
    }

    // 페이지네이션 정보
    $total_pages = ceil($total / $limit);
    $pagination = [
        'current_page' => $page,
        'total_pages' => $total_pages,
        'total_items' => (int)$total,
        'items_per_page' => $limit,
        'has_next' => $page < $total_pages,
        'has_prev' => $page > 1
    ];

    // 응답
    echo json_encode([
        'success' => true,
        'data' => [
            'data' => $products,
            'pagination' => $pagination
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_PRETTY_PRINT);
}
?>
