<?php
// 공개 테스트 APK 다운로드. 사용자 입력 경로를 받지 않고 지정된 파일만 제공한다.
$apk_path = realpath(__DIR__ . '/../mall-app/dist/HOME-K-MART-test.apk');
$allowed_path = realpath(__DIR__ . '/../mall-app/dist');

if ($apk_path === false || $allowed_path === false || dirname($apk_path) !== $allowed_path || !is_readable($apk_path)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo '앱 설치 파일을 준비 중입니다. 잠시 후 다시 시도해주세요.';
    exit;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="HOME-K-MART-test.apk"');
header('Content-Length: ' . filesize($apk_path));
header('Cache-Control: private, no-transform, max-age=0');
header('X-Content-Type-Options: nosniff');

$handle = fopen($apk_path, 'rb');
if ($handle === false) {
    http_response_code(503);
    exit;
}
fpassthru($handle);
fclose($handle);
exit;
