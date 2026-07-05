<?php
require_once __DIR__ . '/../lib/office_helper.php';
header('Content-Type: application/json; charset=utf-8');

// PHP 오류가 JSON을 깨지 않도록 출력 버퍼링
ob_start();

try {
    if (!is_logged_in()) {
        ob_end_clean();
        echo json_encode(['success'=>false,'error'=>'unauthorized']); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ob_end_clean();
        echo json_encode(['success'=>false,'error'=>'method']); exit;
    }

    $body     = json_decode(file_get_contents('php://input'), true);
    $store_id = get_office_store_id();
    $year     = (int)($body['year']   ?? 0);
    $month    = (int)($body['month']  ?? 0);
    $period   = $body['period']       ?? '';
    $action   = $body['action']       ?? 'confirm';

    if (!$year || !$month || !in_array($period, ['first','second'])) {
        ob_end_clean();
        echo json_encode(['success'=>false,'error'=>'invalid_params']); exit;
    }

    $conn = get_db_connection();

    // confirmed 컬럼 존재 여부 확인
    $cc = $conn->query("SHOW COLUMNS FROM office_schedules LIKE 'confirmed'");
    if (!$cc || $cc->num_rows === 0) {
        $conn->close(); ob_end_clean();
        echo json_encode(['success'=>false,'error'=>'migration_required',
                          'message'=>'먼저 run_attendance_migration.php를 실행하세요.']); exit;
    }

    // schedule_id 가져오기 (없으면 생성)
    $sc_stmt = $conn->prepare("SELECT id FROM office_schedules WHERE store_id=? AND year=? AND month=? AND period=?");
    $sc_stmt->bind_param('iiis', $store_id, $year, $month, $period);
    $sc_stmt->execute();
    $sc_row = $sc_stmt->get_result()->fetch_assoc();
    $sc_stmt->close();

    if (!$sc_row) {
        $conn->close(); ob_end_clean();
        echo json_encode(['success'=>false,'error'=>'no_schedule','message'=>'저장된 스케줄이 없습니다. 먼저 저장하세요.']); exit;
    }
    $schedule_id  = (int)$sc_row['id'];
    $confirmed_by = (int)($_SESSION['user_id'] ?? 0);

    if ($action === 'unconfirm') {
        if (($_SESSION['role'] ?? '') !== 'super_admin') {
            $conn->close(); ob_end_clean();
            echo json_encode(['success'=>false,'error'=>'permission_denied']); exit;
        }
        $stmt = $conn->prepare("UPDATE office_schedules SET confirmed=0, confirmed_at=NULL, confirmed_by=NULL WHERE id=? AND store_id=?");
        $stmt->bind_param('ii', $schedule_id, $store_id);
    } else {
        $stmt = $conn->prepare("UPDATE office_schedules SET confirmed=1, confirmed_at=NOW(), confirmed_by=? WHERE id=? AND store_id=?");
        $stmt->bind_param('iii', $confirmed_by, $schedule_id, $store_id);
    }

    if (!$stmt) {
        throw new Exception('준비 실패: ' . $conn->error);
    }
    $stmt->execute();
    $stmt->close();
    $conn->close();

    ob_end_clean();
    echo json_encode(['success'=>true, 'action'=>$action]);

} catch (Exception $e) {
    ob_end_clean();
    error_log('ajax_confirm_schedule error: ' . $e->getMessage());
    echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
}
