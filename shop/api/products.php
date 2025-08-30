<?php
/**
 * 상품 API - 간단한 버전
 * Updated: 2025-08-30 14:17
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    require_once '../../config/db_config.php';
    $conn = get_db_connection();
    
    if (!$conn) {
        throw new Exception("데이터베이스 연결 실패");
    }
    
    // 상품 조회
    $sql = "SELECT 
                p.id,
                p.barcode,
                p.name_kr,
                p.name_en,
                p.description,
                p.category_id,
                c.name as category_name,
                i.cost_price,
                i.selling_price,
                i.quantity,
                p.image_path,
                p.status
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN inventory i ON p.id = i.product_id
            WHERE p.status = 'active' OR p.status IS NULL
            ORDER BY p.name_kr ASC
            LIMIT 20";
    
    $result = $conn->query($sql);
    
    if (!$result) {
        throw new Exception("쿼리 실행 실패: " . $conn->error);
    }
    
    $products = [];
    while ($row = $result->fetch_assoc()) {
        $products[] = [
            'id' => (int)$row['id'],
            'barcode' => $row['barcode'],
            'name_kr' => $row['name_kr'],
            'name_en' => $row['name_en'],
            'description' => $row['description'],
            'category_id' => (int)$row['category_id'],
            'category_name' => $row['category_name'],
            'cost_price' => (float)$row['cost_price'],
            'selling_price' => (float)$row['selling_price'],
            'quantity' => (int)$row['quantity'],
            'image_path' => $row['image_path'],
            'image' => $row['image_path'] ? '/homekmart/admin/uploads/' . $row['image_path'] : null,
            'status' => $row['status']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'products' => $products,
        'total' => count($products),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => '상품 조회 중 오류가 발생했습니다.',
        'details' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>