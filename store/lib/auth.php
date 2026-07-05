<?php
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../config/db.php';

function store_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
}

function store_require_login(): void {
    store_session_start();
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . STORE_BASE . '/login.php');
        exit;
    }
}

function store_require_store_user(): void {
    store_require_login();
    if (empty($_SESSION['store_id'])) {
        store_set_flash('error', 'Please log in with a store account.');
        header('Location: ' . STORE_BASE . '/login.php');
        exit;
    }
}

function store_current_user_id(): int  { return (int)($_SESSION['user_id']  ?? 0); }
function store_current_store_id(): int { return (int)($_SESSION['store_id'] ?? 0); }
function store_current_name(): string  { return $_SESSION['full_name'] ?? $_SESSION['username'] ?? ''; }

function store_csrf_token(): string {
    store_session_start();
    if (empty($_SESSION['store_csrf'])) {
        $_SESSION['store_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['store_csrf'];
}

function store_verify_csrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['store_csrf'] ?? '', $token)) {
        http_response_code(403);
        exit('Security error');
    }
}

function store_set_flash(string $type, string $msg): void {
    store_session_start();
    $_SESSION['store_flash'] = ['type' => $type, 'message' => $msg];
}

function store_get_flash(): ?array {
    $f = $_SESSION['store_flash'] ?? null;
    unset($_SESSION['store_flash']);
    return $f;
}

function store_status_label(string $s): string {
    return ['pending'=>'Pending','approved'=>'Approved','shipped'=>'Shipped','delivered'=>'Delivered',
            'cancelled'=>'Cancelled','cancel_requested'=>'Cancellation Requested'][$s] ?? $s;
}

function store_status_class(string $s): string {
    return ['pending'=>'bg-yellow-100 text-yellow-800','approved'=>'bg-blue-100 text-blue-800',
            'shipped'=>'bg-purple-100 text-purple-800','delivered'=>'bg-green-100 text-green-800',
            'cancelled'=>'bg-gray-100 text-gray-500',
            'cancel_requested'=>'bg-orange-100 text-orange-700'][$s] ?? 'bg-gray-100 text-gray-500';
}
