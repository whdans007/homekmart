<?php
// 모든 출력 버퍼 정리
while (ob_get_level()) {
    ob_end_clean();
}

// 오류 출력 완전 차단
error_reporting(0);
ini_set('display_errors', 0);

// JSON 헤더 설정
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// 세션 시작
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

try {
    // 로그인 확인
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Not logged in');
    }
    
    // POST 데이터 읽기
    $input = json_decode(file_get_contents('php://input'), true);
    $ids = $input['ids'] ?? [];
    
    if (empty($ids)) {
        throw new Exception('No IDs provided');
    }
    
    // 데이터베이스 연결
    require_once dirname(__FILE__) . '/../config/db_config.php';
    $conn = get_db_connection();
    
    if (!$conn) {
        throw new Exception('Database connection failed');
    }
    
    // ID 배열 안전 처리
    $ids = array_map('intval', $ids);
    if (empty($ids)) {
        throw new Exception('Invalid IDs');
    }
    
    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
    
    // 쿼리 - 날짜 조건 없이 ID로만 조회
    // products 테이블과 조인하여 상품 정보 가져오기
    $query = "
        SELECT 
            pch.id,
            p.sku,
            p.name_en as product_name_en,
            p.name_ko as product_name_ko,
            pch.new_selling_price
        FROM price_change_history pch
        LEFT JOIN products p ON pch.product_id = p.id
        WHERE pch.id IN ($placeholders)
    ";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception('Query preparation failed');
    }
    
    // 파라미터 바인딩
    $types = str_repeat('i', count($ids));
    $stmt->bind_param($types, ...$ids);
    
    if (!$stmt->execute()) {
        throw new Exception('Query execution failed');
    }
    
    $result = $stmt->get_result();
    $products = [];
    
    while ($row = $result->fetch_assoc()) {
        $products[] = [
            'id' => $row['id'],
            'sku' => $row['sku'] ?: 'N/A',
            'product_name_en' => $row['product_name_en'] ?: '',
            'product_name_ko' => $row['product_name_ko'] ?: '',
            'selling_price' => number_format($row['new_selling_price'] ?: 0)
        ];
    }
    
    $stmt->close();
    $conn->close();
    
    // 성공 응답
    echo json_encode([
        'success' => true,
        'products' => $products,
        'debug' => [
            'ids_received' => $ids,
            'count' => count($products)
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
exit;
?>