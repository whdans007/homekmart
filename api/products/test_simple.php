<?php
/**
 * 간단한 상품 조회 테스트
 */

// 에러 표시 활성화
error_reporting(E_ALL);
ini_set('display_errors', 1);

// CORS 헤더
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');

try {
    // 데이터베이스 설정
    require_once __DIR__ . '/../../config/db_config.php';

    $conn = get_db_connection();

    if (!$conn) {
        throw new Exception("Database connection failed");
    }

    // store_id 파라미터 확인
    $store_id = isset($_GET['store_id']) ? intval($_GET['store_id']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 5;

    // 간단한 쿼리
    $sql = "
        SELECT
            p.id,
            p.name,
            p.barcode,
            i.selling_price,
            i.quantity
        FROM products p
        INNER JOIN inventory i ON p.id = i.product_id
        WHERE i.store_id = ?
        LIMIT ?
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $store_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $products = [];
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }

    echo json_encode([
        'success' => true,
        'count' => count($products),
        'store_id' => $store_id,
        'data' => $products
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
