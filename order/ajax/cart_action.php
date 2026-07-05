<?php
// Design Ref: §5.2 — 장바구니 CRUD AJAX
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/order_helper.php';
ord_require_manager();
ord_verify_csrf();
header('Content-Type: application/json; charset=utf-8');

$action  = $_POST['action'] ?? '';
$storeId = ord_current_store_id();

if (!$storeId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '점포 정보가 없습니다.']);
    exit;
}

try {
    $conn = get_ord_db();
    ord_ensure_cart_schema($conn);

    if ($action === 'add' || $action === 'update') {
        $itemId  = (int)($_POST['inventory_item_id'] ?? 0);
        $vendorId = (int)($_POST['vendor_id'] ?? 0);
        $qty     = (float)($_POST['quantity'] ?? 1);
        if ($qty <= 0) $qty = 1;

        if (!$itemId || !$vendorId) { throw new Exception('필수 파라미터 누락'); }

        $addedBy = ord_current_user_id() ?: null;
        $stmt = $conn->prepare("
            INSERT INTO order_cart_items (store_id, vendor_id, inventory_item_id, quantity, added_by)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), updated_at = NOW()
        ");
        $stmt->bind_param('iiidi', $storeId, $vendorId, $itemId, $qty, $addedBy);
        $stmt->execute();
        $stmt->close();

    } elseif ($action === 'remove') {
        $itemId = (int)($_POST['inventory_item_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM order_cart_items WHERE store_id = ? AND inventory_item_id = ?");
        $stmt->bind_param('ii', $storeId, $itemId);
        $stmt->execute();
        $stmt->close();

    } elseif ($action === 'clear_vendor') {
        $vendorId = (int)($_POST['vendor_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM order_cart_items WHERE store_id = ? AND vendor_id = ?");
        $stmt->bind_param('ii', $storeId, $vendorId);
        $stmt->execute();
        $stmt->close();

    } else {
        throw new Exception('알 수 없는 액션');
    }

    // 장바구니 총 수 반환
    $stmt = $conn->prepare("SELECT COUNT(*) FROM order_cart_items WHERE store_id = ?");
    $stmt->bind_param('i', $storeId);
    $stmt->execute();
    $stmt->bind_result($cartCount);
    $stmt->fetch();
    $stmt->close();
    $conn->close();

    echo json_encode(['success' => true, 'cart_count' => (int)$cartCount]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
