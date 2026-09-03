<?php
/**
 * POST mall/admin/ajax/update_member_tier.php
 * Design Ref: shopping-mall.design.md §4.1 update_member_tier.php
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../lib/session_helper.php';
require_once __DIR__ . '/../../../lib/permission_helper.php';
require_once __DIR__ . '/../../../config/db_config.php';
require_once __DIR__ . '/../../lib/csrf.php';

function json_error($code, $message, $http = 400) {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
    exit;
}

if (!is_logged_in()) {
    json_error('UNAUTHORIZED', '로그인이 필요합니다', 401);
}
if (!has_permission('mall_management')) {
    json_error('UNAUTHORIZED', '권한이 없습니다', 403);
}
if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
    json_error('CSRF_INVALID', '요청이 만료되었습니다. 새로고침 후 다시 시도해주세요', 403);
}

$member_id = (int)($_POST['member_id'] ?? 0);
$retail_tier = $_POST['retail_tier'] ?? '';

if ($member_id <= 0 || !in_array($retail_tier, ['general', 'good', 'vip', 'platinum'], true)) {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}

try {
    $conn = get_db_connection();

    $stmt = $conn->prepare(
        "UPDATE mall_members SET retail_tier = ? WHERE id = ? AND member_type = 'retail'"
    );
    $stmt->bind_param('si', $retail_tier, $member_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $conn->close();

    if ($affected === 0) {
        json_error('VALIDATION_ERROR', '대상 소매 회원을 찾을 수 없습니다', 404);
    }

    echo json_encode(['success' => true, 'data' => ['member_id' => $member_id, 'retail_tier' => $retail_tier]]);
} catch (Exception $e) {
    error_log('update_member_tier.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
