<?php
/**
 * POST mall/admin/ajax/reorder_product_images.php
 * Design Ref: shopping-mall.design.md §11.1 (신규), §5.4 상품 큐레이션 이미지 관리
 * 대상 이미지와 인접(direction 방향) 이미지의 sort_order를 서로 맞바꾼다.
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
$direction = $_POST['direction'] ?? '';

if ($image_id <= 0 || !in_array($direction, ['up', 'down'], true)) {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}

try {
    $conn = get_db_connection();
    $store_id = MALL_STORE_ID;

    $stmt = $conn->prepare(
        'SELECT mpi.id, mpi.product_id, mpi.sort_order
         FROM mall_product_images mpi
         INNER JOIN mall_products mp ON mp.product_id = mpi.product_id
         WHERE mpi.id = ? AND mp.store_id = ?'
    );
    $stmt->bind_param('ii', $image_id, $store_id);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$current) {
        $conn->close();
        json_error('VALIDATION_ERROR', '대상 이미지를 찾을 수 없습니다', 404);
    }

    $order_dir = ($direction === 'up') ? 'DESC' : 'ASC';
    $compare_op = ($direction === 'up') ? '<' : '>';

    $neighbor_stmt = $conn->prepare(
        "SELECT id, sort_order FROM mall_product_images
         WHERE product_id = ? AND sort_order {$compare_op} ?
         ORDER BY sort_order {$order_dir} LIMIT 1"
    );
    $neighbor_stmt->bind_param('ii', $current['product_id'], $current['sort_order']);
    $neighbor_stmt->execute();
    $neighbor = $neighbor_stmt->get_result()->fetch_assoc();
    $neighbor_stmt->close();

    if (!$neighbor) {
        $conn->close();
        echo json_encode(['success' => true, 'data' => ['moved' => false]]);
        exit;
    }

    $conn->begin_transaction();
    $update = $conn->prepare('UPDATE mall_product_images SET sort_order = ? WHERE id = ?');
    $update->bind_param('ii', $neighbor['sort_order'], $current['id']);
    $update->execute();
    $update->bind_param('ii', $current['sort_order'], $neighbor['id']);
    $update->execute();
    $update->close();
    $conn->commit();
    $conn->close();

    echo json_encode(['success' => true, 'data' => ['moved' => true]]);
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
        $conn->close();
    }
    error_log('reorder_product_images.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
