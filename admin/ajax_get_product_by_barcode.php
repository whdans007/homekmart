<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit();
}

// 매입관리 권한 확인
if (!has_permission('purchase_management')) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit();
}

$barcode = $_GET['barcode'] ?? $_POST['barcode'] ?? '';

if (empty($barcode)) {
    echo json_encode(['success' => false, 'message' => '바코드를 입력해주세요.']);
    exit();
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 사용자의 점포 ID 확인
    $store_id = $_SESSION['store_id'] ?? null;

    // 세션에 store_id가 없으면 데이터베이스에서 조회
    if (!$store_id) {
        $user_id = $_SESSION['user_id'] ?? null;
        if ($user_id) {
            $user_stmt = $pdo->prepare("SELECT store_id FROM users WHERE id = ?");
            $user_stmt->execute([$user_id]);
            $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
            $store_id = $user['store_id'] ?? null;

            // 세션에 store_id 저장
            if ($store_id) {
                $_SESSION['store_id'] = $store_id;
            }
        }
    }

    if (!$store_id) {
        echo json_encode(['success' => false, 'message' => '점포 정보가 없습니다. 사용자 설정을 확인해주세요.']);
        exit();
    }

    // 바코드로 상품 조회 (점포별 재고 정보 포함)
    $stmt = $pdo->prepare("
        SELECT
            p.id as product_id,
            p.name_ko,
            p.name_en,
            p.sku,
            p.category_id,
            c.name as category_name,
            b.name_ko as brand_name_ko,
            i.cost_price,
            i.selling_price,
            i.store_id,
            i.quantity
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = ?
        WHERE p.sku = ?
        LIMIT 1
    ");
    $stmt->execute([$store_id, $barcode]);

    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        echo json_encode(['success' => false, 'message' => '해당 바코드의 상품을 찾을 수 없습니다.']);
        exit();
    }

    // 재고 정보가 없는 경우 - 상품 정보는 보여주되, 가격은 0으로 표시
    $has_inventory = $product['store_id'] !== null;

    // 성공 응답
    echo json_encode([
        'success' => true,
        'data' => $product,
        'has_inventory' => $has_inventory,
        'product' => [
            'product_id' => $product['product_id'],
            'name_ko' => $product['name_ko'],
            'name_en' => $product['name_en'],
            'sku' => $product['sku'],
            'cost_price' => $product['cost_price'] ? number_format($product['cost_price'], 2) : '0.00',
            'cost_price_raw' => $product['cost_price'] ?? 0,
            'selling_price' => $product['selling_price'] ? number_format($product['selling_price']) : '0',
            'selling_price_raw' => $product['selling_price'] ?? 0
        ]
    ]);

} catch (PDOException $e) {
    error_log("Database error in ajax_get_product_by_barcode.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류가 발생했습니다.']);
} catch (Exception $e) {
    error_log("Error in ajax_get_product_by_barcode.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '오류가 발생했습니다.']);
}
