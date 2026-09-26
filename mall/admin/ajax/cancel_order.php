<?php
/**
 * POST mall/admin/ajax/cancel_order.php
 * 고객 요청 등으로 주문을 취소 처리한다. 아직 배송기사에게 배정되기 전(접수대기/확인됨/상품준비중/
 * 준비완료) 단계에서만 허용한다 — 이미 기사가 배정/출발한 뒤에는 관리자가 임의로 취소할 수 없고
 * (기존 상태 자동전이 원칙과 동일), 그 경우엔 기사에게 직접 연락해 배송실패 처리를 거쳐야 한다.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../lib/session_helper.php';
require_once __DIR__ . '/../../../lib/permission_helper.php';
require_once __DIR__ . '/../../../config/db_config.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../../lib/inventory_service.php';

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
$reason = trim($_POST['reason'] ?? '');

if ($order_id <= 0 || $reason === '') {
    json_error('VALIDATION_ERROR', '취소 사유를 입력해주세요');
}
if (mb_strlen($reason) > 255) {
    json_error('VALIDATION_ERROR', '취소 사유는 255자 이내로 입력해주세요');
}

$cancellable_statuses = ['pending', 'confirmed', 'preparing', 'ready'];

try {
    $conn = get_db_connection();

    $check = $conn->prepare('SELECT status, store_id, order_number FROM mall_orders WHERE id = ?');
    $check->bind_param('i', $order_id);
    $check->execute();
    $order = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$order) {
        $conn->close();
        json_error('VALIDATION_ERROR', '대상 주문을 찾을 수 없습니다', 404);
    }
    if (!in_array($order['status'], $cancellable_statuses, true)) {
        $conn->close();
        json_error('INVALID_STATE_TRANSITION', '배송기사가 배정되기 전(접수대기~준비완료) 주문만 취소할 수 있습니다');
    }

    $conn->begin_transaction();

    $stmt = $conn->prepare(
        "UPDATE mall_orders SET status = 'cancelled', cancel_reason = ?, cancelled_at = NOW() WHERE id = ?"
    );
    $stmt->bind_param('si', $reason, $order_id);
    $stmt->execute();
    $stmt->close();

    // Design §4.3: 취소 시 주문 생성 시점에 반영했던 MALL_OUT을 RETURN_IN으로 복구한다.
    // 신선상품(mall_fresh_order_items)은 재고 원장 대상 외(Plan §3 제외)라 여기서 다루지 않는다.
    $items_stmt = $conn->prepare('SELECT id, product_id, quantity FROM mall_order_items WHERE order_id = ?');
    $items_stmt->bind_param('i', $order_id);
    $items_stmt->execute();
    $items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $items_stmt->close();

    foreach ($items as $order_item) {
        $restore_result = inventory_apply_delta($conn, [
            'store_id' => (int)$order['store_id'],
            'product_id' => (int)$order_item['product_id'],
            'quantity_change' => (float)$order_item['quantity'],
            'event_type' => 'RETURN_IN',
            'source_type' => 'mall_order_item_cancel',
            'source_id' => (int)$order_item['id'],
            'remarks' => "몰 주문 취소 복구 (Order: {$order['order_number']})",
            'manage_transaction' => false,
        ]);
        if (!$restore_result['success']) {
            throw new Exception('재고 복구 중 오류가 발생했습니다: ' . $restore_result['error']);
        }
    }

    $conn->commit();
    $conn->close();

    echo json_encode(['success' => true, 'data' => ['order_id' => $order_id, 'status' => 'cancelled']]);
} catch (Exception $e) {
    if (isset($conn) && $conn->ping()) {
        $conn->rollback();
        $conn->close();
    }
    error_log('cancel_order.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
