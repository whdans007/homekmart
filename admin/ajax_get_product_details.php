<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

header('Content-Type: application/json');

// 디버깅을 위한 로깅
error_log("Product details request - Method: " . $_SERVER['REQUEST_METHOD']);
error_log("GET data: " . json_encode($_GET));
error_log("POST data: " . json_encode($_POST));

if (!is_logged_in()) {
    error_log("Not logged in for product details");
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

// 임시로 권한 체크 단순화 (디버깅용)
if (!in_array($_SESSION['role'] ?? '', ['super_admin', 'admin'])) {
    if (!has_permission('product_management')) {
        error_log("Permission denied for product details - role: " . ($_SESSION['role'] ?? 'none'));
        echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
        exit;
    }
}

$product_id = $_GET['id'] ?? $_POST['product_id'] ?? 0;
$current_store_id = $_GET['store_id'] ?? $_POST['store_id'] ?? null;

error_log("Product ID: $product_id, Store ID: $current_store_id");

if (empty($product_id)) {
    error_log("Empty product ID");
    echo json_encode(['success' => false, 'message' => '상품 ID가 필요합니다.']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 기본 상품 정보 조회
    error_log("Querying product with ID: $product_id");
    
    $stmt = $pdo->prepare("
        SELECT 
            p.*, 
            c.name as category_name,
            c.name_en as category_name_en,
            b.name_ko as brand_name_ko,
            b.name_en as brand_name_en,
            u.full_name as last_modified_by
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        LEFT JOIN users u ON p.last_modified_by_user_id = u.id
        WHERE p.id = ?
    ");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        error_log("Product not found with ID: $product_id");
        echo json_encode(['success' => false, 'message' => '상품을 찾을 수 없습니다.']);
        exit;
    }
    
    error_log("Product found: " . $product['name_ko'] . " (ID: $product_id)");

    // inventory 테이블의 컬럼 구조 확인
    $cost_column_check = $pdo->prepare("SHOW COLUMNS FROM inventory LIKE 'cost_price'");
    $cost_column_check->execute();
    $has_cost_price_column = $cost_column_check->fetch();
    
    $selling_column_check = $pdo->prepare("SHOW COLUMNS FROM inventory LIKE 'selling_price'");
    $selling_column_check->execute();
    $has_selling_price_column = $selling_column_check->fetch();
    
    $box_price_column_check = $pdo->prepare("SHOW COLUMNS FROM inventory LIKE 'box_price'");
    $box_price_column_check->execute();
    $has_box_price_column = $box_price_column_check->fetch();
    
    // inventory 테이블의 stock_quantity 컬럼 확인
    $stock_column_check = $pdo->prepare("SHOW COLUMNS FROM inventory LIKE 'stock_quantity'");
    $stock_column_check->execute();
    $has_stock_quantity_column = $stock_column_check->fetch();
    
    // 모든 지점 정보 조회 (원가/판매가/박스단가/재고 포함, 재고 관계없이 모든 점포 표시)
    $select_columns = '';
    if ($has_selling_price_column) {
        $select_columns .= ', i.selling_price';
    }
    if ($has_cost_price_column) {
        $select_columns .= ', i.cost_price';
    }
    if ($has_box_price_column) {
        $select_columns .= ', i.box_price';
    }
    if ($has_stock_quantity_column) {
        $select_columns .= ', i.stock_quantity';
    }
    
    if ($current_store_id) {
        // 현재 점포를 우선 표시하면서 모든 점포 조회
        $inv_stmt = $pdo->prepare("
            SELECT s.name as store_name, s.id as store_id{$select_columns},
                   CASE WHEN s.id = ? THEN 0 ELSE 1 END as sort_order
            FROM stores s
            LEFT JOIN inventory i ON s.id = i.store_id AND i.product_id = ?
            ORDER BY sort_order, s.name
        ");
        $inv_stmt->execute([$current_store_id, $product_id]);
    } else {
        // 모든 점포 조회
        $inv_stmt = $pdo->prepare("
            SELECT s.name as store_name, s.id as store_id{$select_columns}
            FROM stores s
            LEFT JOIN inventory i ON s.id = i.store_id AND i.product_id = ?
            ORDER BY s.name
        ");
        $inv_stmt->execute([$product_id]);
    }
    $product['inventory'] = $inv_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 디버깅: 점포별 가격 데이터 로그
    error_log("Product store pricing data: " . json_encode($product['inventory']));

    // 응답 구조 통일 (기존 호환성 유지 + new_products_management.php 호환)
    $inventory_data = $product['inventory'];
    
    echo json_encode([
        'success' => true, 
        'data' => $product,        // 기존 product_management.php와 호환
        'product' => $product,     // new_products_management.php와 호환
        'inventory' => $inventory_data
    ]);

} catch (PDOException $e) {
    error_log("Database error in product details: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("General error in product details: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '시스템 오류: ' . $e->getMessage()]);
}
