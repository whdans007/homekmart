<?php
/**
 * GET mall/admin/ajax/get_driver_locations.php
 * Design Ref: mall-delivery-dispatch.design.md §4.1, §5.4 — 실시간 배송 지도 폴링 조회
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../lib/session_helper.php';
require_once __DIR__ . '/../../../lib/permission_helper.php';
require_once __DIR__ . '/../../../config/db_config.php';

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

try {
    $conn = get_db_connection();
    $result = $conn->query(
        "SELECT d.id AS driver_id, d.name, d.last_lat, d.last_lng, d.last_seen_at,
                a.order_id, o.order_number
         FROM mall_drivers d
         INNER JOIN mall_order_driver_assignments a ON a.driver_id = d.id AND a.status = 'delivering'
         INNER JOIN mall_orders o ON o.id = a.order_id
         WHERE d.last_lat IS NOT NULL AND d.last_lng IS NOT NULL"
    );
    $drivers = $result->fetch_all(MYSQLI_ASSOC);
    $conn->close();

    echo json_encode(['success' => true, 'data' => ['drivers' => $drivers]]);
} catch (Exception $e) {
    error_log('get_driver_locations.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
