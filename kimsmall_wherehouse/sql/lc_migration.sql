-- ============================================================
-- 물류센터 독립 시스템 DB 마이그레이션
-- DB: sunset
-- 실행 날짜: 2026-05-20
-- ============================================================

-- 1. 물류 전용 상품 마스터
CREATE TABLE IF NOT EXISTS kw_products (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='물류 전용 상품 마스터';

-- 2. 입고 (로트 단위)
CREATE TABLE IF NOT EXISTS kw_inbound (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    inbound_date    DATE NOT NULL                      COMMENT '입고일자',
    product_id      INT NOT NULL                       COMMENT 'kw_products.id',
    lot_number      VARCHAR(100)                       COMMENT '로트번호 (선택)',
    expiry_date     DATE                               COMMENT '유통기한',
    quantity        INT NOT NULL DEFAULT 0             COMMENT '입고 수량',
    cost_price      DECIMAL(15,2) DEFAULT 0.00         COMMENT '입고 단가',
    supplier_id     INT                                COMMENT 'suppliers.id',
    notes           TEXT                               COMMENT '비고',
    created_by      INT                                COMMENT 'users.id',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES kw_products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='물류 입고 (로트 단위)';

-- 3. 재고 (lot 단위, FIFO 기반)
-- MariaDB 10.2+ GENERATED COLUMN 지원 필요
CREATE TABLE IF NOT EXISTS kw_inventory (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    inbound_id      INT NOT NULL                       COMMENT 'kw_inbound.id',
    product_id      INT NOT NULL                       COMMENT 'kw_products.id',
    lot_number      VARCHAR(100)                       COMMENT '로트번호',
    expiry_date     DATE                               COMMENT '유통기한',
    quantity_in     INT NOT NULL DEFAULT 0             COMMENT '입고 수량',
    quantity_out    INT NOT NULL DEFAULT 0             COMMENT '누적 출고 수량',
    quantity_remain INT AS (quantity_in - quantity_out) STORED COMMENT '현재고 (자동계산)',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (inbound_id)  REFERENCES kw_inbound(id)  ON DELETE RESTRICT,
    FOREIGN KEY (product_id)  REFERENCES kw_products(id) ON DELETE RESTRICT,
    INDEX idx_product_expiry (product_id, expiry_date),
    INDEX idx_expiry_remain  (expiry_date, quantity_remain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='물류 재고 (lot 단위 FIFO)';

-- 4. 점포 주문 헤더
CREATE TABLE IF NOT EXISTS kw_orders (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    order_date      DATE NOT NULL                      COMMENT '주문일자',
    store_id        INT NOT NULL                       COMMENT 'stores.id',
    status          ENUM('pending','approved','shipped','delivered','cancelled')
                    DEFAULT 'pending'                  COMMENT '주문 상태',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='점포 주문';

-- 5. 주문 상세
CREATE TABLE IF NOT EXISTS kw_order_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    order_id        INT NOT NULL                       COMMENT 'kw_orders.id',
    product_id      INT NOT NULL                       COMMENT 'kw_products.id',
    quantity        INT NOT NULL DEFAULT 0             COMMENT '주문 수량',
    unit_price      DECIMAL(15,2) DEFAULT 0.00         COMMENT '단가',
    total_amount    DECIMAL(15,2) AS (quantity * unit_price) STORED COMMENT '소계',
    FOREIGN KEY (order_id)   REFERENCES kw_orders(id)    ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES kw_products(id)  ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='주문 상세 항목';

SELECT '물류센터 마이그레이션 완료' AS result;
