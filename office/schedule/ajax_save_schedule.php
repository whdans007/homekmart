<?php
require_once __DIR__ . '/../lib/office_helper.php';
require_once __DIR__ . '/../../lib/store_config_helper.php';

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
$valid_shifts = STORE_SHIFT_KEYS;
// Design Ref: homekmart-store-config §5.3 FR-F2-14 — 점포별 근무시간 표시값 + 기존 보조 시간(장시간 커버) 병행 허용
$store_shift_displays = array_column(get_store_shifts($store_id), 'display');
$valid_sup_times = array_merge($store_shift_displays, ['8AM~8PM', '8PM~8AM', '8AM-8PM', '8PM-8AM']);

try {
    $conn = get_db_connection();
    $conn->autocommit(false);

    // 헤더 upsert
    $schedule_id = get_or_create_schedule($store_id, $year, $month, $period);

    $valid_att = ['present','sick_leave','vacation','sil','absent','suspension','early_leave','late'];

    // attendance 컬럼 존재 여부 확인
    $ac = $conn->query("SHOW COLUMNS FROM office_schedule_items LIKE 'attendance'");
    $has_att = $ac && $ac->num_rows > 0;


    // 기존 근태값 보존 (is_off=0 근무 항목의 attendance)
    $att_map = [];
    $prev = $conn->prepare(
        "SELECT schedule_date, shift, job_role, employee_id, attendance
         FROM office_schedule_items WHERE schedule_id=? AND is_off=0"
    );
    $prev->bind_param('i', $schedule_id);
    $prev->execute();
    foreach ($prev->get_result()->fetch_all(MYSQLI_ASSOC) as $prow) {
        $k = $prow['schedule_date'].'|'.$prow['shift'].'|'.$prow['job_role'].'|'.$prow['employee_id'];
        $att_map[$k] = $prow['attendance'];
    }
    $prev->close();

    // 기존 items 삭제
    $del = $conn->prepare("DELETE FROM office_schedule_items WHERE schedule_id=?");
    $del->bind_param('i', $schedule_id);
    $del->execute();
    $del->close();

    // 새 items 배치 INSERT
    $sql = $has_att
        ? "INSERT INTO office_schedule_items
           (schedule_id, schedule_date, shift, job_role, employee_id, is_off, replacement_employee_id, supervisor_shift_time, attendance)
           VALUES (?,?,?,?,?,?,?,?,?)"
        : "INSERT INTO office_schedule_items
           (schedule_id, schedule_date, shift, job_role, employee_id, is_off, replacement_employee_id, supervisor_shift_time)
           VALUES (?,?,?,?,?,?,?,?)";

    $ins = $conn->prepare($sql);

    $saved = 0;
    foreach ($items as $item) {
        $date        = $item['date']     ?? '';
        $shift       = $item['shift']    ?? '';
        $job_role    = $item['job_role'] ?? '';
        $employee_id = !empty($item['employee_id']) ? (int)$item['employee_id'] : null;
        $is_off      = (int)($item['is_off'] ?? 0);
        $replace_id  = null;
        $raw_sup_time = $item['supervisor_shift_time'] ?? '';
        $sup_time = in_array($raw_sup_time, $valid_sup_times) ? $raw_sup_time : null;

        // is_off=0 근무 항목은 DB에 저장된 근태값 우선 유지 (확정 후 입력한 데이터 보존)
        if ($is_off === 0) {
            $k = $date.'|'.$shift.'|'.$job_role.'|'.$employee_id;
            $attendance = $att_map[$k] ?? 'present';
        } else {
            $attendance = in_array($item['attendance'] ?? '', $valid_att) ? $item['attendance'] : 'present';
        }

        if (!$date || !in_array($shift, $valid_shifts) || !in_array($job_role, $valid_roles)) continue;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;

        if ($has_att) {
            $ins->bind_param('issssisss',
                $schedule_id, $date, $shift, $job_role,
                $employee_id, $is_off, $replace_id, $sup_time, $attendance
            );
        } else {
            $ins->bind_param('issssiss',
                $schedule_id, $date, $shift, $job_role,
                $employee_id, $is_off, $replace_id, $sup_time
            );
        }
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
    if (isset($conn)) { try { $conn->rollback(); $conn->close(); } catch (Exception $ignored) {} }
    error_log('ajax_save_schedule error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
