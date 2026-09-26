<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../lib/session_helper.php';
require_once __DIR__ . '/../../../lib/permission_helper.php';
require_once __DIR__ . '/../../../config/db_config.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../lib/order.php';
function fresh_order_json_error($code, $message, $http = 400) { http_response_code($http); echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]); exit; }
if (!is_logged_in() || !has_mall_permission('mall_management')) fresh_order_json_error('UNAUTHORIZED', '권한이 없습니다', 403);
if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) fresh_order_json_error('CSRF_INVALID', '요청이 만료되었습니다', 403);
$itemId = (int)($_POST['mall_fresh_order_item_id'] ?? 0);
$soldOut = ($_POST['sold_out'] ?? '1') === '0' ? 0 : 1;
if ($itemId <= 0) fresh_order_json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
try {
    $conn = get_db_connection();
    $lookup = $conn->prepare('SELECT order_id, mall_fresh_product_id FROM mall_fresh_order_items WHERE id = ?');
    $lookup->bind_param('i', $itemId); $lookup->execute();
    $item = $lookup->get_result()->fetch_assoc(); $lookup->close();
    if (!$item) { $conn->close(); fresh_order_json_error('NOT_FOUND', '주문 항목을 찾을 수 없습니다', 404); }
    $orderId = (int)$item['order_id'];
    $productId = (int)$item['mall_fresh_product_id'];
    $stmt = $conn->prepare('UPDATE mall_fresh_order_items SET is_sold_out = ? WHERE id = ?');
    $stmt->bind_param('ii', $soldOut, $itemId); $stmt->execute(); $stmt->close();
    $productStmt = $conn->prepare('UPDATE mall_fresh_products SET is_sold_out = ? WHERE id = ?');
    $productStmt->bind_param('ii', $soldOut, $productId); $productStmt->execute(); $productStmt->close();
    $totals = mall_recalculate_order_totals($conn, $orderId);
    $conn->close();
    echo json_encode(['success' => true, 'data' => ['order_id' => $orderId, 'totals' => $totals]]);
} catch (Throwable $e) { if (isset($conn) && $conn->ping()) $conn->close(); error_log('mark_fresh_sold_out.php: ' . $e->getMessage()); fresh_order_json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500); }
