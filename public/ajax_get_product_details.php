<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';

header('Content-Type: application/json');

if (!is_logged_in() || !in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

$product_id = $_GET['id'] ?? 0;
$current_store_id = $_GET['store_id'] ?? null;

if (empty($product_id)) {
    echo json_encode(['success' => false, 'message' => '상품 ID가 필요합니다.']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 기본 상품 정보 조회 (새로 추가된 barcode, pieces_per_box 포함)
    $stmt = $pdo->prepare("
        SELECT 
            p.*, 
            c.name as category_name, 
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
        echo json_encode(['success' => false, 'message' => '상품을 찾을 수 없습니다.']);
        exit;
    }

    // inventory 테이블의 컬럼 구조 확인
    $cost_column_check = $pdo->prepare("SHOW COLUMNS FROM inventory LIKE 'cost_price'");
    $cost_column_check->execute();
    $has_cost_price_column = $cost_column_check->fetch();
    
    $selling_column_check = $pdo->prepare("SHOW COLUMNS FROM inventory LIKE 'selling_price'");
    $selling_column_check->execute();
    $has_selling_price_column = $selling_column_check->fetch();
    
    // 모든 지점 정보 조회 (원가/판매가 포함, 재고 관계없이 모든 점포 표시)
    $price_columns = '';
    $select_columns = '';
    if ($has_selling_price_column) {
        $price_columns .= ', i.selling_price';
        $select_columns .= ', i.selling_price';
    }
    if ($has_cost_price_column) {
        $price_columns .= ', i.cost_price';
        $select_columns .= ', i.cost_price';
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

    echo json_encode(['success' => true, 'data' => $product]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류: ' . $e->getMessage()]);
}
