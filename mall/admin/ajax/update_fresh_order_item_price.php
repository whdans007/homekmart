<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../lib/session_helper.php';
require_once __DIR__ . '/../../../lib/permission_helper.php';
require_once __DIR__ . '/../../../config/db_config.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../lib/order.php';
function fresh_price_json_error($code, $message, $http = 400) { http_response_code($http); echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]); exit; }
if (!is_logged_in() || !has_permission('mall_management')) fresh_price_json_error('UNAUTHORIZED', '권한이 없습니다', 403);
if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) fresh_price_json_error('CSRF_INVALID', '요청이 만료되었습니다', 403);
$itemId = (int)($_POST['mall_fresh_order_item_id'] ?? 0);
$price = filter_var($_POST['unit_price'] ?? null, FILTER_VALIDATE_FLOAT);
if ($itemId <= 0 || $price === false || $price < 0) fresh_price_json_error('VALIDATION_ERROR', '판매가를 확인해주세요');
try {
    $conn = get_db_connection();
    $lookup = $conn->prepare("SELECT foi.order_id, o.status FROM mall_fresh_order_items foi JOIN mall_orders o ON o.id = foi.order_id WHERE foi.id = ?");
    $lookup->bind_param('i', $itemId); $lookup->execute();
    $item = $lookup->get_result()->fetch_assoc(); $lookup->close();
    if (!$item) { $conn->close(); fresh_price_json_error('NOT_FOUND', '주문 항목을 찾을 수 없습니다', 404); }
    if (!in_array($item['status'], ['pending', 'confirmed', 'preparing', 'ready'], true)) { $conn->close(); fresh_price_json_error('INVALID_STATE', '현재 상태에서는 가격을 변경할 수 없습니다'); }
    $orderId = (int)$item['order_id']; $price = round((float)$price, 2);
    $stmt = $conn->prepare('UPDATE mall_fresh_order_items SET unit_price_snapshot = ?, estimated_price = ?, confirmed_price = ? WHERE id = ?');
    $stmt->bind_param('dddi', $price, $price, $price, $itemId); $stmt->execute(); $stmt->close();
    $totals = mall_recalculate_order_totals($conn, $orderId); $conn->close();
    echo json_encode(['success' => true, 'data' => ['order_id' => $orderId, 'price' => $price, 'totals' => $totals]]);
} catch (Throwable $e) { if (isset($conn) && $conn->ping()) $conn->close(); error_log('update_fresh_order_item_price.php: ' . $e->getMessage()); fresh_price_json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500); }
