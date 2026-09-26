<?php
/**
 * GET mall/admin/ajax/get_order_detail.php?order_id=123
 * 주문 관리 화면(orders.php)에서 행을 클릭했을 때 뜨는 상세 모달에 필요한 데이터를 한 번에 반환한다:
 * 주문/배송지 스냅샷/기사 정보 + 주문 항목 + 배정 가능한 활성 기사 목록.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../lib/session_helper.php';
require_once __DIR__ . '/../../../lib/permission_helper.php';
require_once __DIR__ . '/../../../config/db_config.php';
require_once __DIR__ . '/../../lib/delivery.php';

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

$order_id = (int)($_GET['order_id'] ?? 0);
if ($order_id <= 0) {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}

try {
    $conn = get_db_connection();

    $stmt = $conn->prepare(
        "SELECT o.id, o.order_number, o.channel, o.status, o.subtotal, o.discount_amount, o.shipping_fee, o.total_amount,
                o.estimated_ready_at, o.confirmed_at, o.ready_at, o.created_at, o.current_driver_id,
                o.cancel_reason, o.cancelled_at,
                o.ship_recipient_name, o.ship_phone, o.ship_region, o.ship_city, o.ship_barangay, o.ship_detail_address, o.ship_landmark,
                m.name AS member_name, m.email,
                cd.name AS current_driver_name,
                a.assigned_at, a.delivering_at, a.arrived_at, a.completed_at,
                (SELECT failed_reason FROM mall_order_driver_assignments
                 WHERE order_id = o.id AND status = 'failed' ORDER BY id DESC LIMIT 1) AS failed_reason
         FROM mall_orders o
         INNER JOIN mall_members m ON m.id = o.member_id
         LEFT JOIN mall_drivers cd ON cd.id = o.current_driver_id
         LEFT JOIN mall_order_driver_assignments a ON a.id = (
             SELECT id FROM mall_order_driver_assignments WHERE order_id = o.id ORDER BY id DESC LIMIT 1
         )
         WHERE o.id = ?"
    );
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        $conn->close();
        json_error('VALIDATION_ERROR', '대상 주문을 찾을 수 없습니다', 404);
    }

    $items_stmt = $conn->prepare(
        'SELECT oi.id AS order_item_id, oi.product_id, oi.product_name_snapshot, p.name_en AS product_name_en, oi.unit_price_snapshot,
                oi.discount_rate_snapshot, oi.quantity, oi.line_total, oi.is_sold_out
         FROM mall_order_items oi
         LEFT JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id = ? ORDER BY oi.id'
    );
    $items_stmt->bind_param('i', $order_id);
    $items_stmt->execute();
    $items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $items_stmt->close();

    $prep_stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'mall_default_prep_minutes'");
    $prep_stmt->execute();
    $prep_row = $prep_stmt->get_result()->fetch_assoc();
    $prep_stmt->close();
    $default_prep_minutes = $prep_row ? (int)$prep_row['setting_value'] : 30;

    $conn->close();

    $active_drivers = mall_driver_list_active();
    // Design Ref: mall-fresh-products.design.md §5.4 — 신선상품 라인은 mall_order_items와 완전히
    // 분리된 테이블이라 별도로 조회해 함께 내려준다.
    $fresh_items = mall_fresh_order_items_get_by_order($order_id);

    echo json_encode(['success' => true, 'data' => [
        'order' => $order,
        'items' => $items,
        'fresh_items' => $fresh_items,
        'active_drivers' => $active_drivers,
        'default_prep_minutes' => $default_prep_minutes,
    ]]);
} catch (Exception $e) {
    error_log('get_order_detail.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
