<?php
/**
 * POST mall/admin/ajax/delete_product_image.php
 * Design Ref: shopping-mall.design.md §11.1 (신규), §5.4 상품 큐레이션 이미지 관리
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

$image_id = (int)($_POST['image_id'] ?? 0);
if ($image_id <= 0) {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}

try {
    $conn = get_db_connection();

    // 이 몰(store_id)에 큐레이션된 상품의 이미지만 삭제 가능하도록 범위 제한
    $stmt = $conn->prepare(
        'SELECT mpi.id, mpi.image_path
         FROM mall_product_images mpi
         INNER JOIN mall_products mp ON mp.product_id = mpi.product_id
         WHERE mpi.id = ? AND mp.store_id = ?'
    );
    $store_id = MALL_STORE_ID;
    $stmt->bind_param('ii', $image_id, $store_id);
    $stmt->execute();
    $image = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$image) {
        $conn->close();
        json_error('VALIDATION_ERROR', '대상 이미지를 찾을 수 없습니다', 404);
    }

    $delete = $conn->prepare('DELETE FROM mall_product_images WHERE id = ?');
    $delete->bind_param('i', $image_id);
    $delete->execute();
    $delete->close();
    $conn->close();

    $file_path = __DIR__ . '/../../' . $image['image_path'];
    if (is_file($file_path)) {
        unlink($file_path);
    }

    echo json_encode(['success' => true, 'data' => ['image_id' => $image_id]]);
} catch (Exception $e) {
    error_log('delete_product_image.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
