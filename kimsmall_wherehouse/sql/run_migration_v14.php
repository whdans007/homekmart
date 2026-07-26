<?php
/**
 * KIM'S MALL 창고 DB 마이그레이션 v14
 * kw_inbound_batches.confirmed_at / confirmed_by 컬럼 추가
 * 접속: http://서버주소/sunset/kimsmall_wherehouse/sql/run_migration_v14.php
 * 주의: 실행 후 이 파일을 삭제하세요.
 */
require_once __DIR__ . '/../../config/db_config.php';

mysqli_report(MYSQLI_REPORT_OFF); // PHP 8.1+ 기본 예외 던짐 방지 — 기존 if/else 오류 처리 로직 유지
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');

$steps = [];

// 1. confirmed_at 컬럼 (v6의 is_confirmed 뒤에 위치)
$col1 = $conn->query("SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kw_inbound_batches' AND COLUMN_NAME = 'confirmed_at'");
$col1_exists = (int)$col1->fetch_assoc()['cnt'];
if ($col1_exists) {
    $steps[] = ['SKIP', 'kw_inbound_batches.confirmed_at 컬럼이 이미 존재합니다.'];
} elseif ($conn->query("ALTER TABLE kw_inbound_batches
    ADD COLUMN confirmed_at DATETIME DEFAULT NULL COMMENT '검수 확정 일시' AFTER is_confirmed")) {
    $steps[] = ['OK', 'kw_inbound_batches.confirmed_at 컬럼 추가 완료'];
} else {
    $steps[] = ['ERROR', 'confirmed_at 컬럼 추가 실패: ' . $conn->error];
}

// 2. confirmed_by 컬럼
$col2 = $conn->query("SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kw_inbound_batches' AND COLUMN_NAME = 'confirmed_by'");
$col2_exists = (int)$col2->fetch_assoc()['cnt'];
if ($col2_exists) {
    $steps[] = ['SKIP', 'kw_inbound_batches.confirmed_by 컬럼이 이미 존재합니다.'];
} elseif ($conn->query("ALTER TABLE kw_inbound_batches
    ADD COLUMN confirmed_by INT DEFAULT NULL COMMENT 'users.id 확정자' AFTER confirmed_at")) {
    $steps[] = ['OK', 'kw_inbound_batches.confirmed_by 컬럼 추가 완료'];
} else {
    $steps[] = ['ERROR', 'confirmed_by 컬럼 추가 실패: ' . $conn->error];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head><meta charset="UTF-8"><title>Migration v14</title>
<style>body{font-family:monospace;padding:2em;} .ok{color:green;} .skip{color:#888;} .error{color:red;}</style>
</head>
<body>
<h2>Migration v14 결과</h2>
<?php foreach ($steps as [$status, $msg]): ?>
<p class="<?php echo strtolower($status); ?>">[<?php echo $status; ?>] <?php echo htmlspecialchars($msg); ?></p>
<?php endforeach; ?>
<p><strong>완료 후 이 파일을 삭제하세요.</strong></p>
</body>
</html>
