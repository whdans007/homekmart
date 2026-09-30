<?php
// 주문 화면에서 이미지가 없는 상품에 대표 이미지를 업로드한다 (AJAX, JSON 응답).
// 이미 이미지가 있는 상품은 다른 점포 화면에도 영향을 주므로 덮어쓰지 않는다.
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/../logistics/lib/image_helper.php';
header('Content-Type: application/json; charset=utf-8');

store_require_store_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}
store_verify_csrf();

$product_id = (int)($_POST['product_id'] ?? 0);
if ($product_id <= 0 || empty($_FILES['image'])) {
    echo json_encode(['success' => false, 'message' => 'Select an image to upload.']);
    exit;
}

try {
    $conn = get_store_db();

    $has_col = $conn->query("SHOW COLUMNS FROM lc_products LIKE 'image_path'");
    if (!$has_col || $has_col->num_rows === 0) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Image feature is not available yet.']);
        exit;
    }

    $st = $conn->prepare("SELECT image_path FROM lc_products WHERE id = ? AND is_active = 1");
    $st->bind_param('i', $product_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'Product not found.']);
        exit;
    }
    if (!empty($row['image_path'])) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => 'This product already has an image.']);
        exit;
    }

    $img_err  = '';
    $rel_path = lc_handle_product_image_upload($_FILES['image'], $img_err);
    if ($rel_path === null) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => $img_err ?: 'Image upload failed.']);
        exit;
    }

    // 동시 업로드 경합 방지: 여전히 비어 있을 때만 저장
    $st = $conn->prepare("UPDATE lc_products SET image_path = ? WHERE id = ? AND (image_path IS NULL OR image_path = '')");
    $st->bind_param('si', $rel_path, $product_id);
    $st->execute();
    $updated = $st->affected_rows > 0;
    $st->close();
    $conn->close();

    if (!$updated) {
        lc_delete_product_image($rel_path);
        echo json_encode(['success' => false, 'message' => 'This product already has an image.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'url'     => STORE_WEB_ROOT . '/logistics/' . $rel_path,
    ]);
} catch (Throwable $e) {
    error_log('store/upload_product_image.php failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Upload failed. Please try again.']);
}
