<?php
/**
 * 상품 목록 조회 API
 * GET /api/products
 * 쿼리 파라미터:
 * - page: 페이지 번호 (기본값: 1)
 * - limit: 페이지당 항목 수 (기본값: 20, 최대: 100)
 * - store_id: 점포 ID (필수)
 * - category_id: 카테고리 ID (선택)
 * - search: 검색어 (선택)
 */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiError(405, 'Method not allowed');
}

// 점포 ID 필수
if (!isset($_GET['store_id']) || empty($_GET['store_id'])) {
    apiError(400, 'store_id is required');
}

$store_id = intval($_GET['store_id']);
$category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : null;
$search = isset($_GET['search']) ? trim($_GET['search']) : null;

$pagination = getPaginationParams();

try {
    $pdo = getApiDbConnection();

    // WHERE 조건 구성
    $where_clauses = ["i.store_id = ?"];
    $params = [$store_id];

    if ($category_id) {
        $where_clauses[] = "p.category_id = ?";
        $params[] = $category_id;
    }

    if ($search) {
        $where_clauses[] = "(p.name LIKE ? OR p.barcode LIKE ? OR p.sku LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }

    $where_clause = implode(' AND ', $where_clauses);

    // 전체 개수 조회
    $count_sql = "
        SELECT COUNT(DISTINCT p.id)
        FROM products p
        INNER JOIN inventory i ON p.id = i.product_id
        WHERE $where_clause
    ";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total = $count_stmt->fetchColumn();

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

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, [$pagination['limit'], $pagination['offset']]));
    $products = $stmt->fetchAll();

    // 가격 포맷팅
    foreach ($products as &$product) {
        $product['selling_price'] = floatval($product['selling_price']);
        $product['cost_price'] = floatval($product['cost_price']);
        $product['quantity'] = intval($product['quantity']);
        $product['is_active'] = (bool) $product['is_active'];
    }

    $response = paginatedResponse($products, $total, $pagination['page'], $pagination['limit']);
    apiSuccess($response);

} catch (PDOException $e) {
    error_log("Products fetch error: " . $e->getMessage());
    apiError(500, 'Failed to fetch products');
}
?>
