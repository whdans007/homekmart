<?php
// Tablet price lookup — autocomplete by SKU / English name, store 1
require_once __DIR__ . '/../config/db_config.php';
header('Content-Type: application/json; charset=utf-8');

$q       = trim($_GET['q'] ?? '');
$storeId = 6; // Kims Mall, fixed
$limit   = min(max(1, (int)($_GET['limit'] ?? 12)), 30);

if (mb_strlen($q) < 1) {
    echo json_encode(['success' => false, 'products' => []]);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $hasProdPrice = (bool)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='products' AND column_name='selling_price'"
    )->fetchColumn();

    $priceExpr = $hasProdPrice
        ? 'COALESCE(i.selling_price, p.selling_price)'
        : 'i.selling_price';

    $hasIsActive = (bool)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='products' AND column_name='is_active'"
    )->fetchColumn();

    $activeClause = $hasIsActive ? 'AND p.is_active = 1' : '';

    $like = '%' . $q . '%';

    $sql = "
        SELECT
            p.id AS product_id,
            p.sku,
            p.name_en,
            {$priceExpr} AS selling_price
        FROM products p
        LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = ?
        WHERE (p.sku LIKE ? OR p.name_en LIKE ?)
        {$activeClause}
        ORDER BY
            CASE
                WHEN p.sku     = ?    THEN 0
                WHEN p.sku     LIKE ? THEN 1
                WHEN p.name_en LIKE ? THEN 2
                ELSE 3
            END,
            p.name_en ASC
        LIMIT {$limit}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$storeId, $like, $like, $q, $like, $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $products = array_map(function($r) {
        return [
            'product_id'        => $r['product_id'],
            'sku'               => $r['sku'],
            'name_en'           => $r['name_en'] ?? '',
            'selling_price'     => $r['selling_price'] !== null
                                    ? number_format((float)$r['selling_price'], 0)
                                    : 'N/A',
            'selling_price_raw' => (float)($r['selling_price'] ?? 0),
        ];
    }, $rows);

    echo json_encode(['success' => true, 'products' => $products]);

} catch (Throwable $e) {
    error_log('pricing-tablet/ajax_suggest.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'products' => [], 'message' => 'Search error.']);
}
