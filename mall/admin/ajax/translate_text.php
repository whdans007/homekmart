<?php
/**
 * POST mall/admin/ajax/translate_text.php
 * 카테고리명을 한국어에서 영어로 번역한다.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../lib/session_helper.php';
require_once __DIR__ . '/../../../lib/permission_helper.php';
require_once __DIR__ . '/../../lib/csrf.php';

function json_error($code, $message, $http = 400) {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
    exit;
}

if (!is_logged_in()) {
    json_error('UNAUTHORIZED', '로그인이 필요합니다', 401);
}
if (!has_mall_permission('category_management')) {
    json_error('UNAUTHORIZED', '카테고리 관리 권한이 없습니다', 403);
}
if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
    json_error('CSRF_INVALID', '요청이 만료되었습니다. 새로고침 후 다시 시도해주세요', 403);
}

$text = trim($_POST['text'] ?? '');
if ($text === '' || mb_strlen($text, 'UTF-8') > 100) {
    json_error('VALIDATION_ERROR', '번역할 카테고리명을 확인해주세요');
}

$url = 'https://clients5.google.com/translate_a/t?client=dict-chrome-ex&sl=ko&tl=en&q=' . rawurlencode($text);
$curl = curl_init($url);
if ($curl === false) {
    json_error('TRANSLATE_FAILED', '번역에 실패했습니다', 502);
}

curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_USERAGENT => 'Mozilla/5.0',
]);

$body = curl_exec($curl);
$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
$curl_error = curl_errno($curl);
curl_close($curl);

if ($body === false || $curl_error !== 0 || $status < 200 || $status >= 300) {
    json_error('TRANSLATE_FAILED', '번역에 실패했습니다', 502);
}

$translated = json_decode($body, true);
$result = is_array($translated) ? ($translated[0] ?? null) : null;
if (!is_string($result) || trim($result) === '') {
    json_error('TRANSLATE_FAILED', '번역에 실패했습니다', 502);
}

echo json_encode(['success' => true, 'data' => ['translated' => $result]]);
