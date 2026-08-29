<?php
/**
 * POST admin/ajax_upload_product_image.php
 * 상품 상세 모달의 "사진 관리" 섹션 - mall_product_images 업로드.
 * mall/admin/ajax/save_retail_product.php의 upload_image 로직을 admin 권한으로 이식.
 * 업로드 경로/DB는 mall과 동일하게 공유하여 mall 큐레이션 등록 여부와 무관하게 재사용 가능하도록 한다.
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

$product_id = (int)($_POST['product_id'] ?? 0);
if ($product_id <= 0 || empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    json_error('VALIDATION_ERROR', '이미지 파일을 확인해주세요');
}

try {
    $conn = get_db_connection();

    $product_check = $conn->prepare('SELECT id FROM products WHERE id = ?');
    $product_check->bind_param('i', $product_id);
    $product_check->execute();
    if (!$product_check->get_result()->fetch_assoc()) {
        $product_check->close();
        $conn->close();
        json_error('VALIDATION_ERROR', '상품을 찾을 수 없습니다', 404);
    }
    $product_check->close();

    $allowed_mimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $_FILES['image']['tmp_name']);
    finfo_close($finfo);

    if (!isset($allowed_mimes[$mime])) {
        $conn->close();
        json_error('VALIDATION_ERROR', 'JPG/PNG/WEBP 이미지만 업로드할 수 있습니다');
    }
    if ($_FILES['image']['size'] > 5 * 1024 * 1024) {
        $conn->close();
        json_error('VALIDATION_ERROR', '이미지 크기는 5MB 이하여야 합니다');
    }

    $upload_dir = __DIR__ . '/../mall/uploads/products/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowed_mimes[$mime];
    $dest = $upload_dir . $filename;

    if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
        $conn->close();
        json_error('SERVER_ERROR', '파일 저장에 실패했습니다', 500);
    }

    $next_sort = $conn->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort FROM mall_product_images WHERE product_id = ?');
    $next_sort->bind_param('i', $product_id);
    $next_sort->execute();
    $sort_order = (int)($next_sort->get_result()->fetch_assoc()['next_sort'] ?? 0);
    $next_sort->close();

    $image_path = 'uploads/products/' . $filename;
    $stmt = $conn->prepare('INSERT INTO mall_product_images (product_id, image_path, sort_order) VALUES (?, ?, ?)');
    $stmt->bind_param('isi', $product_id, $image_path, $sort_order);
    $stmt->execute();
    $image_id = $stmt->insert_id;
    $stmt->close();
    $conn->close();

    echo json_encode(['success' => true, 'data' => [
        'id' => $image_id,
        'image_path' => $image_path,
        'image_url' => '/mall/' . $image_path,
        'sort_order' => $sort_order,
    ]]);
} catch (Exception $e) {
    error_log('ajax_upload_product_image.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
