<?php
// Access control for Main Office (all-store data viewer).
// Shares the admin login session; only main_office_admin+ role level may access.
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';

function mo_require_admin(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!is_logged_in()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: ../admin/login.php');
        exit;
    }

    if (!is_main_office_admin()) {
        header('Location: ../index.php?error=permission_denied');
        exit;
    }
}
