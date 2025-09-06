<?php
/**
 * 안전한 카테고리 API
 */

// 에러 표시 활성화 (디버깅용)
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// 출력 버퍼링 시작
ob_start();

try {
    // 1. 설정 파일 확인
    $config_path = __DIR__ . '/config/db_config.php';
    if (!file_exists($config_path)) {
        throw new Exception("Config file not found: $config_path");
    }
    
    require_once $config_path;
    
    // 2. 필수 상수 확인
    $required_constants = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_CHARSET'];
    foreach ($required_constants as $const) {
        if (!defined($const)) {
            throw new Exception("Required constant '$const' not defined");
        }
    }
    
    // 3. 데이터베이스 연결
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    
    // 4. 카테고리별 상품 개수와 함께 조회
    $sql = "
        SELECT 
            c.id, 
            c.name, 
            c.description,
            COUNT(DISTINCT CASE 
                WHEN (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00') 
                THEN p.id 
                END
            ) as product_count,
            COALESCE(SUM(CASE 
                WHEN (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00') 
                THEN i.quantity 
                ELSE 0 
                END
            ), 0) as total_stock
        FROM categories c
        LEFT JOIN products p ON c.id = p.category_id
        LEFT JOIN inventory i ON p.id = i.product_id
        GROUP BY c.id, c.name, c.description
        ORDER BY c.name ASC
    ";
    
    $stmt = $pdo->query($sql);
    $categories = $stmt->fetchAll();
    
    // 5. 데이터 가공
    $processed_categories = [];
    foreach ($categories as $category) {
        $processed_categories[] = [
            'id' => (int)$category['id'],
            'name' => $category['name'],
            'description' => $category['description'],
            'product_count' => (int)$category['product_count'],
            'total_stock' => (int)$category['total_stock'],
            'has_products' => $category['product_count'] > 0,
            'available_for_delivery' => $category['total_stock'] > 0
        ];
    }
    
    // 6. 응답 구성
    $response = [
        'success' => true,
        'data' => [
            'categories' => $processed_categories,
            'count' => count($processed_categories),
            'total_products' => array_sum(array_column($processed_categories, 'product_count')),
            'total_stock' => array_sum(array_column($processed_categories, 'total_stock'))
        ],
        'message' => 'Categories retrieved successfully',
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    // 7. 출력 버퍼 정리 및 응답
    ob_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage(),
        'code' => $e->getCode(),
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