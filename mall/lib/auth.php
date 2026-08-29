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
            "SELECT id, member_type, email, name, english_name, phone, business_name, business_reg_no,
                    store_id, retail_tier, wholesale_status, wholesale_customer_id, is_active,
                    (password_hash IS NOT NULL) AS has_password,
                    (google_id IS NOT NULL) AS is_google_linked
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
 * 구글이 발급한 ID 토큰(JWT)을 검증합니다. 서명 검증은 구글 tokeninfo 엔드포인트에 위임한다
 * (트래픽이 매우 커지면 JWKS를 받아 로컬에서 서명 검증하는 방식으로 바꾸는 게 권장되지만,
 * 이 규모의 쇼핑몰에서는 구글 공식 문서가 안내하는 tokeninfo 방식으로 충분하다).
 * @param string $id_token 프론트엔드(Google Identity Services)가 전달한 credential 값
 * @return array{sub:string, email:string, name:string}|null 검증 성공 시 구글 프로필, 실패 시 null
 */
function mall_verify_google_id_token($id_token) {
    if (!is_string($id_token) || $id_token === '') {
        return null;
    }

    $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($id_token));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $http_code !== 200) {
        return null;
    }

    $payload = json_decode($response, true);
    if (!is_array($payload) || empty($payload['sub']) || empty($payload['email'])) {
        return null;
    }
    // aud: 이 토큰이 우리 클라이언트 ID를 대상으로 발급됐는지 확인(다른 사이트용 토큰 재사용 방지).
    if (($payload['aud'] ?? '') !== MALL_GOOGLE_CLIENT_ID) {
        return null;
    }
    if (($payload['email_verified'] ?? 'false') !== 'true') {
        return null;
    }

    return [
        'sub' => $payload['sub'],
        'email' => $payload['email'],
        'name' => $payload['name'] ?? explode('@', $payload['email'])[0],
    ];
}

/**
 * 검증된 구글 프로필로 로그인을 시도합니다. google_id로 이미 연동된 계정이 있으면 그 계정으로,
 * 없지만 같은(검증된) 이메일의 기존 계정이 있으면 해당 계정에 google_id를 연동해서 로그인합니다.
 * 계정이 아예 없으면 로그인하지 않고 null을 반환합니다(호출측이 회원가입 단계로 안내해야 함).
 * @param array{sub:string, email:string, name:string} $google
 * @return array|null 로그인된 회원 정보, 계정이 없으면 null
 */
function mall_google_login($google) {
    mall_session_start();

    try {
        $conn = get_db_connection();

        $stmt = $conn->prepare('SELECT id FROM mall_members WHERE (google_id = ? OR email = ?) AND is_active = 1');
        $stmt->bind_param('ss', $google['sub'], $google['email']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            $conn->close();
            return null;
        }

        // 기존 로컬 계정에 처음으로 구글 연동하는 경우 google_id를 채워준다.
        $link_stmt = $conn->prepare('UPDATE mall_members SET google_id = ? WHERE id = ? AND google_id IS NULL');
        $link_stmt->bind_param('si', $google['sub'], $row['id']);
        $link_stmt->execute();
        $link_stmt->close();
        $conn->close();

        session_regenerate_id(true);
        $_SESSION['mall_member_id'] = $row['id'];

        return mall_current_member();
    } catch (Exception $e) {
        error_log('mall_google_login error: ' . $e->getMessage());
        return null;
    }
}

/**
 * 구글 계정으로 새 회원을 생성하고 로그인합니다. 로컬 비밀번호는 두지 않는다(password_hash NULL).
 * @param array{sub:string, email:string, name:string} $google
 * @param string $member_type retail|wholesale
 * @param string $business_name 도매 회원만 필수
 * @param string $business_reg_no 도매 회원 선택
 * @return array{success:bool, member?:array, error?:string}
 */
function mall_google_signup($google, $member_type, $business_name = '', $business_reg_no = '') {
    mall_session_start();
    $member_type = in_array($member_type, ['retail', 'wholesale'], true) ? $member_type : 'retail';

    if ($member_type === 'wholesale' && trim($business_name) === '') {
        return ['success' => false, 'error' => 'BUSINESS_NAME_REQUIRED'];
    }

    try {
        $conn = get_db_connection();

        $check = $conn->prepare('SELECT id FROM mall_members WHERE email = ? OR google_id = ?');
        $check->bind_param('ss', $google['email'], $google['sub']);
        $check->execute();
        $check->store_result();
        if ($check->num_rows > 0) {
            $check->close();
            $conn->close();
            return ['success' => false, 'error' => 'ALREADY_REGISTERED'];
        }
        $check->close();

        $wholesale_status = ($member_type === 'wholesale') ? 'pending' : null;
        $business_name_val = ($member_type === 'wholesale') ? trim($business_name) : null;
        $business_reg_no_val = ($member_type === 'wholesale') ? trim($business_reg_no) : null;

        $insert = $conn->prepare(
            'INSERT INTO mall_members
                (member_type, email, password_hash, google_id, name, business_name, business_reg_no, store_id, retail_tier, wholesale_status)
             VALUES (?, ?, NULL, ?, ?, ?, ?, ?, "general", ?)'
        );
        $store_id = MALL_STORE_ID;
        $insert->bind_param(
            'ssssssis',
            $member_type, $google['email'], $google['sub'], $google['name'],
            $business_name_val, $business_reg_no_val, $store_id, $wholesale_status
        );

        if (!$insert->execute()) {
            $insert->close();
            $conn->close();
            return ['success' => false, 'error' => 'SERVER_ERROR'];
        }
        $member_id = $conn->insert_id;
        $insert->close();
        $conn->close();

        session_regenerate_id(true);
        $_SESSION['mall_member_id'] = $member_id;

        return ['success' => true, 'member' => mall_current_member()];
    } catch (Exception $e) {
        error_log('mall_google_signup error: ' . $e->getMessage());
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
