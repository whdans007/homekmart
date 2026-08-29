<?php
/**
 * POST mall/ajax/google_auth.php
 * Google Identity Services가 발급한 ID 토큰(credential)을 받아 로그인을 시도한다.
 * 이미 연동/이메일이 일치하는 회원이 있으면 즉시 로그인, 없으면 회원가입이 필요함을 알린다
 * (구글 프로필은 세션(mall_pending_google)에 잠시 저장해 signup.php에서 이어받는다).
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/cart.php';

function json_error($code, $message, $http = 400) {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
    exit;
}

$google = mall_verify_google_id_token($_POST['credential'] ?? '');
if (!$google) {
    json_error('INVALID_TOKEN', '구글 인증에 실패했습니다. 다시 시도해주세요', 401);
}

// mall_google_login()이 성공 시 session_regenerate_id()를 호출해 게스트 토큰이 바뀌므로 미리 캡처해둔다.
$guest_token_before_login = mall_is_logged_in() ? null : mall_guest_token();
$member = mall_google_login($google);

if (!$member) {
    mall_session_start();
    $_SESSION['mall_pending_google'] = $google;
    echo json_encode(['success' => false, 'error' => ['code' => 'NEEDS_SIGNUP', 'message' => '가입이 필요합니다']]);
    exit;
}

if ($guest_token_before_login) {
    mall_cart_merge_guest_into_member($member['id'], $guest_token_before_login);
}

echo json_encode(['success' => true, 'data' => ['redirect' => mall_get_login_redirect_target()]]);
