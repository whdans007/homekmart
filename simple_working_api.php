<?php
/**
 * 가장 간단한 작동 API
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// 하드코딩된 샘플 데이터 (DB 연결 없이)
$sample_products = [
    [
        'id' => 1,
        'name' => 'Samsung Galaxy S24',
        'description' => 'Latest smartphone from Samsung',
        'price' => ['min' => 45000.00, 'max' => 55000.00, 'currency' => 'PHP', 'formatted' => '₱45,000 - ₱55,000'],
        'available_for_delivery' => true
    ],
    [
        'id' => 2, 
        'name' => 'Apple iPhone 15',
        'description' => 'Premium iPhone model',
        'price' => ['min' => 60000.00, 'max' => 70000.00, 'currency' => 'PHP', 'formatted' => '₱60,000 - ₱70,000'],
        'available_for_delivery' => true
    ],
    [
        'id' => 3,
        'name' => 'Xiaomi Mi 14',
        'description' => 'High-performance Android phone',
        'price' => ['min' => 25000.00, 'max' => 35000.00, 'currency' => 'PHP', 'formatted' => '₱25,000 - ₱35,000'],
        'available_for_delivery' => true
    ]
];

$response = [
    'success' => true,
    'message' => 'Sample products for Philippines delivery',
    'data' => [
        'products' => $sample_products,
        'count' => count($sample_products),
        'currency' => [
            'code' => 'PHP',
            'symbol' => '₱',
            'name' => 'Philippine Peso'
        ],
        'delivery_info' => [
            'available_areas' => ['Metro Manila', 'Cebu', 'Davao'],
            'delivery_fee' => 50.00,
            'estimated_time' => '1-2 hours'
        ]
    ],
    'timestamp' => date('Y-m-d H:i:s'),
    'api_version' => '1.0'
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>