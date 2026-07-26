<?php
/**
 * KIM'S MALL 창고 DB 마이그레이션 v4
 * kw_products: barcode_multi 컬럼 제거
 * 접속: http://서버주소/sunset/kimsmall_wherehouse/sql/run_migration_v4.php
 * 주의: 실행 후 이 파일을 삭제하세요.
 */
require_once __DIR__ . '/../../config/db_config.php';

mysqli_report(MYSQLI_REPORT_OFF); // PHP 8.1+ 기본 예외 던짐 방지 — 기존 if/else 오류 처리 로직 유지
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');

// 컬럼 존재 여부 확인
$col_check = $conn->query(
    "SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kw_products' AND COLUMN_NAME = 'barcode_multi'"
);
$exists = (int)$col_check->fetch_assoc()['cnt'];

if (!$exists) {
    $status = 'SKIP';
    $msg    = 'barcode_multi 컬럼이 이미 존재하지 않습니다.';
} elseif ($conn->query("ALTER TABLE kw_products DROP COLUMN barcode_multi")) {
    $status = 'OK';
    $msg    = 'barcode_multi 컬럼 제거 완료';
} else {
    $status = 'ERROR';
    $msg    = $conn->error;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head><meta charset="UTF-8"><title>Migration v4</title>
<style>body{font-family:monospace;padding:2em;} .ok{color:green;} .skip{color:#888;} .err{color:red;}</style>
</head>
<body>
<h2>Migration v4 결과</h2>
<p class="<?php echo strtolower($status); ?>">[<?php echo $status; ?>] <?php echo htmlspecialchars($msg); ?></p>
<p><strong>완료 후 이 파일을 삭제하세요.</strong></p>
</body>
</html>
