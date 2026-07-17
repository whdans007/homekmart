<?php
// 거래처에 이전에 납품(판매)했던 상품 목록 조회 (신규 판매등록 화면의 "기존 납품상품 리스트" 모달용)
// 등록 도매상품뿐 아니라 수기(custom) 입력 품목도 함께 조회됨
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';

ensure_logged_in();

header('Content-Type: application/json');

if (!has_permission('wholesale_management')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'products' => [], 'message' => '권한이 없습니다.']);
    exit;
}

$customer_id = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
if ($customer_id <= 0) {
    echo json_encode(['success' => true, 'products' => []]);
    exit;
}

$is_super_admin = ($_SESSION['role'] === 'super_admin');
$session_store_id = $_SESSION['store_id'] ?? null;

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $where = ["ws.customer_id = ?", "ws.status != 'cancelled'", "(wsi.product_id IS NULL OR p.is_active = 1)"];
    $params = [$session_store_id, $session_store_id, $customer_id];
    if (!$is_super_admin) {
        $where[] = "ws.store_id = ?";
        $params[] = $session_store_id;
    }

    // 등록 도매상품과 수기(custom) 입력 품목을 모두 조회 (판매일 내림차순, 전체 이력 반환)
    $sql = "
        SELECT
            wsi.product_id,
            wsi.custom_product_name,
            wsi.custom_cost_price,
            wsi.unit_price as last_unit_price,
            ws.sale_date,
            p.sku,
            COALESCE(wp.wholesale_name_ko, p.name_ko, wsi.custom_product_name) as display_name_ko,
            COALESCE(wp.wholesale_name_en, p.name_en, wsi.custom_product_name) as display_name_en,
            COALESCE(wp.wholesale_price, 0) as wholesale_price,
            COALESCE(wp.wholesale_price_piece, 0) as wholesale_price_piece,
            COALESCE(NULLIF(wp.cost_price, 0), 0) as wp_cost_box,
            COALESCE(NULLIF(wp.cost_price_piece, 0), 0) as wp_cost_piece,
            COALESCE(p.pieces_per_box, wp.min_quantity, 1) as min_quantity,
            COALESCE(NULLIF(inv.cost_price, 0), wsi.custom_cost_price, 0) as cost_price,
            COALESCE(inv.selling_price, 0) as selling_price
        FROM wholesale_sale_items wsi
        JOIN wholesale_sales ws ON wsi.sale_id = ws.id
        LEFT JOIN products p ON wsi.product_id = p.id
        LEFT JOIN wholesale_products wp ON wp.product_id = wsi.product_id AND wp.store_id = ? AND wp.is_active = 1
        LEFT JOIN inventory inv ON inv.product_id = wsi.product_id AND inv.store_id = ?
        WHERE " . implode(" AND ", $where) . "
        ORDER BY ws.sale_date DESC, ws.id DESC, wsi.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 판매일 내림차순으로 과거 납품 이력 전체를 반환 (모달에서 검색/페이지네이션으로 탐색)
    $products = [];
    foreach ($rows as $row) {
        $is_manual = empty($row['product_id']);
        if ($is_manual && trim((string)$row['custom_product_name']) === '') {
            continue;
        }

        $products[] = [
            'id' => $is_manual ? null : (int)$row['product_id'],
            'is_manual' => $is_manual,
            'sku' => $row['sku'],
            'display_name_ko' => $row['display_name_ko'],
            'display_name_en' => $row['display_name_en'],
            'wholesale_price' => (float)$row['wholesale_price'],
            'wholesale_price_piece' => (float)$row['wholesale_price_piece'],
            'wp_cost_box' => (float)$row['wp_cost_box'],
            'wp_cost_piece' => (float)$row['wp_cost_piece'],
            'min_quantity' => (int)$row['min_quantity'],
            'cost_price' => (float)$row['cost_price'],
            'selling_price' => (float)$row['selling_price'],
            'last_unit_price' => (float)$row['last_unit_price'],
            'last_sale_date' => $row['sale_date'],
        ];
    }

    echo json_encode(['success' => true, 'products' => $products]);

} catch (PDOException $e) {
    error_log("Wholesale customer products search error: " . $e->getMessage());
    echo json_encode(['success' => false, 'products' => [], 'message' => '조회 중 오류가 발생했습니다.']);
}
