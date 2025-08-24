<?php
require_once 'partials/header.php';
require_permission('settings', 'index.php');

// 출력 버퍼 정리 (header.php에서 시작된 ob_start 때문)
if (ob_get_level()) {
    ob_end_clean();
}

// 파일명 파라미터 확인
$filename = $_GET['file'] ?? '';
if (empty($filename)) {
    http_response_code(400);
    die('파일명이 지정되지 않았습니다.');
}

// 파일명 보안 검사
$filename = basename($filename); // 경로 순회 방지
if (!preg_match('/^homekmart_backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.(sql|sql\.gz)$/', $filename)) {
    http_response_code(400);
    die('잘못된 파일명입니다.');
}

// 백업 디렉토리 경로
$backup_dir = __DIR__ . '/../backups/';
$filepath = $backup_dir . $filename;

// 파일 존재 확인
if (!file_exists($filepath) || !is_file($filepath)) {
    http_response_code(404);
    die('요청한 백업 파일을 찾을 수 없습니다.');
}

// 파일 정보
$filesize = filesize($filepath);
$file_ext = pathinfo($filename, PATHINFO_EXTENSION);

// MIME 타입 설정
$mime_type = 'application/octet-stream';
if ($file_ext === 'sql') {
    $mime_type = 'application/sql';
} elseif ($file_ext === 'gz') {
    $mime_type = 'application/gzip';
}

// 다운로드 헤더 설정
header('Content-Type: ' . $mime_type);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . $filesize);
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Expires: 0');

// 대용량 파일을 위한 버퍼링 설정
if ($filesize > 10 * 1024 * 1024) { // 10MB 이상
    // 큰 파일의 경우 청크 단위로 읽기
    $handle = fopen($filepath, 'rb');
    if ($handle === false) {
        http_response_code(500);
        die('파일을 읽을 수 없습니다.');
    }
    
    // 8KB 단위로 파일 전송
    $chunk_size = 8192;
    while (!feof($handle)) {
        $chunk = fread($handle, $chunk_size);
        echo $chunk;
        flush();
    }
    fclose($handle);
} else {
    // 작은 파일의 경우 한 번에 전송
    readfile($filepath);
}

// 다운로드 로그 기록 (선택사항)
error_log("Backup file downloaded: $filename by user ID: " . ($_SESSION['user_id'] ?? 'unknown'));

exit;
?>