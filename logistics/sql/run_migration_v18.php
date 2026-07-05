<?php
/**
 * 물류센터 DB 마이그레이션 v18
 * BOX/PCS 단위 분리 재고 관리
 * Design Ref: box-pcs-unit.design.md §3.1
 *  - lc_inbound: inbound_unit, pieces_per_box, cost_price_pcs
 *  - lc_inventory: unit (+ idx_product_unit)
 *  - lc_order_items: order_unit, pieces_per_box
 *  - lc_box_breaks 테이블 신규
 *  - 기존 데이터 라벨링 (수량 무변경 — Plan FR-10, SC-8)
 * 접속: http://서버주소/logistics/sql/run_migration_v18.php
 * 주의: 실행 후 이 파일은 삭제하세요!
 */
require_once __DIR__ . '/../../config/db_config.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');

$steps = [];

// 컬럼 존재 여부 확인 (재실행 안전)
function v18_column_exists(mysqli $conn, string $table, string $column): bool {
    $st = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $st->bind_param('ss', $table, $column);
    $st->execute();
    $cnt = (int)$st->get_result()->fetch_row()[0];
    $st->close();
    return $cnt > 0;
}

function v18_index_exists(mysqli $conn, string $table, string $index): bool {
    $st = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?"
    );
    $st->bind_param('ss', $table, $index);
    $st->execute();
    $cnt = (int)$st->get_result()->fetch_row()[0];
    $st->close();
    return $cnt > 0;
}

function v18_table_exists(mysqli $conn, string $table): bool {
    $st = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $st->bind_param('s', $table);
    $st->execute();
    $cnt = (int)$st->get_result()->fetch_row()[0];
    $st->close();
    return $cnt > 0;
}

function v18_run(mysqli $conn, array &$steps, string $label, string $sql): bool {
    if ($conn->query($sql)) { $steps[] = ['OK', $label]; return true; }
    $steps[] = ['ERROR', "$label 실패: " . $conn->error];
    return false;
}

// ── Plan SC-8: 마이그레이션 전 lot 수량 합계 스냅샷 (수량 무변경 검증) ──
$before = $conn->query(
    "SELECT COALESCE(SUM(quantity_in),0) AS tin, COALESCE(SUM(quantity_out),0) AS tout, COUNT(*) AS cnt
     FROM lc_inventory"
)->fetch_assoc();
$steps[] = ['INFO', "사전 스냅샷: lot {$before['cnt']}건, quantity_in 합 {$before['tin']}, quantity_out 합 {$before['tout']}"];

// ── 1) lc_inbound 컬럼 ──────────────────────────────────────────
if (v18_column_exists($conn, 'lc_inbound', 'inbound_unit')) {
    $steps[] = ['SKIP', 'lc_inbound.inbound_unit 이미 존재'];
} else {
    v18_run($conn, $steps, 'lc_inbound: inbound_unit/pieces_per_box/cost_price_pcs 추가',
        "ALTER TABLE lc_inbound
            ADD COLUMN inbound_unit   ENUM('BOX','PCS') NOT NULL DEFAULT 'PCS' COMMENT '입고 단위' AFTER quantity,
            ADD COLUMN pieces_per_box INT NOT NULL DEFAULT 1 COMMENT '입고 시점 박스당 낱개 수(스냅샷)' AFTER inbound_unit,
            ADD COLUMN cost_price_pcs DECIMAL(15,4) NOT NULL DEFAULT 0 COMMENT 'PCS 환산 원가' AFTER discount_rate");
}

// ── 2) lc_inventory 컬럼 + 인덱스 ───────────────────────────────
$inv_col_added = false;
if (v18_column_exists($conn, 'lc_inventory', 'unit')) {
    $steps[] = ['SKIP', 'lc_inventory.unit 이미 존재'];
} else {
    $inv_col_added = v18_run($conn, $steps, 'lc_inventory: unit 추가',
        "ALTER TABLE lc_inventory
            ADD COLUMN unit ENUM('BOX','PCS') NOT NULL DEFAULT 'PCS' COMMENT 'lot 단위' AFTER product_id");
}
if (v18_index_exists($conn, 'lc_inventory', 'idx_product_unit')) {
    $steps[] = ['SKIP', 'idx_product_unit 인덱스 이미 존재'];
} else {
    v18_run($conn, $steps, 'lc_inventory: idx_product_unit 인덱스 추가',
        "ALTER TABLE lc_inventory ADD INDEX idx_product_unit (product_id, unit)");
}

// ── 3) lc_order_items 컬럼 ──────────────────────────────────────
if (v18_column_exists($conn, 'lc_order_items', 'order_unit')) {
    $steps[] = ['SKIP', 'lc_order_items.order_unit 이미 존재'];
} else {
    v18_run($conn, $steps, 'lc_order_items: order_unit/pieces_per_box 추가',
        "ALTER TABLE lc_order_items
            ADD COLUMN order_unit     ENUM('BOX','PCS') NOT NULL DEFAULT 'PCS' COMMENT '주문/출고 단위' AFTER quantity,
            ADD COLUMN pieces_per_box INT NOT NULL DEFAULT 1 COMMENT '주문 시점 ppb 스냅샷' AFTER order_unit");
}

// ── 4) lc_box_breaks 테이블 ─────────────────────────────────────
if (v18_table_exists($conn, 'lc_box_breaks')) {
    $steps[] = ['SKIP', 'lc_box_breaks 테이블 이미 존재'];
} else {
    v18_run($conn, $steps, 'lc_box_breaks 테이블 생성',
        "CREATE TABLE lc_box_breaks (
            id                  INT AUTO_INCREMENT PRIMARY KEY,
            product_id          INT NOT NULL              COMMENT 'lc_products.id',
            source_inventory_id INT NOT NULL              COMMENT '개봉한 BOX lot (lc_inventory.id)',
            new_inventory_id    INT NULL                  COMMENT '생성된 PCS lot (전량 파손 시 NULL)',
            boxes_opened        INT NOT NULL              COMMENT '개봉 박스 수',
            pieces_per_box      INT NOT NULL              COMMENT '개봉 시 적용 ppb',
            pcs_created         INT NOT NULL              COMMENT '생성 PCS 수 (= boxes×ppb − damaged)',
            damaged_qty         INT NOT NULL DEFAULT 0    COMMENT '파손 수량(PCS)',
            damage_cost         DECIMAL(15,4) NOT NULL DEFAULT 0 COMMENT '파손 손실 (= damaged × PCS단가)',
            notes               VARCHAR(255)              COMMENT '비고',
            created_by          INT                       COMMENT 'users.id',
            created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id)          REFERENCES lc_products(id)  ON DELETE RESTRICT,
            FOREIGN KEY (source_inventory_id) REFERENCES lc_inventory(id) ON DELETE RESTRICT,
            INDEX idx_product_time (product_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='박스 개봉/파손 이력'");
}

// ── 5) 기존 데이터 라벨링 (수량 무변경 — 컬럼이 새로 생긴 경우에만) ──
// Plan FR-10: 상품 unit 값 기준 — 'BOX'/'박스'(대소문자·공백 무시) → BOX, 그 외 → PCS
// 재실행 시에도 안전: 라벨링은 멱등 (동일 결과)
if (v18_run($conn, $steps, 'lc_inventory 단위 라벨링',
    "UPDATE lc_inventory i JOIN lc_products p ON i.product_id = p.id
     SET i.unit = IF(UPPER(TRIM(p.unit)) IN ('BOX','박스'), 'BOX', 'PCS')")) {
    $steps[] = ['INFO', '라벨링 영향 행: ' . $conn->affected_rows];
}

// cost_price_pcs는 0인 행만 갱신 (이미 입력된 값 보존 — 재실행 안전)
v18_run($conn, $steps, 'lc_inbound 단위/ppb/PCS원가 라벨링',
    "UPDATE lc_inbound b JOIN lc_products p ON b.product_id = p.id
     SET b.inbound_unit   = IF(UPPER(TRIM(p.unit)) IN ('BOX','박스'), 'BOX', 'PCS'),
         b.pieces_per_box = IF(b.pieces_per_box <= 1, GREATEST(1, IFNULL(p.pieces_per_box, 1)), b.pieces_per_box),
         b.cost_price_pcs = IF(b.cost_price_pcs > 0, b.cost_price_pcs,
                               IF(UPPER(TRIM(p.unit)) IN ('BOX','박스'),
                                  b.cost_price / GREATEST(1, IFNULL(p.pieces_per_box, 1)),
                                  b.cost_price))");

v18_run($conn, $steps, 'lc_order_items 단위/ppb 라벨링',
    "UPDATE lc_order_items oi JOIN lc_products p ON oi.product_id = p.id
     SET oi.order_unit     = IF(UPPER(TRIM(p.unit)) IN ('BOX','박스'), 'BOX', 'PCS'),
         oi.pieces_per_box = IF(oi.pieces_per_box <= 1, GREATEST(1, IFNULL(p.pieces_per_box, 1)), oi.pieces_per_box)");

// ── Plan SC-8: 사후 검증 — 수량 합계 동일해야 통과 ──────────────
$after = $conn->query(
    "SELECT COALESCE(SUM(quantity_in),0) AS tin, COALESCE(SUM(quantity_out),0) AS tout, COUNT(*) AS cnt
     FROM lc_inventory"
)->fetch_assoc();

if ($before['tin'] === $after['tin'] && $before['tout'] === $after['tout'] && $before['cnt'] === $after['cnt']) {
    $steps[] = ['OK', "수량 무변경 검증 통과: lot {$after['cnt']}건, in {$after['tin']}, out {$after['tout']} (변화 없음)"];
} else {
    $steps[] = ['ERROR', "수량 검증 실패! 전: in {$before['tin']}/out {$before['tout']}/{$before['cnt']}건 → 후: in {$after['tin']}/out {$after['tout']}/{$after['cnt']}건"];
}

// ── 라벨링 결과 리포트 ──────────────────────────────────────────
$report = $conn->query(
    "SELECT unit, COUNT(*) AS lots, COALESCE(SUM(quantity_in - quantity_out),0) AS remain
     FROM lc_inventory GROUP BY unit"
)->fetch_all(MYSQLI_ASSOC);
foreach ($report as $r) {
    $steps[] = ['INFO', "재고 라벨링 결과: {$r['unit']} lot {$r['lots']}건, 잔여 수량 {$r['remain']}"];
}
$zero_pcs = $conn->query(
    "SELECT COUNT(*) FROM lc_inbound WHERE cost_price > 0 AND cost_price_pcs = 0"
)->fetch_row()[0];
$steps[] = [(int)$zero_pcs > 0 ? 'WARN' : 'INFO', "cost_price_pcs 미계산(0) 입고 행: {$zero_pcs}건"];

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head><meta charset="UTF-8"><title>Migration v18</title>
<style>body{font-family:monospace;padding:2em;} .ok{color:green;} .skip{color:#888;} .error{color:red;font-weight:bold;} .info{color:#0066cc;} .warn{color:#cc7700;}</style>
</head>
<body>
<h2>Migration v18 결과 — BOX/PCS 단위 분리 재고 관리</h2>
<?php foreach ($steps as [$status, $msg]): ?>
<p class="<?php echo strtolower($status); ?>">[<?php echo $status; ?>] <?php echo htmlspecialchars($msg); ?></p>
<?php endforeach; ?>
<p><strong>완료 후 이 파일은 삭제하세요!</strong></p>
</body>
</html>
