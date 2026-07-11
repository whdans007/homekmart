<?php
// Design Ref: §5 — office_helper provides common DB query functions for all office modules
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';

// office_employees 컬럼 자동 마이그레이션 (한 요청당 1회)
(static function() {
    static $done = false;
    if ($done) return;
    $done = true;
    $c = get_db_connection();
    $cols = [
        'photo'           => "ALTER TABLE office_employees ADD COLUMN photo VARCHAR(255) NULL DEFAULT NULL AFTER job_role",
        'inactive_reason' => "ALTER TABLE office_employees ADD COLUMN inactive_reason VARCHAR(255) NULL DEFAULT NULL AFTER status",
        'inactive_date'   => "ALTER TABLE office_employees ADD COLUMN inactive_date DATE NULL DEFAULT NULL AFTER inactive_reason",
        'agency'          => "ALTER TABLE office_employees ADD COLUMN agency ENUM('STAFF WORKS','GPNC','DIRECT') NULL DEFAULT NULL AFTER job_role",
    ];
    foreach ($cols as $col => $sql) {
        $r = $c->query("SHOW COLUMNS FROM office_employees LIKE '{$col}'");
        if ($r && $r->num_rows === 0) $c->query($sql);
    }
    $c->close();
})();

// Plan SC: office_staff + super_admin 만 접근 허용
function require_office_permission(): void {
    if (in_array($_SESSION['role'] ?? '', ['super_admin', 'admin', 'office_staff'])) return;
    if (has_permission('accounting_management')) return;
    // office/ 깊이에 무관하게 admin 루트로 리다이렉트
    $pos = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/office/');
    $admin_url = ($pos !== false)
        ? substr($_SERVER['SCRIPT_NAME'], 0, $pos) . '/admin/index.php'
        : '/admin/index.php';
    header('Location: ' . $admin_url . '?error=permission_denied');
    exit;
}

// ── Job role functions ──────────────────────────────────────────

function get_job_role_label(string $role): string {
    $labels = [
        'cashier'      => 'Cashier',
        'patcher'      => 'Patcher',
        'butcher'      => 'Butcher',
        'kitchen'      => 'Kitchen',
        'driver'       => 'Driver',
        'merchandiser' => 'Merchandiser',
        'supervisor'   => 'Supervisor',
        'admin'        => 'Admin',
    ];
    return $labels[$role] ?? ucfirst($role);
}

function get_job_roles(): array {
    return ['cashier', 'patcher', 'butcher', 'kitchen', 'driver', 'merchandiser', 'supervisor', 'admin'];
}

function get_agency_options(): array {
    return ['STAFF WORKS', 'GPNC', 'DIRECT'];
}

// ── Employee lookup ──────────────────────────────────────────

function get_office_employees(int $store_id, ?string $job_role = null, string $status = 'active'): array {
    $conn = get_db_connection();
    if ($job_role !== null) {
        $stmt = $conn->prepare(
            "SELECT id, name, job_role, agency, status, inactive_reason, inactive_date, photo FROM office_employees
             WHERE store_id=? AND job_role=? AND status=? ORDER BY name"
        );
        $stmt->bind_param('iss', $store_id, $job_role, $status);
    } else {
        $stmt = $conn->prepare(
            "SELECT id, name, job_role, agency, status, inactive_reason, inactive_date, photo FROM office_employees
             WHERE store_id=? AND status=? ORDER BY job_role, name"
        );
        $stmt->bind_param('is', $store_id, $status);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    return $rows;
}

// ── Expense statistics ──────────────────────────────────────────

function get_purchase_monthly_total(int $store_id, int $year, int $month): array {
    $conn = get_db_connection();

    $stmt = $conn->prepare(
        "SELECT payment_type, SUM(amount) AS total
         FROM office_product_purchases
         WHERE store_id=? AND YEAR(payment_date)=? AND MONTH(payment_date)=?
         GROUP BY payment_type"
    );
    $stmt->bind_param('iii', $store_id, $year, $month);
    $stmt->execute();
    $result = $stmt->get_result();
    $product = ['cash' => 0.0, 'check' => 0.0];
    while ($row = $result->fetch_assoc()) {
        $product[$row['payment_type']] = (float)$row['total'];
    }
    $stmt->close();

    $stmt2 = $conn->prepare(
        "SELECT SUM(amount) AS total FROM office_equipment_purchases
         WHERE store_id=? AND YEAR(payment_date)=? AND MONTH(payment_date)=?"
    );
    $stmt2->bind_param('iii', $store_id, $year, $month);
    $stmt2->execute();
    $eq_row = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();
    $conn->close();

    return [
        'product_cash'  => $product['cash'],
        'product_check' => $product['check'],
        'equipment'     => (float)($eq_row['total'] ?? 0),
    ];
}

// Pending checks: payment_date >= today
function get_pending_checks_count(int $store_id): int {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM office_product_purchases
         WHERE store_id=? AND payment_type='check' AND payment_date >= CURDATE()"
    );
    $stmt->bind_param('i', $store_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return (int)($row['cnt'] ?? 0);
}

// 6-month combined expense trend for Chart.js
function get_purchase_6month_trend(int $store_id): array {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "SELECT DATE_FORMAT(payment_date,'%Y-%m') AS ym, SUM(amount) AS total
         FROM (
           SELECT payment_date, amount FROM office_product_purchases WHERE store_id=?
           UNION ALL
           SELECT payment_date, amount FROM office_equipment_purchases WHERE store_id=?
         ) combined
         WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
         GROUP BY ym ORDER BY ym"
    );
    $stmt->bind_param('ii', $store_id, $store_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    return $rows;
}

// ── Schedule ────────────────────────────────────────

// Return existing or create new schedule
function get_or_create_schedule(int $store_id, int $year, int $month, string $period): int {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "SELECT id FROM office_schedules WHERE store_id=? AND year=? AND month=? AND period=?"
    );
    $stmt->bind_param('iiis', $store_id, $year, $month, $period);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $conn->close();
        return (int)$row['id'];
    }

    $created_by = (int)($_SESSION['user_id'] ?? 0) ?: null;
    $stmt2 = $conn->prepare(
        "INSERT INTO office_schedules (store_id, year, month, period, created_by) VALUES (?,?,?,?,?)"
    );
    $stmt2->bind_param('iiisi', $store_id, $year, $month, $period, $created_by);
    $stmt2->execute();
    $id = (int)$conn->insert_id;
    $stmt2->close();
    $conn->close();
    return $id;
}

// ── Common utilities ──────────────────────────────────────────

function get_office_store_id(): int {
    $sid = isset($_SESSION['store_id']) ? (int)$_SESSION['store_id'] : 0;
    if ($sid > 0) return $sid;
    // 세션에 store_id가 없으면 DB에서 조회 후 세션에 캐시
    if (empty($_SESSION['user_id'])) return 0;
    $conn = get_db_connection();
    $stmt = $conn->prepare("SELECT store_id FROM users WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    $sid = (int)($row['store_id'] ?? 0);
    $_SESSION['store_id'] = $sid ?: null;
    return $sid;
}

function format_amount(float $amount): string {
    return '₱ ' . number_format($amount, 2);
}

// ── 점포 점장(센터장) 조회 ──────────────────────────────────────
// users 테이블에서 role='branch_manager'(점장/센터장)이고 해당 store 소속인 사용자 이름 반환.
// 점장이 지정되지 않은 점포는 빈 문자열 반환.
function get_store_manager_name(int $store_id): string {
    if ($store_id <= 0) return '';
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "SELECT full_name FROM users
         WHERE store_id=? AND role='branch_manager'
         ORDER BY id LIMIT 1"
    );
    $stmt->bind_param('i', $store_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return trim($row['full_name'] ?? '');
}

// ── Receipt integration ────────────────────────────────────────

// Plan SC9 — Link receipt when saving expense
function link_receipt(int $receipt_id, string $type, int $purchase_id): void {
    if ($receipt_id <= 0) return;
    $conn     = get_db_connection();
    $store_id = get_office_store_id();
    $stmt     = $conn->prepare(
        "UPDATE office_receipts
         SET linked_purchase_type=?, linked_purchase_id=?
         WHERE id=? AND store_id=? AND linked_purchase_id IS NULL"
    );
    $stmt->bind_param('siii', $type, $purchase_id, $receipt_id, $store_id);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

// Plan SC10 — Unlink receipt when deleting expense
function unlink_receipt_by_purchase(string $type, int $purchase_id): void {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "UPDATE office_receipts
         SET linked_purchase_type=NULL, linked_purchase_id=NULL
         WHERE linked_purchase_type=? AND linked_purchase_id=?"
    );
    $stmt->bind_param('si', $type, $purchase_id);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

// Safe POST input helpers
function post_str(string $key, string $default = ''): string {
    return trim($_POST[$key] ?? $default);
}

function post_int(string $key, int $default = 0): int {
    return (int)($_POST[$key] ?? $default);
}

function post_float(string $key, float $default = 0.0): float {
    return (float)($_POST[$key] ?? $default);
}

function post_date(string $key): ?string {
    $val = trim($_POST[$key] ?? '');
    if ($val === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) return null;
    return $val;
}

// ── Attendance helpers ────────────────────────────────────────────
// Design Ref: §5 — attendance 관련 공통 함수

function get_attendance_fingerprints(int $store_id): array {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "SELECT f.id, f.finger_slot, f.enrolled_at,
                e.id AS employee_id, e.name, e.job_role
         FROM office_fingerprints f
         JOIN office_employees e ON f.employee_id = e.id
         WHERE f.store_id = ?
         ORDER BY f.finger_slot"
    );
    $stmt->bind_param('i', $store_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    return $rows;
}

// store_id 전체 지문 등록 목록을 employee_id 기준으로 그룹화 (직원당 최대 2개: 지문1/지문2)
function get_fingerprints_by_employee(int $store_id): array {
    $rows = get_attendance_fingerprints($store_id);
    $by_emp = [];
    foreach ($rows as $row) {
        $by_emp[$row['employee_id']][] = $row;
    }
    return $by_emp;
}

// ── 지문 등록(enroll) 요청 — 웹 트리거 → ESP32 polling 처리 ────────────

// 해당 매장에 진행 중인(pending/enrolling) 등록 요청이 있는지 조회
function get_active_enroll_request(int $store_id): ?array {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "SELECT r.id, r.employee_id, r.status, r.result_slot, r.error_message, e.name
         FROM office_fingerprint_enroll_requests r
         JOIN office_employees e ON r.employee_id = e.id
         WHERE r.store_id = ? AND r.status IN ('pending','enrolling') AND r.request_type = 'enroll'
         ORDER BY r.id DESC LIMIT 1"
    );
    $stmt->bind_param('i', $store_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $row ?: null;
}

// 가장 최근 등록 요청 1건 (완료/실패 결과 표시용)
function get_latest_enroll_request(int $store_id): ?array {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "SELECT r.id, r.employee_id, r.status, r.result_slot, r.error_message, e.name
         FROM office_fingerprint_enroll_requests r
         JOIN office_employees e ON r.employee_id = e.id
         WHERE r.store_id = ?
         ORDER BY r.id DESC LIMIT 1"
    );
    $stmt->bind_param('i', $store_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $row ?: null;
}

// 새 등록 요청 생성 (이미 진행 중인 요청이 있으면 생성하지 않음). 성공 시 insert id, 실패 시 0
function create_enroll_request(int $store_id, int $employee_id, ?int $requested_by): int {
    if (get_active_enroll_request($store_id)) {
        return 0;
    }
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "INSERT INTO office_fingerprint_enroll_requests (store_id, employee_id, status, requested_by)
         VALUES (?, ?, 'pending', ?)"
    );
    $stmt->bind_param('iii', $store_id, $employee_id, $requested_by);
    $id = $stmt->execute() ? (int)$conn->insert_id : 0;
    $stmt->close();
    $conn->close();
    return $id;
}

// 지문 삭제 요청 생성 — 웹에서 삭제 시 ESP32 센서의 해당 슬롯도 함께 삭제하도록 큐에 등록
function create_delete_request(int $store_id, int $finger_slot, int $employee_id, ?int $requested_by): int {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "INSERT INTO office_fingerprint_enroll_requests (store_id, employee_id, request_type, finger_slot, status, requested_by)
         VALUES (?, ?, 'delete', ?, 'pending', ?)"
    );
    $stmt->bind_param('iiii', $store_id, $employee_id, $finger_slot, $requested_by);
    $id = $stmt->execute() ? (int)$conn->insert_id : 0;
    $stmt->close();
    $conn->close();
    return $id;
}

// 센서 지문 전체 초기화 요청 생성 (이미 진행 중인 초기화 요청이 있으면 생성하지 않음)
function create_delete_all_request(int $store_id, ?int $requested_by): int {
    if (get_active_delete_all_request($store_id)) {
        return 0;
    }
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "INSERT INTO office_fingerprint_enroll_requests (store_id, employee_id, request_type, status, requested_by)
         VALUES (?, NULL, 'delete_all', 'pending', ?)"
    );
    $stmt->bind_param('ii', $store_id, $requested_by);
    $id = $stmt->execute() ? (int)$conn->insert_id : 0;
    $stmt->close();
    $conn->close();
    return $id;
}

// 진행 중인(pending/enrolling) 센서 초기화 요청 조회
function get_active_delete_all_request(int $store_id): ?array {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "SELECT id, status, error_message FROM office_fingerprint_enroll_requests
         WHERE store_id = ? AND request_type = 'delete_all' AND status IN ('pending','enrolling')
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->bind_param('i', $store_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $row ?: null;
}

// 가장 최근 센서 초기화 요청 1건 (완료/실패 결과 표시용)
function get_latest_delete_all_request(int $store_id): ?array {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "SELECT id, status, error_message FROM office_fingerprint_enroll_requests
         WHERE store_id = ? AND request_type = 'delete_all'
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->bind_param('i', $store_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $row ?: null;
}

// request_id 로 단건 조회 (진행 상태 추적용)
function get_enroll_request_by_id(int $id, int $store_id): ?array {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "SELECT r.id, r.employee_id, r.status, r.result_slot, r.error_message, e.name
         FROM office_fingerprint_enroll_requests r
         JOIN office_employees e ON r.employee_id = e.id
         WHERE r.id = ? AND r.store_id = ?"
    );
    $stmt->bind_param('ii', $id, $store_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $row ?: null;
}

// 등록 요청 취소 (pending/enrolling 상태일 때만)
function cancel_enroll_request(int $id, int $store_id): void {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "DELETE FROM office_fingerprint_enroll_requests WHERE id=? AND store_id=? AND status IN ('pending','enrolling')"
    );
    $stmt->bind_param('ii', $id, $store_id);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

function get_today_attendance(int $store_id): array {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "SELECT e.id AS employee_id, e.name, e.job_role,
                MAX(CASE WHEN l.event_type='clock_in'    THEN l.event_time END) AS clock_in,
                MAX(CASE WHEN l.event_type='break_start' THEN l.event_time END) AS break_start,
                MAX(CASE WHEN l.event_type='break_end'   THEN l.event_time END) AS break_end,
                MAX(CASE WHEN l.event_type='clock_out'   THEN l.event_time END) AS clock_out
         FROM office_employees e
         LEFT JOIN office_attendance_logs l
           ON e.id = l.employee_id AND DATE(l.event_time) = CURDATE() AND l.store_id = ?
         WHERE e.store_id = ? AND e.status = 'active'
         GROUP BY e.id
         ORDER BY e.job_role, e.name"
    );
    $stmt->bind_param('ii', $store_id, $store_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    return $rows;
}

function get_monthly_attendance(int $store_id, int $year, int $month): array {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "SELECT e.id AS employee_id, e.name, e.job_role,
                DATE(l.event_time) AS work_date, l.event_type, l.event_time, l.source
         FROM office_employees e
         JOIN office_attendance_logs l ON e.id = l.employee_id
         WHERE e.store_id = ? AND l.store_id = ?
           AND YEAR(l.event_time) = ? AND MONTH(l.event_time) = ?
         ORDER BY e.name, l.event_time"
    );
    $stmt->bind_param('iiii', $store_id, $store_id, $year, $month);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    return $rows;
}

// Plan SC: 실근무 8시간 초과분 = 오버타임
function calc_work_summary(array $events): array {
    $clock_in = $break_start = $break_end = $clock_out = null;
    foreach ($events as $e) {
        switch ($e['event_type']) {
            case 'clock_in':    $clock_in    = $e['event_time']; break;
            case 'break_start': $break_start = $e['event_time']; break;
            case 'break_end':   $break_end   = $e['event_time']; break;
            case 'clock_out':   $clock_out   = $e['event_time']; break;
        }
    }
    if (!$clock_in || !$clock_out) {
        return ['net_minutes' => 0, 'regular_minutes' => 0, 'overtime_minutes' => 0];
    }
    $total   = (strtotime($clock_out) - strtotime($clock_in)) / 60;
    $brk     = ($break_start && $break_end)
               ? (strtotime($break_end) - strtotime($break_start)) / 60
               : 0;
    $net     = max(0, $total - $brk);
    $regular = min($net, 480);     // 8h = 480min
    $ot      = max(0, $net - 480);
    return [
        'net_minutes'      => (int)$net,
        'regular_minutes'  => (int)$regular,
        'overtime_minutes' => (int)$ot,
    ];
}
