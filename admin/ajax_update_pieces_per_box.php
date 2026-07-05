<?php
header('Content-Type: application/json');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 권한 확인 (매입 페이지와 동일하게 purchase_management 권한 기준 - manager 등급 포함)
if (!is_logged_in() || !has_permission('purchase_management')) {
    echo json_encode(['success' => false, 'error' => '권한이 없습니다.']);
    exit;
}

// POST 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => '잘못된 요청 방식입니다.']);
    exit;
}

// JSON 데이터 또는 POST 데이터 파싱
$input = json_decode(file_get_contents('php://input'), true);

// JSON이 없으면 POST 데이터 사용
if (!$input) {
    $input = $_POST;
}

if (empty($input)) {
    echo json_encode(['success' => false, 'error' => 'POST 데이터가 없습니다.']);
    exit;
}

$product_id = $input['product_id'] ?? null;
$pieces_per_box = $input['pieces_per_box'] ?? null;

// 입력값 검증
if (!$product_id || !$pieces_per_box) {
    echo json_encode(['success' => false, 'error' => '상품 ID와 박스당 수량이 필요합니다.']);
    exit;
}

$pieces_per_box = (int)$pieces_per_box;
if ($pieces_per_box <= 0) {
    echo json_encode(['success' => false, 'error' => '박스당 수량은 1 이상이어야 합니다.']);
    exit;
}

try {
    $conn = get_db_connection();
    
    // 상품이 존재하는지 확인
    $check_stmt = $conn->prepare("SELECT id, name_ko, pieces_per_box FROM products WHERE id = ?");
    $check_stmt->bind_param("i", $product_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => '존재하지 않는 상품입니다.']);
        exit;
    }
    
    $product = $result->fetch_assoc();
    $check_stmt->close();
    
    // 박스당 수량이 동일하면 업데이트하지 않음
    if ($product['pieces_per_box'] == $pieces_per_box) {
        echo json_encode([
            'success' => true, 
            'message' => '박스당 수량이 이미 동일합니다.',
            'product_name' => $product['name_ko'],
            'old_pieces_per_box' => $product['pieces_per_box'],
            'new_pieces_per_box' => $pieces_per_box
        ]);
        exit;
    }
    
    // 박스당 수량 업데이트
    $update_stmt = $conn->prepare("UPDATE products SET pieces_per_box = ? WHERE id = ?");
    $update_stmt->bind_param("ii", $pieces_per_box, $product_id);
    
    if ($update_stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => '상품의 박스당 수량이 성공적으로 업데이트되었습니다.',
            'product_name' => $product['name_ko'],
            'old_pieces_per_box' => $product['pieces_per_box'],
            'new_pieces_per_box' => $pieces_per_box
        ]);
    } else {
        throw new Exception('박스당 수량 업데이트에 실패했습니다: ' . $update_stmt->error);
    }
    
    $update_stmt->close();
    
} catch (Exception $e) {
    error_log("박스당 수량 업데이트 오류: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>