<?php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

if (!has_permission('product_management') && !has_permission('shop_access')) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.']);
    exit;
}

$barcode = trim($_POST['barcode'] ?? '');

if (empty($barcode)) {
    echo json_encode(['success' => false, 'message' => '바코드를 입력해주세요.']);
    exit;
}

try {
    $conn = get_db_connection();

    // 현재 사용자의 store_id 확인
    $current_user_id = $_SESSION['user_id'] ?? 0;
    $current_store_id = $_SESSION['store_id'] ?? 0;

    if (empty($current_store_id) && !empty($current_user_id)) {
        $user_stmt = $conn->prepare("SELECT store_id FROM users WHERE id = ?");
        $user_stmt->bind_param("i", $current_user_id);
        $user_stmt->execute();
        $user_row = $user_stmt->get_result()->fetch_assoc();
        if ($user_row) $current_store_id = $user_row['store_id'];
        $user_stmt->close();
    }

    if (empty($current_store_id)) {
        echo json_encode(['success' => false, 'message' => '점포 정보를 찾을 수 없습니다.']);
        exit;
    }

    // SKU로 상품 조회
    $product_stmt = $conn->prepare("SELECT id, name_ko, name_en, sku FROM products WHERE sku = ? LIMIT 1");
    $product_stmt->bind_param("s", $barcode);
    $product_stmt->execute();
    $product = $product_stmt->get_result()->fetch_assoc();
    $product_stmt->close();

    if (!$product) {
        echo json_encode(['success' => false, 'message' => "[{$barcode}] 없는 상품입니다."]);
        exit;
    }

    // 해당 상품이 현재 점포의 주문 리스트에 있는지 확인 (최근 수정된 리스트 우선)
    $item_stmt = $conn->prepare("
        SELECT soli.id as item_id, soli.order_list_id, soli.order_status, sol.title as list_title
        FROM store_order_list_items soli
        JOIN store_order_lists sol ON soli.order_list_id = sol.id
        WHERE sol.store_id = ? AND soli.product_id = ?
        ORDER BY sol.updated_at DESC
        LIMIT 1
    ");
    $item_stmt->bind_param("ii", $current_store_id, $product['id']);
    $item_stmt->execute();
    $item = $item_stmt->get_result()->fetch_assoc();
    $item_stmt->close();

    if (!$item) {
        $product_name = $product['name_ko'] ?: $product['name_en'];
        echo json_encode([
            'success' => false,
            'message' => "[{$product_name}] 주문 리스트에 없는 상품입니다."
        ]);
        exit;
    }

    // 현재 상태에 따라 반대로 토글
    $new_status = ($item['order_status'] === '주문') ? '비주문' : '주문';

    $update_stmt = $conn->prepare("UPDATE store_order_list_items SET order_status = ? WHERE id = ?");
    $update_stmt->bind_param("si", $new_status, $item['item_id']);
    $update_stmt->execute();
    $affected = $update_stmt->affected_rows;
    $update_stmt->close();
    $conn->close();

    if ($affected > 0) {
        $product_name = $product['name_ko'] ?: $product['name_en'];
        echo json_encode([
            'success' => true,
            'message' => "[{$product_name}] {$new_status}으로 변경되었습니다.",
            'product_name' => $product_name,
            'new_status' => $new_status,
            'list_title' => $item['list_title'],
            'list_id' => $item['order_list_id']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '상태 변경에 실패했습니다.']);
    }

} catch (Exception $e) {
    error_log("Barcode toggle error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '오류가 발생했습니다.']);
}
