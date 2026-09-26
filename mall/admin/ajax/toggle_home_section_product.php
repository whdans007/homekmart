<?php
/**
 * POST mall/admin/ajax/toggle_home_section_product.php
 * 상품 큐레이션(products.php)에서 상품별로 "오늘의특가/기획전/새상품" 노출 여부를 바로 토글한다.
 * mall_home_sections의 해당 슬롯 draft 행 config.product_ids 배열에 추가/제거만 한다
 * (제목/노출 설정은 건드리지 않음 — 그건 홈 레이아웃 화면에서 관리). 발행은 여전히
 * 홈 레이아웃 화면의 "적용" 버튼을 눌러야 고객 화면에 반영된다.
 *
 * 진열에 추가(active=1)하는 상품이 실제 판매 기준 점포(MALL_STORE_ID)에 아직 큐레이션(mall_products)되어
 * 있지 않으면 여기서 같이 큐레이션해준다 — 그래야 카테고리 화면과 똑같이 가격/노출/할인을 바로 편집할 수 있다
 * (그냥 진열에만 넣고 큐레이션은 안 하면 상품 큐레이션 화면에서 "미큐레이션" 상태로만 남아 편집이 안 됨).
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
if (!has_mall_permission('mall_management')) {
    json_error('UNAUTHORIZED', '권한이 없습니다', 403);
}
if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
    json_error('CSRF_INVALID', '요청이 만료되었습니다. 새로고침 후 다시 시도해주세요', 403);
}

// 배너(promo_banner)는 상품 목록이 아니라 여기서 다루지 않는다.
$toggleable_slots = ['today_deals', 'new_arrivals', 'promo_products'];
$slot_key = $_POST['slot_key'] ?? '';
if (!in_array($slot_key, $toggleable_slots, true)) {
    json_error('VALIDATION_ERROR', '유효하지 않은 슬롯입니다');
}
$product_type = ($_POST['product_type'] ?? 'general') === 'fresh' ? 'fresh' : 'general';
$product_id = (int)($_POST['product_id'] ?? 0);
$fresh_product_id = (int)($_POST['fresh_product_id'] ?? 0);
$active = ($_POST['active'] ?? '0') === '1';
if (($product_type === 'general' && $product_id <= 0) || ($product_type === 'fresh' && $fresh_product_id <= 0)) {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}

$default_titles = [
    'today_deals' => '오늘의 특가',
    'new_arrivals' => '새로 들어온 한국 상품',
    'promo_products' => '이번 주 기획전',
];

try {
    $conn = mall_get_db_connection();
    $store_id = MALL_STORE_ID;

    if ($active && $product_type === 'general') {
        $curated_check = $conn->prepare('SELECT id FROM mall_products WHERE product_id = ? AND store_id = ?');
        $curated_check->bind_param('ii', $product_id, $store_id);
        $curated_check->execute();
        $curated_row = $curated_check->get_result()->fetch_assoc();
        $curated_check->close();

        // 홈 노출(오늘의특가/기획전/새상품)은 이미 특가/기획 가격으로 노출되는 자리라, 등급별 할인이
        // 중복 적용되지 않도록 일반/도매 할인을 항상 제외(0)로 맞춘다 — 새로 큐레이션할 때뿐 아니라
        // 이미 큐레이션되어 있던 상품을 진열에 추가할 때도 마찬가지로 꺼준다.
        if (!$curated_row) {
            $product_lookup = $conn->prepare('SELECT name_ko, name_en FROM products WHERE id = ? AND is_active = 1');
            $product_lookup->bind_param('i', $product_id);
            $product_lookup->execute();
            $product_row = $product_lookup->get_result()->fetch_assoc();
            $product_lookup->close();
            if (!$product_row) {
                json_error('VALIDATION_ERROR', '유효하지 않은 상품입니다', 404);
            }

            $curate_ins = $conn->prepare(
                'INSERT INTO mall_products (product_id, store_id, display_name, display_name_en, is_active, display_order, retail_discount_allowed, wholesale_discount_allowed) VALUES (?, ?, ?, ?, 1, 0, 0, 0)'
            );
            $curate_ins->bind_param('iiss', $product_id, $store_id, $product_row['name_ko'], $product_row['name_en']);
            $curate_ins->execute();
            $curate_ins->close();
        } else {
            $discount_off = $conn->prepare('UPDATE mall_products SET retail_discount_allowed = 0, wholesale_discount_allowed = 0 WHERE id = ?');
            $discount_off->bind_param('i', $curated_row['id']);
            $discount_off->execute();
            $discount_off->close();
        }
    }

    $stmt = $conn->prepare("SELECT id, config FROM mall_home_sections WHERE store_id = ? AND slot_key = ? AND status = 'draft'");
    $stmt->bind_param('is', $store_id, $slot_key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $product_ids = [];
    $fresh_product_ids = [];
    if ($row && $row['config']) {
        $decoded = json_decode($row['config'], true);
        $product_ids = is_array($decoded) && !empty($decoded['product_ids']) ? array_map('intval', $decoded['product_ids']) : [];
        $fresh_product_ids = is_array($decoded) && !empty($decoded['fresh_product_ids']) ? array_map('intval', $decoded['fresh_product_ids']) : [];
    }

    if ($product_type === 'fresh') {
        if ($active) {
            $fresh_check = $conn->prepare("SELECT id FROM mall_fresh_products WHERE id = ? AND status = 'active'");
            $fresh_check->bind_param('i', $fresh_product_id);
            $fresh_check->execute();
            if (!$fresh_check->get_result()->fetch_assoc()) { $fresh_check->close(); json_error('VALIDATION_ERROR', '유효하지 않은 신선상품입니다', 404); }
            $fresh_check->close();
            if (!in_array($fresh_product_id, $fresh_product_ids, true)) { $fresh_product_ids[] = $fresh_product_id; }
        } else {
            $fresh_product_ids = array_values(array_filter($fresh_product_ids, fn($id) => $id !== $fresh_product_id));
        }
    } elseif ($active) {
        if (!in_array($product_id, $product_ids, true)) {
            $product_ids[] = $product_id;
        }
    } else {
        $product_ids = array_values(array_filter($product_ids, fn($id) => $id !== $product_id));
    }

    $config_json = json_encode(['product_ids' => $product_ids, 'fresh_product_ids' => $fresh_product_ids], JSON_UNESCAPED_UNICODE);

    if ($row) {
        $upd = $conn->prepare('UPDATE mall_home_sections SET config = ? WHERE id = ?');
        $upd->bind_param('si', $config_json, $row['id']);
        $upd->execute();
        $upd->close();
    } else {
        $title = $default_titles[$slot_key] ?? '';
        $ins = $conn->prepare(
            "INSERT INTO mall_home_sections (store_id, section_type, slot_key, title, subtitle, config, sort_order, is_active, status)
             VALUES (?, 'product_list', ?, ?, '', ?, 0, 1, 'draft')"
        );
        $ins->bind_param('isss', $store_id, $slot_key, $title, $config_json);
        $ins->execute();
        $ins->close();
    }

    echo json_encode(['success' => true, 'data' => ['count' => count($product_ids) + count($fresh_product_ids)]]);
} catch (Throwable $e) {
    error_log('toggle_home_section_product.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
