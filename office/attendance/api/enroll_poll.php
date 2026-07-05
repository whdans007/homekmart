<?php
// Design Ref: §4.3 — ESP32가 주기적으로 polling 하여 대기 중인 지문 등록 요청을 가져옴.
// 세션 불필요, X-Api-Key 인증. punch.php 패턴 답습.
require_once __DIR__ . '/../../../config/db_config.php';
header('Content-Type: application/json; charset=utf-8');
ob_start();

try {
    // 1. API Key 검증
    $api_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!defined('ATTENDANCE_API_KEY') || $api_key !== ATTENDANCE_API_KEY) {
        ob_end_clean();
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'unauthorized']); exit;
    }

    // 2. store_id 파라미터
    $store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 0;
    if ($store_id <= 0) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_params']); exit;
    }

    $conn = get_db_connection();

    // 3. 대기 중인(pending) 요청 1건을 'enrolling' 으로 전환하여 가져옴
    $stmt = $conn->prepare(
        "SELECT id, employee_id, request_type, finger_slot FROM office_fingerprint_enroll_requests
         WHERE store_id = ? AND status = 'pending'
         ORDER BY id ASC LIMIT 1"
    );
    $stmt->bind_param('i', $store_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $conn->close();
        ob_end_clean();
        echo json_encode(['success' => true, 'has_request' => false]);
        exit;
    }

    $upd = $conn->prepare("UPDATE office_fingerprint_enroll_requests SET status='enrolling' WHERE id=?");
    $upd->bind_param('i', $row['id']);
    $upd->execute();
    $upd->close();
    $conn->close();

    ob_end_clean();
    echo json_encode([
        'success'      => true,
        'has_request'  => true,
        'request_id'   => (int)$row['id'],
        'employee_id'  => (int)$row['employee_id'],
        'request_type' => $row['request_type'],
        'finger_slot'  => $row['finger_slot'] !== null ? (int)$row['finger_slot'] : null,
    ]);

} catch (Exception $e) {
    ob_end_clean();
    error_log('attendance enroll_poll.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
}
