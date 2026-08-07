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
        'hire_date'       => "ALTER TABLE office_employees ADD COLUMN hire_date DATE NULL DEFAULT NULL AFTER job_role",
        // Design Ref: employee-no-numbering — 사번(YY+회사코드01+순번3자리, 예: 2601059)
        'employee_no'     => "ALTER TABLE office_employees ADD COLUMN employee_no VARCHAR(7) NULL DEFAULT NULL AFTER hire_date",
    ];
    foreach ($cols as $col => $sql) {
        $r = $c->query("SHOW COLUMNS FROM office_employees LIKE '{$col}'");
        if ($r && $r->num_rows === 0) $c->query($sql);
    }
    // employee_no 고유 인덱스 (컬럼 추가 후 별도 확인 — 중복 방지)
    $idx = $c->query("SHOW INDEX FROM office_employees WHERE Key_name = 'uniq_employee_no'");
    if ($idx && $idx->num_rows === 0) {
        $c->query("ALTER TABLE office_employees ADD UNIQUE INDEX uniq_employee_no (employee_no)");
    }

    // Design Ref: §2.1 — 발령 이력 로그 (append-only)
    $c->query(
        "CREATE TABLE IF NOT EXISTS office_employee_history (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id INT UNSIGNED NOT NULL,
            store_id    INT UNSIGNED NOT NULL,
            event_type  ENUM('hire','transfer','role_change','promotion','note') NOT NULL DEFAULT 'note',
            content     VARCHAR(500) NOT NULL,
            event_date  DATE NOT NULL,
            created_by  INT UNSIGNED NULL,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_employee_date (employee_id, event_date),
            FOREIGN KEY (employee_id) REFERENCES office_employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Design Ref: §2.1 — 점포 전입 승인 요청
    $c->query(
        "CREATE TABLE IF NOT EXISTS office_employee_transfers (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id    INT UNSIGNED NOT NULL,
            from_store_id  INT UNSIGNED NOT NULL,
            to_store_id    INT UNSIGNED NOT NULL,
            reason         VARCHAR(500) NULL,
            status         ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
            requested_by   INT UNSIGNED NULL,
            approved_by    INT UNSIGNED NULL,
            decision_note  VARCHAR(500) NULL,
            decided_at     TIMESTAMP NULL DEFAULT NULL,
            created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status_to_store (status, to_store_id),
            INDEX idx_employee (employee_id),
            FOREIGN KEY (employee_id) REFERENCES office_employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Design Ref: employee-hr-records.design.md §3.3 — 인사기록(경고장 등, 향후 지각/결근 점수제 확장 가능)
    $c->query(
        "CREATE TABLE IF NOT EXISTS office_employee_records (
            id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id       INT UNSIGNED NOT NULL,
            store_id          INT UNSIGNED NOT NULL,
            event_type        ENUM('warning','late','absence','other') NOT NULL DEFAULT 'warning',
            category          VARCHAR(100) NULL,
            content           VARCHAR(500) NULL,
            points            INT NOT NULL DEFAULT 0,
            attachment_files  JSON NULL,
            created_by        INT UNSIGNED NULL,
            created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_employee_created (employee_id, created_at),
            INDEX idx_store_type (store_id, event_type),
            FOREIGN KEY (employee_id) REFERENCES office_employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $c->close();
})();

// Plan SC: office_staff + super_admin 만 접근 허용
// 리다이렉트 없는 boolean 버전 — ajax_*.php에서 JSON 오류로 응답해야 할 때 사용.
// (require_office_permission()의 HTML 리다이렉트를 fetch()가 그대로 받으면 JSON.parse가
//  깨져 "unexpected server response"처럼 원인을 알 수 없는 에러로 보임 — office/deferred_tracker
//  ajax_save_dtr.php에서 실제로 발생한 사례)
function has_office_permission(): bool {
    if (in_array($_SESSION['role'] ?? '', ['super_admin', 'admin', 'office_staff'])) return true;
    return has_permission('accounting_management');
}

// 아포스트로피(')가 포함된 값(예: "JEN'S FRUIT")을 POST로 그대로 보내면 일부 호스팅사의
// WAF/ModSecurity가 SQL 인젝션으로 오탐지해 요청 자체를 403으로 차단하는 사례가 확인됨
// (office/deferred_tracker Add/Edit Entry). 클라이언트에서 base64로 감싸 보내고 여기서 복원한다.
// 구버전 캐시 JS가 원문 그대로 보내는 과도기 대응: base64 디코드 실패 시 원문을 그대로 사용.
function office_b64_decode(string $v): string {
    if ($v === '') return '';
    $decoded = base64_decode($v, true);
    return $decoded !== false ? $decoded : $v;
}

function require_office_permission(): void {
    if (has_office_permission()) return;
    // office/ 깊이에 무관하게 admin 루트로 리다이렉트
    $pos = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/office/');
    $admin_url = ($pos !== false)
        ? substr($_SERVER['SCRIPT_NAME'], 0, $pos) . '/admin/index.php'
        : '/admin/index.php';
    header('Location: ' . $admin_url . '?error=permission_denied');
    exit;
}

// ── 사번 발급 ────────────────────────────────────────────────────
// 사번 형식: 입사년도 2자리 + 회사코드 2자리(고정) + 순번 3자리 (예: 2026년 59번째 입사자 → "2601059")
// 순번은 전체 회사(모든 점포 합산) 기준, 입사연도별로 매년 001부터 다시 시작.
// Design Ref: employee-no-numbering (2026-08-06 요청)
if (!defined('OFFICE_COMPANY_CODE')) define('OFFICE_COMPANY_CODE', '01');

function generate_employee_no(?string $hire_date): string {
    $year = $hire_date ? date('y', strtotime($hire_date)) : date('y');
    $prefix = $year . OFFICE_COMPANY_CODE;

    $conn = get_db_connection();
    $like = $prefix . '%';
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM office_employees WHERE employee_no LIKE ?");
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    $conn->close();

    return $prefix . sprintf('%03d', $count + 1);
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
            "SELECT id, name, job_role, agency, status, inactive_reason, inactive_date, photo, hire_date, employee_no FROM office_employees
             WHERE store_id=? AND job_role=? AND status=? ORDER BY name"
        );
        $stmt->bind_param('iss', $store_id, $job_role, $status);
    } else {
        $stmt = $conn->prepare(
            "SELECT id, name, job_role, agency, status, inactive_reason, inactive_date, photo, hire_date, employee_no FROM office_employees
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

    // 현금 매입: payment_date 기준
    $stmt = $conn->prepare(
        "SELECT SUM(amount) AS total FROM office_product_purchases
         WHERE store_id=? AND payment_type='cash' AND YEAR(payment_date)=? AND MONTH(payment_date)=?"
    );
    $stmt->bind_param('iii', $store_id, $year, $month);
    $stmt->execute();
    $cash_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // 수표 매입: check_issued_date 기준 (payment_date와 달라질 수 있음 — Ref: sales_report_helper.php §2b)
    $stmt2 = $conn->prepare(
        "SELECT SUM(amount) AS total FROM office_product_purchases
         WHERE store_id=? AND payment_type='check' AND YEAR(check_issued_date)=? AND MONTH(check_issued_date)=?"
    );
    $stmt2->bind_param('iii', $store_id, $year, $month);
    $stmt2->execute();
    $check_row = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();

    $stmt3 = $conn->prepare(
        "SELECT SUM(amount) AS total FROM office_equipment_purchases
         WHERE store_id=? AND YEAR(payment_date)=? AND MONTH(payment_date)=?"
    );
    $stmt3->bind_param('iii', $store_id, $year, $month);
    $stmt3->execute();
    $eq_row = $stmt3->get_result()->fetch_assoc();
    $stmt3->close();
    $conn->close();

    return [
        'product_cash'  => (float)($cash_row['total'] ?? 0),
        'product_check' => (float)($check_row['total'] ?? 0),
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

// 전 점포 매입 합계 + 미결제 수표 건수를 한 번에 조회 (main_office/index.php 대시보드용)
// get_purchase_monthly_total()/get_pending_checks_count()를 점포 수만큼 반복 호출하면
// 점포당 커넥션이 2개씩 열려(총 2N+1개) 공유호스팅 커넥션 제한에 걸리는 문제가 있었음
// (Design Ref: main_office 커넥션 버스트 문제, permission_pdo()와 동일한 유형 — lib/permission_helper.php 참고)
// @return array store_id => ['product_cash'=>float, 'product_check'=>float, 'equipment'=>float, 'pending_checks'=>int]
function get_main_office_store_summaries(int $year, int $month): array {
    $conn = get_db_connection();
    $summaries = [];
    $blank = ['product_cash' => 0.0, 'product_check' => 0.0, 'equipment' => 0.0, 'pending_checks' => 0];

    // 현금/수표 매입 합계 (기준 날짜 컬럼이 서로 달라 CASE WHEN으로 한 번에 집계)
    $stmt = $conn->prepare(
        "SELECT store_id,
                SUM(CASE WHEN payment_type='cash' AND YEAR(payment_date)=? AND MONTH(payment_date)=? THEN amount ELSE 0 END) AS cash_total,
                SUM(CASE WHEN payment_type='check' AND YEAR(check_issued_date)=? AND MONTH(check_issued_date)=? THEN amount ELSE 0 END) AS check_total
         FROM office_product_purchases
         GROUP BY store_id"
    );
    $stmt->bind_param('iiii', $year, $month, $year, $month);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $sid = (int)$row['store_id'];
        if (!isset($summaries[$sid])) $summaries[$sid] = $blank;
        $summaries[$sid]['product_cash']  = (float)($row['cash_total'] ?? 0);
        $summaries[$sid]['product_check'] = (float)($row['check_total'] ?? 0);
    }
    $stmt->close();

    // 장비 매입 합계
    $stmt2 = $conn->prepare(
        "SELECT store_id, SUM(amount) AS eq_total FROM office_equipment_purchases
         WHERE YEAR(payment_date)=? AND MONTH(payment_date)=? GROUP BY store_id"
    );
    $stmt2->bind_param('ii', $year, $month);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    while ($row = $res2->fetch_assoc()) {
        $sid = (int)$row['store_id'];
        if (!isset($summaries[$sid])) $summaries[$sid] = $blank;
        $summaries[$sid]['equipment'] = (float)($row['eq_total'] ?? 0);
    }
    $stmt2->close();

    // 미결제 수표 건수 (payment_date >= 오늘)
    $stmt3 = $conn->prepare(
        "SELECT store_id, COUNT(*) AS cnt FROM office_product_purchases
         WHERE payment_type='check' AND payment_date >= CURDATE() GROUP BY store_id"
    );
    $stmt3->execute();
    $res3 = $stmt3->get_result();
    while ($row = $res3->fetch_assoc()) {
        $sid = (int)$row['store_id'];
        if (!isset($summaries[$sid])) $summaries[$sid] = $blank;
        $summaries[$sid]['pending_checks'] = (int)($row['cnt'] ?? 0);
    }
    $stmt3->close();

    $conn->close();
    return $summaries;
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

// ── 저장된 리포트 상태(state_json) 조회 — main_office 읽기 전용 열람용 ──────────
// er_saved_state / cd_saved_state / cer_saved_state 는 모두 동일 스키마
// (store_id, save_date, state_json, saved_at)를 쓰므로 테이블명만 바꿔 재사용한다.
// $table은 화이트리스트로만 허용 (SQL Injection 방지 — 테이블명은 바인딩 불가).
function get_saved_report_state(string $table, int $store_id, string $date): ?array {
    $allowed = ['er_saved_state', 'cd_saved_state', 'cer_saved_state'];
    if (!in_array($table, $allowed, true)) {
        return null;
    }

    $conn = get_db_connection();
    $tbl_check = $conn->query("SHOW TABLES LIKE '{$table}'");
    if (!$tbl_check || $tbl_check->num_rows === 0) {
        $conn->close();
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT state_json, DATE_FORMAT(saved_at,'%Y-%m-%d %H:%i') AS saved_at
         FROM {$table} WHERE store_id=? AND save_date=?"
    );
    $stmt->bind_param('is', $store_id, $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$row) {
        return null;
    }

    $decoded = json_decode($row['state_json'], true);
    if (!is_array($decoded)) {
        return null;
    }

    $decoded['saved_at'] = $row['saved_at'];
    return $decoded;
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

// 점포가 admin/my_store.php에서 지정한 오피스 대표 직원 이름 (결제란 PREPARED 표시용)
// Design Ref: main_office 결제란(PREPARED) 대표직원 지정 기능 — stores.representative_user_id
function get_store_representative_name(int $store_id): string {
    if ($store_id <= 0) return '';
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "SELECT u.full_name FROM stores s
         JOIN users u ON u.id = s.representative_user_id
         WHERE s.id = ?"
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

// ── 발령 이력 / 점포 전입 (Design Ref: docs/02-design/features/employee-hire-transfer.design.md §2.2) ──

// 해당 점포 소속 직원 전원의 발령 이력을 employee_id 기준으로 일괄 조회 (N+1 방지)
function get_employee_history_by_store(int $store_id): array {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "SELECT h.* FROM office_employee_history h
         JOIN office_employees e ON h.employee_id = e.id
         WHERE e.store_id = ?
         ORDER BY h.event_date DESC, h.id DESC"
    );
    $stmt->bind_param('i', $store_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();

    $by_emp = [];
    foreach ($rows as $row) {
        $by_emp[$row['employee_id']][] = $row;
    }
    return $by_emp;
}

// 발령 이력 1건 추가
function add_employee_history(int $employee_id, int $store_id, string $event_type, string $content, string $event_date, ?int $created_by): bool {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "INSERT INTO office_employee_history (employee_id, store_id, event_type, content, event_date, created_by)
         VALUES (?,?,?,?,?,?)"
    );
    $stmt->bind_param('iisssi', $employee_id, $store_id, $event_type, $content, $event_date, $created_by);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $ok;
}

// ── Employee HR records (경고장 등 인사기록) ──────────────────────
// Design Ref: employee-hr-records.design.md §2.0 (Option A) — employee_history와 동일한 헬퍼 패턴

function get_employee_records_by_store(int $store_id): array {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "SELECT r.* FROM office_employee_records r
         JOIN office_employees e ON r.employee_id = e.id
         WHERE e.store_id = ?
         ORDER BY r.created_at DESC, r.id DESC"
    );
    $stmt->bind_param('i', $store_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();

    $by_emp = [];
    foreach ($rows as $row) {
        $row['attachment_files'] = $row['attachment_files'] ? (json_decode($row['attachment_files'], true) ?: []) : [];
        $by_emp[$row['employee_id']][] = $row;
    }
    return $by_emp;
}

function add_employee_record(int $employee_id, int $store_id, string $event_type, ?string $category, ?string $content, int $points, array $attachment_files, ?int $created_by): bool {
    $conn = get_db_connection();
    $files_json = json_encode(array_values($attachment_files));
    $stmt = $conn->prepare(
        "INSERT INTO office_employee_records (employee_id, store_id, event_type, category, content, points, attachment_files, created_by)
         VALUES (?,?,?,?,?,?,?,?)"
    );
    $stmt->bind_param('iisssisi', $employee_id, $store_id, $event_type, $category, $content, $points, $files_json, $created_by);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $ok;
}

// $new_files는 기존 첨부에 추가로 합쳐질 새 파일명 배열, $removed_files는 기존 첨부 중 제거할 파일명 배열 (둘 다 없으면 [])
// Design Ref: employee-hr-records.design.md §4.2 — 이미지 추가·제거 (analysis.md §9.1 이미지 개별삭제 보완)
// 반환값: 성공 시 ['ok' => true, 'removed' => 실제로 지워진 파일명 배열], 실패(소유권 불일치) 시 ['ok' => false, 'removed' => []]
function update_employee_record(int $id, int $store_id, string $event_type, ?string $category, ?string $content, int $points, array $new_files, array $removed_files = []): array {
    $conn = get_db_connection();

    $chk = $conn->prepare(
        "SELECT r.id, r.attachment_files FROM office_employee_records r
         JOIN office_employees e ON r.employee_id = e.id
         WHERE r.id = ? AND e.store_id = ?"
    );
    $chk->bind_param('ii', $id, $store_id);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$row) { $conn->close(); return ['ok' => false, 'removed' => []]; }

    $existing = $row['attachment_files'] ? (json_decode($row['attachment_files'], true) ?: []) : [];
    $after_removal = array_values(array_diff($existing, $removed_files));
    $actually_removed = array_values(array_diff($existing, $after_removal));
    $merged = array_values(array_merge($after_removal, $new_files));
    $files_json = json_encode($merged);

    $stmt = $conn->prepare(
        "UPDATE office_employee_records SET event_type=?, category=?, content=?, points=?, attachment_files=? WHERE id=?"
    );
    $stmt->bind_param('sssisi', $event_type, $category, $content, $points, $files_json, $id);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();
    return ['ok' => $ok, 'removed' => $actually_removed];
}

// 반환값: 삭제 성공 시 실제 디스크에서 지워야 할 첨부파일명 배열, 실패(소유권 불일치 등) 시 null
function delete_employee_record(int $id, int $store_id): ?array {
    $conn = get_db_connection();

    $chk = $conn->prepare(
        "SELECT r.id, r.attachment_files FROM office_employee_records r
         JOIN office_employees e ON r.employee_id = e.id
         WHERE r.id = ? AND e.store_id = ?"
    );
    $chk->bind_param('ii', $id, $store_id);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$row) { $conn->close(); return null; }

    $files = $row['attachment_files'] ? (json_decode($row['attachment_files'], true) ?: []) : [];

    $del = $conn->prepare("DELETE FROM office_employee_records WHERE id = ?");
    $del->bind_param('i', $id);
    $del->execute();
    $del->close();
    $conn->close();
    return $files;
}

// 전입 대상 점포 select용 전체 점포 목록
function get_all_stores(): array {
    $conn = get_db_connection();
    $r = $conn->query("SELECT id, name FROM stores ORDER BY name ASC");
    $rows = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    $conn->close();
    return $rows;
}

// 현재 점포 소속 직원 중 본인이 신청한 처리 대기중 전입 요청이 있는 employee_id 목록
// (정보모달의 "전입 요청" 버튼 비활성화 판단용)
function get_employee_ids_with_pending_transfer(int $store_id): array {
    $conn = get_db_connection();
    $stmt = $conn->prepare("SELECT employee_id FROM office_employee_transfers WHERE status='pending' AND from_store_id=?");
    $stmt->bind_param('i', $store_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    return array_map('intval', array_column($rows, 'employee_id'));
}

// 해당 직원의 처리 대기중 전입 요청 1건 (중복 요청 방지용)
function get_employee_pending_transfer(int $employee_id): ?array {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "SELECT * FROM office_employee_transfers WHERE employee_id=? AND status='pending' ORDER BY id DESC LIMIT 1"
    );
    $stmt->bind_param('i', $employee_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $row ?: null;
}

// 전입 요청 생성. 성공 시 true, 실패 시 오류 메시지 문자열 반환
function create_employee_transfer_request(int $employee_id, int $from_store_id, int $to_store_id, ?string $reason, ?int $requested_by) {
    if ($from_store_id === $to_store_id) {
        return '현재 소속과 동일한 점포입니다.';
    }
    if (get_employee_pending_transfer($employee_id)) {
        return '이미 처리 대기중인 전입 요청이 있습니다.';
    }
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "INSERT INTO office_employee_transfers (employee_id, from_store_id, to_store_id, reason, status, requested_by)
         VALUES (?,?,?,?,'pending',?)"
    );
    $reason_val = ($reason !== null && $reason !== '') ? $reason : null;
    $stmt->bind_param('iiisi', $employee_id, $from_store_id, $to_store_id, $reason_val, $requested_by);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $ok ? true : '요청 생성 중 오류가 발생했습니다.';
}

// 특정 점포로 들어오는(목적지) 처리 대기중 전입 요청 목록 (직원 정보 JOIN)
function get_pending_transfers_to_store(int $store_id): array {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "SELECT t.id, t.employee_id, t.from_store_id, t.to_store_id, t.reason, t.created_at,
                e.name AS employee_name, e.job_role, e.photo,
                fs.name AS from_store_name
         FROM office_employee_transfers t
         JOIN office_employees e ON t.employee_id = e.id
         LEFT JOIN stores fs ON t.from_store_id = fs.id
         WHERE t.status = 'pending' AND t.to_store_id = ?
         ORDER BY t.created_at ASC"
    );
    $stmt->bind_param('i', $store_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    return $rows;
}

// 전입 요청 승인/반려. $current_store_id(처리자의 현재 세션 점포)가 요청의 to_store_id와
// 일치하는지 반드시 재검증한다 — 이 검증이 없으면 다른 점포 스태프가 임의로 승인할 수 있다.
function decide_employee_transfer(int $request_id, bool $approve, int $current_store_id, int $approver_id, string $note) {
    $conn = get_db_connection();
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT * FROM office_employee_transfers WHERE id=? FOR UPDATE");
        $stmt->bind_param('i', $request_id);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$req || $req['status'] !== 'pending') {
            $conn->rollback();
            $conn->close();
            return '이미 처리되었거나 존재하지 않는 요청입니다.';
        }
        if ((int)$req['to_store_id'] !== $current_store_id) {
            $conn->rollback();
            $conn->close();
            return '이 요청을 처리할 권한이 없습니다.';
        }

        if ($approve) {
            $upd = $conn->prepare("UPDATE office_employees SET store_id=? WHERE id=?");
            $upd->bind_param('ii', $req['to_store_id'], $req['employee_id']);
            $upd->execute();
            $upd->close();

            $stores = $conn->prepare("SELECT id, name FROM stores WHERE id IN (?,?)");
            $stores->bind_param('ii', $req['from_store_id'], $req['to_store_id']);
            $stores->execute();
            $store_names = [];
            foreach ($stores->get_result()->fetch_all(MYSQLI_ASSOC) as $s) {
                $store_names[$s['id']] = $s['name'];
            }
            $stores->close();
            $from_name = $store_names[$req['from_store_id']] ?? ('#' . $req['from_store_id']);
            $to_name   = $store_names[$req['to_store_id']] ?? ('#' . $req['to_store_id']);

            $content = $from_name . ' → ' . $to_name . ' 전입';
            $today = date('Y-m-d');
            $hist = $conn->prepare(
                "INSERT INTO office_employee_history (employee_id, store_id, event_type, content, event_date, created_by)
                 VALUES (?,?,'transfer',?,?,?)"
            );
            $hist->bind_param('iissi', $req['employee_id'], $req['to_store_id'], $content, $today, $approver_id);
            $hist->execute();
            $hist->close();
        }

        $status = $approve ? 'approved' : 'rejected';
        $note_val = ($note !== '') ? $note : null;
        $u = $conn->prepare(
            "UPDATE office_employee_transfers SET status=?, approved_by=?, decision_note=?, decided_at=NOW() WHERE id=?"
        );
        $u->bind_param('sisi', $status, $approver_id, $note_val, $request_id);
        $u->execute();
        $u->close();

        $conn->commit();
        $conn->close();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        $conn->close();
        error_log("decide_employee_transfer error: " . $e->getMessage());
        return '처리 중 오류가 발생했습니다.';
    }
}
