<?php
/**
 * POST admin/ajax_delete_product_image.php
 * 상품 상세 모달의 "사진 관리" 섹션 - mall_product_images 삭제.
 * mall 큐레이션 등록 여부와 무관하게 image_id만으로 대상을 찾는다(admin/product_management.php 범위).
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
if ($image_id <= 0) {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}

try {
    $conn = get_db_connection();

    $stmt = $conn->prepare('SELECT id, image_path FROM mall_product_images WHERE id = ?');
    $stmt->bind_param('i', $image_id);
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

    $file_path = __DIR__ . '/../mall/' . $image['image_path'];
    if (is_file($file_path)) {
        unlink($file_path);
    }

    echo json_encode(['success' => true, 'data' => ['image_id' => $image_id]]);
} catch (Exception $e) {
    error_log('ajax_delete_product_image.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
