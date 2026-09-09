<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../lib/driver.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../../config/db_config.php';
mall_driver_session_start();
$driver = mall_driver_current();
if (!$driver) { http_response_code(401); echo json_encode(['success'=>false]); exit; }
if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) { http_response_code(403); echo json_encode(['success'=>false]); exit; }
$token = trim($_POST['token'] ?? '');
if ($token === '' || strlen($token) > 4096) { http_response_code(422); echo json_encode(['success'=>false]); exit; }
try {
    $conn = get_db_connection();
    $hash = hash('sha256', $token);
    $stmt = $conn->prepare("INSERT INTO mall_driver_device_tokens (driver_id,platform,token_hash,token,is_active,last_registered_at) VALUES (?,'android',?,?,1,NOW()) ON DUPLICATE KEY UPDATE driver_id=VALUES(driver_id),token=VALUES(token),is_active=1,last_registered_at=NOW()");
    $stmt->bind_param('iss', $driver['id'], $hash, $token);
    $stmt->execute();
    echo json_encode(['success'=>true]);
} catch (Throwable $e) {
    error_log('driver token registration: '.$e->getMessage());
    http_response_code(500); echo json_encode(['success'=>false]);
}
