<?php
// Design Ref: §3.2 — logistics 패턴, ord_* 접두사
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../config/db.php';

function ord_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
}

function ord_require_login(): void {
    ord_session_start();
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . ORD_BASE . '/login.php');
        exit;
    }
}

function ord_is_admin(): bool {
    ord_session_start();
    return in_array($_SESSION['role'] ?? '', ['super_admin', 'admin'])
        || has_permission('purchase_management');
}

function ord_require_admin(): void {
    ord_require_login();
    if (!ord_is_admin()) {
        ord_set_flash('error', '접근 권한이 없습니다.');
        header('Location: ' . ORD_BASE . '/index.php');
        exit;
    }
}

// 발주 시스템 전체 접근 기준: 매니저(level 40) 이상
if (!defined('ORD_LEVEL_MANAGER')) define('ORD_LEVEL_MANAGER', 40);

function ord_is_manager(): bool {
    ord_session_start();
    // super_admin / admin 은 항상 허용
    if (in_array($_SESSION['role'] ?? '', ['super_admin', 'admin'], true)) {
        return true;
    }
    return current_user_level() >= ORD_LEVEL_MANAGER;
}

/**
 * 발주 시스템 접근 게이트 — 로그인 + 매니저 이상 등급만 허용.
 * 권한 미달 사용자는 관리자 화면으로 돌려보낸다.
 * (ORD_BASE 내부로 리다이렉트하면 게이트 재실행으로 무한 루프가 발생하므로 /admin 으로 보낸다)
 */
function ord_require_manager(): void {
    ord_require_login();
    if (!ord_is_manager()) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => '발주 시스템은 매니저 이상만 접근할 수 있습니다.'];
        header('Location: ' . ORD_WEB_ROOT . '/admin/');
        exit;
    }
}

function ord_current_user_id(): int  { return (int)($_SESSION['user_id'] ?? 0); }
function ord_current_store_id(): int { return (int)($_SESSION['store_id'] ?? 0); }
function ord_current_role(): string  { return $_SESSION['role'] ?? ''; }

function ord_csrf_token(): string {
    ord_session_start();
    if (empty($_SESSION['ord_csrf'])) {
        $_SESSION['ord_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['ord_csrf'];
}

function ord_verify_csrf(): void {
    ord_session_start();
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['ord_csrf'] ?? '', $token)) {
        http_response_code(403);
        exit(json_encode(['success' => false, 'error' => 'CSRF validation failed']));
    }
}

function ord_set_flash(string $type, string $message): void {
    ord_session_start();
    $_SESSION['ord_flash'] = ['type' => $type, 'message' => $message];
}

function ord_get_flash(): ?array {
    $flash = $_SESSION['ord_flash'] ?? null;
    unset($_SESSION['ord_flash']);
    return $flash;
}
