#!/usr/bin/env php
<?php
/**
 * 배달 주소 API CLI 테스트 스크립트
 */

$BASE_URL = 'https://192-168-1-123.philsarang.direct.quickconnect.to/homekmart/api/addresses/';
$USER_ID = 13;

echo "========================================\n";
echo "배달 주소 API 테스트 시작\n";
echo "========================================\n\n";

// 1. 주소 목록 조회 (초기 상태)
echo "1. 주소 목록 조회 (초기 상태)\n";
echo "GET {$BASE_URL}list.php?user_id={$USER_ID}\n";
$response = file_get_contents("{$BASE_URL}list.php?user_id={$USER_ID}");
$data = json_decode($response, true);
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
echo "결과: " . ($data['success'] ? "✅ 성공" : "❌ 실패") . "\n\n";
sleep(1);

// 2. 새 주소 생성 (일반 주소)
echo "2. 새 주소 생성 (일반 주소)\n";
$addressData = [
    'user_id' => $USER_ID,
    'address_name' => '집',
    'house_number' => '123',
    'street' => 'Rizal Avenue',
    'barangay' => 'Poblacion',
    'city' => 'Makati',
    'province' => 'Metro Manila',
    'postal_code' => '1200',
    'detailed_address' => '2층 오른쪽 문',
    'landmark' => '세븐일레븐 옆',
    'delivery_notes' => '문 앞에 놔주세요',
    'latitude' => 14.5547,
    'longitude' => 121.0244,
    'is_default' => false
];

$options = [
    'http' => [
        'header'  => "Content-Type: application/json\r\n",
        'method'  => 'POST',
        'content' => json_encode($addressData),
    ],
];
$context = stream_context_create($options);
$response = file_get_contents("{$BASE_URL}create.php", false, $context);
$data = json_decode($response, true);
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
echo "결과: " . ($data['success'] ? "✅ 성공" : "❌ 실패") . "\n";
$address1_id = $data['data']['id'] ?? null;
echo "생성된 주소 ID: {$address1_id}\n\n";
sleep(1);

// 3. 기본 주소로 생성
echo "3. 기본 주소로 생성\n";
$defaultAddressData = [
    'user_id' => $USER_ID,
    'address_name' => '회사',
    'house_number' => '456',
    'street' => 'Ayala Avenue',
    'barangay' => 'Bel-Air',
    'city' => 'Makati',
    'province' => 'Metro Manila',
    'postal_code' => '1209',
    'detailed_address' => '5층 501호',
    'landmark' => '스타벅스 건물',
    'delivery_notes' => '경비실에 맡겨주세요',
    'latitude' => 14.5512,
    'longitude' => 121.0270,
    'is_default' => true
];

$options['http']['content'] = json_encode($defaultAddressData);
$context = stream_context_create($options);
$response = file_get_contents("{$BASE_URL}create.php", false, $context);
$data = json_decode($response, true);
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
echo "결과: " . ($data['success'] ? "✅ 성공" : "❌ 실패") . "\n";
$address2_id = $data['data']['id'] ?? null;
echo "생성된 기본 주소 ID: {$address2_id}\n\n";
sleep(1);

// 4. 주소 목록 조회 (2개 주소 확인)
echo "4. 주소 목록 조회 (2개 주소 확인)\n";
$response = file_get_contents("{$BASE_URL}list.php?user_id={$USER_ID}");
$data = json_decode($response, true);
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
echo "결과: " . ($data['success'] ? "✅ 성공" : "❌ 실패") . "\n";
echo "주소 개수: " . count($data['data'] ?? []) . "\n\n";
sleep(1);

// 5. 주소 수정
if ($address1_id) {
    echo "5. 주소 수정 (ID: {$address1_id})\n";
    $updateData = [
        'address_name' => '집 (수정됨)',
        'delivery_notes' => '초인종 눌러주세요',
        'landmark' => 'GS25 편의점 맞은편'
    ];

    $options = [
        'http' => [
            'header'  => "Content-Type: application/json\r\n",
            'method'  => 'PUT',
            'content' => json_encode($updateData),
        ],
    ];
    $context = stream_context_create($options);
    $response = file_get_contents("{$BASE_URL}update.php?id={$address1_id}&user_id={$USER_ID}", false, $context);
    $data = json_decode($response, true);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    echo "결과: " . ($data['success'] ? "✅ 성공" : "❌ 실패") . "\n\n";
    sleep(1);
}

// 6. 기본 주소 변경
if ($address1_id) {
    echo "6. 기본 주소 변경 (ID: {$address1_id}로 변경)\n";
    $options = [
        'http' => [
            'header'  => "Content-Type: application/json\r\n",
            'method'  => 'POST',
        ],
    ];
    $context = stream_context_create($options);
    $response = file_get_contents("{$BASE_URL}set-default.php?id={$address1_id}&user_id={$USER_ID}", false, $context);
    $data = json_decode($response, true);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    echo "결과: " . ($data['success'] ? "✅ 성공" : "❌ 실패") . "\n\n";
    sleep(1);
}

// 7. 주소 목록 재조회 (기본 주소 변경 확인)
echo "7. 주소 목록 재조회 (기본 주소 변경 확인)\n";
$response = file_get_contents("{$BASE_URL}list.php?user_id={$USER_ID}");
$data = json_decode($response, true);
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
echo "결과: " . ($data['success'] ? "✅ 성공" : "❌ 실패") . "\n\n";
sleep(1);

// 8. 주소 삭제
if ($address2_id) {
    echo "8. 주소 삭제 (ID: {$address2_id})\n";
    $options = [
        'http' => [
            'method'  => 'DELETE',
        ],
    ];
    $context = stream_context_create($options);
    $response = file_get_contents("{$BASE_URL}delete.php?id={$address2_id}&user_id={$USER_ID}", false, $context);
    $data = json_decode($response, true);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    echo "결과: " . ($data['success'] ? "✅ 성공" : "❌ 실패") . "\n\n";
    sleep(1);
}

// 9. 최종 주소 목록 조회
echo "9. 최종 주소 목록 조회 (삭제 후 확인)\n";
$response = file_get_contents("{$BASE_URL}list.php?user_id={$USER_ID}");
$data = json_decode($response, true);
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
echo "결과: " . ($data['success'] ? "✅ 성공" : "❌ 실패") . "\n";
echo "남은 주소 개수: " . count($data['data'] ?? []) . "\n\n";

echo "========================================\n";
echo "배달 주소 API 테스트 완료\n";
echo "========================================\n";
?>
