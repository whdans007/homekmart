<?php
/**
 * 수정된 상품 API (에러 처리 강화)
 */

// 에러 표시 활성화
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// 출력 버퍼링으로 깔끔한 출력 보장
ob_start();

try {
    // 설정 파일 경로 확인
    $config_path = __DIR__ . '/config/db_config.php';
    if (!file_exists($config_path)) {
        throw new Exception("Config file not found: $config_path");
    }
    
    require_once $config_path;
    
    // 필수 상수 확인
    if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS')) {
        throw new Exception('Database constants not properly defined');
    }
    
    // 데이터베이스 연결
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 파라미터 처리
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    
    // WHERE 조건 구성
    $where_conditions = ["(p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')"];
    $params = [];
    
    if ($search) {
        $where_conditions[] = "p.name LIKE ?";
        $params[] = "%{$search}%";
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // 상품 목록 쿼리 (간단화)
    $sql = "
        SELECT 
            p.id, 
            p.name, 
            p.description, 
            p.barcode,
            c.name as category_name,
            b.name_ko as brand_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN brands b ON p.brand_id = b.id
        WHERE $where_clause
        ORDER BY p.name ASC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 데이터 가공
    $processed_products = [];
    foreach ($products as $product) {
        $processed_products[] = [
            'id' => (int)$product['id'],
            'name' => $product['name'],
            'description' => $product['description'],
            'barcode' => $product['barcode'],
            'category_name' => $product['category_name'] ?: 'Unknown',
            'brand_name' => $product['brand_name'] ?: 'Unknown',
            'currency' => 'PHP',
            'available_for_delivery' => true // 간단화
        ];
    }
    
    // 총 개수 조회
    $count_sql = "SELECT COUNT(*) as total FROM products p WHERE $where_clause";
    $count_params = array_slice($params, 0, -2); // LIMIT, OFFSET 제거
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($count_params);
    $total_count = (int)$count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 응답 구성
    $response = [
        'success' => true,
        'data' => [
            'products' => $processed_products,
            'pagination' => [
                'total_count' => $total_count,
                'current_count' => count($processed_products),
                'limit' => $limit,
                'offset' => $offset
            ],
            'filters' => [
                'search' => $search
            ]
        ],
        'message' => 'Products retrieved successfully',
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    // 출력 버퍼 정리
    ob_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage(),
        'error_code' => $e->getCode(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} finally {
    ob_end_flush();
}
?>