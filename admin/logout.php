<?php
require_once __DIR__ . '/../lib/session_helper.php';

// 모든 세션 변수를 비웁니다.
if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/../config/db_config.php';
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare("UPDATE users SET remember_token = NULL, remember_token_expiry = NULL WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
    } catch (PDOException $e) {
        // 오류가 발생해도 로그아웃은 계속 진행되어야 하므로, 오류를 기록만 합니다.
        error_log("Remember me token clear failed for user_id: " . $_SESSION['user_id'] . " - " . $e->getMessage());
    }
}

// "로그인 상태 유지" 쿠키 삭제
if (isset($_COOKIE['remember_me'])) {
    unset($_COOKIE['remember_me']);
    setcookie('remember_me', '', time() - 3600, '/'); 
}

$_SESSION = [];

// 세션을 파기합니다.
session_destroy();

// 로그인 페이지로 리디렉션합니다.
header("Location: login.php");
exit();