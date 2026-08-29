<?php
/**
 * POST mall/admin/ajax/publish_home_layout.php
 * 초안(draft) 홈 레이아웃 전체를 published로 발행한다.
 * 기존 published 행을 전부 지우고, 현재 draft 상태를 그대로 복제해서 새 published로 만든다
 * (draft의 sort_order/is_active/config를 그대로 스냅샷).
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../lib/session_helper.php';
require_once __DIR__ . '/../../../lib/permission_helper.php';
require_once __DIR__ . '/../../../config/db_config.php';
require_once __DIR__ . '/../../config/mall_config.php';
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

try {
    $conn = get_db_connection();
    $store_id = MALL_STORE_ID;
    $user_id = $_SESSION['user_id'] ?? null;

    $conn->begin_transaction();

    $del_stmt = $conn->prepare("DELETE FROM mall_home_sections WHERE store_id = ? AND status = 'published'");
    $del_stmt->bind_param('i', $store_id);
    $del_stmt->execute();
    $del_stmt->close();

    // slot_key를 SELECT 목록에서 빠뜨리면 발행본이 전부 slot_key NULL로 만들어져서
    // mall_get_active_home_sections()의 "slot_key IS NOT NULL" 조건에 걸러져 고객 화면에 절대 안 보인다
    // — "홈 레이아웃에서 저장해도 적용이 안 된다" 버그의 원인이었다.
    $ins_stmt = $conn->prepare(
        "INSERT INTO mall_home_sections (store_id, section_type, slot_key, title, subtitle, config, sort_order, is_active, status, published_at, published_by)
         SELECT store_id, section_type, slot_key, title, subtitle, config, sort_order, is_active, 'published', NOW(), ?
         FROM mall_home_sections WHERE store_id = ? AND status = 'draft' AND slot_key IS NOT NULL"
    );
    $ins_stmt->bind_param('ii', $user_id, $store_id);
    $ins_stmt->execute();
    $published_count = $ins_stmt->affected_rows;
    $ins_stmt->close();

    $conn->commit();
    $conn->close();

    echo json_encode(['success' => true, 'data' => ['published_count' => $published_count]]);
} catch (Throwable $e) {
    if (isset($conn)) {
        $conn->rollback();
        $conn->close();
    }
    error_log('publish_home_layout.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
