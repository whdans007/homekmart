<?php
/**
 * 성공 패턴 기반 상품 API
 * step_by_step.php 패턴 활용
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    // step_by_step.php와 동일한 방식으로 설정 로드
    require_once __DIR__ . '/config/db_config.php';
    
    // step_by_step.php와 동일한 방식으로 DB 연결
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 파라미터 처리
    $limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 10;
    
    // 간단한 상품 조회 쿼리
    $sql = "SELECT p.id, p.name, p.description, p.barcode FROM products p WHERE (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00') ORDER BY p.id ASC LIMIT " . $limit;
    
    $stmt = $pdo->query($sql);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 상품 데이터 가공
    $processed_products = [];
    foreach ($products as $product) {
        $processed_products[] = [
            'id' => (int)$product['id'],
            'name' => $product['name'],
            'description' => $product['description'],
            'barcode' => $product['barcode'],
            'currency' => 'PHP',
            'available_for_delivery' => true
        ];
    }
    
    // 응답 구성 (step_by_step.php와 유사한 구조)
    $response = [
        'success' => true,
        'message' => 'Products retrieved successfully',
        'data' => [
            'products' => $processed_products,
            'count' => count($processed_products),
            'limit' => $limit,
            'currency' => 'PHP'
        ],
        'timestamp' => date('Y-m-d H:i:s'),
        'server_info' => [
            'php_version' => phpversion(),
            'method' => $_SERVER['REQUEST_METHOD']
        ]
    ];
    
    // JSON 출력
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    // step_by_step.php와 유사한 에러 처리
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>