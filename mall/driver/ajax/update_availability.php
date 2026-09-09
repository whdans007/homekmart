<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../lib/driver.php';
require_once __DIR__ . '/../../lib/csrf.php';
$driver = mall_driver_current();
if (!$driver) { http_response_code(401); echo json_encode(['success'=>false,'error'=>['message'=>'로그인이 필요합니다.']]); exit; }
if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) { http_response_code(403); echo json_encode(['success'=>false,'error'=>['message'=>'요청이 만료되었습니다.']]); exit; }
$available = ($_POST['is_available'] ?? '0') === '1' ? 1 : 0;
try {
    $conn = mall_get_db_connection();
    $stmt = $conn->prepare('UPDATE mall_drivers SET is_available = ? WHERE id = ?');
    $stmt->bind_param('ii', $available, $driver['id']);
    $stmt->execute();
    echo json_encode(['success'=>true,'data'=>['is_available'=>$available]]);
} catch (Throwable $e) {
    error_log('update driver availability: '.$e->getMessage());
    http_response_code(500); echo json_encode(['success'=>false,'error'=>['message'=>'처리 중 오류가 발생했습니다.']]);
}
