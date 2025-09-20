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
    if (!has_permission('wholesale_management')) {
        echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
        exit;
    }

    $query = trim($_POST['q'] ?? '');
    $limit = min(max(1, (int)($_POST['limit'] ?? 10)), 50);
    
    // 디버깅 로그
    error_log("Search query: '$query', limit: $limit");
    error_log("POST data: " . print_r($_POST, true));
    error_log("Current store ID: " . ($_SESSION['store_id'] ?? 'not_set'));
    
    if (strlen($query) < 2) {
        echo json_encode(['success' => false, 'products' => [], 'message' => '검색어가 너무 짧습니다']);
        exit;
    }
    
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $search_query = "%{$query}%";
    
    // 간단한 상품 검색 - inventory 테이블과 조인하여 현재 점포의 가격 정보 포함
    $current_store_id = $_SESSION['store_id'] ?? 1; // 현재 점포 ID

    $sql = "
        SELECT
            p.id,
            p.sku,
            p.name_ko,
            p.name_en,
            COALESCE(p.pieces_per_box, 1) as pieces_per_box,
            COALESCE(i.selling_price, 0) as selling_price,
            COALESCE(i.cost_price, 0) as cost_price
        FROM products p
        LEFT JOIN inventory i ON p.id = i.product_id AND i.store_id = ?
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
        $current_store_id,                           // JOIN 조건
        $search_query, $search_query, $search_query, // WHERE 조건
        $search_query, $search_query, $search_query  // ORDER BY 조건
    ]);
    
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 디버깅 로그
    error_log("Found products: " . count($products));
    error_log("Products data: " . json_encode($products));
    
    if (!empty($products)) {
        echo json_encode(['success' => true, 'products' => $products]);
    } else {
        echo json_encode(['success' => false, 'products' => [], 'message' => '검색 결과가 없습니다.']);
    }
    
} catch (Exception $e) {
    error_log("Product search error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    echo json_encode(['success' => false, 'message' => '검색 중 오류가 발생했습니다: ' . $e->getMessage()]);
}
?>