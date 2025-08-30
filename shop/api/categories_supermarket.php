<?php
/**
 * 슈퍼마켓 카테고리 API
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$supermarket_categories = [
    [
        'id' => 1,
        'name_kr' => '신선식품',
        'name_en' => 'Fresh Foods',
        'icon_class' => 'fas fa-leaf',
        'icon' => 'fas fa-leaf',
        'product_count' => 45,
        'description' => '채소, 과일, 육류, 해산물',
        'color' => '#4CAF50'
    ],
    [
        'id' => 2,
        'name_kr' => '가공식품',
        'name_en' => 'Processed Foods',
        'icon_class' => 'fas fa-cookie-bite',
        'icon' => 'fas fa-cookie-bite',
        'product_count' => 78,
        'description' => '라면, 과자, 음료, 통조림',
        'color' => '#FF9800'
    ],
    [
        'id' => 3,
        'name_kr' => '냉동식품',
        'name_en' => 'Frozen Foods',
        'icon_class' => 'fas fa-snowflake',
        'icon' => 'fas fa-snowflake',
        'product_count' => 32,
        'description' => '아이스크림, 만두, 냉동고기',
        'color' => '#2196F3'
    ],
    [
        'id' => 4,
        'name_kr' => '생활용품',
        'name_en' => 'Household Items',
        'icon_class' => 'fas fa-home',
        'icon' => 'fas fa-home',
        'product_count' => 56,
        'description' => '세제, 화장지, 청소용품',
        'color' => '#9C27B0'
    ],
    [
        'id' => 5,
        'name_kr' => '건강/미용',
        'name_en' => 'Health & Beauty',
        'icon_class' => 'fas fa-heart',
        'icon' => 'fas fa-heart',
        'product_count' => 23,
        'description' => '비타민, 화장품, 샴푸',
        'color' => '#E91E63'
    ],
    [
        'id' => 6,
        'name_kr' => '주방용품',
        'name_en' => 'Kitchen Supplies',
        'icon_class' => 'fas fa-utensils',
        'icon' => 'fas fa-utensils',
        'product_count' => 41,
        'description' => '조리기구, 그릇, 도시락',
        'color' => '#607D8B'
    ]
];

$response = [
    'success' => true,
    'categories' => $supermarket_categories,
    'total' => count($supermarket_categories),
    'type' => 'supermarket',
    'timestamp' => date('Y-m-d H:i:s')
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>