<?php
/**
 * mall_members 전용 세션 관리
 * Design Ref: shopping-mall.design.md §9.1 Infrastructure Layer, §7 세션 분리 요구사항
 *
 * admin/lib/session_helper.php의 관리자 세션과는 세션 이름부터 분리한다(MALLSESSID).
 * 이 파일이 요구하는 상수(MALL_SESSION_NAME)는 mall/config/mall_config.php에 정의되어 있다.
 */

require_once __DIR__ . '/../config/mall_config.php';
require_once __DIR__ . '/../../config/db_config.php';

/**
 * mall 전용 세션을 시작합니다. 이미 시작된 세션이 있으면 아무 것도 하지 않습니다.
 * @return void
 */
function mall_session_start() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(MALL_SESSION_NAME);
    session_start();
}

/**
 * 현재 mall_members 세션 로그인 여부를 확인합니다.
 * @return bool
 */
function mall_is_logged_in() {
    mall_session_start();
    return isset($_SESSION['mall_member_id']);
}

/**
 * 비회원(게스트) 장바구니를 구분하는 식별자 — mall 세션 ID를 그대로 쓴다.
 * 장바구니는 회원가입 없이도 담을 수 있어야 하므로(주문 시에만 로그인 필요), 이 값으로
 * mall_cart_items를 회원과 동일하게 채널/수량 관리한다.
 * 주의: mall_attempt_login()이 로그인 성공 시 session_regenerate_id()를 호출해 이 값이 바뀌므로,
 * 게스트 장바구니를 회원 장바구니로 병합하려면 로그인 호출 "전에" 이 값을 미리 캡처해둬야 한다.
 * @return string
 */
function mall_guest_token() {
    mall_session_start();
    return session_id();
}

/**
 * 로그인되어 있지 않으면 로그인 페이지로 리디렉션합니다.
 * @param string $redirect_url 로그인 페이지 경로
 * @return void
 */
function mall_require_login($redirect_url = '/mall/login.php') {
    mall_session_start();
    if (!mall_is_logged_in()) {
        $_SESSION['mall_redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: ' . $redirect_url);
        exit;
    }
}

/**
 * 현재 로그인한 회원 정보를 DB에서 조회해 반환합니다.
 * 승인 상태 등 민감 정보는 항상 최신값을 서버에서 재확인하기 위해 세션 캐시에 의존하지 않습니다.
 * @return array|null 회원 정보 배열 또는 미로그인 시 null
 */
function mall_current_member() {
    mall_session_start();
    if (!isset($_SESSION['mall_member_id'])) {
        return null;
    }

    try {
        $conn = get_db_connection();
        $stmt = $conn->prepare(
            "SELECT id, member_type, email, name, phone, business_name, business_reg_no,
                    store_id, retail_tier, wholesale_status, wholesale_customer_id, is_active
             FROM mall_members WHERE id = ? AND is_active = 1"
        );
        $stmt->bind_param('i', $_SESSION['mall_member_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $member = $result->fetch_assoc();
        $stmt->close();
        $conn->close();

        return $member ?: null;
    } catch (Exception $e) {
        error_log('mall_current_member error: ' . $e->getMessage());
        return null;
    }
}

/**
 * 현재 로그인한 회원이 승인된 도매 회원인지 확인합니다(서버측 재검증, 세션 캐시 신뢰 금지).
 * @return bool
 */
function mall_is_wholesale_approved() {
    $member = mall_current_member();
    return $member !== null
        && $member['member_type'] === 'wholesale'
        && $member['wholesale_status'] === 'approved';
}

/**
 * 이메일/비밀번호로 로그인을 시도합니다.
 * @param string $email
 * @param string $password
 * @return array{success:bool, member?:array, error?:string} 성공 시 member 포함, 실패 시 error 코드
 */
function mall_attempt_login($email, $password) {
    mall_session_start();

    try {
        $conn = get_db_connection();
        $stmt = $conn->prepare(
            "SELECT id, password_hash, member_type, is_active FROM mall_members WHERE email = ?"
        );
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        $conn->close();

        if (!$row || !$row['is_active'] || !password_verify($password, $row['password_hash'])) {
            return ['success' => false, 'error' => 'INVALID_CREDENTIALS'];
        }

        session_regenerate_id(true);
        $_SESSION['mall_member_id'] = $row['id'];
        $_SESSION['mall_member_type'] = $row['member_type'];

        return ['success' => true, 'member' => mall_current_member()];
    } catch (Exception $e) {
        error_log('mall_attempt_login error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'SERVER_ERROR'];
    }
}

/**
 * 현재 mall 세션을 종료합니다.
 * @return void
 */
function mall_logout() {
    mall_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/**
 * 로그인 후 이동할 대상 URL을 반환합니다. mall_require_login()이 기억해 둔 URL이 있으면
 * 그곳으로, 없으면 기본값으로 이동합니다. 동일 출처 경로만 허용해 오픈 리다이렉트를 방지합니다.
 * @param string $default
 * @return string
 */
function mall_get_login_redirect_target($default = '/mall/index.php') {
    mall_session_start();
    $target = $_SESSION['mall_redirect_after_login'] ?? '';
    unset($_SESSION['mall_redirect_after_login']);

    if (is_string($target) && $target !== '' && $target[0] === '/' && substr($target, 0, 2) !== '//') {
        return $target;
    }
    return $default;
}
