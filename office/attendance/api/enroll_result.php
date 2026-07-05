<?php
// Design Ref: §4.3 — ESP32가 지문 등록(enroll) 결과를 보고. 성공 시 office_fingerprints에 매핑 생성.
// 세션 불필요, X-Api-Key 인증. punch.php 패턴 답습.
require_once __DIR__ . '/../../../config/db_config.php';
header('Content-Type: application/json; charset=utf-8');
ob_start();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'POST_required']); exit;
    }

    // 1. API Key 검증
    $api_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!defined('ATTENDANCE_API_KEY') || $api_key !== ATTENDANCE_API_KEY) {
        ob_end_clean();
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'unauthorized']); exit;
    }

    // 2. JSON 파싱
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_json']); exit;
    }

    $request_id = isset($body['request_id']) ? (int)$body['request_id'] : 0;
    $success    = !empty($body['success']);
    $finger_slot = isset($body['finger_slot']) ? (int)$body['finger_slot'] : -1;
    $error_msg  = substr(trim($body['error'] ?? ''), 0, 100);

    if ($request_id <= 0) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_params']); exit;
    }

    $conn = get_db_connection();

    // 3. 요청 조회
    $stmt = $conn->prepare(
        "SELECT store_id, employee_id, request_type FROM office_fingerprint_enroll_requests WHERE id=? AND status='enrolling'"
    );
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$req) {
        $conn->close();
        ob_end_clean();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'request_not_found']); exit;
    }

    $store_id    = (int)$req['store_id'];
    $employee_id = (int)$req['employee_id'];

    // delete 요청: office_fingerprints 는 이미 employees.php 에서 삭제됨. 상태만 갱신.
    if ($req['request_type'] === 'delete') {
        $status = $success ? 'success' : 'failed';
        $msg    = $error_msg !== '' ? $error_msg : ($success ? null : 'delete_failed');
        $upd = $conn->prepare(
            "UPDATE office_fingerprint_enroll_requests SET status=?, error_message=? WHERE id=?"
        );
        $upd->bind_param('ssi', $status, $msg, $request_id);
        $upd->execute();
        $upd->close();
        $conn->close();
        ob_end_clean();
        echo json_encode(['success' => true]); exit;
    }

    // delete_all 요청: 센서 전체 초기화 결과 반영. 성공 시 해당 매장의 잔여 매핑도 정리.
    if ($req['request_type'] === 'delete_all') {
        $status = $success ? 'success' : 'failed';
        $msg    = $error_msg !== '' ? $error_msg : ($success ? null : 'reset_failed');

        if ($success) {
            $del = $conn->prepare("DELETE FROM office_fingerprints WHERE store_id=?");
            $del->bind_param('i', $store_id);
            $del->execute();
            $del->close();
        }

        $upd = $conn->prepare(
            "UPDATE office_fingerprint_enroll_requests SET status=?, error_message=? WHERE id=?"
        );
        $upd->bind_param('ssi', $status, $msg, $request_id);
        $upd->execute();
        $upd->close();
        $conn->close();
        ob_end_clean();
        echo json_encode(['success' => true]); exit;
    }

    if (!$success || $finger_slot < 1 || $finger_slot > 127) {
        $msg = $error_msg !== '' ? $error_msg : 'enroll_failed';
        $upd = $conn->prepare(
            "UPDATE office_fingerprint_enroll_requests SET status='failed', error_message=? WHERE id=?"
        );
        $upd->bind_param('si', $msg, $request_id);
        $upd->execute();
        $upd->close();
        $conn->close();
        ob_end_clean();
        echo json_encode(['success' => true]); exit;
    }

    // 4. 직원당 최대 2개 제한 재확인
    $cnt = $conn->prepare("SELECT COUNT(*) AS c FROM office_fingerprints WHERE store_id=? AND employee_id=?");
    $cnt->bind_param('ii', $store_id, $employee_id);
    $cnt->execute();
    $count = (int)($cnt->get_result()->fetch_assoc()['c'] ?? 0);
    $cnt->close();

    if ($count >= 2) {
        $upd = $conn->prepare(
            "UPDATE office_fingerprint_enroll_requests SET status='failed', error_message='limit_reached' WHERE id=?"
        );
        $upd->bind_param('i', $request_id);
        $upd->execute();
        $upd->close();
        $conn->close();
        ob_end_clean();
        echo json_encode(['success' => true]); exit;
    }

    // 5. office_fingerprints INSERT
    $ins = $conn->prepare(
        "INSERT INTO office_fingerprints (store_id, employee_id, finger_slot) VALUES (?, ?, ?)"
    );
    $ins->bind_param('iii', $store_id, $employee_id, $finger_slot);

    if ($ins->execute()) {
        $ins->close();
        $upd = $conn->prepare(
            "UPDATE office_fingerprint_enroll_requests SET status='success', result_slot=? WHERE id=?"
        );
        $upd->bind_param('ii', $finger_slot, $request_id);
        $upd->execute();
        $upd->close();
    } else {
        $ins_errno = $conn->errno;
        $ins->close();
        $msg = ($ins_errno === 1062) ? 'slot_already_used' : 'db_error';
        $upd = $conn->prepare(
            "UPDATE office_fingerprint_enroll_requests SET status='failed', error_message=? WHERE id=?"
        );
        $upd->bind_param('si', $msg, $request_id);
        $upd->execute();
        $upd->close();
    }

    $conn->close();
    ob_end_clean();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    ob_end_clean();
    error_log('attendance enroll_result.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
}
