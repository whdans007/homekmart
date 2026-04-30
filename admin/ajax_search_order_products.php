<?php
// 오류 로깅 활성화
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../lib/session_helper.php';
    require_once __DIR__ . '/../lib/permission_helper.php';
    require_once __DIR__ . '/../config/db_config.php';

    // 세션 및 권한 확인
    ensure_logged_in();
    if (!has_permission('product_management') && !has_permission('shop_access')) {
        echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
        exit;
    }

    $query = trim($_POST['q'] ?? '');
    $limit = min(max(1, (int)($_POST['limit'] ?? 10)), 50);

    if (strlen($query) < 2) {
        echo json_encode(['success' => false, 'products' => [], 'message' => '검색어가 너무 짧습니다']);
        exit;
    }

    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $search_query = "%{$query}%";

    // 현재 점포 ID
    $current_store_id = $_SESSION['store_id'] ?? 1;

    $sql = "
        SELECT
            p.id,
            p.sku,
            p.name_ko,
            p.name_en,
            COALESCE(p.pieces_per_box, 1) as pieces_per_box
        FROM products p
        WHERE p.is_active = 1
        AND (p.sku LIKE ? OR p.name_ko LIKE ? OR p.name_en LIKE ?)
        ORDER BY
            CASE
                WHEN p.sku LIKE ? THEN 1
                WHEN p.name_en LIKE ? THEN 2
                WHEN p.name_ko LIKE ? THEN 3
                ELSE 4
            END,
            p.name_en ASC, p.name_ko ASC
        LIMIT " . (int)$limit . "
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $search_query, $search_query, $search_query, // WHERE 조건
        $search_query, $search_query, $search_query  // ORDER BY 조건
    ]);

    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($products)) {
        echo json_encode(['success' => true, 'products' => $products]);
    } else {
        echo json_encode(['success' => false, 'products' => [], 'message' => '검색 결과가 없습니다.']);
    }

} catch (Exception $e) {
    error_log("Product search error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '검색 중 오류가 발생했습니다: ' . $e->getMessage()]);
}
?>
