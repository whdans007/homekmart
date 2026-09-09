<?php
/**
 * POST mall/ajax/register_device_token.php
 * Design Ref: mall-order-chat-push.design.md §5, §6.2 — 고객 앱(Capacitor)이 발급받은 FCM
 * registration token을 등록/갱신한다. 같은 물리 기기가 다른 회원으로 재로그인해 다시 등록하면
 * token_hash UNIQUE 키 기준 ON DUPLICATE KEY UPDATE로 최신 로그인 회원에게 자동 재귀속된다
 * (§4.1 — 별도의 "로그아웃 시 토큰 해제" API 없이 기기 공유 시나리오를 자연스럽게 처리).
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../../config/db_config.php';

function json_error($code, $message, $http = 400) {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
    exit;
}

if (!mall_is_logged_in()) {
    json_error('UNAUTHORIZED', '로그인이 필요합니다', 401);
}
if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
    json_error('CSRF_INVALID', '요청이 만료되었습니다. 새로고침 후 다시 시도해주세요', 403);
}

$member = mall_current_member();
$token = trim($_POST['token'] ?? '');
$platform = trim($_POST['platform'] ?? 'android');
$app_version = trim($_POST['app_version'] ?? '');

if ($token === '' || mb_strlen($token) > 4096) {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}
if ($platform !== 'android') {
    // v1은 Android만 지원(mall-order-chat-push.design.md §0 SCOPE)
    json_error('VALIDATION_ERROR', '지원하지 않는 플랫폼입니다');
}
if ($app_version !== '' && mb_strlen($app_version) > 20) {
    $app_version = mb_substr($app_version, 0, 20);
}

try {
    $conn = get_db_connection();
    $token_hash = hash('sha256', $token);

    $stmt = $conn->prepare(
        'INSERT INTO mall_device_tokens (member_id, platform, token_hash, token, app_version, is_active, last_registered_at)
         VALUES (?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE
            member_id = VALUES(member_id),
            token = VALUES(token),
            app_version = VALUES(app_version),
            is_active = 1,
            last_registered_at = CURRENT_TIMESTAMP'
    );
    $app_version_val = $app_version !== '' ? $app_version : null;
    $stmt->bind_param('issss', $member['id'], $platform, $token_hash, $token, $app_version_val);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('register_device_token.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
