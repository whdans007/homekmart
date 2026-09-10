<?php
/**
 * mall_drivers(배송기사) 전용 세션 관리
 * Design Ref: mall-delivery-dispatch.design.md §7 — 기사 세션 분리
 *
 * mall_members(고객) 세션(MALLSESSID), admin users 세션과는 세션 이름부터 분리한다
 * (MALL_DRIVER_SESSION_NAME은 mall/config/mall_config.php에 정의되어 있다).
 */

require_once __DIR__ . '/../config/mall_config.php';
require_once __DIR__ . '/../../config/db_config.php';

define('MALL_DRIVER_REMEMBER_COOKIE', 'MALLDRIVER_REMEMBER');
define('MALL_DRIVER_REMEMBER_LIFETIME', 60 * 60 * 24 * 365 * 5);

/**
 * 배송기사 전용 세션을 시작합니다. 이미 시작된 세션이 있으면 아무 것도 하지 않습니다.
 * @return void
 */
function mall_driver_session_start() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(MALL_DRIVER_SESSION_NAME);
    // 기사 앱은 매일 사용하는 전용 단말이므로 브라우저 종료 후에도 30일간 로그인을 유지한다.
    $lifetime = 60 * 60 * 24 * 30;
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    // PHP 세션 파일이 서버에서 정리되더라도 앱의 장기 로그인 토큰으로 세션을 복구한다.
    if (empty($_SESSION['mall_driver_id']) && !empty($_COOKIE[MALL_DRIVER_REMEMBER_COOKIE])) {
        try {
            $raw = (string)$_COOKIE[MALL_DRIVER_REMEMBER_COOKIE];
            $hash = hash('sha256', $raw);
            $conn = mall_get_db_connection();
            $stmt = $conn->prepare(
                'SELECT t.driver_id FROM mall_driver_login_tokens t
                 INNER JOIN mall_drivers d ON d.id = t.driver_id
                 WHERE t.token_hash = ? AND t.expires_at > NOW() AND d.is_active = 1 LIMIT 1'
            );
            $stmt->bind_param('s', $hash);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $_SESSION['mall_driver_id'] = (int)$row['driver_id'];
                $expires = date('Y-m-d H:i:s', time() + MALL_DRIVER_REMEMBER_LIFETIME);
                $touch = $conn->prepare('UPDATE mall_driver_login_tokens SET last_used_at = NOW(), expires_at = ? WHERE token_hash = ?');
                $touch->bind_param('ss', $expires, $hash); $touch->execute(); $touch->close();
                setcookie(MALL_DRIVER_REMEMBER_COOKIE, $raw, [
                    'expires' => time() + MALL_DRIVER_REMEMBER_LIFETIME,
                    'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'
                ]);
            } else {
                setcookie(MALL_DRIVER_REMEMBER_COOKIE, '', time() - 3600, '/', '', true, true);
            }
        } catch (Throwable $e) {
            // 마이그레이션 전에도 기존 세션 로그인은 계속 동작해야 한다.
            error_log('mall driver remember login: ' . $e->getMessage());
        }
    }
}

function mall_driver_issue_remember_token($driver_id) {
    try {
        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $expires = date('Y-m-d H:i:s', time() + MALL_DRIVER_REMEMBER_LIFETIME);
        $conn = mall_get_db_connection();
        $stmt = $conn->prepare('INSERT INTO mall_driver_login_tokens (driver_id, token_hash, expires_at) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $driver_id, $hash, $expires);
        $stmt->execute(); $stmt->close();
        setcookie(MALL_DRIVER_REMEMBER_COOKIE, $raw, [
            'expires' => time() + MALL_DRIVER_REMEMBER_LIFETIME,
            'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'
        ]);
    } catch (Throwable $e) {
        error_log('mall driver issue remember token: ' . $e->getMessage());
    }
}

/**
 * 현재 mall_drivers 세션 로그인 여부를 확인합니다.
 * @return bool
 */
function mall_driver_is_logged_in() {
    mall_driver_session_start();
    return isset($_SESSION['mall_driver_id']);
}

/**
 * 로그인되어 있지 않으면 기사 로그인 페이지로 리디렉션합니다.
 * @param string $redirect_url
 * @return void
 */
function mall_driver_require_login($redirect_url = '/mall/driver/login.php') {
    mall_driver_session_start();
    if (!mall_driver_is_logged_in()) {
        header('Location: ' . $redirect_url);
        exit;
    }
}

/**
 * 현재 로그인한 배송기사 정보를 DB에서 조회해 반환합니다.
 * @return array|null 기사 정보 배열 또는 미로그인/비활성 시 null
 */
function mall_driver_current() {
    mall_driver_session_start();
    if (!isset($_SESSION['mall_driver_id'])) {
        return null;
    }

    try {
        $conn = mall_get_db_connection();
        $stmt = $conn->prepare(
            'SELECT id, name, phone, driver_type, vehicle_info, is_active, is_available, approval_status
             FROM mall_drivers WHERE id = ? AND is_active = 1 AND approval_status = "approved"'
        );
        $stmt->bind_param('i', $_SESSION['mall_driver_id']);
        $stmt->execute();
        $driver = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $driver ?: null;
    } catch (Exception $e) {
        error_log('mall_driver_current error: ' . $e->getMessage());
        return null;
    }
}

/**
 * 연락처/비밀번호로 배송기사 로그인을 시도합니다.
 * @param string $phone
 * @param string $password
 * @return array{success:bool, driver?:array, error?:string}
 */
function mall_driver_attempt_login($phone, $password) {
    mall_driver_session_start();

    try {
        $conn = mall_get_db_connection();
        $stmt = $conn->prepare(
            'SELECT id, password_hash, is_active, approval_status FROM mall_drivers WHERE phone = ?'
        );
        $stmt->bind_param('s', $phone);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || !password_verify($password, $row['password_hash'])) {
            return ['success' => false, 'error' => 'INVALID_CREDENTIALS'];
        }
        if (($row['approval_status'] ?? 'approved') === 'pending') {
            return ['success' => false, 'error' => 'PENDING_APPROVAL'];
        }
        if (($row['approval_status'] ?? 'approved') !== 'approved' || !$row['is_active']) {
            return ['success' => false, 'error' => 'INACTIVE'];
        }

        session_regenerate_id(true);
        $_SESSION['mall_driver_id'] = $row['id'];
        mall_driver_issue_remember_token((int)$row['id']);

        return ['success' => true, 'driver' => mall_driver_current()];
    } catch (Exception $e) {
        error_log('mall_driver_attempt_login error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'SERVER_ERROR'];
    }
}

function mall_driver_signup($name, $phone, $password, $vehicle_info) {
    $name = trim($name);
    $phone = trim($phone);
    $vehicle_info = trim($vehicle_info);
    if ($name === '' || $phone === '' || strlen($password) < 8) {
        return ['success' => false, 'error' => 'VALIDATION_ERROR'];
    }
    try {
        $conn = mall_get_db_connection();
        $check = $conn->prepare('SELECT id FROM mall_drivers WHERE phone = ?');
        $check->bind_param('s', $phone);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();
        if ($exists) return ['success' => false, 'error' => 'DUPLICATE_PHONE'];

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO mall_drivers (name, phone, password_hash, vehicle_info, driver_type, is_active, is_available, approval_status) VALUES (?, ?, ?, ?, 'external', 0, 0, 'pending')");
        $stmt->bind_param('ssss', $name, $phone, $hash, $vehicle_info);
        $stmt->execute();
        $stmt->close();
        return ['success' => true];
    } catch (Throwable $e) {
        error_log('mall_driver_signup: ' . $e->getMessage());
        return ['success' => false, 'error' => 'SERVER_ERROR'];
    }
}

/**
 * 현재 mall_drivers 세션을 종료합니다.
 * @return void
 */
function mall_driver_logout() {
    mall_driver_session_start();
    if (!empty($_COOKIE[MALL_DRIVER_REMEMBER_COOKIE])) {
        try {
            $hash = hash('sha256', (string)$_COOKIE[MALL_DRIVER_REMEMBER_COOKIE]);
            $conn = mall_get_db_connection();
            $stmt = $conn->prepare('DELETE FROM mall_driver_login_tokens WHERE token_hash = ?');
            $stmt->bind_param('s', $hash); $stmt->execute(); $stmt->close();
        } catch (Throwable $e) { error_log('mall driver remember logout: ' . $e->getMessage()); }
        setcookie(MALL_DRIVER_REMEMBER_COOKIE, '', time() - 3600, '/', '', true, true);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
