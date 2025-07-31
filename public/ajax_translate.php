<?php
header('Content-Type: application/json');

// 1. cURL 확장 기능이 설치 및 활성화되어 있는지 확인합니다.
if (!function_exists('curl_init')) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Server configuration error: The cURL extension for PHP is not enabled. Please enable it in your php.ini file.'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$text = $_POST['text'] ?? '';
$target_lang = $_POST['target_lang'] ?? 'en'; // 기본값: 영어
$source_lang = 'ko'; // 소스 언어: 한국어

if (empty($text)) {
    echo json_encode(['success' => false, 'message' => 'No text to translate.']);
    exit;
}

// MyMemory API 엔드포인트
$apiUrl = 'https://api.mymemory.translated.net/get';

// API 요청 파라미터
$params = [
    'q' => $text,
    'langpair' => $source_lang . '|' . $target_lang,
];

$url = $apiUrl . '?' . http_build_query($params);

try {
    // cURL을 사용하여 API 호출
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 로컬 환경 등에서 SSL 인증서 문제를 방지
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // 10초 연결 타임아웃
    curl_setopt($ch, CURLOPT_TIMEOUT, 15); // 15초 전체 실행 타임아웃
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        throw new Exception('cURL Error: ' . curl_error($ch));
    }
    
    curl_close($ch);

    if ($http_code !== 200) {
        throw new Exception('API request failed with status code ' . $http_code . '. Response: ' . $response);
    }

    $data = json_decode($response, true);

    if (isset($data['responseStatus']) && $data['responseStatus'] == 200) {
        echo json_encode([
            'success' => true,
            'translated_text' => $data['responseData']['translatedText']
        ]);
    } else {
        throw new Exception($data['responseDetails'] ?? 'Unknown API error');
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred during translation: ' . $e->getMessage()]);
}
