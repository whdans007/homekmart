<?php
/**
 * POST mall/ajax/submit_review.php
 * Design Ref: shopping-mall.design.md §4.1, §3.1 mall_reviews 구매 검증
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
$product_id = (int)($_POST['product_id'] ?? 0);
$order_id = (int)($_POST['order_id'] ?? 0);
$rating = (int)($_POST['rating'] ?? 0);
$comment = trim($_POST['comment'] ?? '');

if ($product_id <= 0 || $order_id <= 0 || $rating < 1 || $rating > 5) {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}

try {
    $conn = get_db_connection();

    // 구매 검증: 이 회원의 완료된 주문이며, 해당 주문에 이 상품이 포함되어 있어야 한다.
    $check = $conn->prepare(
        "SELECT oi.id
         FROM mall_orders o
         INNER JOIN mall_order_items oi ON oi.order_id = o.id
         WHERE o.id = ? AND o.member_id = ? AND o.status = 'completed' AND oi.product_id = ?"
    );
    $check->bind_param('iii', $order_id, $member['id'], $product_id);
    $check->execute();
    $verified = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$verified) {
        $conn->close();
        json_error('VALIDATION_ERROR', '구매 완료한 상품에만 리뷰를 작성할 수 있습니다', 403);
    }

    $dup = $conn->prepare('SELECT id FROM mall_reviews WHERE member_id = ? AND order_id = ? AND product_id = ?');
    $dup->bind_param('iii', $member['id'], $order_id, $product_id);
    $dup->execute();
    if ($dup->get_result()->fetch_assoc()) {
        $dup->close();
        $conn->close();
        json_error('VALIDATION_ERROR', '이미 이 주문에 대한 리뷰를 작성했습니다');
    }
    $dup->close();

    $stmt = $conn->prepare(
        'INSERT INTO mall_reviews (member_id, product_id, order_id, rating, comment) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iiiis', $member['id'], $product_id, $order_id, $rating, $comment);
    $stmt->execute();
    $review_id = $stmt->insert_id;
    $stmt->close();
    $conn->close();

    echo json_encode(['success' => true, 'data' => ['review_id' => $review_id]]);
} catch (Exception $e) {
    error_log('submit_review.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
