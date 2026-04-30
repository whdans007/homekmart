<?php
require_once __DIR__ . '/../lib/office_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    echo json_encode(['success' => false, 'error' => 'invalid_json']);
    exit;
}

$store_id = get_office_store_id();
$year     = (int)($body['year']   ?? 0);
$month    = (int)($body['month']  ?? 0);
$period   = $body['period'] ?? '';
$items    = $body['items']  ?? [];

if (!$year || !$month || !in_array($period, ['first', 'second']) || empty($items)) {
    echo json_encode(['success' => false, 'error' => 'invalid_params']);
    exit;
}

$valid_roles  = get_job_roles();
$valid_shifts = ['morning', 'mid', 'gy'];
$valid_sup_time = ['8AM-8PM', '8PM-8AM'];

try {
    $conn = get_db_connection();
    $conn->autocommit(false);

    // 헤더 upsert
    $schedule_id = get_or_create_schedule($store_id, $year, $month, $period);

    // 기존 items 삭제
    $del = $conn->prepare("DELETE FROM office_schedule_items WHERE schedule_id=?");
    $del->bind_param('i', $schedule_id);
    $del->execute();
    $del->close();

    // 새 items 배치 INSERT
    $ins = $conn->prepare(
        "INSERT INTO office_schedule_items
         (schedule_id, schedule_date, shift, job_role, employee_id, is_off, replacement_employee_id, supervisor_shift_time)
         VALUES (?,?,?,?,?,?,?,?)"
    );

    $saved = 0;
    foreach ($items as $item) {
        $date         = $item['date']        ?? '';
        $shift        = $item['shift']       ?? '';
        $job_role     = $item['job_role']    ?? '';
        $employee_id  = !empty($item['employee_id'])  ? (int)$item['employee_id']  : null;
        $is_off       = (int)($item['is_off'] ?? 0);
        $replace_id   = !empty($item['replacement_employee_id']) ? (int)$item['replacement_employee_id'] : null;
        $sup_time     = ($job_role === 'supervisor' && in_array($item['supervisor_shift_time'] ?? '', $valid_sup_time))
                        ? $item['supervisor_shift_time'] : null;

        if (!$date || !in_array($shift, $valid_shifts) || !in_array($job_role, $valid_roles)) continue;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;

        $ins->bind_param('issssiis',
            $schedule_id, $date, $shift, $job_role,
            $employee_id, $is_off, $replace_id, $sup_time
        );
        $ins->execute();
        $saved++;
    }
    $ins->close();

    // 헤더 updated_at 갱신
    $upd = $conn->prepare("UPDATE office_schedules SET updated_at=NOW() WHERE id=?");
    $upd->bind_param('i', $schedule_id);
    $upd->execute();
    $upd->close();

    $conn->commit();
    $conn->close();

    echo json_encode(['success' => true, 'schedule_id' => $schedule_id, 'saved_count' => $saved]);

} catch (Exception $e) {
    $conn->rollback();
    $conn->close();
    error_log('ajax_save_schedule error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'db_error']);
}
