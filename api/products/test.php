<?php
/**
 * 최소한의 products API 테스트
 */

// 모든 에러 표시
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// CORS
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');

// 1단계: config 로드
require_once __DIR__ . '/../../config/db_config.php';

// 2단계: DB 연결
$conn = get_db_connection();

// 3단계: 쿼리 실행
$sql = "
    SELECT
        p.id,
        p.name_ko,
        p.name_en,
        i.selling_price,
        i.quantity
    FROM products p
    INNER JOIN inventory i ON p.id = i.product_id
    WHERE i.store_id = 1
    LIMIT 5
";

$result = $conn->query($sql);

// 4단계: 결과 처리
$products = [];
while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}

// 5단계: 출력
echo json_encode([
    'success' => true,
    'data' => $products
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
