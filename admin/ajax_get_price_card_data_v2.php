<?php
// 출력 버퍼 정리
ob_clean();

// 오류 출력 완전 차단
error_reporting(0);
ini_set('display_errors', 0);

// JSON 헤더 먼저 설정
header('Content-Type: application/json; charset=utf-8');

// 세션 확인
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 로그인 확인
if (!isset($_SESSION['user_id'])) {
    die(json_encode(['success' => false, 'message' => 'Not logged in']));
}

// POST 요청 확인
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die(json_encode(['success' => false, 'message' => 'Invalid request method']));
}

// 입력 데이터 받기
$input = json_decode(file_get_contents('php://input'), true);
$ids = isset($input['ids']) ? $input['ids'] : [];

if (empty($ids)) {
    die(json_encode(['success' => false, 'message' => 'No IDs provided']));
}

// 데이터베이스 연결
require_once dirname(__FILE__) . '/../config/db_config.php';
$conn = get_db_connection();

if (!$conn) {
    die(json_encode(['success' => false, 'message' => 'Database connection failed']));
}

// ID 배열 안전 처리
$ids = array_map('intval', $ids);
$placeholders = str_repeat('?,', count($ids) - 1) . '?';

// 쿼리 실행
$query = "
    SELECT DISTINCT
        id,
        sku,
        product_name_en,
        product_name_ko,
        new_selling_price as selling_price
    FROM price_change_history
    WHERE id IN ($placeholders)
";

$stmt = $conn->prepare($query);
if (!$stmt) {
    die(json_encode(['success' => false, 'message' => 'Query failed']));
}

// 파라미터 바인딩
$types = str_repeat('i', count($ids));
$stmt->bind_param($types, ...$ids);

if (!$stmt->execute()) {
    die(json_encode(['success' => false, 'message' => 'Execution failed']));
}

$result = $stmt->get_result();
$products = [];

while ($row = $result->fetch_assoc()) {
    $products[] = [
        'id' => $row['id'],
        'sku' => $row['sku'] ?: 'N/A',
        'product_name_en' => $row['product_name_en'] ?: '',
        'product_name_ko' => $row['product_name_ko'] ?: '',
        'selling_price' => number_format($row['selling_price'] ?: 0)
    ];
}

$stmt->close();
$conn->close();

// 성공 응답
echo json_encode(['success' => true, 'products' => $products]);
exit;
?>