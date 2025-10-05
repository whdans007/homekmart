<?php
/**
 * 상품 목록 조회 API (MySQLi 버전)
 * GET /api/products/index_mysqli.php
 */

// 에러 표시
ini_set('display_errors', '1');
error_reporting(E_ALL);

// CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

// OPTIONS 요청 처리
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// DB 연결
require_once __DIR__ . '/../../config/db_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => ['message' => 'Method not allowed']], JSON_UNESCAPED_UNICODE);
    exit();
}

// 파라미터 검증
if (!isset($_GET['store_id']) || empty($_GET['store_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => ['message' => 'store_id is required']], JSON_UNESCAPED_UNICODE);
    exit();
}

$store_id = intval($_GET['store_id']);
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;
$offset = ($page - 1) * $limit;
$category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : null;
$search = isset($_GET['search']) ? trim($_GET['search']) : null;

try {
    $conn = get_db_connection();

    // WHERE 조건 구성
    $where_clauses = ["i.store_id = $store_id"];

    if ($category_id) {
        $where_clauses[] = "p.category_id = $category_id";
    }

    if ($search) {
        $search_escaped = $conn->real_escape_string($search);
        $where_clauses[] = "(p.name_ko LIKE '%$search_escaped%' OR p.name_en LIKE '%$search_escaped%' OR p.sku LIKE '%$search_escaped%')";
    }

    $where_clause = implode(' AND ', $where_clauses);

    // 전체 개수 조회
    $count_sql = "
        SELECT COUNT(DISTINCT p.id) as total
        FROM products p
        INNER JOIN inventory i ON p.id = i.product_id
        WHERE $where_clause
    ";
    $count_result = $conn->query($count_sql);
    $total = $count_result->fetch_assoc()['total'];

    // 상품 목록 조회
    $sql = "
        SELECT
            p.id,
            p.name_ko,
            p.name_en,
            p.sku,
            p.description,
            p.category_id,
            c.name_ko as category_name,
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
        ORDER BY p.name_ko ASC
        LIMIT $limit OFFSET $offset
    ";

    $result = $conn->query($sql);

    $products = [];
    while ($row = $result->fetch_assoc()) {
        $row['selling_price'] = floatval($row['selling_price']);
        $row['cost_price'] = floatval($row['cost_price']);
        $row['quantity'] = intval($row['quantity']);
        $row['is_active'] = (bool) $row['is_active'];
        $products[] = $row;
    }

    $total_pages = ceil($total / $limit);

    $response = [
        'success' => true,
        'data' => $products,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $total_pages,
            'total_items' => intval($total),
            'items_per_page' => $limit,
            'has_next' => $page < $total_pages,
            'has_prev' => $page > 1
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    error_log("Products API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'message' => 'Failed to fetch products',
            'details' => $e->getMessage()
        ]
    ], JSON_UNESCAPED_UNICODE);
}
?>
