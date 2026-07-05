<?php
// Tablet price lookup — search by SKU, read-only, store 1
require_once __DIR__ . '/../config/db_config.php';
header('Content-Type: application/json; charset=utf-8');

$barcode = trim($_GET['barcode'] ?? $_POST['barcode'] ?? '');
$storeId = 6; // Kims Mall, fixed

if ($barcode === '') {
    echo json_encode(['success' => false, 'message' => 'Please enter a barcode or SKU.']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $hasProdPrice = (bool)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name='products' AND column_name='selling_price'"
    )->fetchColumn();

    $priceExpr = $hasProdPrice
        ? 'COALESCE(i.selling_price, p.selling_price)'
        : 'i.selling_price';

    $stmt = $pdo->prepare("
        SELECT
            p.id AS product_id,
            p.name_en,
            p.sku,
            {$priceExpr} AS selling_price
        FROM products p
        LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = ?
        WHERE p.sku = ?
        LIMIT 1
    ");
    $stmt->execute([$storeId, $barcode]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        echo json_encode(['success' => false, 'message' => 'Product not found: ' . htmlspecialchars($barcode)]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'product' => [
            'product_id'        => $product['product_id'],
            'name_en'           => $product['name_en'] ?? '',
            'sku'               => $product['sku'],
            'selling_price'     => $product['selling_price'] !== null
                                    ? number_format((float)$product['selling_price'], 0)
                                    : 'N/A',
            'selling_price_raw' => (float)($product['selling_price'] ?? 0),
        ]
    ]);

} catch (PDOException $e) {
    error_log('pricing-tablet/ajax_search.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
