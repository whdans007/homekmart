<?php
// 오류 출력 방지
error_reporting(0);
ini_set('display_errors', 0);

// 세션 시작
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__FILE__) . '/../config/db_config.php';
require_once dirname(__FILE__) . '/../lib/permission_helper.php';

header('Content-Type: application/json; charset=utf-8');

// 로그인 확인
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

// 권한 확인
if (!has_permission('admin_access')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$conn = get_db_connection();
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$ids = isset($input['ids']) ? $input['ids'] : [];

if (empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'No IDs provided']);
    exit;
}

// ID 배열을 안전하게 처리
$ids = array_map('intval', $ids);
$placeholders = str_repeat('?,', count($ids) - 1) . '?';

// price_change_history 테이블에서 최신 정보 가져오기
$store_id = $_SESSION['store_id'] ?? null;
$is_super_admin = ($_SESSION['role'] ?? '') === 'super_admin';

// super_admin이면 모든 데이터, 아니면 store_id로 필터링
if ($is_super_admin || !$store_id) {
    $query = "
        SELECT DISTINCT
            pch.id,
            pch.sku,
            pch.product_name_en,
            pch.product_name_ko,
            pch.new_selling_price as selling_price
        FROM price_change_history pch
        WHERE pch.id IN ($placeholders)
    ";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Query preparation failed: ' . $conn->error]);
        exit;
    }
    
    // 파라미터 바인딩 (ID만)
    $types = str_repeat('i', count($ids));
    $stmt->bind_param($types, ...$ids);
} else {
    $query = "
        SELECT DISTINCT
            pch.id,
            pch.sku,
            pch.product_name_en,
            pch.product_name_ko,
            pch.new_selling_price as selling_price
        FROM price_change_history pch
        WHERE pch.id IN ($placeholders)
        AND pch.store_id = ?
    ";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Query preparation failed: ' . $conn->error]);
        exit;
    }
    
    // 파라미터 바인딩 (ID + store_id)
    $types = str_repeat('i', count($ids)) . 'i';
    $params = array_merge($ids, [$store_id]);
    $stmt->bind_param($types, ...$params);
}

if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Query execution failed: ' . $stmt->error]);
    exit;
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

echo json_encode(['success' => true, 'products' => $products]);
?>