<?php
/**
 * POST mall/admin/ajax/reorder_categories.php
 * products.php 카테고리 관리 모달의 드래그앤드롭 순서 변경을 저장한다.
 * order = "12,7,9,3" 형태로 원하는 순서의 카테고리 id를 전달하면 sort_order를 일괄 갱신한다.
 * parent_id가 없으면 대분류(parent_id IS NULL) 순서 변경, 있으면 그 대분류의 소분류 순서 변경.
 * categories 테이블은 몰 전용이 아니라 전체 시스템이 공유하므로 category_management 권한을 추가로 확인한다.
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
if (!has_permission('category_management')) {
    json_error('UNAUTHORIZED', '카테고리 관리 권한이 없습니다', 403);
}
if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
    json_error('CSRF_INVALID', '요청이 만료되었습니다. 새로고침 후 다시 시도해주세요', 403);
}

$ids = array_filter(array_map('intval', explode(',', $_POST['order'] ?? '')));
$parent_id = isset($_POST['parent_id']) && $_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : null;
if (empty($ids)) {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}

try {
    $conn = get_db_connection();

    $conn->begin_transaction();
    if ($parent_id) {
        // 소분류 순서 변경 — 해당 대분류의 하위인 것만 반영
        $stmt = $conn->prepare("UPDATE categories SET sort_order = ? WHERE id = ? AND parent_id = ?");
        foreach (array_values($ids) as $index => $category_id) {
            $stmt->bind_param('iii', $index, $category_id, $parent_id);
            $stmt->execute();
        }
    } else {
        // 대분류(parent_id IS NULL) 순서 변경 — 넘어온 id 중 실제 대분류인 것만 반영
        $stmt = $conn->prepare("UPDATE categories SET sort_order = ? WHERE id = ? AND parent_id IS NULL");
        foreach (array_values($ids) as $index => $category_id) {
            $stmt->bind_param('ii', $index, $category_id);
            $stmt->execute();
        }
    }
    $stmt->close();
    $conn->commit();
    $conn->close();

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if (isset($conn)) {
        $conn->rollback();
        $conn->close();
    }
    error_log('reorder_categories.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
