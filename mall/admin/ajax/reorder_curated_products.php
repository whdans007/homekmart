<?php
/**
 * POST mall/admin/ajax/reorder_curated_products.php
 * products.php 큐레이션된 상품 목록의 드래그앤드롭 순서 변경을 저장한다.
 * order = "12,7,9,3" 형태로 원하는 순서의 mall_products.id를 전달하면 display_order를 일괄 갱신한다.
 * offset(선택) — 목록이 페이지네이션되어 있을 때, 지금 보이는 페이지가 전체 중 몇 번째부터 시작하는지.
 * 안 주면 0(페이지네이션 없는 홈 노출 목록 등). 이게 없으면 2페이지를 드래그해도 항상 0부터 번호가 매겨져
 * 1페이지 순서와 충돌한다.
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
if (!has_mall_permission('mall_management')) {
    json_error('UNAUTHORIZED', '권한이 없습니다', 403);
}
if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
    json_error('CSRF_INVALID', '요청이 만료되었습니다. 새로고침 후 다시 시도해주세요', 403);
}

$ids = array_filter(array_map('intval', explode(',', $_POST['order'] ?? '')));
$offset = max(0, (int)($_POST['offset'] ?? 0));
if (empty($ids)) {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}

try {
    $conn = get_db_connection();

    $conn->begin_transaction();
    $stmt = $conn->prepare('UPDATE mall_products SET display_order = ? WHERE id = ?');
    foreach (array_values($ids) as $index => $mall_product_id) {
        $display_order = $offset + $index;
        $stmt->bind_param('ii', $display_order, $mall_product_id);
        $stmt->execute();
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
    error_log('reorder_curated_products.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
