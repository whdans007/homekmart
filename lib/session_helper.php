<?php

// 세션이 시작되지 않았다면 시작합니다.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 로그인 되어 있는지 확인하는 함수
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

// 관리자 권한이 있는지 확인하는 함수
function is_admin() {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['super_admin', 'admin']);
}

// 로그인 되어 있는지 확인하고, 안 되어 있다면 로그인 페이지로 리디렉션합니다.
function ensure_logged_in() {
    if (!is_logged_in()) {
        // 세션이 없다면 "로그인 상태 유지" 쿠키를 확인합니다.
        if (isset($_COOKIE['remember_me'])) {
            try_login_from_cookie();
        }

        // 쿠키로도 로그인이 안됐다면, 다시 한번 세션을 확인하고 리디렉션합니다.
        if (!is_logged_in()) {
            header('Location: login.php');
            exit();
        }
    }
}

function try_login_from_cookie() {
    if (empty($_COOKIE['remember_me'])) {
        return;
    }

    list($user_id, $token) = explode(':', base64_decode($_COOKIE['remember_me']), 2);

    if (empty($user_id) || empty($token)) {
        return;
    }

    require_once __DIR__ . '/../config/db_config.php';
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND remember_token_expiry > NOW()");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && !empty($user['remember_token']) && password_verify($token, $user['remember_token'])) {
        // 쿠키가 유효하면, 세션을 설정하여 로그인 처리합니다.
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
    } else {
        // 유효하지 않은 쿠키는 삭제합니다.
        setcookie('remember_me', '', time() - 3600, '/');
    }
}
