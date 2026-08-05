<?php
// Design Ref: §4.2 — Option C: 기존 permission_helper 재활용 + 물류 전용 래퍼
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../config/db.php'; // LC_BASE 상수 로드

function kw_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// KIM'S MALL 창고 전용 소속 판정 (M TOWN 물류센터의 is_logistics_department()와 분리된 세션 캐시 사용)
function kw_is_logistics_department(): bool {
    kw_session_start();

    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    if (isset($_SESSION['kw_is_logistics'])) {
        return (bool)$_SESSION['kw_is_logistics'];
    }

    // '물류센터' 역할 이상(level 기준)이면 소속 점포와 무관하게 접근 허용
    if (current_role_at_least_label('물류센터')) {
        $_SESSION['kw_is_logistics'] = true;
        return true;
    }

    try {
        require_once __DIR__ . '/../../config/db_config.php';
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->prepare(
            "SELECT s.name AS store_name
             FROM users u
             LEFT JOIN stores s ON u.store_id = s.id
             WHERE u.id = ?"
        );
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $is_logistics = (isset($row['store_name']) && $row['store_name'] === "KIMS MALL WHEREHOUSE (킴스몰 창고)");
        $_SESSION['kw_is_logistics'] = $is_logistics;

        return $is_logistics;
    } catch (Exception $e) {
        error_log("kw_is_logistics_department error: " . $e->getMessage());
        return false;
    }
}

// KIM'S MALL 창고(소속지점 CENTER) 소속만 접근 가능. 단, super_admin 은 예외로 허용
// Design Ref: role-permission-management - KIM'S MALL 창고 페이지는 CENTER 소속 전용(슈퍼관리자 제외), 그 외 지점은 store/ 에서 주문
function kw_require_login(): void {
    kw_session_start();
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . LC_BASE . '/login.php');
        exit;
    }
    if (($_SESSION['role'] ?? '') !== 'super_admin' && !kw_is_logistics_department()) {
        header('Location: ' . LC_WEB_ROOT . '/store/index.php');
        exit;
    }
}

// KIM'S MALL 창고 직원 또는 관리자 여부
// M TOWN 물류센터(logistics_* 권한/CENTER 소속)와는 별개로 'KIMS MALL WHEREHOUSE (킴스몰 창고)' 소속만 직원으로 인정
function kw_is_staff(): bool {
    kw_session_start();
    if (in_array($_SESSION['role'] ?? '', ['super_admin', 'admin'])) {
        return true;
    }
    return kw_is_logistics_department();
}

// 점포 담당자 여부 (물류직원/관리자가 아닌 일반 store 소속)
function kw_is_store_user(): bool {
    return !kw_is_staff() && !empty($_SESSION['store_id']);
}

// 물류직원/관리자 전용 페이지 접근 제한
// Design Ref: role-permission-management - KIM'S MALL 창고 외 페이지는 물류 직원/관리자만 접근, 점포 직원은 발주 페이지로 리다이렉트
function kw_require_staff(): void {
    kw_require_login();
    if (!kw_is_staff()) {
        $_SESSION['kw_flash'] = ['type' => 'error', 'message' => 'Access denied.'];
        header('Location: ' . LC_BASE . '/order_new.php');
        exit;
    }
}

function kw_current_user_id(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function kw_current_store_id(): ?int {
    return isset($_SESSION['store_id']) ? (int)$_SESSION['store_id'] : null;
}

function kw_current_role(): string {
    return $_SESSION['role'] ?? '';
}

// 관리자(super_admin/admin/branch_manager) 여부 — 배치/주문 등 삭제 권한 판단용
// Design Ref: role-permission-management - 점장(branch_manager)은 KIM'S MALL 창고 전체 권한 보유
function kw_is_admin(): bool {
    if (in_array(kw_current_role(), ['super_admin', 'admin', 'branch_manager'], true)) {
        return true;
    }
    return current_role_at_least_label('물류센터');
}

// CSRF 토큰 생성/검증
function kw_csrf_token(): string {
    kw_session_start();
    if (empty($_SESSION['kw_csrf'])) {
        $_SESSION['kw_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['kw_csrf'];
}

function kw_verify_csrf(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['kw_csrf'] ?? '', $token)) {
        http_response_code(403);
        exit('CSRF validation failed');
    }
}

// 플래시 메시지
function kw_set_flash(string $type, string $message): void {
    kw_session_start();
    $_SESSION['kw_flash'] = ['type' => $type, 'message' => $message];
}

function kw_get_flash(): ?array {
    $flash = $_SESSION['kw_flash'] ?? null;
    unset($_SESSION['kw_flash']);
    return $flash;
}
