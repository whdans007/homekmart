<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../lib/session_helper.php';
require_once __DIR__ . '/../../../lib/permission_helper.php';
require_once __DIR__ . '/../../../config/db_config.php';
require_once __DIR__ . '/../../../mall/config/mall_config.php';
require_once __DIR__ . '/../../lib/csrf.php';
ensure_logged_in();
require_permission('mall_management', '../../../admin/index.php');
if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) { http_response_code(403); echo json_encode(['success'=>false,'error'=>['message'=>'요청이 만료되었습니다. 새로고침 후 다시 시도해주세요']]); exit; }
$order_id = (int)($_POST['order_id'] ?? 0);
if ($order_id <= 0) { http_response_code(400); echo json_encode(['success'=>false,'error'=>['message'=>'잘못된 주문입니다.']]); exit; }
$conn = get_db_connection();
$conn->begin_transaction();
try {
    $stmt = $conn->prepare('SELECT status, current_driver_id FROM mall_orders WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $order_id); $stmt->execute(); $order = $stmt->get_result()->fetch_assoc(); $stmt->close();
    $previous = ['confirmed'=>'pending','preparing'=>'pending','ready'=>'preparing','assigned'=>'ready','delivering'=>'assigned','arrived'=>'delivering','completed'=>'arrived'];
    if (!$order || !isset($previous[$order['status']])) throw new RuntimeException('이전 단계로 되돌릴 수 없는 상태입니다.');
    $from = $order['status']; $to = $previous[$from];
    if ($from === 'assigned' && !empty($order['current_driver_id'])) {
        $a = $conn->prepare('DELETE FROM mall_order_driver_assignments WHERE order_id = ? AND driver_id = ? AND status = ?');
        $a->bind_param('iis', $order_id, $order['current_driver_id'], $from); $a->execute(); $a->close();
    } elseif (in_array($from, ['delivering','arrived','completed'], true) && !empty($order['current_driver_id'])) {
        $a = $conn->prepare('UPDATE mall_order_driver_assignments SET status = ? WHERE order_id = ? AND driver_id = ? AND status = ?');
        $a->bind_param('siis', $to, $order_id, $order['current_driver_id'], $from); $a->execute(); $a->close();
    }
    if ($from === 'assigned') { $clear = $conn->prepare('UPDATE mall_orders SET status = ?, current_driver_id = NULL WHERE id = ?'); $clear->bind_param('si', $to, $order_id); }
    else { $clear = $conn->prepare('UPDATE mall_orders SET status = ? WHERE id = ?'); $clear->bind_param('si', $to, $order_id); }
    $clear->execute(); $clear->close(); $conn->commit();
    echo json_encode(['success'=>true,'data'=>['status'=>$to]]);
} catch (Throwable $e) { $conn->rollback(); http_response_code(400); echo json_encode(['success'=>false,'error'=>['message'=>$e->getMessage()]]); }
$conn->close();
