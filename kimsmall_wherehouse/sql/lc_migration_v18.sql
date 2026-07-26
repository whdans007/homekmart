-- ============================================================
-- Migration v18: BOX/PCS 단위 분리 재고 관리
-- Design Ref: box-pcs-unit.design.md §3.1
--  1) kw_inbound: 입고 단위 + ppb 스냅샷 + PCS 환산 원가
--  2) kw_inventory: lot 단위
--  3) kw_order_items: 주문 단위 + ppb 스냅샷
--  4) kw_box_breaks: 박스 개봉/파손 이력 (신규)
--  5) 기존 데이터 라벨링 (수량 무변경 — Plan FR-10)
-- 실행: run_migration_v18.php (권장 — 재실행 안전 가드 + 검증 포함)
-- ============================================================

-- 1) kw_inbound
ALTER TABLE kw_inbound
    ADD COLUMN inbound_unit   ENUM('BOX','PCS') NOT NULL DEFAULT 'PCS' COMMENT '입고 단위' AFTER quantity,
    ADD COLUMN pieces_per_box INT NOT NULL DEFAULT 1                   COMMENT '입고 시점 박스당 낱개 수(스냅샷)' AFTER inbound_unit,
    ADD COLUMN cost_price_pcs DECIMAL(15,4) NOT NULL DEFAULT 0         COMMENT 'PCS 환산 원가 (BOX: cost_price/ppb, PCS: cost_price)' AFTER discount_rate;

-- 2) kw_inventory
ALTER TABLE kw_inventory
    ADD COLUMN unit ENUM('BOX','PCS') NOT NULL DEFAULT 'PCS' COMMENT 'lot 단위' AFTER product_id,
    ADD INDEX idx_product_unit (product_id, unit);

-- 3) kw_order_items
ALTER TABLE kw_order_items
    ADD COLUMN order_unit     ENUM('BOX','PCS') NOT NULL DEFAULT 'PCS' COMMENT '주문/출고 단위' AFTER quantity,
    ADD COLUMN pieces_per_box INT NOT NULL DEFAULT 1                   COMMENT '주문 시점 ppb 스냅샷' AFTER order_unit;

-- 4) 박스 개봉/파손 이력
CREATE TABLE IF NOT EXISTS kw_box_breaks (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    product_id          INT NOT NULL              COMMENT 'kw_products.id',
    source_inventory_id INT NOT NULL              COMMENT '개봉한 BOX lot (kw_inventory.id)',
    new_inventory_id    INT NULL                  COMMENT '생성된 PCS lot (전량 파손 시 NULL)',
    boxes_opened        INT NOT NULL              COMMENT '개봉 박스 수',
    pieces_per_box      INT NOT NULL              COMMENT '개봉 시 적용 ppb',
    pcs_created         INT NOT NULL              COMMENT '생성 PCS 수 (= boxes×ppb − damaged)',
    damaged_qty         INT NOT NULL DEFAULT 0    COMMENT '파손 수량(PCS)',
    damage_cost         DECIMAL(15,4) NOT NULL DEFAULT 0 COMMENT '파손 손실 (= damaged × PCS단가)',
    notes               VARCHAR(255)              COMMENT '비고',
    created_by          INT                       COMMENT 'users.id',
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id)          REFERENCES kw_products(id)  ON DELETE RESTRICT,
    FOREIGN KEY (source_inventory_id) REFERENCES kw_inventory(id) ON DELETE RESTRICT,
    INDEX idx_product_time (product_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='박스 개봉/파손 이력';

-- 5) 기존 데이터 라벨링 (수량 무변경)
--    BOX 판정: 상품 unit 정규화 후 'BOX'/'박스' → BOX, 그 외 → PCS
UPDATE kw_inventory i JOIN kw_products p ON i.product_id = p.id
SET i.unit = IF(UPPER(TRIM(p.unit)) IN ('BOX','박스'), 'BOX', 'PCS');

UPDATE kw_inbound b JOIN kw_products p ON b.product_id = p.id
SET b.inbound_unit   = IF(UPPER(TRIM(p.unit)) IN ('BOX','박스'), 'BOX', 'PCS'),
    b.pieces_per_box = GREATEST(1, IFNULL(p.pieces_per_box, 1)),
    b.cost_price_pcs = IF(UPPER(TRIM(p.unit)) IN ('BOX','박스'),
                          b.cost_price / GREATEST(1, IFNULL(p.pieces_per_box, 1)),
                          b.cost_price);

UPDATE kw_order_items oi JOIN kw_products p ON oi.product_id = p.id
SET oi.order_unit     = IF(UPPER(TRIM(p.unit)) IN ('BOX','박스'), 'BOX', 'PCS'),
    oi.pieces_per_box = GREATEST(1, IFNULL(p.pieces_per_box, 1));

SELECT 'Migration v18 완료: BOX/PCS 단위 분리 (inbound_unit, inventory.unit, order_unit, kw_box_breaks)' AS result;
