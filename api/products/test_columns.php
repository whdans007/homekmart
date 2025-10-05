<?php
/**
 * products 테이블 컬럼 구조 확인
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

// 3단계: products 테이블 구조 확인
$sql = "DESCRIBE products";
$result = $conn->query($sql);

$columns = [];
while ($row = $result->fetch_assoc()) {
    $columns[] = $row;
}

// 4단계: 출력
echo json_encode([
    'success' => true,
    'table' => 'products',
    'columns' => $columns
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
