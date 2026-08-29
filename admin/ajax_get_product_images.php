<?php
/**
 * GET admin/ajax_get_product_images.php
 * 상품 상세 모달의 "사진 관리" 섹션 - mall_product_images 목록 조회.
 * mall 큐레이션 여부와 무관하게 product_id 기준으로 조회한다.
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

$product_id = (int)($_GET['product_id'] ?? 0);
if ($product_id <= 0) {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}

try {
    $conn = get_db_connection();

    $stmt = $conn->prepare('SELECT id, image_path, sort_order FROM mall_product_images WHERE product_id = ? ORDER BY sort_order');
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();

    foreach ($rows as &$row) {
        $row['image_url'] = '/mall/' . $row['image_path'];
    }

    echo json_encode(['success' => true, 'data' => $rows]);
} catch (Exception $e) {
    error_log('ajax_get_product_images.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
