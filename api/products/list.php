<?php
/**
 * 상품 목록 조회 API (test.php 기반)
 */

// 모든 에러 표시
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// CORS
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');

// config 로드
require_once __DIR__ . '/../../config/db_config.php';

// DB 연결
$conn = get_db_connection();

// 파라미터
$store_id = isset($_GET['store_id']) ? intval($_GET['store_id']) : 1;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;
$offset = ($page - 1) * $limit;

// 전체 개수
$count_sql = "SELECT COUNT(DISTINCT p.id) as total FROM products p INNER JOIN inventory i ON p.id = i.product_id WHERE i.store_id = $store_id";
$count_result = $conn->query($count_sql);
$total = $count_result->fetch_assoc()['total'];

// 상품 목록
$sql = "
    SELECT
        p.id,
        p.name_ko,
        p.name_en,
        p.sku,
        p.description,
        p.category_id,
        c.name as category_name,
        p.brand_id,
        b.name_ko as brand_name,
        i.selling_price,
        i.cost_price,
        i.quantity,
        p.image_url,
        p.is_active
    FROM products p
    INNER JOIN inventory i ON p.id = i.product_id
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN brands b ON p.brand_id = b.id
    WHERE i.store_id = $store_id
    ORDER BY p.name_ko ASC
    LIMIT $limit OFFSET $offset
";

$result = $conn->query($sql);

$products = [];
while ($row = $result->fetch_assoc()) {
    $row['selling_price'] = floatval($row['selling_price']);
    $row['cost_price'] = floatval($row['cost_price']);
    $row['quantity'] = intval($row['quantity']);
    $row['is_active'] = (bool) intval($row['is_active']);
    $products[] = $row;
}

$total_pages = ceil($total / $limit);

echo json_encode([
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
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
