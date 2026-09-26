<?php
/**
 * POST mall/admin/ajax/save_reference_store.php
 * Design Ref: docs/02-design/features/unified-inventory-reference-store.design.md §2.1, §6.1
 *
 * 쇼핑몰 가격/재고/품절 자동화의 기준 점포(Reference Store)를 영구 저장한다.
 * 저장은 mall_reference_store_history에 이력을 남기고 system_settings 캐시값을 갱신하는
 * 단일 트랜잭션으로 처리된다(lib/reference_store_service.php::reference_store_set()).
 *
 * 주의: 이 엔드포인트는 "기준 점포 설정값"만 바꾼다. 과거 거래의 store_id는 절대 바꾸지 않으며,
 * 새 점포의 시작 재고는 별도 실사 조정으로 입력해야 한다(Plan §4.1).
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../lib/session_helper.php';
require_once __DIR__ . '/../../../lib/permission_helper.php';
require_once __DIR__ . '/../../../config/db_config.php';
require_once __DIR__ . '/../../config/mall_config.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../../lib/reference_store_service.php';

function json_error($code, $message, $http = 400)
{
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('METHOD_NOT_ALLOWED', 'POST 요청만 허용됩니다', 405);
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

$store_id = (int)($_POST['store_id'] ?? 0);
if ($store_id <= 0) {
    json_error('VALIDATION_ERROR', '점포를 선택해주세요');
}

try {
    $conn = mall_get_db_connection();
    $user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

    $result = reference_store_set($conn, $store_id, $user_id);

    if (!$result['success']) {
        json_error('VALIDATION_ERROR', $result['error'] ?? '저장에 실패했습니다', 400);
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'store_id' => $store_id,
            'changed' => $result['changed'],
            'effective_from' => $result['effective_from'],
        ],
    ]);
} catch (Throwable $e) {
    error_log('save_reference_store.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
