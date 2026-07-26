<?php
/**
 * KIM'S MALL 창고 DB 마이그레이션 v8
 * kw_order_item_lots 테이블 생성 (FIFO 출고 lot 원가 상세)
 * 접속: http://서버주소/sunset/kimsmall_wherehouse/sql/run_migration_v8.php
 * 주의: 실행 후 이 파일을 삭제하세요.
 */
require_once __DIR__ . '/../../config/db_config.php';

mysqli_report(MYSQLI_REPORT_OFF); // PHP 8.1+ 기본 예외 던짐 방지 — 기존 if/else 오류 처리 로직 유지
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');

$steps = [];

$tbl = $conn->query("SELECT COUNT(*) AS cnt FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kw_order_item_lots'");
$tbl_exists = (int)$tbl->fetch_assoc()['cnt'];

if ($tbl_exists) {
    $steps[] = ['SKIP', 'kw_order_item_lots 테이블이 이미 존재합니다.'];
} elseif ($conn->query("
    CREATE TABLE kw_order_item_lots (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        order_item_id   INT NOT NULL               COMMENT 'kw_order_items.id',
        inventory_id    INT NOT NULL               COMMENT 'kw_inventory.id (차감된 lot)',
        inbound_id      INT NOT NULL               COMMENT 'kw_inbound.id',
        quantity        INT NOT NULL DEFAULT 0     COMMENT '해당 lot에서 차감된 수량',
        cost_price      DECIMAL(15,4) NOT NULL     COMMENT '해당 lot의 입고 단가',
        FOREIGN KEY (order_item_id) REFERENCES kw_order_items(id) ON DELETE CASCADE,
        FOREIGN KEY (inventory_id)  REFERENCES kw_inventory(id)   ON DELETE RESTRICT,
        FOREIGN KEY (inbound_id)    REFERENCES kw_inbound(id)     ON DELETE RESTRICT,
        INDEX idx_order_item (order_item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='주문 출고 lot 원가 상세'
")) {
    $steps[] = ['OK', 'kw_order_item_lots 테이블 생성 완료'];
} else {
    $steps[] = ['ERROR', '테이블 생성 실패: ' . $conn->error];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head><meta charset="UTF-8"><title>Migration v8</title>
<style>body{font-family:monospace;padding:2em;} .ok{color:green;} .skip{color:#888;} .error{color:red;}</style>
</head>
<body>
<h2>Migration v8 결과</h2>
<?php foreach ($steps as [$status, $msg]): ?>
<p class="<?php echo strtolower($status); ?>">[<?php echo $status; ?>] <?php echo htmlspecialchars($msg); ?></p>
<?php endforeach; ?>
<p><strong>완료 후 이 파일을 삭제하세요.</strong></p>
</body>
</html>
