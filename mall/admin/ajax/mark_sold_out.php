<?php
/**
 * POST mall/admin/ajax/mark_sold_out.php
 * 주문 상세 모달에서 재고 소진(또는 재입고)을 확인한 즉시 해당 주문 항목의 품절 상태를 전환하고,
 * 몰 화면 진열 상태(mall_products.is_sold_out)도 함께 맞춘 뒤 주문 금액을 다시 계산한다.
 * sold_out=1(기본값) 이면 품절 처리, sold_out=0 이면 품절 취소.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../lib/session_helper.php';
require_once __DIR__ . '/../../../lib/permission_helper.php';
require_once __DIR__ . '/../../../config/db_config.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../lib/order.php';

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

$order_item_id = (int)($_POST['order_item_id'] ?? 0);
$sold_out = ($_POST['sold_out'] ?? '1') === '0' ? 0 : 1;
if ($order_item_id <= 0) {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}

try {
    $conn = get_db_connection();

    $lookup = $conn->prepare('SELECT order_id, product_id FROM mall_order_items WHERE id = ?');
    $lookup->bind_param('i', $order_item_id);
    $lookup->execute();
    $item = $lookup->get_result()->fetch_assoc();
    $lookup->close();

    if (!$item) {
        $conn->close();
        json_error('VALIDATION_ERROR', '대상 주문 항목을 찾을 수 없습니다', 404);
    }
    $order_id = (int)$item['order_id'];
    $product_id = (int)$item['product_id'];

    $conn->begin_transaction();

    $update_item = $conn->prepare('UPDATE mall_order_items SET is_sold_out = ? WHERE id = ?');
    $update_item->bind_param('ii', $sold_out, $order_item_id);
    $update_item->execute();
    $update_item->close();

    $update_product = $conn->prepare('UPDATE mall_products SET is_sold_out = ? WHERE product_id = ?');
    $update_product->bind_param('ii', $sold_out, $product_id);
    $update_product->execute();
    $update_product->close();

    $totals = mall_recalculate_order_totals($conn, $order_id);

    $conn->commit();
    $conn->close();

    echo json_encode(['success' => true, 'data' => ['order_id' => $order_id, 'totals' => $totals]]);
} catch (Exception $e) {
    if (isset($conn) && $conn->ping()) {
        $conn->rollback();
        $conn->close();
    }
    error_log('mark_sold_out.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
