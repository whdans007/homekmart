<?php
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/permission_helper.php';

header('Content-Type: application/json');

if (!is_logged_in() || !has_permission('store_transfer_management')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '접근 권한이 없습니다.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '허용되지 않은 요청 방식입니다.']);
    exit;
}

$q        = trim($_POST['q'] ?? '');
$store_id = (int)($_POST['store_id'] ?? 0);

if (!$store_id) {
    echo json_encode(['success' => false, 'message' => '점포를 선택해주세요.']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $search = "%{$q}%";

    $sql = "
        SELECT
            p.id,
            p.sku,
            COALESCE(p.name_ko, '') AS name_ko,
            COALESCE(p.name_en, '') AS name_en,
            COALESCE(i.cost_price, 0) AS cost_price,
            COALESCE((
                SELECT pch.new_selling_price
                FROM price_change_history pch
                WHERE pch.sku = p.sku
                ORDER BY pch.created_at DESC
                LIMIT 1
            ), 0) AS selling_price
        FROM products p
        LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = :store_id
        WHERE p.is_active = 1
          AND (p.name_ko LIKE :q OR p.name_en LIKE :q OR p.sku LIKE :q)
        ORDER BY p.name_ko ASC
        LIMIT 20
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':store_id' => $store_id, ':q' => $search]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $products = [];
    foreach ($rows as $row) {
        $products[] = [
            'id'            => (int)$row['id'],
            'sku'           => $row['sku'] ?: '',
            'name_ko'       => $row['name_ko'],
            'name_en'       => $row['name_en'],
            'cost_price'    => number_format((float)$row['cost_price'], 2, '.', ''),
            'selling_price' => $row['selling_price'] > 0
                               ? number_format((float)$row['selling_price'], 2, '.', '')
                               : null,
        ];
    }

    echo json_encode(['success' => true, 'products' => $products]);

} catch (PDOException $e) {
    error_log("ajax_search_event_products error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '상품 검색 중 오류가 발생했습니다.']);
}
