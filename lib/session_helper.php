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
            // 원래 접근하려던 URL을 기억해두었다가 로그인 후 그곳으로 돌려보냅니다.
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';
            header('Location: login.php');
            exit();
        }
    }
}

/**
 * 로그인 후 이동할 대상 URL을 반환합니다.
 * ensure_logged_in()이 기억해 둔 원래 접근 URL이 있으면 그곳으로,
 * 없으면 기본값($default)으로 보냅니다. 사용 후 세션 값은 제거합니다.
 * 동일 출처 경로(/로 시작, //로 시작하지 않음)만 허용하여 오픈 리다이렉트를 방지합니다.
 *
 * @param string $default 기억된 URL이 없을 때의 기본 이동 경로
 * @return string 이동할 안전한 경로
 */
function get_login_redirect_target($default = '/index.php') {
    $target = $_SESSION['redirect_after_login'] ?? '';
    unset($_SESSION['redirect_after_login']);

    if (is_string($target) && $target !== '' && $target[0] === '/' && substr($target, 0, 2) !== '//') {
        return $target;
    }
    return $default;
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
        $_SESSION['full_name'] = $user['full_name'] ?? $user['name'] ?? '';
        $_SESSION['role'] = $user['role'];
        $_SESSION['store_id'] = $user['store_id'] ?? null;
    } else {
        // 유효하지 않은 쿠키는 삭제합니다.
        setcookie('remember_me', '', time() - 3600, '/');
    }
}

/**
 * 현재 로그인된 사용자의 정보를 반환합니다.
 * @return array|null 사용자 정보 배열 또는 null
 */
function get_user_info() {
    if (!is_logged_in()) {
        return null;
    }

    require_once __DIR__ . '/../config/db_config.php';
    
    try {
        $conn = get_db_connection();
        $user_stmt = $conn->prepare("
            SELECT u.id, u.username, u.full_name, u.email, u.role, u.store_id,
                   u.permissions, u.is_active, u.preferred_language,
                   s.name as store_name
            FROM users u
            LEFT JOIN stores s ON u.store_id = s.id
            WHERE u.id = ?
        ");
        $user_stmt->bind_param("i", $_SESSION['user_id']);
        $user_stmt->execute();
        $user_stmt->bind_result(
            $u_id, $u_username, $u_full_name, $u_email, $u_role, $u_store_id,
            $u_permissions, $u_is_active, $u_preferred_language, $u_store_name
        );

        $user_row = null;
        if ($user_stmt->fetch()) {
            $user_row = [
                'id'                 => $u_id,
                'username'           => $u_username,
                'full_name'          => $u_full_name,
                'email'              => $u_email,
                'role'               => $u_role,
                'store_id'           => $u_store_id,
                'permissions'        => $u_permissions,
                'is_active'          => $u_is_active,
                'preferred_language' => $u_preferred_language,
                'store_name'         => $u_store_name,
            ];
        }

        $user_stmt->close();
        $conn->close();
        return $user_row;
        
    } catch (Exception $e) {
        error_log("get_user_info error: " . $e->getMessage());
        return null;
    }
}

/**
 * 현재 사용자의 역할을 반환합니다.
 * @return string|null 사용자 역할 또는 null
 */
function get_user_role() {
    return $_SESSION['role'] ?? null;
}

/**
 * 현재 사용자의 점포 ID를 반환합니다.
 * @return int|null 점포 ID 또는 null
 */
function get_user_store_id() {
    $user_info = get_user_info();
    return $user_info ? $user_info['store_id'] : null;
}

/**
 * 특정 역할을 가지고 있는지 확인합니다.
 * @param string $role 확인할 역할
 * @return bool 역할 보유 여부
 */
function has_role($role) {
    return get_user_role() === $role;
}
