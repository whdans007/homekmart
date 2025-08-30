<?php
/**
 * 임시 카테고리 API (fake 데이터)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

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
    ],
    [
        'id' => 4,
        'name_kr' => '식품',
        'name_en' => 'Food',
        'icon_class' => 'fas fa-apple-alt',
        'icon' => 'fas fa-apple-alt',
        'product_count' => 12
    ],
    [
        'id' => 5,
        'name_kr' => '뷰티',
        'name_en' => 'Beauty',
        'icon_class' => 'fas fa-heart',
        'icon' => 'fas fa-heart',
        'product_count' => 7
    ],
    [
        'id' => 6,
        'name_kr' => '스포츠',
        'name_en' => 'Sports',
        'icon_class' => 'fas fa-running',
        'icon' => 'fas fa-running',
        'product_count' => 4
    ]
];

$response = [
    'success' => true,
    'categories' => $fake_categories,
    'total' => count($fake_categories),
    'source' => 'fake_data',
    'timestamp' => date('Y-m-d H:i:s')
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>