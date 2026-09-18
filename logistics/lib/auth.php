<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../lib/lang_helper.php';
// Design Ref: §4.2 — Option C: 기존 permission_helper 재활용 + 물류 전용 래퍼
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../config/db.php'; // LC_BASE 상수 로드

function lc_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// 물류센터(소속지점 CENTER) 소속만 접근 가능. 단, super_admin 은 예외로 허용
// Design Ref: role-permission-management - 물류센터 페이지는 CENTER 소속 전용(슈퍼관리자 제외), 그 외 지점은 store/ 에서 주문
function lc_require_login(): void {
    lc_session_start();
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . LC_BASE . '/login.php');
        exit;
    }
    if (($_SESSION['role'] ?? '') !== 'super_admin' && !is_logistics_department() && !current_role_at_least_label('물류센터')) {
        header('Location: ' . LC_WEB_ROOT . '/store/index.php');
        exit;
    }
}

// 물류직원 또는 관리자 여부
// Design Ref: role-permission-management - logistics_* 권한 보유 역할은 물류 직원 메뉴 노출
// '물류센터' 역할 이상(level 기준)이면 소속 점포와 무관하게 직원으로 인정
function lc_is_staff(): bool {
    lc_session_start();
    if (in_array($_SESSION['role'] ?? '', ['super_admin', 'admin'])) {
        return true;
    }
    if (has_permission('logistics_purchase_management')
        || has_permission('logistics_outbound_management')
        || has_permission('logistics_inventory_management')) {
        return true;
    }
    if (current_role_at_least_label('물류센터')) {
        return true;
    }
    return is_logistics_department();
}

// 점포 담당자 여부 (물류직원/관리자가 아닌 일반 store 소속)
function lc_is_store_user(): bool {
    return !lc_is_staff() && !empty($_SESSION['store_id']);
}

// 물류직원/관리자 전용 페이지 접근 제한
// Design Ref: role-permission-management - 물류센터 외 페이지는 물류 직원/관리자만 접근, 점포 직원은 발주 페이지로 리다이렉트
function lc_require_staff(): void {
    lc_require_login();
    if (!lc_is_staff()) {
        $_SESSION['lc_flash'] = ['type' => 'error', 'message' => 'Access denied.'];
        header('Location: ' . LC_BASE . '/order_new.php');
        exit;
    }
}

function lc_current_user_id(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function lc_current_store_id(): ?int {
    return isset($_SESSION['store_id']) ? (int)$_SESSION['store_id'] : null;
}

// 물류센터(CENTER) 자체 점포명 — 출고/배분 목적지로 선택되면 안 됨 (자기 자신에게 출고하는 오류 방지)
const LC_CENTER_STORE_NAME = 'CENTER (물류센터)';

// store_id가 물류센터(CENTER) 자신인지 확인
function lc_is_center_store(mysqli $conn, int $store_id): bool {
    if ($store_id <= 0) return false;
    $st = $conn->prepare("SELECT 1 FROM stores WHERE id = ? AND name = ?");
    $name = LC_CENTER_STORE_NAME;
    $st->bind_param('is', $store_id, $name);
    $st->execute();
    $found = (bool)$st->get_result()->fetch_row();
    $st->close();
    return $found;
}

function lc_current_role(): string {
    return $_SESSION['role'] ?? '';
}

// 관리자(super_admin/admin/branch_manager) 여부 — 배치/주문 등 삭제 권한 판단용
// Design Ref: role-permission-management - 점장(branch_manager)은 물류센터 전체 권한 보유
function lc_is_admin(): bool {
    if (in_array(lc_current_role(), ['super_admin', 'admin', 'branch_manager'], true)) {
        return true;
    }
    return current_role_at_least_label('물류센터');
}

// 슈퍼어드민 여부 — 배달 완료(delivered) 주문의 단품 수정/삭제 등 최고 권한 작업 판단용
function lc_is_super_admin(): bool {
    return lc_current_role() === 'super_admin';
}

// CSRF 토큰 생성/검증
function lc_csrf_token(): string {
    lc_session_start();
    if (empty($_SESSION['lc_csrf'])) {
        $_SESSION['lc_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['lc_csrf'];
}

function lc_verify_csrf(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['lc_csrf'] ?? '', $token)) {
        http_response_code(403);
        exit('CSRF validation failed');
    }
}

// 플래시 메시지
function lc_set_flash(string $type, string $message): void {
    lc_session_start();
    $_SESSION['lc_flash'] = ['type' => $type, 'message' => $message];
}

function lc_get_flash(): ?array {
    $flash = $_SESSION['lc_flash'] ?? null;
    unset($_SESSION['lc_flash']);
    return $flash;
}
