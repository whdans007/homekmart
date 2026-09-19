<?php

// 세션이 시작되지 않았다면 시작합니다.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * 테스트 서버에서만 자동 로그인을 허용할지 확인합니다.
 *
 * 운영 도메인을 기본 허용 목록에 넣지 않고, 호스트명은 정확히 일치시킵니다.
 * 추가 테스트 호스트가 필요하면 HOMEKMART_TEST_SERVER_HOSTS 환경 변수에
 * 쉼표로 구분해 지정할 수 있습니다.
 */
function is_test_server() {
    $server_name = strtolower(trim((string)($_SERVER['SERVER_NAME'] ?? '')));
    $http_host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    $http_host = preg_replace('/:\\d+$/', '', $http_host);

    $allowed_hosts = [
        'localhost',
        '127.0.0.1',
        '::1',
        'homekmart.test',
        '192-168-0-138.philsarang.direct.quickconnect.to',
        '192-168-1-123.philsarang.direct.quickconnect.to',
    ];

    $configured_hosts = getenv('HOMEKMART_TEST_SERVER_HOSTS');
    if ($configured_hosts !== false && trim($configured_hosts) !== '') {
        foreach (explode(',', $configured_hosts) as $configured_host) {
            $configured_host = strtolower(trim($configured_host));
            if ($configured_host !== '') {
                $allowed_hosts[] = $configured_host;
            }
        }
    }

    // SERVER_NAME을 우선 사용하고, 로컬 개발 서버처럼 비어 있는 경우에만 HTTP_HOST를 사용합니다.
    $request_host = $server_name !== '' ? $server_name : $http_host;
    return in_array($request_host, array_unique($allowed_hosts), true);
}

/**
 * 테스트 서버에서 활성화된 super_admin 계정으로 세션을 생성합니다.
 * 계정의 비밀번호를 우회하지 않고, users 테이블의 실제 활성 계정만 사용합니다.
 */
function try_auto_login_on_test_server() {
    if (is_logged_in() || !is_test_server()) {
        return false;
    }

    require_once __DIR__ . '/../config/db_config.php';

    try {
        $conn = get_db_connection();
        $stmt = $conn->prepare(
            "SELECT id, username, full_name, role, store_id
             FROM users
             WHERE role = 'super_admin' AND is_active = 1
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        $conn->close();

        if (!$user) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'] ?? '';
        $_SESSION['role'] = $user['role'];
        $_SESSION['store_id'] = $user['store_id'] ?? null;
        $_SESSION['auto_login'] = true;

        return true;
    } catch (Throwable $e) {
        error_log('Test server auto-login failed: ' . $e->getMessage());
        return false;
    }
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
            header('Location: /admin/login.php');
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

// session_helper.php를 사용하는 진입점 전체에서 테스트 서버 자동 로그인을 적용합니다.
if (!is_logged_in()) {
    try_auto_login_on_test_server();
}
