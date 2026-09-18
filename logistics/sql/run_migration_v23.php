<?php
// Migration v23: 프로모션 스키마 추가
ini_set('display_errors', '1');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . '/../../config/db_config.php';

header('Content-Type: text/html; charset=utf-8');
echo "<pre>\n";
echo "=== Migration v23: 프로모션 스키마 추가 ===\n\n";

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset(DB_CHARSET);

    $table_exists = function (mysqli $conn, string $table): bool {
        $stmt = $conn->prepare('SHOW TABLES LIKE ?');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    };

    $column_exists = function (mysqli $conn, string $table, string $column): bool {
        $stmt = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->bind_param('s', $column);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    };

    $promotion_table_before = $table_exists($conn, 'lc_lot_promotions');
    $promotion_column_before = $column_exists($conn, 'lc_order_items', 'promotion_id');

    echo '[적용 전] lc_lot_promotions 테이블: ' . ($promotion_table_before ? 'Y' : 'N') . "\n";
    echo '[적용 전] lc_order_items.promotion_id 컬럼: ' . ($promotion_column_before ? 'Y' : 'N') . "\n\n";

    if ($promotion_table_before) {
        echo "SKIP: lc_lot_promotions 테이블이 이미 존재합니다.\n";
    } else {
        $conn->query(<<<'SQL'
CREATE TABLE lc_lot_promotions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    inventory_id    INT NOT NULL COMMENT 'lc_inventory.id FK — 할인 대상 LOT',
    product_id      INT NOT NULL COMMENT '조회 편의용 비정규화 — lc_products.id',
    lot_number      VARCHAR(100) NULL COMMENT '조회 편의용 비정규화 — 등록 시점 스냅샷',
    expiry_date     DATE NULL COMMENT '조회 편의용 비정규화 — 등록 시점 스냅샷',
    unit            ENUM('BOX','PACK','PCS') NOT NULL COMMENT '등록 시점 lc_inventory.unit 스냅샷(참고용 표시)',
    discount_rate   DECIMAL(5,2) NOT NULL COMMENT '할인율(%), 0 초과 100 미만',
    base_price      DECIMAL(12,2) NOT NULL COMMENT '등록 시점 lc_inbound.cost_price 스냅샷',
    discounted_price DECIMAL(12,2) NOT NULL COMMENT 'ROUND(base_price * (1 - discount_rate/100), 2)',
    status          ENUM('active','cancelled','expired') NOT NULL DEFAULT 'active',
    registered_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    registered_by   INT NULL COMMENT 'users.id',
    cancelled_at    DATETIME NULL,
    cancelled_by    INT NULL COMMENT 'users.id',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    active_inventory_id INT GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN inventory_id ELSE NULL END) STORED
                    COMMENT '동시 등록 경쟁조건 방지용 — status=active일 때만 inventory_id 값, 아니면 NULL(MySQL UNIQUE는 NULL끼리 충돌 안 함)',
    FOREIGN KEY (inventory_id) REFERENCES lc_inventory(id) ON DELETE RESTRICT,
    FOREIGN KEY (product_id)   REFERENCES lc_products(id)  ON DELETE RESTRICT,
    KEY idx_inventory_id (inventory_id),
    KEY idx_product_status (product_id, status),
    KEY idx_status_expiry (status, expiry_date),
    UNIQUE KEY uq_active_inventory (active_inventory_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='LOT 단위 할인(프로모션) 등록/이력';
SQL
        );
        echo "OK: lc_lot_promotions 테이블을 생성했습니다.\n";
    }

    if ($promotion_column_before) {
        echo "SKIP: lc_order_items.promotion_id 컬럼이 이미 존재합니다.\n";
    } else {
        $conn->query(<<<'SQL'
ALTER TABLE lc_order_items
    ADD COLUMN promotion_id INT NULL COMMENT 'lc_lot_promotions.id — 프로모션 지정 주문 항목만 설정, 일반 항목은 NULL' AFTER product_id,
    ADD KEY idx_promotion_id (promotion_id);
SQL
        );
        echo "OK: lc_order_items.promotion_id 컬럼을 추가했습니다.\n";
    }

    $promotion_table_after = $table_exists($conn, 'lc_lot_promotions');
    $promotion_column_after = $column_exists($conn, 'lc_order_items', 'promotion_id');

    echo "\n[적용 후] lc_lot_promotions 테이블: " . ($promotion_table_after ? 'Y' : 'N') . "\n";
    echo "[적용 후] lc_order_items.promotion_id 컬럼: " . ($promotion_column_after ? 'Y' : 'N') . "\n";
    echo "\n" . ($promotion_table_after && $promotion_column_after
        ? "Migration v23 완료."
        : "Migration v23 검증 실패. 로그를 확인하세요.") . "\n";

    $conn->close();
} catch (Throwable $e) {
    echo "\n예외 발생: " . $e->getMessage() . "\n";
    echo "  파일: " . $e->getFile() . ':' . $e->getLine() . "\n";
}

echo "</pre>\n";
