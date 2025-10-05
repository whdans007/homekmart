<?php
/**
 * 주소 목록 조회 간단 테스트
 */

echo "주소 목록 조회 테스트\n";
echo "URL: https://192-168-1-123.philsarang.direct.quickconnect.to/homekmart/api/addresses/list.php?user_id=13\n\n";

$url = 'https://192-168-1-123.philsarang.direct.quickconnect.to/homekmart/api/addresses/list.php?user_id=13';

$context = stream_context_create([
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
    ],
]);

$response = @file_get_contents($url, false, $context);

if ($response === false) {
    echo "❌ 요청 실패\n";
    echo "에러: " . error_get_last()['message'] . "\n";
} else {
    echo "✅ 응답 수신\n\n";
    $data = json_decode($response, true);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}
?>
