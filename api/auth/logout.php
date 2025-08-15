<?php
/**
 * 로그아웃 API
 */

// POST 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Method not allowed', 405);
}

// 현재 인증 상태 확인
$auth = verify_session_auth();

if ($auth) {
    // 로그아웃 기록 로깅
    debug_log("User logout", [
        'user_id' => $auth['user_id'],
        'username' => $auth['username'],
        'session_duration' => isset($_SESSION['login_time']) ? time() - $_SESSION['login_time'] : 'unknown',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
}

// 세션 시작 (필요한 경우)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 세션 데이터 완전 삭제
$_SESSION = [];

// 세션 쿠키 삭제
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 세션 파괴
session_destroy();

// 성공 응답
api_success([
    'logged_out' => true,
    'timestamp' => date('c')
], 'Logout successful');
?>