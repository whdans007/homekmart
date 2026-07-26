<?php
/**
 * KIM'S MALL 창고 DB 마이그레이션 v7
 * kw_products.requires_expiry 컬럼 추가
 * 접속: http://서버주소/sunset/kimsmall_wherehouse/sql/run_migration_v7.php
 * 주의: 실행 후 이 파일을 삭제하세요.
 */
require_once __DIR__ . '/../../config/db_config.php';

mysqli_report(MYSQLI_REPORT_OFF); // PHP 8.1+ 기본 예외 던짐 방지 — 기존 if/else 오류 처리 로직 유지
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');

$steps = [];

$col = $conn->query("SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kw_products' AND COLUMN_NAME = 'requires_expiry'");
$col_exists = (int)$col->fetch_assoc()['cnt'];

if ($col_exists) {
    $steps[] = ['SKIP', 'kw_products.requires_expiry 컬럼이 이미 존재합니다.'];
} elseif ($conn->query("ALTER TABLE kw_products
    ADD COLUMN requires_expiry TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '입고 시 유통기한 필수 입력 여부' AFTER min_stock")) {
    $steps[] = ['OK', 'kw_products.requires_expiry 컬럼 추가 완료'];
} else {
    $steps[] = ['ERROR', '컬럼 추가 실패: ' . $conn->error];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head><meta charset="UTF-8"><title>Migration v7</title>
<style>body{font-family:monospace;padding:2em;} .ok{color:green;} .skip{color:#888;} .error{color:red;}</style>
</head>
<body>
<h2>Migration v7 결과</h2>
<?php foreach ($steps as [$status, $msg]): ?>
<p class="<?php echo strtolower($status); ?>">[<?php echo $status; ?>] <?php echo htmlspecialchars($msg); ?></p>
<?php endforeach; ?>
<p><strong>완료 후 이 파일을 삭제하세요.</strong></p>
</body>
</html>
