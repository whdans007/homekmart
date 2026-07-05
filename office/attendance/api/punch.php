<?php
// Design Ref: §4.1 — ESP32 타임펀치 수신 API. 세션 불필요, X-Api-Key 인증.
// Plan SC: 지문+버튼 1회로 기록 완료, 오프라인 버퍼 업로드도 동일 엔드포인트 사용.
require_once __DIR__ . '/../../../config/db_config.php';
header('Content-Type: application/json; charset=utf-8');
ob_start();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'POST_required', 'info' => 'This endpoint requires HTTP POST with X-Api-Key header']); exit;
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

    // 3. 유효성 검사
    $store_id    = isset($body['store_id'])    ? (int)$body['store_id']    : 0;
    $finger_slot = isset($body['finger_slot']) ? (int)$body['finger_slot'] : -1;
    $event_type  = trim($body['event_type']  ?? '');
    $event_time  = trim($body['event_time']  ?? '');
    $device_id   = substr(trim($body['device_id'] ?? ''), 0, 50);

    $valid_events = ['clock_in', 'break_start', 'break_end', 'clock_out'];

    if ($store_id <= 0 || $finger_slot < 0 || $finger_slot > 127
        || !in_array($event_type, $valid_events, true)
        || $event_time === ''
    ) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_params']); exit;
    }

    // event_time 형식 정규화: ISO 8601 → MySQL DATETIME
    $dt = DateTime::createFromFormat('Y-m-d\TH:i:s', $event_time)
       ?: DateTime::createFromFormat('Y-m-d H:i:s', $event_time);
    if (!$dt) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_event_time']); exit;
    }
    $mysql_time = $dt->format('Y-m-d H:i:s');

    $conn = get_db_connection();

    // 4. finger_slot → employee_id 조회
    $stmt = $conn->prepare(
        "SELECT f.employee_id, e.name
         FROM office_fingerprints f
         JOIN office_employees e ON f.employee_id = e.id
         WHERE f.store_id = ? AND f.finger_slot = ?
         LIMIT 1"
    );
    $stmt->bind_param('ii', $store_id, $finger_slot);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $conn->close();
        ob_end_clean();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'employee_not_found']); exit;
    }

    $employee_id   = (int)$row['employee_id'];
    $employee_name = $row['name'];

    // 5. 출퇴근 이벤트 INSERT
    $ins = $conn->prepare(
        "INSERT INTO office_attendance_logs
           (store_id, employee_id, event_type, event_time, source, device_id)
         VALUES (?, ?, ?, ?, 'device', ?)"
    );
    $ins->bind_param('iisss', $store_id, $employee_id, $event_type, $mysql_time, $device_id);
    $ins->execute();
    $ins->close();
    $conn->close();

    ob_end_clean();
    echo json_encode([
        'success'       => true,
        'employee_name' => $employee_name,
        'event_type'    => $event_type,
    ]);

} catch (Exception $e) {
    ob_end_clean();
    error_log('attendance punch.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
}
