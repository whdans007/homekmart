<?php
/**
 * 단순 테스트 API (DB 연결 없이)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// 가짜 데이터 생성
$fake_products = [
    [
        'id' => 1,
        'name_kr' => '테스트 상품 1',
        'name_en' => 'Test Product 1',
        'selling_price' => 10000,
        'status' => 'active',
        'category_id' => 1,
        'category_name' => '전자제품',
        'image' => null
    ],
    [
        'id' => 2,
        'name_kr' => '테스트 상품 2',
        'name_en' => 'Test Product 2',
        'selling_price' => 20000,
        'status' => 'active',
        'category_id' => 2,
        'category_name' => '의류',
        'image' => null
    ]
];

$fake_categories = [
    [
        'id' => 1,
        'name_kr' => '전자제품',
        'name_en' => 'Electronics',
        'icon_class' => 'fas fa-mobile-alt',
        'icon' => 'fas fa-mobile-alt',
        'product_count' => 5
    ],
    [
        'id' => 2,
        'name_kr' => '의류',
        'name_en' => 'Clothing',
        'icon_class' => 'fas fa-tshirt',
        'icon' => 'fas fa-tshirt',
        'product_count' => 3
    ],
    [
        'id' => 3,
        'name_kr' => '생활용품',
        'name_en' => 'Living',
        'icon_class' => 'fas fa-home',
        'icon' => 'fas fa-home',
        'product_count' => 8
    ]
];

// URL 파라미터 확인
$request_uri = $_SERVER['REQUEST_URI'];

if (strpos($request_uri, 'categories') !== false) {
    // 카테고리 응답
    $response = [
        'success' => true,
        'categories' => $fake_categories,
        'total' => count($fake_categories),
        'source' => 'fake_data',
        'timestamp' => date('Y-m-d H:i:s')
    ];
} else {
    // 상품 응답 (기본값)
    $response = [
        'success' => true,
        'products' => $fake_products,
        'total' => count($fake_products),
        'source' => 'fake_data',
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>