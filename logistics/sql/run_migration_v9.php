<?php
/**
 * 물류센터 DB 마이그레이션 v9
 * lc_orders.status ENUM에 'cancel_requested' 추가
 * 접속: http://서버주소/sunset/logistics/sql/run_migration_v9.php
 * 주의: 실행 후 이 파일을 삭제하세요.
 */
require_once __DIR__ . '/../../config/db_config.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');

$steps = [];

// 현재 ENUM 값 확인
$row = $conn->query("
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'lc_orders'
      AND COLUMN_NAME  = 'status'
")->fetch_assoc();

if (!$row) {
    $steps[] = ['ERROR', 'lc_orders.status 컬럼을 찾을 수 없습니다.'];
} elseif (str_contains($row['COLUMN_TYPE'], 'cancel_requested')) {
    $steps[] = ['SKIP', "ENUM에 'cancel_requested'가 이미 포함되어 있습니다. ({$row['COLUMN_TYPE']})"];
} elseif ($conn->query("
    ALTER TABLE lc_orders
    MODIFY COLUMN status
        ENUM('pending','approved','cancel_requested','shipped','delivered','cancelled')
        NOT NULL DEFAULT 'pending'
        COMMENT '주문 상태'
")) {
    $steps[] = ['OK', "lc_orders.status ENUM에 'cancel_requested' 추가 완료"];
} else {
    $steps[] = ['ERROR', 'ALTER 실패: ' . $conn->error];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head><meta charset="UTF-8"><title>Migration v9</title>
<style>body{font-family:monospace;padding:2em;} .ok{color:green;} .skip{color:#888;} .error{color:red;}</style>
</head>
<body>
<h2>Migration v9 결과</h2>
<?php foreach ($steps as [$status, $msg]): ?>
<p class="<?php echo strtolower($status); ?>">[<?php echo $status; ?>] <?php echo htmlspecialchars($msg); ?></p>
<?php endforeach; ?>
<p><strong>완료 후 이 파일을 삭제하세요.</strong></p>
</body>
</html>
