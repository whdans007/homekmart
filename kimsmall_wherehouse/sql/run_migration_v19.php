<?php
/**
 * KIM'S MALL 창고 DB 마이그레이션 v19
 * kw_orders에 deleted_at / deleted_by 컬럼 추가 (소프트 삭제/복구)
 * 접속: http://서버주소/kimsmall_wherehouse/sql/run_migration_v19.php
 * 주의: 실행 후 이 파일을 삭제하세요.
 */
require_once __DIR__ . '/../../config/db_config.php';

mysqli_report(MYSQLI_REPORT_OFF); // PHP 8.1+ 기본 예외 던짐 방지 — 기존 if/else 오류 처리 로직 유지
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');

$steps = [];

// 현재 컬럼 확인
$row = $conn->query("
    SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'kw_orders'
      AND COLUMN_NAME  = 'deleted_at'
")->fetch_assoc();

if ((int)$row['cnt'] > 0) {
    $steps[] = ['SKIP', "kw_orders.deleted_at 컬럼이 이미 존재합니다."];
} elseif ($conn->query("
    ALTER TABLE kw_orders
        ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT '소프트 삭제 시각 (NULL=정상)' AFTER delivered_at,
        ADD COLUMN deleted_by INT NULL DEFAULT NULL        COMMENT '삭제 처리자 (users.id)' AFTER deleted_at,
        ADD INDEX idx_deleted_at (deleted_at)
")) {
    $steps[] = ['OK', "kw_orders.deleted_at / deleted_by 컬럼 추가 완료"];
} else {
    $steps[] = ['ERROR', 'ALTER 실패: ' . $conn->error];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head><meta charset="UTF-8"><title>Migration v19</title>
<style>body{font-family:monospace;padding:2em;} .ok{color:green;} .skip{color:#888;} .error{color:red;}</style>
</head>
<body>
<h2>Migration v19 결과 — 주문 소프트 삭제(휴지통) 컬럼 추가</h2>
<?php foreach ($steps as [$status, $msg]): ?>
<p class="<?php echo strtolower($status); ?>">[<?php echo $status; ?>] <?php echo htmlspecialchars($msg); ?></p>
<?php endforeach; ?>
<p><strong>완료 후 이 파일을 삭제하세요.</strong></p>
</body>
</html>
