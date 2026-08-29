<?php
/**
 * POST mall/admin/ajax/toggle_wholesale_visibility.php
 * Design Ref: shopping-mall.design.md §4.1
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

$wholesale_product_id = (int)($_POST['wholesale_product_id'] ?? 0);
$is_visible = ($_POST['is_visible'] ?? '0') === '1' ? 1 : 0;

if ($wholesale_product_id <= 0) {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}

try {
    $conn = get_db_connection();

    $stmt = $conn->prepare(
        'INSERT INTO mall_wholesale_visibility (wholesale_product_id, is_visible) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE is_visible = VALUES(is_visible)'
    );
    $stmt->bind_param('ii', $wholesale_product_id, $is_visible);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    echo json_encode(['success' => true, 'data' => ['wholesale_product_id' => $wholesale_product_id, 'is_visible' => (bool)$is_visible]]);
} catch (Exception $e) {
    error_log('toggle_wholesale_visibility.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
