<?php
/**
 * Authenticated, CSRF-protected FCM self-test for the currently logged-in member.
 * No token, credential, filesystem path, or Google response body is returned.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/push.php';

function fcm_diagnostic_error($code, $http = 400) {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => ['code' => $code]]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fcm_diagnostic_error('METHOD_NOT_ALLOWED', 405);
}
if (!mall_is_logged_in()) {
    fcm_diagnostic_error('UNAUTHORIZED', 401);
}
if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
    fcm_diagnostic_error('CSRF_INVALID', 403);
}

$member = mall_current_member();
$result = [
    'credential_file_exists' => false,
    'credential_file_readable' => false,
    'credential_json_valid' => false,
    'oauth_access_token' => false,
    'active_token_count' => 0,
    'send_success_count' => 0,
    'send_failure_count' => 0,
];

try {
    $path = defined('MALL_FCM_SERVICE_ACCOUNT_FILE') ? MALL_FCM_SERVICE_ACCOUNT_FILE : '';
    $result['credential_file_exists'] = $path !== '' && is_file($path);
    $result['credential_file_readable'] = $result['credential_file_exists'] && is_readable($path);

    $service_account = mall_fcm_load_service_account();
    $result['credential_json_valid'] = is_array($service_account);

    if ($service_account !== null) {
        $access_token = mall_fcm_get_access_token();
        $result['oauth_access_token'] = is_string($access_token) && $access_token !== '';
    } else {
        $access_token = null;
    }

    $conn = mall_get_db_connection();
    $stmt = $conn->prepare('SELECT token FROM mall_device_tokens WHERE member_id = ? AND is_active = 1');
    $member_id = (int)$member['id'];
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $tokens = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    $result['active_token_count'] = count($tokens);

    if ($access_token !== null && $service_account !== null) {
        foreach ($tokens as $row) {
            $send = mall_fcm_send(
                $access_token,
                $service_account['project_id'],
                $row['token'],
                'HOME K MART 서버 테스트',
                '운영 서버의 FCM 발송 연결이 정상입니다.',
                ['type' => 'test']
            );
            if ($send['ok']) {
                $result['send_success_count']++;
            } else {
                $result['send_failure_count']++;
            }
        }
    }

    echo json_encode(['success' => true, 'data' => $result]);
} catch (Throwable $e) {
    error_log('diagnose_fcm.php error: ' . $e->getMessage());
    fcm_diagnostic_error('SERVER_ERROR', 500);
}
