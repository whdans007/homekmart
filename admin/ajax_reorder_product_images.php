<?php
/**
 * POST admin/ajax_reorder_product_images.php
 * 상품 상세 모달의 "사진 관리" 섹션 - mall_product_images 순서 변경(인접 항목과 sort_order 교환).
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

function json_error($code, $message, $http = 400) {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
    exit;
}

if (!is_logged_in()) {
    json_error('UNAUTHORIZED', '로그인이 필요합니다', 401);
}
if (!has_permission('product_management')) {
    json_error('UNAUTHORIZED', '권한이 없습니다', 403);
}

$image_id = (int)($_POST['image_id'] ?? 0);
$direction = $_POST['direction'] ?? '';

if ($image_id <= 0 || !in_array($direction, ['up', 'down'], true)) {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}

try {
    $conn = get_db_connection();

    $stmt = $conn->prepare('SELECT id, product_id, sort_order FROM mall_product_images WHERE id = ?');
    $stmt->bind_param('i', $image_id);
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
    error_log('ajax_reorder_product_images.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
