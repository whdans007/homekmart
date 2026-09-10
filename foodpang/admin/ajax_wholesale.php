<?php
/**
 * Foodpang 외부 도매 매핑(fpwx) AJAX 엔드포인트.
 * search_hkm_products / manual_map / reject_mapping / dismiss_candidate / export_mappings 를 처리한다.
 * fpwx_* 매핑 테이블만 변경하며, Foodpang 원본 스냅샷(fpwx_raw_*)이나 기존 products는 절대 변경하지 않는다.
 */
ob_start();

try {
    require_once __DIR__ . '/../../config/db_config.php';
    require_once __DIR__ . '/../../lib/session_helper.php';
    require_once __DIR__ . '/../../lib/permission_helper.php';
    require_once __DIR__ . '/../../mall/lib/csrf.php';
    require_once __DIR__ . '/../../lib/fpwx_helper.php';
} catch (Exception $e) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => '필요한 라이브러리를 불러올 수 없습니다: ' . $e->getMessage()]]);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ob_clean();
header('Content-Type: application/json; charset=utf-8');

function fpwx_json_error($code, $message, $http = 400) {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
    exit;
}

if (!is_logged_in()) {
    fpwx_json_error('UNAUTHORIZED', '로그인이 필요합니다', 401);
}
if (!has_permission('foodpang_wholesale_management')) {
    fpwx_json_error('UNAUTHORIZED', '권한이 없습니다', 403);
}

$user_id = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $pdo = fpwx_pdo();

    if ($action === 'search_hkm_products') {
        $q = trim($_GET['q'] ?? '');
        if ($q === '') {
            echo json_encode(['success' => true, 'data' => []]);
            exit;
        }
        echo json_encode(['success' => true, 'data' => fpwx_search_hkm_products($pdo, $q)]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fpwx_json_error('VALIDATION_ERROR', '잘못된 요청 방식입니다');
    }
    if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
        fpwx_json_error('CSRF_INVALID', '요청이 만료되었습니다. 새로고침 후 다시 시도해주세요.', 403);
    }

    if ($action === 'manual_map') {
        $sales_code = trim($_POST['sales_code'] ?? '');
        $hkm_product_id = (int)($_POST['hkm_product_id'] ?? 0);
        $package_type = trim($_POST['package_type'] ?? '') ?: null;
        $units_per_sale = (float)($_POST['units_per_sale'] ?? 1);

        if ($sales_code === '' || $hkm_product_id <= 0) {
            fpwx_json_error('VALIDATION_ERROR', '판매코드와 HKM 상품을 확인해주세요');
        }
        if ($units_per_sale <= 0) {
            fpwx_json_error('VALIDATION_ERROR', '환산 수량은 0보다 커야 합니다');
        }

        $check = $pdo->prepare('SELECT id FROM products WHERE id = ?');
        $check->execute([$hkm_product_id]);
        if (!$check->fetch()) {
            fpwx_json_error('VALIDATION_ERROR', '유효하지 않은 HKM 상품입니다');
        }

        fpwx_apply_manual_mapping($pdo, $sales_code, $hkm_product_id, $package_type, $units_per_sale, $user_id);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'reject_mapping') {
        $sales_code = trim($_POST['sales_code'] ?? '');
        if ($sales_code === '') {
            fpwx_json_error('VALIDATION_ERROR', '판매코드를 확인해주세요');
        }
        fpwx_reject_mapping($pdo, $sales_code, $user_id, trim($_POST['note'] ?? '') ?: null);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'dismiss_candidate') {
        $candidate_id = (int)($_POST['candidate_id'] ?? 0);
        if ($candidate_id <= 0) {
            fpwx_json_error('VALIDATION_ERROR', '입력값을 확인해주세요');
        }
        $stmt = $pdo->prepare('UPDATE fpwx_match_candidates SET status = "dismissed", resolved_by = ?, resolved_at = NOW() WHERE id = ? AND status = "pending"');
        $stmt->execute([$user_id, $candidate_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    fpwx_json_error('VALIDATION_ERROR', '알 수 없는 작업입니다');
} catch (Throwable $e) {
    error_log('ajax_wholesale.php error: ' . $e->getMessage());
    fpwx_json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
