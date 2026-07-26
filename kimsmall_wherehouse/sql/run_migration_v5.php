<?php
/**
 * KIM'S MALL 창고 DB 마이그레이션 v5
 * kw_inbound_batches 추가 + kw_inbound.batch_id 컬럼 추가
 * 접속: http://서버주소/sunset/kimsmall_wherehouse/sql/run_migration_v5.php
 * 주의: 실행 후 이 파일을 삭제하세요.
 */
require_once __DIR__ . '/../../config/db_config.php';

mysqli_report(MYSQLI_REPORT_OFF); // PHP 8.1+ 기본 예외 던짐 방지 — 기존 if/else 오류 처리 로직 유지
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');

$steps = [];

// 1. kw_inbound_batches 테이블
$tbl = $conn->query("SHOW TABLES LIKE 'kw_inbound_batches'");
if ($tbl && $tbl->num_rows > 0) {
    $steps[] = ['SKIP', 'kw_inbound_batches 테이블이 이미 존재합니다.'];
} elseif ($conn->query("
    CREATE TABLE kw_inbound_batches (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        inbound_date DATE NOT NULL,
        supplier_id  INT,
        notes        TEXT,
        created_by   INT,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
")) {
    $steps[] = ['OK', 'kw_inbound_batches 테이블 생성 완료'];
} else {
    $steps[] = ['ERROR', 'kw_inbound_batches 생성 실패: ' . $conn->error];
}

// 2. kw_inbound.batch_id 컬럼
$col = $conn->query("SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kw_inbound' AND COLUMN_NAME = 'batch_id'");
$col_exists = (int)$col->fetch_assoc()['cnt'];
if ($col_exists) {
    $steps[] = ['SKIP', 'kw_inbound.batch_id 컬럼이 이미 존재합니다.'];
} elseif ($conn->query("ALTER TABLE kw_inbound ADD COLUMN batch_id INT NULL AFTER id")) {
    $steps[] = ['OK', 'kw_inbound.batch_id 컬럼 추가 완료'];
} else {
    $steps[] = ['ERROR', 'batch_id 컬럼 추가 실패: ' . $conn->error];
}

// 3. FK 추가 (이미 있으면 skip)
$fk = $conn->query("SELECT COUNT(*) AS cnt FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kw_inbound' AND CONSTRAINT_NAME = 'fk_kw_inbound_batch'");
$fk_exists = (int)$fk->fetch_assoc()['cnt'];
if ($fk_exists) {
    $steps[] = ['SKIP', 'FK fk_kw_inbound_batch가 이미 존재합니다.'];
} elseif ($conn->query("ALTER TABLE kw_inbound ADD CONSTRAINT fk_kw_inbound_batch FOREIGN KEY (batch_id) REFERENCES kw_inbound_batches(id) ON DELETE SET NULL")) {
    $steps[] = ['OK', 'FK fk_kw_inbound_batch 추가 완료'];
} else {
    $steps[] = ['ERROR', 'FK 추가 실패: ' . $conn->error];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head><meta charset="UTF-8"><title>Migration v5</title>
<style>body{font-family:monospace;padding:2em;} .ok{color:green;} .skip{color:#888;} .error{color:red;}</style>
</head>
<body>
<h2>Migration v5 결과</h2>
<?php foreach ($steps as [$status, $msg]): ?>
<p class="<?php echo strtolower($status); ?>">[<?php echo $status; ?>] <?php echo htmlspecialchars($msg); ?></p>
<?php endforeach; ?>
<p><strong>완료 후 이 파일을 삭제하세요.</strong></p>
</body>
</html>
