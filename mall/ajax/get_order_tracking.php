<?php
/**
 * GET mall/ajax/get_order_tracking.php?order_id=X
 * Design Ref: mall-delivery-dispatch.design.md §4.2 — 고객용 주문 상태+기사 위치 폴링 조회
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
$order_id = (int)($_GET['order_id'] ?? 0);

if ($order_id <= 0) {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}

try {
    $conn = get_db_connection();
    // 소유권 검증: 본인 주문만 조회 가능(IDOR 방지)
    $stmt = $conn->prepare('SELECT id, status, current_driver_id FROM mall_orders WHERE id = ? AND member_id = ?');
    $stmt->bind_param('ii', $order_id, $member['id']);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        $conn->close();
        json_error('UNAUTHORIZED', '주문을 찾을 수 없습니다', 404);
    }

    $driver_location = null;
    if (in_array($order['status'], ['delivering', 'arrived'], true) && $order['current_driver_id']) {
        $driver_stmt = $conn->prepare('SELECT last_lat, last_lng, last_seen_at FROM mall_drivers WHERE id = ?');
        $driver_stmt->bind_param('i', $order['current_driver_id']);
        $driver_stmt->execute();
        $driver = $driver_stmt->get_result()->fetch_assoc();
        $driver_stmt->close();

        if ($driver && $driver['last_lat'] !== null && $driver['last_lng'] !== null) {
            $driver_location = ['lat' => (float)$driver['last_lat'], 'lng' => (float)$driver['last_lng'], 'recorded_at' => $driver['last_seen_at']];
        }
    }

    $conn->close();

    echo json_encode(['success' => true, 'data' => ['status' => $order['status'], 'driver_location' => $driver_location]]);
} catch (Exception $e) {
    error_log('get_order_tracking.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
