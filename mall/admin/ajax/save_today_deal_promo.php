<?php
/**
 * POST mall/admin/ajax/save_today_deal_promo.php
 * 상품 큐레이션(products.php) "오늘의특가" 화면에서 상품별 프로모(1+1/퍼센트할인/원가세일)를 설정한다.
 *
 * 실제 판매가(mall_products.selling_price_override)와 프로모 정보(promo_type/promo_value/promo_was)를
 * 전부 mall_products에 직접 저장한다 — mall_home_sections(초안/발행) 쪽에는 안 건드린다.
 * 이유: 예전엔 프로모 뱃지 정보를 mall_home_sections.config(초안)에 저장했는데, 가격은 즉시 반영되는데
 * 뱃지는 "홈 레이아웃 적용"을 눌러야만 보여서 "가격은 바뀌는데 뱃지가 안 보인다"는 혼란이 있었다.
 * mall_products에 직접 저장하면 가격처럼 저장 즉시 고객 화면에도 바로 반영된다.
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

$product_id = (int)($_POST['product_id'] ?? 0);
$promo_type = $_POST['promo_type'] ?? 'none';
$promo_value = isset($_POST['promo_value']) && $_POST['promo_value'] !== '' ? (float)$_POST['promo_value'] : null;

if ($product_id <= 0) {
    json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
}
if (!in_array($promo_type, ['none', '1plus1', 'percent', 'cost_sale'], true)) {
    json_error('VALIDATION_ERROR', '유효하지 않은 프로모 유형입니다');
}
if ($promo_type === 'percent' && (!$promo_value || $promo_value <= 0 || $promo_value >= 100)) {
    json_error('VALIDATION_ERROR', '할인율은 0~100 사이 숫자여야 합니다');
}

try {
    $conn = mall_get_db_connection();
    $store_id = (int)MALL_STORE_ID;

    $mp_stmt = $conn->prepare('SELECT id, cost_price_override, selling_price_override, promo_type, promo_was FROM mall_products WHERE product_id = ? AND store_id = ?');
    $mp_stmt->bind_param('ii', $product_id, $store_id);
    $mp_stmt->execute();
    $mp_row = $mp_stmt->get_result()->fetch_assoc();
    $mp_stmt->close();
    if (!$mp_row) {
        json_error('VALIDATION_ERROR', '먼저 이 상품을 큐레이션해주세요', 404);
    }

    $inv_stmt = $conn->prepare('SELECT cost_price, selling_price FROM inventory WHERE product_id = ? AND store_id = ?');
    $inv_stmt->bind_param('ii', $product_id, $store_id);
    $inv_stmt->execute();
    $inv_row = $inv_stmt->get_result()->fetch_assoc();
    $inv_stmt->close();

    $original_selling_price = $inv_row && $inv_row['selling_price'] !== null ? (float)$inv_row['selling_price'] : null;
    $effective_cost_price = $mp_row['cost_price_override'] !== null
        ? (float)$mp_row['cost_price_override']
        : ($inv_row && $inv_row['cost_price'] !== null ? (float)$inv_row['cost_price'] : null);
    $current_override = $mp_row['selling_price_override'] !== null ? (float)$mp_row['selling_price_override'] : null;

    // 기준가(취소선용 "원래 가격") — 이미 이 상품에 프로모가 걸려있으면 그때 저장해둔 원래 가격을 그대로
    // 재사용하고(중첩 할인 방지), 처음 거는 거면 지금의 실제 판매가를 기준으로 잡는다.
    $reference_price = $mp_row['promo_type'] !== null && $mp_row['promo_was'] !== null
        ? (float)$mp_row['promo_was']
        : ($current_override ?? $original_selling_price);

    $new_override = null;
    $new_promo_type = null;
    $new_promo_value = null;
    $new_promo_was = null;

    if ($promo_type === 'none') {
        // 전부 해제 — 가격도 원래 판매가로 되돌린다.
    } elseif ($promo_type === '1plus1') {
        $new_promo_type = '1plus1';
        // 1+1은 가격 자체는 안 바뀐다 — 걸려있던 프로모 가격 override는 해제.
    } elseif ($promo_type === 'percent') {
        if ($reference_price === null) {
            json_error('VALIDATION_ERROR', '기준 판매가 정보가 없어 퍼센트 할인을 계산할 수 없습니다');
        }
        $new_override = round($reference_price * (1 - $promo_value / 100), 2);
        $new_promo_type = 'percent';
        $new_promo_value = $promo_value;
        $new_promo_was = $reference_price;
    } elseif ($promo_type === 'cost_sale') {
        if ($effective_cost_price === null) {
            json_error('VALIDATION_ERROR', '원가 정보가 없어 원가세일을 적용할 수 없습니다');
        }
        if ($reference_price === null) {
            json_error('VALIDATION_ERROR', '기준 판매가 정보가 없어 원가세일을 적용할 수 없습니다');
        }
        $new_override = $effective_cost_price;
        $new_promo_type = 'cost_sale';
        $new_promo_was = $reference_price;
    }

    $upd = $conn->prepare(
        'UPDATE mall_products SET selling_price_override = ?, promo_type = ?, promo_value = ?, promo_was = ? WHERE id = ?'
    );
    $upd->bind_param('dsddi', $new_override, $new_promo_type, $new_promo_value, $new_promo_was, $mp_row['id']);
    $upd->execute();
    $upd->close();

    echo json_encode(['success' => true, 'data' => [
        'new_price' => $new_override,
        'original_selling_price' => $original_selling_price,
        'effective_cost_price' => $effective_cost_price,
    ]]);
} catch (Throwable $e) {
    error_log('save_today_deal_promo.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
