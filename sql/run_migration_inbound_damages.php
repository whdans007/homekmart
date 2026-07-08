<?php
/**
 * 입고 시 파손 상품 이력 마이그레이션 (inbound-damage-registration)
 * 접속: http://서버주소/sunset/sql/run_migration_inbound_damages.php
 * 주의: 실행 후 이 파일을 삭제하세요.
 */
require_once __DIR__ . '/../config/db_config.php';

// PHP 8.1+ mysqli 기본값(예외 발생)에서도 각 단계를 OK/SKIP/ERROR로 안전하게 보고하기 위해
// 예외 모드를 끄고 기존 query()의 false 반환 방식으로 통일한다.
mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('DB 연결 실패: ' . $conn->connect_error);
}
$conn->set_charset(DB_CHARSET);

$steps = [];

// 1. lc_inbound_damages 테이블
$tbl = $conn->query("SHOW TABLES LIKE 'lc_inbound_damages'");
if ($tbl && $tbl->num_rows > 0) {
    $steps[] = ['SKIP', 'lc_inbound_damages 테이블이 이미 존재합니다.'];
} elseif ($conn->query("
    CREATE TABLE lc_inbound_damages (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        inbound_id   INT NOT NULL,
        product_id   INT NOT NULL,
        supplier_id  INT,
        quantity     INT NOT NULL,
        unit         ENUM('BOX','PCS') NOT NULL,
        cost_loss    DECIMAL(15,4) NOT NULL DEFAULT 0,
        reason       VARCHAR(255) NOT NULL,
        created_by   INT,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_product_time (product_id, created_at),
        INDEX idx_supplier_time (supplier_id, created_at),
        INDEX idx_inbound (inbound_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='입고 시 파손 상품 이력'
")) {
    $steps[] = ['OK', 'lc_inbound_damages 테이블 생성 완료 (FK 없이)'];
} else {
    $steps[] = ['ERROR', 'lc_inbound_damages 생성 실패: ' . $conn->error];
}

// 2. FK: lc_inbound(id) — 타입 불일치 시 생략 가능
if ($conn->query("
    ALTER TABLE lc_inbound_damages
    ADD CONSTRAINT fk_lid_inbound FOREIGN KEY (inbound_id) REFERENCES lc_inbound(id) ON DELETE CASCADE
")) {
    $steps[] = ['OK', 'lc_inbound_damages -> lc_inbound FK 추가 완료'];
} else {
    $steps[] = ['SKIP', 'lc_inbound FK 추가 생략(이미 존재하거나 타입 불일치): ' . $conn->error];
}

// 3. FK: lc_products(id) — 타입 불일치 시 생략 가능
if ($conn->query("
    ALTER TABLE lc_inbound_damages
    ADD CONSTRAINT fk_lid_product FOREIGN KEY (product_id) REFERENCES lc_products(id) ON DELETE RESTRICT
")) {
    $steps[] = ['OK', 'lc_inbound_damages -> lc_products FK 추가 완료'];
} else {
    $steps[] = ['SKIP', 'lc_products FK 추가 생략(이미 존재하거나 타입 불일치): ' . $conn->error];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head><meta charset="UTF-8"><title>Migration: inbound-damage-registration</title>
<style>body{font-family:monospace;padding:2em;} .ok{color:green;} .skip{color:#888;} .error{color:red;}</style>
</head>
<body>
<h2>inbound-damage-registration 마이그레이션 결과</h2>
<?php foreach ($steps as [$status, $msg]): ?>
<p class="<?php echo strtolower($status); ?>">[<?php echo $status; ?>] <?php echo htmlspecialchars($msg); ?></p>
<?php endforeach; ?>
<p><strong>완료 후 이 파일을 삭제하세요.</strong></p>
</body>
</html>
