<?php
// 거래처의 최근 판매 이력 중 반품 가능한 품목 조회 (신규 판매등록 화면의 "반품등록" 모달용)
// Design Ref: docs/02-design/features/wholesale-sales-return.design.md (2026-07-09 UX 개편)
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';

ensure_logged_in();

header('Content-Type: application/json');

if (!has_permission('wholesale_management')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'items' => [], 'message' => '권한이 없습니다.']);
    exit;
}

$customer_id = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
if ($customer_id <= 0) {
    echo json_encode(['success' => true, 'items' => []]);
    exit;
}

$is_super_admin = ($_SESSION['role'] === 'super_admin');
$session_store_id = $_SESSION['store_id'] ?? null;

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 반품 기능 마이그레이션 적용 여부 확인 (하위 호환)
    $has_returned_qty = $pdo->query("SHOW COLUMNS FROM wholesale_sale_items LIKE 'returned_quantity'")->rowCount() > 0;
    if (!$has_returned_qty) {
        echo json_encode(['success' => true, 'items' => [], 'migration_pending' => true]);
        exit;
    }

    $where = ["ws.customer_id = ?", "ws.status != 'cancelled'", "(wsi.quantity - wsi.returned_quantity) > 0"];
    $params = [$customer_id];
    if (!$is_super_admin) {
        $where[] = "ws.store_id = ?";
        $params[] = $session_store_id;
    }

    $sql = "
        SELECT
            wsi.id as sale_item_id,
            wsi.sale_id,
            ws.sale_date,
            wsi.product_id,
            wsi.quantity,
            wsi.returned_quantity,
            wsi.unit_price,
            wsi.sale_unit,
            COALESCE(wsi.custom_product_name, p.name_ko, p.name_en) as product_name,
            COALESCE(p.sku, '수기') as sku
        FROM wholesale_sale_items wsi
        JOIN wholesale_sales ws ON wsi.sale_id = ws.id
        LEFT JOIN products p ON wsi.product_id = p.id
        WHERE " . implode(" AND ", $where) . "
        ORDER BY ws.sale_date DESC, ws.id DESC, wsi.id DESC
        LIMIT 30
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'items' => $items]);

} catch (PDOException $e) {
    error_log("Wholesale customer returns search error: " . $e->getMessage());
    echo json_encode(['success' => false, 'items' => [], 'message' => '조회 중 오류가 발생했습니다.']);
}
