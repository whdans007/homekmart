<?php
header('Content-Type: application/json');

// cURL 확장 기능 확인
if (!function_exists('curl_init')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '서버 설정 오류: cURL 확장이 활성화되어 있지 않습니다.']);
    exit;
}

$barcode = $_GET['barcode'] ?? '';

if (empty($barcode)) {
    echo json_encode(['success' => false, 'message' => '바코드를 입력해주세요.']);
    exit;
}

// Open Food Facts API 엔드포인트
$apiUrl = "https://world.openfoodfacts.org/api/v0/product/{$barcode}.json";

try {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'MyWebApp/1.0'); // API 사용 시 User-Agent 명시 권장
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // SSL 인증서 검증 비활성화
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        throw new Exception('cURL Error: ' . curl_error($ch));
    }
    
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['status']) && $data['status'] == 1) {
        $product = $data['product'];
        
        // 필요한 정보만 추출하여 새로운 배열 생성
        $result = [
            'sku' => $product['code'] ?? $barcode,
            'name_ko' => $product['product_name_ko'] ?? $product['product_name'] ?? '',
            'name_en' => $product['product_name_en'] ?? '',
            'image_url' => $product['image_url'] ?? '',
            'description' => $product['generic_name'] ?? '',
            // Open Food Facts는 가격 정보를 제공하지 않으므로 비워둡니다.
            'cost_price' => '', 
            'selling_price' => ''
        ];
        
        // 영문명이 비어있고, 기본 상품명이 있다면 채워넣기
        if (empty($result['name_en']) && !empty($product['product_name'])) {
            $result['name_en'] = $product['product_name'];
        }

        echo json_encode(['success' => true, 'data' => $result]);

    } else {
        throw new Exception('해당 바코드의 상품을 웹에서 찾을 수 없습니다.');
    }

} catch (Exception $e) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
