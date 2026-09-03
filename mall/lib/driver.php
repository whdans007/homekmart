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

/**
 * 배송기사 전용 세션을 시작합니다. 이미 시작된 세션이 있으면 아무 것도 하지 않습니다.
 * @return void
 */
function mall_driver_session_start() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(MALL_DRIVER_SESSION_NAME);
    session_start();
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
            'SELECT id, name, phone, driver_type, vehicle_info, is_active
             FROM mall_drivers WHERE id = ? AND is_active = 1'
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
            'SELECT id, password_hash, is_active FROM mall_drivers WHERE phone = ?'
        );
        $stmt->bind_param('s', $phone);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || !$row['is_active'] || !password_verify($password, $row['password_hash'])) {
            return ['success' => false, 'error' => 'INVALID_CREDENTIALS'];
        }

        session_regenerate_id(true);
        $_SESSION['mall_driver_id'] = $row['id'];

        return ['success' => true, 'driver' => mall_driver_current()];
    } catch (Exception $e) {
        error_log('mall_driver_attempt_login error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'SERVER_ERROR'];
    }
}

/**
 * 현재 mall_drivers 세션을 종료합니다.
 * @return void
 */
function mall_driver_logout() {
    mall_driver_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
