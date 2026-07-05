<?php
require_once __DIR__ . '/../lib/office_helper.php';
header('Content-Type: application/json; charset=utf-8');
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

    $body       = json_decode(file_get_contents('php://input'), true);
    $store_id   = get_office_store_id();
    $item_id    = (int)($body['item_id']  ?? 0);
    $attendance = trim($body['attendance'] ?? '');

    $all_valid = ['present','sick_leave','vacation','absent','suspension','early_leave','late'];

    if ($item_id <= 0 || !in_array($attendance, $all_valid)) {
        ob_end_clean();
        echo json_encode(['success'=>false,'error'=>'invalid_params']); exit;
    }

    $conn = get_db_connection();

    // ── attendance 컬럼 확인 + ENUM에 없는 값이면 자동 확장 ──
    $col = $conn->query("SHOW COLUMNS FROM office_schedule_items LIKE 'attendance'");
    if (!$col || $col->num_rows === 0) {
        $conn->close(); ob_end_clean();
        echo json_encode(['success'=>false,'error'=>'attendance 컬럼 없음 — run_attendance_migration.php 실행 필요']); exit;
    }
    $col_row = $col->fetch_assoc();

    // 현재 ENUM 값 파싱
    preg_match_all("/'([^']+)'/", $col_row['Type'], $m);
    $current_enum = $m[1];

    // 저장하려는 값이 ENUM에 없으면 ALTER로 추가
    if (!in_array($attendance, $current_enum)) {
        $new_enum = array_unique(array_merge($current_enum, [$attendance]));
        $enum_def = "'" . implode("','", $new_enum) . "'";
        $conn->query("ALTER TABLE office_schedule_items
                      MODIFY COLUMN attendance ENUM({$enum_def}) NOT NULL DEFAULT 'present'");
    }

    // store 소속 검증
    $chk = $conn->prepare(
        "SELECT si.id FROM office_schedule_items si
         JOIN office_schedules s ON si.schedule_id = s.id
         WHERE si.id=? AND s.store_id=?"
    );
    $chk->bind_param('ii', $item_id, $store_id);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$row) {
        $conn->close(); ob_end_clean();
        echo json_encode(['success'=>false,'error'=>'항목을 찾을 수 없습니다']); exit;
    }

    $stmt = $conn->prepare("UPDATE office_schedule_items SET attendance=? WHERE id=?");
    $stmt->bind_param('si', $attendance, $item_id);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    ob_end_clean();
    echo json_encode(['success'=>true]);

} catch (Exception $e) {
    ob_end_clean();
    error_log('ajax_save_attendance: ' . $e->getMessage());
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
