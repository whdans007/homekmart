<?php
// 간단한 테스트 버전
header('Content-Type: application/json; charset=utf-8');

// 기본 응답
$response = [
    'success' => true,
    'fileName' => 'test.xlsx',
    'headers' => ['바코드', '상품명', '단가', '판매가', '카테고리', '브랜드', '재고', '설명'],
    'data' => [
        ['8801234567890', '테스트 상품 1', '5000', '7000', '식품', '브랜드A', '100', '맛있는 상품'],
        ['8801234567891', '테스트 상품 2', '3000', '4500', '생활용품', '브랜드B', '50', '유용한 상품'],
        ['8801234567892', '테스트 상품 3', '10000', '15000', '전자제품', '브랜드C', '25', '편리한 상품']
    ],
    'method' => 'Test Mode',
    'note' => '테스트 모드에서 실행 중입니다.'
];

echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>