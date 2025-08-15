<?php
// 가장 간단한 상품 API
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// 직접 데이터베이스 연결 정보 (테스트용)
$host = 'localhost';
$dbname = 'min';
$username = 'min1234';
$password = 'Min1234****';
$charset = 'utf8mb4';

try {
    // 간단한 PDO 연결
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=$charset", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 매우 간단한 쿼리
    $sql = "SELECT id, name FROM products LIMIT 5";
    $stmt = $pdo->query($sql);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 간단한 응답
    $response = [
        'success' => true,
        'count' => count($products),
        'products' => $products
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>