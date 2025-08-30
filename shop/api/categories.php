<?php
/**
 * 카테고리 API - 완전한 버전
 * Updated: 2025-08-30 14:16
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
    
    // 카테고리 조회
    $sql = "SELECT 
                c.id,
                c.name,
                c.name_en,
                c.icon_class,
                c.default_margin_rate,
                COUNT(p.id) as product_count
            FROM categories c
            LEFT JOIN products p ON c.id = p.category_id
            GROUP BY c.id
            ORDER BY c.name ASC";
    
    $result = $conn->query($sql);
    
    if (!$result) {
        throw new Exception("쿼리 실행 실패: " . $conn->error);
    }
    
    $categories = [];
    while ($row = $result->fetch_assoc()) {
        $categories[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'name_kr' => $row['name'],
            'name_en' => $row['name_en'] ?: $row['name'],
            'icon_class' => $row['icon_class'] ?: 'fas fa-tag',
            'icon' => $row['icon_class'] ?: 'fas fa-tag',
            'default_margin_rate' => $row['default_margin_rate'],
            'product_count' => (int)$row['product_count']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'categories' => $categories,
        'total' => count($categories),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => '카테고리 조회 중 오류가 발생했습니다.',
        'details' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>