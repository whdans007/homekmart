<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/partials/auth_check.php';
require_once __DIR__ . '/../config/db_config.php';

// 매입관리 권한 확인
if (!has_permission('purchase_management')) {
    echo json_encode(['success' => false, 'error' => '권한이 없습니다.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => '잘못된 요청 방식입니다.']);
    exit;
}

$product_id = intval($_POST['product_id'] ?? 0);
$margin_rate = floatval($_POST['margin_rate'] ?? 0);
$selling_price = floatval($_POST['selling_price'] ?? 0);
$store_id = intval($_POST['store_id'] ?? 0);

if ($product_id <= 0 || $store_id <= 0) {
    echo json_encode(['success' => false, 'error' => '필수 매개변수가 누락되었습니다.']);
    exit;
}

if ($margin_rate < 0 || $margin_rate > 1000) {
    echo json_encode(['success' => false, 'error' => '마진율은 0~1000% 사이여야 합니다.']);
    exit;
}

$conn = get_db_connection();

try {
    $conn->autocommit(false);
    
    // inventory 테이블의 selling_price 업데이트
    $update_sql = "UPDATE inventory SET selling_price = ? WHERE product_id = ? AND store_id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("dii", $selling_price, $product_id, $store_id);
    
    if (!$update_stmt->execute()) {
        throw new Exception('데이터베이스 업데이트 실패');
    }
    
    if ($update_stmt->affected_rows === 0) {
        throw new Exception('업데이트할 데이터가 없습니다.');
    }
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => '마진율과 판매가가 업데이트되었습니다.',
        'data' => [
            'product_id' => $product_id,
            'margin_rate' => $margin_rate,
            'selling_price' => $selling_price
        ]
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    error_log("마진율 업데이트 오류: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} finally {
    $conn->autocommit(true);
    $conn->close();
}
?>