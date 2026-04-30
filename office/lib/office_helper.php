<?php
// Design Ref: §5 — office_helper provides common DB query functions for all office modules
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';

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

// ── 직무 관련 ──────────────────────────────────────────

function get_job_role_label(string $role): string {
    $labels = [
        'cashier'      => '캐쉬어',
        'patcher'      => '파처',
        'butcher'      => '부처',
        'driver'       => '드라이버',
        'merchandiser' => '머천다이져',
        'supervisor'   => '슈퍼바이저',
        'admin'        => '어드민',
    ];
    return $labels[$role] ?? $role;
}

function get_job_roles(): array {
    return ['cashier', 'patcher', 'butcher', 'driver', 'merchandiser', 'supervisor', 'admin'];
}

// ── 직원 조회 ──────────────────────────────────────────

function get_office_employees(int $store_id, ?string $job_role = null, string $status = 'active'): array {
    $conn = get_db_connection();
    if ($job_role !== null) {
        $stmt = $conn->prepare(
            "SELECT id, name, job_role, status FROM office_employees
             WHERE store_id=? AND job_role=? AND status=? ORDER BY name"
        );
        $stmt->bind_param('iss', $store_id, $job_role, $status);
    } else {
        $stmt = $conn->prepare(
            "SELECT id, name, job_role, status FROM office_employees
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

// ── 지출 통계 ──────────────────────────────────────────

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

// 수표 미결 건수: payment_date >= 오늘
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

// 최근 6개월 상품+비품 합산 월별 추이 (Chart.js용)
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

// ── 휴무 계획서 ────────────────────────────────────────

// 존재하면 반환, 없으면 생성 후 id 반환
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

// ── 공통 유틸 ──────────────────────────────────────────

function get_office_store_id(): int {
    return (int)($_SESSION['store_id'] ?? 1);
}

function format_amount(float $amount): string {
    return '₱ ' . number_format($amount, 2);
}

// POST 값 안전하게 가져오기
function post_str(string $key, string $default = ''): string {
    return htmlspecialchars(trim($_POST[$key] ?? $default), ENT_QUOTES, 'UTF-8');
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
