<?php
/**
 * POST mall/admin/ajax/update_order_status.php
 * Design Ref: mall-delivery-dispatch.design.md §3.1 — 주문 상태는 진행 단계에 따라 자동으로만
 * 바뀌어야 하고(접수확인/배정/배송시작/도착/완료는 각자 전용 함수로 처리) 관리자가 임의 상태로
 * 직접 바꿀 수 없어야 한다. 이 엔드포인트는 유일하게 남겨둔 수동 동작인 "취소"(상품준비중 →
 * 접수대기, orders.php의 취소 버튼)만 허용한다.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../lib/session_helper.php';
require_once __DIR__ . '/../../../lib/permission_helper.php';
require_once __DIR__ . '/../../../config/db_config.php';
require_once __DIR__ . '/../../lib/csrf.php';

function json_error($code, $message, $http = 400) {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
    exit;
}

if (!is_logged_in()) {
    json_error('UNAUTHORIZED', '로그인이 필요합니다', 401);
}
if (!has_mall_permission('mall_management')) {
    json_error('UNAUTHORIZED', '권한이 없습니다', 403);
}
if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
    json_error('CSRF_INVALID', '요청이 만료되었습니다. 새로고침 후 다시 시도해주세요', 403);
}

$order_id = (int)($_POST['order_id'] ?? 0);
$status = $_POST['status'] ?? '';

// 이 엔드포인트는 "취소"(상품준비중 → 접수대기) 단 하나의 전이만 허용한다.
// 그 외 모든 상태는 mall/lib/order.php, mall/lib/delivery.php의 전용 함수가
// 진행 단계(접수확인/배정/배송시작/도착/완료 등)에 따라 자동으로만 바꾼다.
if ($order_id <= 0 || $status !== 'pending') {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}

try {
    $conn = get_db_connection();

    $check = $conn->prepare('SELECT status FROM mall_orders WHERE id = ?');
    $check->bind_param('i', $order_id);
    $check->execute();
    $order = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$order) {
        $conn->close();
        json_error('VALIDATION_ERROR', '대상 주문을 찾을 수 없습니다', 404);
    }
    if ($order['status'] !== 'preparing') {
        $conn->close();
        json_error('INVALID_STATE_TRANSITION', '상품준비중 상태의 주문만 접수를 취소할 수 있습니다');
    }

    $stmt = $conn->prepare("UPDATE mall_orders SET status = 'pending' WHERE id = ?");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    echo json_encode(['success' => true, 'data' => ['order_id' => $order_id, 'status' => 'pending']]);
} catch (Exception $e) {
    error_log('update_order_status.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
