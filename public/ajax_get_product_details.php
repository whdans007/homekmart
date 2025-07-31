<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';

header('Content-Type: application/json');

if (!is_logged_in() || !in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

$product_id = $_GET['id'] ?? 0;

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

    // 재고 정보 조회 (지점별)
    $inv_stmt = $pdo->prepare("
        SELECT i.quantity, s.name as store_name 
        FROM inventory i
        JOIN stores s ON i.store_id = s.id
        WHERE i.product_id = ?
        ORDER BY s.name
    ");
    $inv_stmt->execute([$product_id]);
    $product['inventory'] = $inv_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 총 재고 계산
    $total_stock = 0;
    foreach ($product['inventory'] as $inv) {
        $total_stock += $inv['quantity'];
    }
    $product['total_stock'] = $total_stock;

    echo json_encode(['success' => true, 'data' => $product]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류: ' . $e->getMessage()]);
}
