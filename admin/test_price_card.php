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

// 간단한 테스트 응답
try {
    // POST 데이터 읽기
    $input = json_decode(file_get_contents('php://input'), true);
    $ids = $input['ids'] ?? [];
    
    // 테스트 데이터 반환
    $products = [];
    foreach ($ids as $id) {
        $products[] = [
            'id' => $id,
            'sku' => '1234567890123',
            'product_name_en' => 'Test Product ' . $id,
            'product_name_ko' => '테스트 상품 ' . $id,
            'selling_price' => '10,000'
        ];
    }
    
    // JSON 응답
    echo json_encode([
        'success' => true,
        'products' => $products
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error occurred'
    ]);
}
exit;
?>