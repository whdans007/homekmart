<?php
/**
 * POST mall/ajax/toggle_wishlist.php
 * Design Ref: shopping-mall.design.md §4.1
 * product_id를 보내면 담기/해제를 토글하고, wishlist_id를 보내면 그 항목만 제거한다(둘 다 소유자 검증).
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../lib/auth.php';

function json_error($code, $message, $http = 400) {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
    exit;
}

if (!mall_is_logged_in()) {
    json_error('UNAUTHORIZED', '로그인이 필요합니다', 401);
}

$member = mall_current_member();
$wishlist_id = (int)($_POST['wishlist_id'] ?? 0);
$product_id = (int)($_POST['product_id'] ?? 0);

$conn = get_db_connection();

if ($wishlist_id > 0) {
    $stmt = $conn->prepare('DELETE FROM mall_wishlist WHERE id = ? AND member_id = ?');
    $stmt->bind_param('ii', $wishlist_id, $member['id']);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $conn->close();

    echo json_encode(['success' => true, 'data' => ['wishlisted' => false, 'removed' => $affected > 0]]);
    exit;
}

if ($product_id <= 0) {
    $conn->close();
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}

$channel = ($member['member_type'] === 'wholesale') ? 'wholesale' : 'retail';

$check = $conn->prepare('SELECT id FROM mall_wishlist WHERE member_id = ? AND product_id = ? AND channel = ?');
$check->bind_param('iis', $member['id'], $product_id, $channel);
$check->execute();
$existing = $check->get_result()->fetch_assoc();
$check->close();

if ($existing) {
    $del = $conn->prepare('DELETE FROM mall_wishlist WHERE id = ?');
    $del->bind_param('i', $existing['id']);
    $del->execute();
    $del->close();
    $conn->close();
    echo json_encode(['success' => true, 'data' => ['wishlisted' => false]]);
} else {
    $ins = $conn->prepare('INSERT INTO mall_wishlist (member_id, product_id, channel) VALUES (?, ?, ?)');
    $ins->bind_param('iis', $member['id'], $product_id, $channel);
    $ins->execute();
    $ins->close();
    $conn->close();
    echo json_encode(['success' => true, 'data' => ['wishlisted' => true]]);
}
