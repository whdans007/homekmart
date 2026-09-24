<?php
/**
 * POST mall/admin/ajax/save_home_section.php
 * 홈 화면 고정 슬롯(배너=promo_banner / 오늘의특가=today_deals / 새상품=new_arrivals) 저장.
 * slot_key로 draft 행이 이미 있으면 갱신, 없으면 새로 만든다(슬롯당 draft 1행만 존재).
 * 배너는 이미지 업로드(멀티파트)를 포함할 수 있다.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../lib/session_helper.php';
require_once __DIR__ . '/../../../lib/permission_helper.php';
require_once __DIR__ . '/../../../config/db_config.php';
require_once __DIR__ . '/../../config/mall_config.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../lib/home_layout.php';

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

$slot_key = $_POST['slot_key'] ?? '';
if (!in_array($slot_key, MALL_HOME_SLOTS, true) && !in_array($slot_key, MALL_PROMO_PAGE_SLOTS, true)) {
    json_error('VALIDATION_ERROR', '유효하지 않은 슬롯입니다');
}
$section_type = ($slot_key === 'promo_banner') ? 'banner' : 'product_list';
$store_id = MALL_STORE_ID;

try {
    $conn = mall_get_db_connection();

    $title = trim($_POST['title'] ?? '');
    $subtitle = trim($_POST['subtitle'] ?? '');
    $is_active = ($_POST['is_active'] ?? '0') === '1' ? 1 : 0;
    $config = [];

    if ($section_type === 'banner') {
        $link_type = in_array($_POST['link_type'] ?? '', ['url', 'category', 'product', 'promo'], true) ? $_POST['link_type'] : 'url';
        $config['link_type'] = $link_type;
        $config['link_value'] = trim($_POST['link_value'] ?? '');

        // 편집 시 새 이미지를 첨부하지 않으면 기존 이미지를 그대로 유지한다.
        $existing = mall_get_home_slot($slot_key);
        $config['image_path'] = null;
        if ($existing && $existing['config']) {
            $existing_config = json_decode($existing['config'], true);
            $config['image_path'] = $existing_config['image_path'] ?? null;
        }

        if (!empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
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
            $upload_dir = __DIR__ . '/../../uploads/banners/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $filename = bin2hex(random_bytes(16)) . '.' . $allowed_mimes[$mime];
            if (!move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $filename)) {
                $conn->close();
                json_error('SERVER_ERROR', '파일 저장에 실패했습니다', 500);
            }
            $config['image_path'] = 'uploads/banners/' . $filename;
        }
    } else {
        // today_deals | new_arrivals | promo_products — 상품 목록은 이제 상품 큐레이션(products.php)의
        // "홈 노출 위치" 체크박스에서 관리한다(ajax/toggle_home_section_product.php). 여기서는 제목/노출만
        // 바꾸고, 기존 draft에 이미 들어있는 product_ids는 그대로 유지한다.
        $existing = mall_get_home_slot($slot_key);
        $existing_config = ($existing && $existing['config']) ? json_decode($existing['config'], true) : null;
        $config['product_ids'] = (is_array($existing_config) && !empty($existing_config['product_ids'])) ? $existing_config['product_ids'] : [];
        $config['fresh_product_ids'] = (is_array($existing_config) && !empty($existing_config['fresh_product_ids'])) ? $existing_config['fresh_product_ids'] : [];
    }

    $config_json = json_encode($config, JSON_UNESCAPED_UNICODE);
    $sort_order = array_search($slot_key, MALL_HOME_SLOTS, true) ?: 0;

    $existing_id_stmt = $conn->prepare("SELECT id FROM mall_home_sections WHERE store_id = ? AND slot_key = ? AND status = 'draft'");
    $existing_id_stmt->bind_param('is', $store_id, $slot_key);
    $existing_id_stmt->execute();
    $existing_row = $existing_id_stmt->get_result()->fetch_assoc();
    $existing_id_stmt->close();

    if ($existing_row) {
        $stmt = $conn->prepare('UPDATE mall_home_sections SET title = ?, subtitle = ?, config = ?, is_active = ? WHERE id = ?');
        $stmt->bind_param('sssii', $title, $subtitle, $config_json, $is_active, $existing_row['id']);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO mall_home_sections (store_id, section_type, slot_key, title, subtitle, config, sort_order, is_active, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft')"
        );
        $stmt->bind_param('isssssii', $store_id, $section_type, $slot_key, $title, $subtitle, $config_json, $sort_order, $is_active);
        $stmt->execute();
        $stmt->close();
    }

    $conn->close();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log('save_home_section.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
