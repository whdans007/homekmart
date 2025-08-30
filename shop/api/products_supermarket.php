<?php
/**
 * 슈퍼마켓 상품 API
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$supermarket_products = [
    // 신선식품
    [
        'id' => 1,
        'name_kr' => '유기농 상추',
        'name_en' => 'Organic Lettuce',
        'category_id' => 1,
        'category_name' => '신선식품',
        'original_price' => 3500,
        'selling_price' => 2800,
        'discount_rate' => 20,
        'description' => '농약 없이 재배한 신선한 유기농 상추입니다',
        'origin' => '국산(경기)',
        'weight' => '200g',
        'expiry_info' => '냉장보관 7일',
        'is_fresh' => true,
        'is_deal' => true
    ],
    [
        'id' => 2,
        'name_kr' => '한우 등심',
        'name_en' => 'Korean Beef Sirloin',
        'category_id' => 1,
        'category_name' => '신선식품',
        'original_price' => 32000,
        'selling_price' => 28000,
        'discount_rate' => 12,
        'description' => '프리미엄 한우 등심으로 부드럽고 맛있습니다',
        'origin' => '국산(충남)',
        'weight' => '300g',
        'expiry_info' => '냉장보관 3일',
        'is_fresh' => true,
        'is_deal' => false
    ],
    [
        'id' => 3,
        'name_kr' => '제주 감귤',
        'name_en' => 'Jeju Tangerine',
        'category_id' => 1,
        'category_name' => '신선식품',
        'original_price' => 8000,
        'selling_price' => 6500,
        'discount_rate' => 19,
        'description' => '당도 높은 제주산 감귤, 비타민 C가 풍부합니다',
        'origin' => '국산(제주)',
        'weight' => '2kg',
        'expiry_info' => '실온보관 10일',
        'is_fresh' => true,
        'is_deal' => true
    ],
    [
        'id' => 4,
        'name_kr' => '노르웨이 연어',
        'name_en' => 'Norwegian Salmon',
        'category_id' => 1,
        'category_name' => '신선식품',
        'original_price' => 15000,
        'selling_price' => 12000,
        'discount_rate' => 20,
        'description' => '오메가3가 풍부한 노르웨이산 연어입니다',
        'origin' => '수입산(노르웨이)',
        'weight' => '400g',
        'expiry_info' => '냉장보관 2일',
        'is_fresh' => true,
        'is_deal' => true
    ],

    // 가공식품
    [
        'id' => 5,
        'name_kr' => '신라면',
        'name_en' => 'Shin Ramyun',
        'category_id' => 2,
        'category_name' => '가공식품',
        'original_price' => 4500,
        'selling_price' => 3600,
        'discount_rate' => 20,
        'description' => '매콤하고 시원한 맛의 대표 라면',
        'weight' => '120g × 5개',
        'expiry_info' => '실온보관 8개월',
        'is_fresh' => false,
        'is_deal' => true,
        'is_bulk' => true
    ],
    [
        'id' => 6,
        'name_kr' => '포카칩 오리지널',
        'name_en' => 'Poca Chip Original',
        'category_id' => 2,
        'category_name' => '가공식품',
        'original_price' => 2200,
        'selling_price' => 1800,
        'discount_rate' => 18,
        'description' => '바삭바삭한 식감의 감자칩',
        'weight' => '66g',
        'expiry_info' => '실온보관 6개월',
        'is_fresh' => false,
        'is_deal' => false,
        'is_new' => true
    ],
    [
        'id' => 7,
        'name_kr' => '코카콜라',
        'name_en' => 'Coca Cola',
        'category_id' => 2,
        'category_name' => '가공식품',
        'original_price' => 8000,
        'selling_price' => 6400,
        'discount_rate' => 20,
        'description' => '시원하고 상쾌한 콜라',
        'weight' => '355ml × 6캔',
        'expiry_info' => '실온보관 12개월',
        'is_fresh' => false,
        'is_deal' => true,
        'is_bulk' => true
    ],

    // 냉동식품
    [
        'id' => 8,
        'name_kr' => '비비고 왕교자',
        'name_en' => 'Bibigo King Dumpling',
        'category_id' => 3,
        'category_name' => '냉동식품',
        'original_price' => 7500,
        'selling_price' => 6000,
        'discount_rate' => 20,
        'description' => '고기와 야채가 듬뿍 들어간 왕교자',
        'weight' => '350g',
        'expiry_info' => '냉동보관 12개월',
        'is_fresh' => false,
        'is_deal' => true
    ],
    [
        'id' => 9,
        'name_kr' => '하겐다즈 바닐라',
        'name_en' => 'Haagen-Dazs Vanilla',
        'category_id' => 3,
        'category_name' => '냉동식품',
        'original_price' => 8500,
        'selling_price' => 7200,
        'discount_rate' => 15,
        'description' => '프리미엄 바닐라 아이스크림',
        'weight' => '473ml',
        'expiry_info' => '냉동보관 24개월',
        'is_fresh' => false,
        'is_deal' => false,
        'is_premium' => true
    ],

    // 생활용품
    [
        'id' => 10,
        'name_kr' => '다우니 섬유유연제',
        'name_en' => 'Downy Fabric Softener',
        'category_id' => 4,
        'category_name' => '생활용품',
        'original_price' => 6500,
        'selling_price' => 5200,
        'discount_rate' => 20,
        'description' => '오래 지속되는 향기로운 섬유유연제',
        'weight' => '1L',
        'is_fresh' => false,
        'is_deal' => true
    ],
    [
        'id' => 11,
        'name_kr' => '깨끗한나라 화장지',
        'name_en' => 'Clean & Paper Tissue',
        'category_id' => 4,
        'category_name' => '생활용품',
        'original_price' => 12000,
        'selling_price' => 9600,
        'discount_rate' => 20,
        'description' => '부드럽고 튼튼한 3겹 화장지',
        'weight' => '30롤',
        'is_fresh' => false,
        'is_deal' => true,
        'is_bulk' => true
    ],

    // 건강/미용
    [
        'id' => 12,
        'name_kr' => '종합비타민',
        'name_en' => 'Multi Vitamin',
        'category_id' => 5,
        'category_name' => '건강/미용',
        'original_price' => 25000,
        'selling_price' => 20000,
        'discount_rate' => 20,
        'description' => '하루 한 알로 건강 관리',
        'weight' => '60정',
        'expiry_info' => '실온보관 24개월',
        'is_fresh' => false,
        'is_deal' => false,
        'is_health' => true
    ],

    // 주방용품
    [
        'id' => 13,
        'name_kr' => '스테인리스 프라이팬',
        'name_en' => 'Stainless Steel Frying Pan',
        'category_id' => 6,
        'category_name' => '주방용품',
        'original_price' => 45000,
        'selling_price' => 36000,
        'discount_rate' => 20,
        'description' => '오래 사용할 수 있는 스테인리스 프라이팬',
        'size' => '28cm',
        'is_fresh' => false,
        'is_deal' => true
    ]
];

// 파라미터 처리
$category_filter = $_GET['category'] ?? null;
$type_filter = $_GET['type'] ?? null; // deals, new, fresh 등

// 필터링
$filtered_products = $supermarket_products;

if ($category_filter) {
    $filtered_products = array_filter($filtered_products, function($product) use ($category_filter) {
        return $product['category_id'] == $category_filter;
    });
}

if ($type_filter) {
    switch($type_filter) {
        case 'deals':
            $filtered_products = array_filter($filtered_products, function($product) {
                return isset($product['is_deal']) && $product['is_deal'];
            });
            break;
        case 'new':
            $filtered_products = array_filter($filtered_products, function($product) {
                return isset($product['is_new']) && $product['is_new'];
            });
            break;
        case 'fresh':
            $filtered_products = array_filter($filtered_products, function($product) {
                return isset($product['is_fresh']) && $product['is_fresh'];
            });
            break;
    }
}

// 배열 인덱스 재정렬
$filtered_products = array_values($filtered_products);

$response = [
    'success' => true,
    'products' => $filtered_products,
    'total' => count($filtered_products),
    'filter' => [
        'category' => $category_filter,
        'type' => $type_filter
    ],
    'timestamp' => date('Y-m-d H:i:s')
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>