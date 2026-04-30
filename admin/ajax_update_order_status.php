<?php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 권한 확인
if (!has_permission('product_management') && !has_permission('shop_access')) {
    echo json_encode([
        'success' => false,
        'message' => '권한이 없습니다.'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => '잘못된 요청입니다.'
    ]);
    exit;
}

$product_id = (int)($_POST['product_id'] ?? 0);
$list_id = (int)($_POST['list_id'] ?? 0);
$order_status = $_POST['order_status'] ?? '주문';

// 입력값 검증
if ($product_id <= 0 || $list_id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => '유효하지 않은 상품 또는 리스트 ID입니다.'
    ]);
    exit;
}

if (!in_array($order_status, ['주문', '비주문'])) {
    echo json_encode([
        'success' => false,
        'message' => '유효하지 않은 주문 상태입니다.'
    ]);
    exit;
}

try {
    $conn = get_db_connection();
    if (!$conn) {
        throw new Exception("데이터베이스 연결 실패");
    }

    // 현재 사용자의 store_id 확인
    $current_user_id = $_SESSION['user_id'] ?? 0;
    $current_store_id = $_SESSION['store_id'] ?? 0;

    if (empty($current_store_id) && !empty($current_user_id)) {
        $user_sql = "SELECT store_id FROM users WHERE id = ?";
        $user_stmt = $conn->prepare($user_sql);
        if ($user_stmt) {
            $user_stmt->bind_param("i", $current_user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            if ($user_row = $user_result->fetch_assoc()) {
                $current_store_id = $user_row['store_id'];
            }
            $user_stmt->close();
        }
    }

    // 리스트가 사용자의 store에 속하는지 확인
    if (!empty($current_store_id)) {
        $check_sql = "SELECT id FROM store_order_lists WHERE id = ? AND store_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $list_id, $current_store_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        if ($check_result->num_rows === 0) {
            throw new Exception("접근 권한이 없습니다.");
        }
        $check_stmt->close();
    }

    // 주문 상태 업데이트
    $update_sql = "UPDATE store_order_list_items
                   SET order_status = ?
                   WHERE order_list_id = ? AND product_id = ?";
    $update_stmt = $conn->prepare($update_sql);
    if (!$update_stmt) {
        throw new Exception("Prepare 실패: " . $conn->error);
    }

    $update_stmt->bind_param("sii", $order_status, $list_id, $product_id);
    if (!$update_stmt->execute()) {
        throw new Exception("업데이트 실패: " . $update_stmt->error);
    }

    $affected_rows = $update_stmt->affected_rows;
    $update_stmt->close();
    $conn->close();

    if ($affected_rows > 0) {
        echo json_encode([
            'success' => true,
            'message' => '주문 상태가 업데이트되었습니다.',
            'order_status' => $order_status
        ]);
    } else {
        throw new Exception("업데이트된 항목이 없습니다.");
    }

} catch (Exception $e) {
    error_log("Order status update error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '오류가 발생했습니다: ' . $e->getMessage()
    ]);
}
