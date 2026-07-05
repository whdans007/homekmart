<?php
/**
 * 물류센터 DB 마이그레이션 실행기
 * 접속: http://서버주소/sunset/logistics/sql/run_migration.php
 * 주의: 실행 후 이 파일을 삭제하세요.
 */

// 경로: logistics/sql/ → logistics/ → sunset/
require_once __DIR__ . '/../../config/db_config.php';

$error  = '';
$results = [];
$has_error = false;

// DB 연결 테스트
$db_ver = '';
$existing = [];
$tables_sql = [
    'lc_products' => "CREATE TABLE IF NOT EXISTS lc_products (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(200) NOT NULL              COMMENT '상품명',
    sku             VARCHAR(100)                       COMMENT 'SKU',
    barcode         VARCHAR(100)                       COMMENT '바코드',
    unit            VARCHAR(20) DEFAULT '개'           COMMENT '단위 (개/박스/kg)',
    pieces_per_box  INT DEFAULT 1                      COMMENT '박스당 낱개 수',
    category        VARCHAR(100)                       COMMENT '카테고리',
    supplier_id     INT                                COMMENT 'suppliers.id 참조',
    cost_price      DECIMAL(15,2) DEFAULT 0.00         COMMENT '원가',
    selling_price   DECIMAL(15,2) DEFAULT 0.00         COMMENT '판매가',
    min_stock       INT DEFAULT 0                      COMMENT '최소 재고 임계치',
    is_active       TINYINT(1) DEFAULT 1               COMMENT '활성 여부',
    created_by      INT                                COMMENT 'users.id',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='물류 전용 상품 마스터'",

    'lc_inbound' => "CREATE TABLE IF NOT EXISTS lc_inbound (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    inbound_date    DATE NOT NULL                      COMMENT '입고일자',
    product_id      INT NOT NULL                       COMMENT 'lc_products.id',
    lot_number      VARCHAR(100)                       COMMENT '로트번호 (선택)',
    expiry_date     DATE                               COMMENT '유통기한',
    quantity        INT NOT NULL DEFAULT 0             COMMENT '입고 수량',
    cost_price      DECIMAL(15,2) DEFAULT 0.00         COMMENT '입고 단가',
    supplier_id     INT                                COMMENT 'suppliers.id',
    notes           TEXT                               COMMENT '비고',
    created_by      INT                                COMMENT 'users.id',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES lc_products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='물류 입고 (로트 단위)'",

    'lc_inventory' => "CREATE TABLE IF NOT EXISTS lc_inventory (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    inbound_id      INT NOT NULL                       COMMENT 'lc_inbound.id',
    product_id      INT NOT NULL                       COMMENT 'lc_products.id',
    lot_number      VARCHAR(100)                       COMMENT '로트번호',
    expiry_date     DATE                               COMMENT '유통기한',
    quantity_in     INT NOT NULL DEFAULT 0             COMMENT '입고 수량',
    quantity_out    INT NOT NULL DEFAULT 0             COMMENT '누적 출고 수량',
    quantity_remain INT AS (quantity_in - quantity_out) STORED COMMENT '현재고 (자동계산)',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (inbound_id)  REFERENCES lc_inbound(id)  ON DELETE RESTRICT,
    FOREIGN KEY (product_id)  REFERENCES lc_products(id) ON DELETE RESTRICT,
    INDEX idx_product_expiry (product_id, expiry_date),
    INDEX idx_expiry_remain  (expiry_date, quantity_remain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='물류 재고 (lot 단위 FIFO)'",

    'lc_orders' => "CREATE TABLE IF NOT EXISTS lc_orders (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    order_date      DATE NOT NULL                      COMMENT '주문일자',
    store_id        INT NOT NULL                       COMMENT 'stores.id',
    status          ENUM('pending','approved','shipped','delivered','cancelled') DEFAULT 'pending' COMMENT '주문 상태',
    total_amount    DECIMAL(15,2) DEFAULT 0.00         COMMENT '주문 총액',
    notes           TEXT                               COMMENT '주문 비고',
    created_by      INT                                COMMENT 'users.id (점포 담당자)',
    approved_by     INT                                COMMENT 'users.id (물류직원)',
    approved_at     DATETIME                           COMMENT '승인 일시',
    shipped_at      DATETIME                           COMMENT '출고 일시',
    delivered_at    DATETIME                           COMMENT '배달 완료 일시',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_store_status (store_id, status),
    INDEX idx_status       (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='점포 주문'",

    'lc_order_items' => "CREATE TABLE IF NOT EXISTS lc_order_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    order_id        INT NOT NULL                       COMMENT 'lc_orders.id',
    product_id      INT NOT NULL                       COMMENT 'lc_products.id',
    quantity        INT NOT NULL DEFAULT 0             COMMENT '주문 수량',
    unit_price      DECIMAL(15,2) DEFAULT 0.00         COMMENT '단가',
    total_amount    DECIMAL(15,2) AS (quantity * unit_price) STORED COMMENT '소계',
    FOREIGN KEY (order_id)   REFERENCES lc_orders(id)    ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES lc_products(id)  ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='주문 상세 항목'",
];

// DB 연결 및 현황 조회
try {
    $conn = get_db_connection();
    $row = $conn->query("SELECT VERSION() AS v")->fetch_assoc();
    $db_ver = $row['v'] ?? '';
    foreach (array_keys($tables_sql) as $t) {
        $existing[$t] = $conn->query("SHOW TABLES LIKE '{$t}'")->num_rows > 0;
    }
    $conn->close();
} catch (Exception $e) {
    $error = 'DB 연결 오류: ' . $e->getMessage();
}

// 마이그레이션 실행
if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    try {
        $conn = get_db_connection();
        foreach ($tables_sql as $table => $sql) {
            if ($existing[$table]) {
                $results[$table] = ['status' => 'skip', 'msg' => '이미 존재 (건너뜀)'];
                continue;
            }
            if ($conn->query($sql) === true) {
                $results[$table] = ['status' => 'ok', 'msg' => '생성 완료'];
                $existing[$table] = true;
            } else {
                $results[$table] = ['status' => 'error', 'msg' => $conn->error];
                $has_error = true;
            }
        }
        $conn->close();
    } catch (Exception $e) {
        $has_error = true;
        $results['_오류'] = ['status' => 'error', 'msg' => $e->getMessage()];
    }
}

$all_exist = !in_array(false, $existing, true);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>물류센터 DB 마이그레이션</title>
    <style>
        body { font-family: -apple-system, sans-serif; background: #f9fafb; margin: 0; padding: 2rem; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 1.5rem; max-width: 640px; margin: 0 auto 1rem; }
        h1 { font-size: 1.25rem; font-weight: 700; margin: 0 0 .25rem; color: #111; }
        .sub { color: #6b7280; font-size: .875rem; }
        table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        td, th { padding: .5rem .75rem; border-bottom: 1px solid #f3f4f6; text-align: left; }
        th { color: #6b7280; font-weight: 500; }
        .ok   { color: #059669; }
        .skip { color: #9ca3af; }
        .err  { color: #dc2626; }
        .new  { color: #0d9488; }
        .exist{ color: #9ca3af; }
        .warn { background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 1rem; font-size: .875rem; color: #92400e; margin-bottom: 1rem; }
        .success-box { background: #ecfdf5; border: 1px solid #6ee7b7; border-radius: 6px; padding: 1rem; font-size: .875rem; color: #065f46; margin-bottom: 1rem; }
        .err-box { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 6px; padding: 1rem; font-size: .875rem; color: #991b1b; margin-bottom: 1rem; }
        button { width: 100%; padding: .75rem; background: #0d9488; color: #fff; border: none; border-radius: 6px; font-size: 1rem; font-weight: 600; cursor: pointer; }
        button:hover { background: #0f766e; }
        a { color: #0d9488; }
        code { background: #f3f4f6; padding: 1px 4px; border-radius: 3px; font-size: .8rem; }
        .mono { font-family: monospace; }
    </style>
</head>
<body>
<div class="card">
    <h1>🏭 물류센터 DB 마이그레이션</h1>
    <p class="sub">lc_ 테이블 5개 생성 · DB: <strong><?php echo DB_NAME; ?></strong> · 버전: <?php echo htmlspecialchars($db_ver ?: '연결 실패'); ?></p>
</div>

<?php if ($error): ?>
<div class="card">
    <div class="err-box">❌ <?php echo htmlspecialchars($error); ?></div>
</div>
<?php else: ?>

<?php if (!empty($results)): ?>
<div class="card">
    <?php if ($has_error): ?>
    <div class="err-box">⚠️ 일부 테이블 생성에 실패했습니다.</div>
    <?php else: ?>
    <div class="success-box">✅ 마이그레이션 완료! <a href="<?php echo LC_BASE; ?>/login.php">물류센터 로그인 →</a></div>
    <p class="sub" style="margin-top:.5rem">⚠️ 보안을 위해 이 파일(<code>run_migration.php</code>)을 삭제하거나 이름을 바꾸세요.</p>
    <?php endif; ?>

    <table>
        <thead><tr><th>테이블</th><th>결과</th></tr></thead>
        <tbody>
        <?php foreach ($results as $t => $r): ?>
        <tr>
            <td class="mono"><?php echo htmlspecialchars($t); ?></td>
            <td class="<?php echo $r['status']; ?>">
                <?php echo $r['status'] === 'ok' ? '✅' : ($r['status'] === 'skip' ? '➖' : '❌'); ?>
                <?php echo htmlspecialchars($r['msg']); ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="card">
    <p style="font-size:.875rem;font-weight:600;color:#374151;margin:0 0 .75rem">생성 예정 테이블</p>
    <table>
        <thead><tr><th>테이블명</th><th>상태</th></tr></thead>
        <tbody>
        <?php foreach ($existing as $t => $exists): ?>
        <tr>
            <td class="mono"><?php echo $t; ?></td>
            <td class="<?php echo $exists ? 'exist' : 'new'; ?>">
                <?php echo $exists ? '➖ 이미 존재' : '➕ 생성 예정'; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($all_exist && empty($results)): ?>
<div class="card">
    <div class="success-box">✅ 모든 테이블이 이미 존재합니다. <a href="<?php echo LC_BASE; ?>/login.php">물류센터 로그인 →</a></div>
</div>
<?php elseif (empty($results)): ?>
<div class="card">
    <div class="warn">⚠️ <code>IF NOT EXISTS</code>로 실행되므로 기존 테이블/데이터는 건드리지 않습니다.</div>
    <form method="post">
        <input type="hidden" name="action" value="run">
        <button type="submit" onclick="return confirm('lc_ 테이블 5개를 생성하시겠습니까?')">
            마이그레이션 실행
        </button>
    </form>
</div>
<?php endif; ?>

<?php endif; ?>
</body>
</html>
