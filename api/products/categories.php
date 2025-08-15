<?php
/**
 * 카테고리 목록 조회 API
 */

// GET 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Method not allowed', 405);
}

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 상품 수와 함께 카테고리 조회
    $include_counts = isset($_GET['include_counts']) && $_GET['include_counts'] === 'true';
    
    if ($include_counts) {
        $sql = "
            SELECT 
                c.id, c.name,
                COUNT(DISTINCT p.id) as product_count,
                COUNT(DISTINCT CASE WHEN i.quantity > 0 THEN p.id END) as available_count
            FROM categories c
            LEFT JOIN products p ON c.id = p.category_id 
                AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
            LEFT JOIN inventory i ON p.id = i.product_id
            GROUP BY c.id, c.name
            ORDER BY c.name ASC
        ";
    } else {
        $sql = "
            SELECT id, name
            FROM categories
            ORDER BY name ASC
        ";
    }
    
    $stmt = $pdo->query($sql);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 타입 변환
    foreach ($categories as &$category) {
        $category['id'] = (int)$category['id'];
        
        if ($include_counts) {
            $category['product_count'] = (int)$category['product_count'];
            $category['available_count'] = (int)$category['available_count'];
        }
    }
    
    $response_data = [
        'categories' => $categories,
        'total_count' => count($categories),
        'includes_product_counts' => $include_counts
    ];
    
    api_success($response_data, 'Categories retrieved successfully');
    
} catch (PDOException $e) {
    log_api_error("Database error retrieving categories", [
        'error' => $e->getMessage()
    ]);
    api_error('Failed to retrieve categories', 500, 'DATABASE_ERROR');
    
} catch (Exception $e) {
    log_api_error("Unexpected error retrieving categories", [
        'error' => $e->getMessage()
    ]);
    api_error('Failed to retrieve categories', 500, 'UNEXPECTED_ERROR');
}
?>