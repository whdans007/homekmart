<?php
// 디버깅을 위한 간단한 테스트 파일
header('Content-Type: application/json; charset=utf-8');

$debug_info = [
    'php_version' => PHP_VERSION,
    'session_status' => session_status(),
    'post_method' => $_SERVER['REQUEST_METHOD'] === 'POST',
    'files_uploaded' => isset($_FILES['excel_file']),
    'com_available' => class_exists('COM'),
    'os' => PHP_OS,
    'timestamp' => date('Y-m-d H:i:s')
];

// 세션 시작
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$debug_info['session_id'] = session_id();
$debug_info['session_data'] = $_SESSION ?? [];

// 파일 정보
if (isset($_FILES['excel_file'])) {
    $debug_info['file_info'] = [
        'name' => $_FILES['excel_file']['name'],
        'type' => $_FILES['excel_file']['type'],
        'size' => $_FILES['excel_file']['size'],
        'error' => $_FILES['excel_file']['error'],
        'tmp_name' => $_FILES['excel_file']['tmp_name']
    ];
}

// 샘플 데이터 반환
echo json_encode([
    'success' => true,
    'debug' => $debug_info,
    'fileName' => $_FILES['excel_file']['name'] ?? 'test.xlsx',
    'headers' => ['디버그 모드', '테스트 데이터', '상태', '정보'],
    'data' => [
        ['디버깅 중', 'PHP ' . PHP_VERSION, 'OK', '정상 작동'],
        ['세션 ID', substr(session_id(), 0, 8) . '...', 'OK', '세션 활성'],
        ['파일 업로드', isset($_FILES['excel_file']) ? 'YES' : 'NO', 'INFO', '파일 상태']
    ],
    'method' => 'Debug Mode',
    'note' => '디버깅 모드에서 실행 중입니다. 실제 Excel 파일은 처리되지 않습니다.'
]);
?>