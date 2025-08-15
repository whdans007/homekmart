<?php
/**
 * 브랜드 목록 조회 API
 */

// GET 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Method not allowed', 405);
}

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 상품 수와 함께 브랜드 조회
    $include_counts = isset($_GET['include_counts']) && $_GET['include_counts'] === 'true';
    
    if ($include_counts) {
        $sql = "
            SELECT 
                b.id, b.name_ko as name, b.name_en as name_en,
                COUNT(DISTINCT p.id) as product_count,
                COUNT(DISTINCT CASE WHEN i.quantity > 0 THEN p.id END) as available_count
            FROM brands b
            LEFT JOIN products p ON b.id = p.brand_id 
                AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
            LEFT JOIN inventory i ON p.id = i.product_id
            GROUP BY b.id, b.name_ko, b.name_en
            ORDER BY b.name_ko ASC
        ";
    } else {
        $sql = "
            SELECT id, name_ko as name, name_en
            FROM brands
            ORDER BY name_ko ASC
        ";
    }
    
    $stmt = $pdo->query($sql);
    $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 타입 변환
    foreach ($brands as &$brand) {
        $brand['id'] = (int)$brand['id'];
        
        if ($include_counts) {
            $brand['product_count'] = (int)$brand['product_count'];
            $brand['available_count'] = (int)$brand['available_count'];
        }
    }
    
    $response_data = [
        'brands' => $brands,
        'total_count' => count($brands),
        'includes_product_counts' => $include_counts
    ];
    
    api_success($response_data, 'Brands retrieved successfully');
    
} catch (PDOException $e) {
    log_api_error("Database error retrieving brands", [
        'error' => $e->getMessage()
    ]);
    api_error('Failed to retrieve brands', 500, 'DATABASE_ERROR');
    
} catch (Exception $e) {
    log_api_error("Unexpected error retrieving brands", [
        'error' => $e->getMessage()
    ]);
    api_error('Failed to retrieve brands', 500, 'UNEXPECTED_ERROR');
}
?>