<?php
/**
 * GET mall/admin/ajax/search_products.php
 * 홈 레이아웃 "상품 리스트" 섹션 편집 모달의 실시간 상품 검색(읽기 전용, 상태 변경 없음 — CSRF 불필요).
 * Design Ref: mall-home-layout.design.md §5.4 관리자 — 홈 레이아웃, FR-05
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../lib/session_helper.php';
require_once __DIR__ . '/../../../lib/permission_helper.php';
require_once __DIR__ . '/../../../config/db_config.php';

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

$q = trim($_GET['q'] ?? '');
$id = (int)($_GET['id'] ?? 0);
if ($q === '' && $id <= 0) {
    echo json_encode(['success' => true, 'data' => []]);
    exit;
}

try {
    $conn = get_db_connection();

    if ($id > 0) {
        // 배너 "클릭 시 이동 → 상품" 편집 화면에서 기존 저장된 상품 ID로 표시용 이름을 되찾을 때 사용
        $stmt = $conn->prepare('SELECT id AS product_id, name_ko AS display_name, sku FROM products WHERE id = ?');
        $stmt->bind_param('i', $id);
    } else {
        $like = '%' . $q . '%';
        $stmt = $conn->prepare(
            'SELECT id AS product_id, name_ko AS display_name, sku
             FROM products WHERE (name_ko LIKE ? OR name_en LIKE ? OR sku LIKE ?) AND is_active = 1
             ORDER BY name_ko LIMIT 20'
        );
        $stmt->bind_param('sss', $like, $like, $like);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();

    echo json_encode(['success' => true, 'data' => $rows]);
} catch (Exception $e) {
    error_log('search_products.php error: ' . $e->getMessage());
    json_error('SERVER_ERROR', '처리 중 오류가 발생했습니다', 500);
}
