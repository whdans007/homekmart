<?php
/**
 * 점포 목록 API
 * 활성화된 점포 목록과 기본 정보를 반환
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// OPTIONS 요청 처리 (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    require_once '../../config/db_config.php';
    $conn = get_db_connection();
    
    if (!$conn) {
        throw new Exception("데이터베이스 연결 실패");
    }
    
    // 활성화된 점포 목록 조회
    $sql = "SELECT 
                s.id,
                s.name,
                s.address,
                s.phone,
                s.manager,
                s.is_active,
                COUNT(sp.product_id) as product_count,
                COUNT(CASE WHEN sp.is_featured = 1 THEN 1 END) as featured_count
            FROM stores s
            LEFT JOIN store_products sp ON s.id = sp.store_id AND sp.is_available = 1
            WHERE s.is_active = 1
            GROUP BY s.id, s.name, s.address, s.phone, s.manager, s.is_active
            ORDER BY s.id ASC";
    
    $result = $conn->query($sql);
    
    if (!$result) {
        throw new Exception("쿼리 실행 실패: " . $conn->error);
    }
    
    $stores = [];
    while ($row = $result->fetch_assoc()) {
        $stores[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'address' => $row['address'],
            'phone' => $row['phone'],
            'manager' => $row['manager'],
            'is_active' => (bool)$row['is_active'],
            'product_count' => (int)$row['product_count'],
            'featured_count' => (int)$row['featured_count'],
            'status' => $row['is_active'] ? 'active' : 'inactive'
        ];
    }
    
    $response = [
        'success' => true,
        'stores' => $stores,
        'total' => count($stores),
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => '점포 정보를 불러오는데 실패했습니다.',
        'details' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>