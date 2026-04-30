<?php
// 바코드 라벨 출력 도구 - 상품 검색 (인증 불필요)
require_once __DIR__ . '/../config/db_config.php';
header('Content-Type: application/json; charset=utf-8');

$barcode = trim($_GET['barcode'] ?? $_POST['barcode'] ?? '');
$storeId = (int)($_GET['store_id'] ?? 1);

if ($barcode === '') {
    echo json_encode(['success' => false, 'message' => '바코드를 입력해주세요.']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // products 테이블에 selling_price 컬럼 존재 여부 확인
    $hasProdPrice = (bool)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name='products' AND column_name='selling_price'"
    )->fetchColumn();

    // inventory 테이블에 cost_price 컬럼 존재 여부 확인
    $hasCostPrice = (bool)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name='inventory' AND column_name='cost_price'"
    )->fetchColumn();

    $priceExpr    = $hasProdPrice
        ? 'COALESCE(i.selling_price, p.selling_price)'
        : 'i.selling_price';
    $costExpr     = $hasCostPrice ? 'i.cost_price' : 'NULL';

    $stmt = $pdo->prepare("
        SELECT
            p.id AS product_id,
            p.name_ko,
            p.name_en,
            p.sku,
            {$priceExpr} AS selling_price,
            {$costExpr} AS cost_price
        FROM products p
        LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = ?
        WHERE p.sku = ?
        LIMIT 1
    ");
    $stmt->execute([$storeId, $barcode]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        echo json_encode(['success' => false, 'message' => '상품을 찾을 수 없습니다: ' . $barcode]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'product' => [
            'product_id'        => $product['product_id'],
            'name_ko'           => $product['name_ko'] ?? '',
            'name_en'           => $product['name_en'] ?? '',
            'sku'               => $product['sku'],
            'selling_price'     => $product['selling_price'] !== null
                                    ? number_format((float)$product['selling_price'], 0)
                                    : '',
            'selling_price_raw' => (float)($product['selling_price'] ?? 0),
            'cost_price'        => $product['cost_price'] !== null
                                    ? number_format((float)$product['cost_price'], 2)
                                    : '',
            'cost_price_raw'    => (float)($product['cost_price'] ?? 0),
        ]
    ]);

} catch (PDOException $e) {
    error_log('pricing/ajax_search.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류가 발생했습니다.']);
}
