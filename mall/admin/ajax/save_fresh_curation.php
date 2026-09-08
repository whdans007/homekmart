<?php
/**
 * POST mall/admin/ajax/save_fresh_curation.php
 * products.php의 "신선상품" 탭 전용 — mall_fresh_products.category_id에 몰 카테고리를 배정/해제한다.
 * mall_products와 달리 신선상품은 점포별 큐레이션 행이 없어 등록/이동이 동일한 UPDATE 한 번으로 처리된다.
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

    if ($action === 'assign_category') {
        $mall_fresh_product_id = (int)($_POST['mall_fresh_product_id'] ?? 0);
        $category_id = (int)($_POST['category_id'] ?? 0);
        if ($mall_fresh_product_id <= 0 || $category_id <= 0) {
            json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
        }

        $fp_check = $conn->prepare('SELECT id FROM mall_fresh_products WHERE id = ?');
        $fp_check->bind_param('i', $mall_fresh_product_id);
        $fp_check->execute();
        if (!$fp_check->get_result()->fetch_assoc()) {
            $fp_check->close();
            $conn->close();
            json_error('VALIDATION_ERROR', '신선상품을 찾을 수 없습니다');
        }
        $fp_check->close();

        $cat_check = $conn->prepare('SELECT id FROM categories WHERE id = ?');
        $cat_check->bind_param('i', $category_id);
        $cat_check->execute();
        if (!$cat_check->get_result()->fetch_assoc()) {
            $cat_check->close();
            $conn->close();
            json_error('VALIDATION_ERROR', '유효하지 않은 카테고리입니다');
        }
        $cat_check->close();

        $stmt = $conn->prepare('UPDATE mall_fresh_products SET category_id = ? WHERE id = ?');
        $stmt->bind_param('ii', $category_id, $mall_fresh_product_id);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'update') {
        // "큐레이션된 상품" 통합 테이블의 신선상품 행. 일반상품(save_retail_product.php의 update)과 최대한
        // 동일한 구조로 맞춘다: 표시명/판매단가/노출상태는 mall_fresh_products 원본에 직접 반영하고(점포별
        // 오버라이드 개념이 없음), 원가/기준도매가만 "오리지널(최근 매입원가)"과 다를 때만 override 컬럼에 저장한다.
        $mall_fresh_product_id = (int)($_POST['mall_fresh_product_id'] ?? 0);
        $name_ko = trim($_POST['name_ko'] ?? '');
        $name_en = trim($_POST['name_en'] ?? '');
        $price_per_100g_raw = trim($_POST['price_per_100g'] ?? '');
        $is_active = ($_POST['is_active'] ?? '0') === '1';
        $is_sold_out = ($_POST['is_sold_out'] ?? '0') === '1';
        $retail_discount_allowed = ($_POST['retail_discount_allowed'] ?? '0') === '1';
        $wholesale_discount_allowed = ($_POST['wholesale_discount_allowed'] ?? '0') === '1';

        if ($mall_fresh_product_id <= 0 || $name_ko === '') {
            json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
        }
        if ($price_per_100g_raw === '' || !is_numeric($price_per_100g_raw) || (float)$price_per_100g_raw < 0) {
            json_error('VALIDATION_ERROR', '판매단가는 0 이상의 숫자여야 합니다');
        }
        $price_per_100g = (float)$price_per_100g_raw;
        $status = $is_active ? 'active' : 'inactive';

        $cost_price_raw = trim($_POST['cost_price'] ?? '');
        $wholesale_reference_price_raw = trim($_POST['wholesale_reference_price'] ?? '');
        $submitted_cost_price = $cost_price_raw === '' ? null : (float)$cost_price_raw;
        $submitted_wholesale_reference_price = $wholesale_reference_price_raw === '' ? null : (float)$wholesale_reference_price_raw;
        if (($submitted_cost_price !== null && $submitted_cost_price < 0)
            || ($submitted_wholesale_reference_price !== null && $submitted_wholesale_reference_price < 0)) {
            json_error('VALIDATION_ERROR', '가격은 0 이상이어야 합니다');
        }

        $lookup = $conn->prepare('SELECT sale_type FROM mall_fresh_products WHERE id = ?');
        $lookup->bind_param('i', $mall_fresh_product_id);
        $lookup->execute();
        $fp_row = $lookup->get_result()->fetch_assoc();
        $lookup->close();
        if (!$fp_row) {
            $conn->close();
            json_error('VALIDATION_ERROR', '신선상품을 찾을 수 없습니다');
        }
        $sale_type = $fp_row['sale_type'];

        // "오리지널" 원가 = 이 신선상품의 최근 매입 단위원가(무게 상품은 100g당, 낱개 상품은 개당).
        $cost_col = $sale_type === 'piece' ? 'unit_cost_per_piece' : 'unit_cost_per_100g';
        $cost_lookup = $conn->prepare(
            "SELECT {$cost_col} AS latest_cost FROM fresh_purchase_items
             WHERE mall_fresh_product_id = ? ORDER BY purchase_date DESC, id DESC LIMIT 1"
        );
        $cost_lookup->bind_param('i', $mall_fresh_product_id);
        $cost_lookup->execute();
        $cost_row = $cost_lookup->get_result()->fetch_assoc();
        $cost_lookup->close();
        $original_cost_price = ($cost_row && $cost_row['latest_cost'] !== null) ? (float)$cost_row['latest_cost'] : null;

        $markup_lookup = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'mall_wholesale_reference_markup_rate'");
        $markup_lookup->execute();
        $markup_row = $markup_lookup->get_result()->fetch_assoc();
        $markup_lookup->close();
        $markup_rate = $markup_row ? (float)$markup_row['setting_value'] : 15.0;
        $original_wholesale_reference_price = $original_cost_price !== null ? ceil($original_cost_price * (1 + $markup_rate / 100)) : null;

        $values_equal = function ($a, $b) {
            if ($a === null || $b === null) {
                return $a === $b;
            }
            return abs($a - $b) < 0.005;
        };
        $cost_price_override = $values_equal($submitted_cost_price, $original_cost_price) ? null : $submitted_cost_price;
        $wholesale_reference_price_override = $values_equal($submitted_wholesale_reference_price, $original_wholesale_reference_price) ? null : $submitted_wholesale_reference_price;

        $stmt = $conn->prepare(
            'UPDATE mall_fresh_products
             SET name_ko = ?, name_en = ?, price_per_100g = ?, status = ?,
                 cost_price_override = ?, wholesale_reference_price_override = ?,
                 is_sold_out = ?, retail_discount_allowed = ?, wholesale_discount_allowed = ?
             WHERE id = ?'
        );
        $is_sold_out_int = $is_sold_out ? 1 : 0;
        $retail_discount_allowed_int = $retail_discount_allowed ? 1 : 0;
        $wholesale_discount_allowed_int = $wholesale_discount_allowed ? 1 : 0;
        $stmt->bind_param(
            'ssdsddiiii',
            $name_ko, $name_en, $price_per_100g, $status,
            $cost_price_override, $wholesale_reference_price_override,
            $is_sold_out_int, $retail_discount_allowed_int, $wholesale_discount_allowed_int, $mall_fresh_product_id
        );
        $stmt->execute();
        $stmt->close();
        $conn->close();
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'reorder') {
        // products.php 통합 테이블의 드래그앤드롭 순서 변경 — 신선상품 행끼리만 재정렬한다(페이지네이션 없음).
        $ids = array_filter(array_map('intval', explode(',', $_POST['order'] ?? '')));
        if (empty($ids)) {
            json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
        }
        $conn->begin_transaction();
        $stmt = $conn->prepare('UPDATE mall_fresh_products SET display_order = ? WHERE id = ?');
        foreach (array_values($ids) as $index => $fresh_id) {
            $stmt->bind_param('ii', $index, $fresh_id);
            $stmt->execute();
        }
        $stmt->close();
        $conn->commit();
        $conn->close();
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'upload_image') {
        // 신선상품은 mall_product_images 같은 다중 이미지 테이블이 없어 mall_fresh_products.image_url
        // 단일 컬럼에 저장한다(새로 올리면 기존 이미지를 교체).
        $mall_fresh_product_id = (int)($_POST['mall_fresh_product_id'] ?? 0);
        if ($mall_fresh_product_id <= 0 || empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            json_error('VALIDATION_ERROR', '이미지 파일을 확인해주세요');
        }

        $allowed_mimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['image']['tmp_name']);
        finfo_close($finfo);

        if (!isset($allowed_mimes[$mime])) {
            json_error('VALIDATION_ERROR', 'JPG/PNG/WEBP 이미지만 업로드할 수 있습니다');
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

        $image_url = '/mall/uploads/products/' . $filename;
        $stmt = $conn->prepare('UPDATE mall_fresh_products SET image_url = ? WHERE id = ?');
        $stmt->bind_param('si', $image_url, $mall_fresh_product_id);
        $stmt->execute();
        $stmt->close();
        $conn->close();

        echo json_encode(['success' => true, 'data' => ['image_url' => $image_url]]);
        exit;
    }

    if ($action === 'unassign_category') {
        $mall_fresh_product_id = (int)($_POST['mall_fresh_product_id'] ?? 0);
        if ($mall_fresh_product_id <= 0) {
            json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
        }

        $stmt = $conn->prepare('UPDATE mall_fresh_products SET category_id = NULL WHERE id = ?');
        $stmt->bind_param('i', $mall_fresh_product_id);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        echo json_encode(['success' => true]);
        exit;
    }

    $conn->close();
    json_error('VALIDATION_ERROR', '알 수 없는 작업입니다');
} catch (Exception $e) {
    error_log('save_fresh_curation.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
