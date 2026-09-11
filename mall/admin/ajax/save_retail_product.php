<?php
/**
 * POST mall/admin/ajax/save_retail_product.php
 * Design Ref: shopping-mall.design.md §4.1, §7 이미지 업로드 보안(확장자/MIME 화이트리스트, 파일명 난수화)
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
if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
    json_error('CSRF_INVALID', '요청이 만료되었습니다. 새로고침 후 다시 시도해주세요', 403);
}

$action = $_POST['action'] ?? '';

try {
    $conn = get_db_connection();

    if ($action === 'add') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        $display_name = trim($_POST['display_name'] ?? '');
        $display_name_en = trim($_POST['display_name_en'] ?? '');
        if ($product_id <= 0) {
            json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
        }

        // 큐레이션 기준 점포: products.php에서 선택한 점포. 존재하지 않으면 기본 점포로 되돌린다.
        $store_id = (int)($_POST['store_id'] ?? MALL_STORE_ID);
        $store_check = $conn->prepare('SELECT id FROM stores WHERE id = ?');
        $store_check->bind_param('i', $store_id);
        $store_check->execute();
        if (!$store_check->get_result()->fetch_assoc()) {
            $store_id = MALL_STORE_ID;
        }
        $store_check->close();

        $check = $conn->prepare('SELECT id FROM mall_products WHERE product_id = ?');
        $check->bind_param('i', $product_id);
        $check->execute();
        if ($check->get_result()->fetch_assoc()) {
            $check->close();
            $conn->close();
            json_error('VALIDATION_ERROR', '이미 등록된 상품입니다');
        }
        $check->close();

        // 카테고리 "전체"에서는 등록할 수 없다 — 반드시 대분류를 선택한 상태여야 한다.
        $category_id = (int)($_POST['category_id'] ?? 0);
        if ($category_id <= 0) {
            $conn->close();
            json_error('VALIDATION_ERROR', '카테고리를 먼저 선택해야 상품을 추가할 수 있습니다');
        }
        $cat_check = $conn->prepare('SELECT id FROM categories WHERE id = ?');
        $cat_check->bind_param('i', $category_id);
        $cat_check->execute();
        if (!$cat_check->get_result()->fetch_assoc()) {
            $cat_check->close();
            $conn->close();
            json_error('VALIDATION_ERROR', '유효하지 않은 카테고리입니다');
        }
        $cat_check->close();

        // 큐레이션 화면에서 선택한 카테고리를 상품의 분류로 지정한다.
        $cat_update = $conn->prepare('UPDATE products SET category_id = ? WHERE id = ?');
        $cat_update->bind_param('ii', $category_id, $product_id);
        $cat_update->execute();
        $cat_update->close();

        $stmt = $conn->prepare(
            'INSERT INTO mall_products (product_id, store_id, display_name, display_name_en, is_active, display_order) VALUES (?, ?, ?, ?, 1, 0)'
        );
        $stmt->bind_param('iiss', $product_id, $store_id, $display_name, $display_name_en);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'update') {
        $mall_product_id = (int)($_POST['mall_product_id'] ?? 0);
        $display_name = trim($_POST['display_name'] ?? '');
        $display_name_en = trim($_POST['display_name_en'] ?? '');
        $display_order = (int)($_POST['display_order'] ?? 0);
        $is_active = ($_POST['is_active'] ?? '0') === '1' ? 1 : 0;
        $is_sold_out = ($_POST['is_sold_out'] ?? '0') === '1' ? 1 : 0;
        $retail_discount_allowed = ($_POST['retail_discount_allowed'] ?? '0') === '1' ? 1 : 0;
        $wholesale_discount_allowed = ($_POST['wholesale_discount_allowed'] ?? '0') === '1' ? 1 : 0;

        if ($mall_product_id <= 0) {
            json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
        }

        // 빈 값이면 NULL(오리지널 값을 그대로 사용, 기준도매가는 자동계산)로 처리한다.
        $cost_price_raw = trim($_POST['cost_price'] ?? '');
        $selling_price_raw = trim($_POST['selling_price'] ?? '');
        $wholesale_reference_price_raw = trim($_POST['wholesale_reference_price'] ?? '');
        $submitted_cost_price = $cost_price_raw === '' ? null : (float)$cost_price_raw;
        $submitted_selling_price = $selling_price_raw === '' ? null : (float)$selling_price_raw;
        $submitted_wholesale_reference_price = $wholesale_reference_price_raw === '' ? null : (float)$wholesale_reference_price_raw;
        if (($submitted_cost_price !== null && $submitted_cost_price < 0)
            || ($submitted_selling_price !== null && $submitted_selling_price < 0)
            || ($submitted_wholesale_reference_price !== null && $submitted_wholesale_reference_price < 0)) {
            json_error('VALIDATION_ERROR', '가격은 0 이상이어야 합니다');
        }

        $lookup = $conn->prepare('SELECT product_id, store_id FROM mall_products WHERE id = ?');
        $lookup->bind_param('i', $mall_product_id);
        $lookup->execute();
        $mp_row = $lookup->get_result()->fetch_assoc();
        $lookup->close();
        if (!$mp_row) {
            $conn->close();
            json_error('VALIDATION_ERROR', '큐레이션 상품을 찾을 수 없습니다');
        }
        $product_id = (int)$mp_row['product_id'];
        $store_id = (int)$mp_row['store_id'];

        // 매장 재고(inventory)의 실제 원가/판매가는 건드리지 않고, 오리지널 값과 비교해
        // 값이 다를 때만 mall_products에 override로 저장한다(같으면 override 해제 = NULL).
        $inv_lookup = $conn->prepare('SELECT cost_price, selling_price FROM inventory WHERE product_id = ? AND store_id = ?');
        $inv_lookup->bind_param('ii', $product_id, $store_id);
        $inv_lookup->execute();
        $inv_row = $inv_lookup->get_result()->fetch_assoc();
        $inv_lookup->close();
        $original_cost_price = ($inv_row && $inv_row['cost_price'] !== null) ? (float)$inv_row['cost_price'] : null;
        $original_selling_price = ($inv_row && $inv_row['selling_price'] !== null) ? (float)$inv_row['selling_price'] : null;

        $markup_lookup = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'mall_wholesale_reference_markup_rate'");
        $markup_lookup->execute();
        $markup_row = $markup_lookup->get_result()->fetch_assoc();
        $markup_lookup->close();
        $markup_rate = $markup_row ? (float)$markup_row['setting_value'] : 15.0;
        // 기준도매가는 소숫점 이하를 항상 올림 처리한다.
        $original_wholesale_reference_price = $original_cost_price !== null ? ceil($original_cost_price * (1 + $markup_rate / 100)) : null;

        $values_equal = function ($a, $b) {
            if ($a === null || $b === null) {
                return $a === $b;
            }
            return abs($a - $b) < 0.005;
        };

        $cost_price_override = $values_equal($submitted_cost_price, $original_cost_price) ? null : $submitted_cost_price;
        $selling_price_override = $values_equal($submitted_selling_price, $original_selling_price) ? null : $submitted_selling_price;
        $wholesale_reference_price = $values_equal($submitted_wholesale_reference_price, $original_wholesale_reference_price) ? null : $submitted_wholesale_reference_price;

        $stmt = $conn->prepare(
            'UPDATE mall_products
             SET display_name = ?, display_name_en = ?,
                 cost_price_override = ?, selling_price_override = ?, wholesale_reference_price = ?,
                 display_order = ?, is_active = ?, is_sold_out = ?, retail_discount_allowed = ?, wholesale_discount_allowed = ?
             WHERE id = ?'
        );
        $stmt->bind_param(
            'ssdddiiiiii',
            $display_name, $display_name_en,
            $cost_price_override, $selling_price_override, $wholesale_reference_price,
            $display_order, $is_active, $is_sold_out, $retail_discount_allowed, $wholesale_discount_allowed, $mall_product_id
        );
        $stmt->execute();
        $stmt->close();
        $conn->close();
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete') {
        $mall_product_id = (int)($_POST['mall_product_id'] ?? 0);
        if ($mall_product_id <= 0) {
            json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
        }

        $stmt = $conn->prepare('DELETE FROM mall_products WHERE id = ?');
        $stmt->bind_param('i', $mall_product_id);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'move_category') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        $category_id = (int)($_POST['category_id'] ?? 0);
        if ($product_id <= 0 || $category_id <= 0) {
            json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
        }

        $cat_check = $conn->prepare('SELECT id FROM categories WHERE id = ?');
        $cat_check->bind_param('i', $category_id);
        $cat_check->execute();
        if (!$cat_check->get_result()->fetch_assoc()) {
            $cat_check->close();
            $conn->close();
            json_error('VALIDATION_ERROR', '유효하지 않은 카테고리입니다');
        }
        $cat_check->close();

        // 검색 화면의 "이동등록" 버튼 전용 — 이미 몰에 큐레이션된(mall_products) 상품만 이동 대상
        $mp_check = $conn->prepare('SELECT id FROM mall_products WHERE product_id = ?');
        $mp_check->bind_param('i', $product_id);
        $mp_check->execute();
        if (!$mp_check->get_result()->fetch_assoc()) {
            $mp_check->close();
            $conn->close();
            json_error('VALIDATION_ERROR', '아직 쇼핑몰에 등록되지 않은 상품입니다');
        }
        $mp_check->close();

        $update = $conn->prepare('UPDATE products SET category_id = ? WHERE id = ?');
        $update->bind_param('ii', $category_id, $product_id);
        $update->execute();
        $update->close();
        $conn->close();
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'upload_image') {
        $product_id = (int)($_POST['product_id'] ?? 0);

        if ($product_id <= 0 || empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            json_error('VALIDATION_ERROR', '이미지 파일을 확인해주세요');
        }

        $allowed_mimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'avif'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['image']['tmp_name']);
        finfo_close($finfo);

        if (!isset($allowed_mimes[$mime])) {
            json_error('VALIDATION_ERROR', 'JPG/PNG/WEBP/AVIF 이미지만 업로드할 수 있습니다');
        }
        if ($_FILES['image']['size'] > 5 * 1024 * 1024) {
            json_error('VALIDATION_ERROR', '이미지 크기는 5MB 이하여야 합니다');
        }

        $upload_dir = __DIR__ . '/../../uploads/products/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $allowed_mimes[$mime];
        $dest = $upload_dir . $filename;

        if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
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
        $stmt->close();
        $conn->close();

        echo json_encode(['success' => true, 'data' => ['image_path' => $image_path]]);
        exit;
    }

    $conn->close();
    json_error('VALIDATION_ERROR', '알 수 없는 작업입니다');
} catch (Exception $e) {
    error_log('save_retail_product.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
