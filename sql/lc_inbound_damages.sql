-- ============================================================
-- 입고 시 파손 상품 이력 (inbound-damage-registration)
-- 입고 등록 시점에 이미 파손된 상태로 도착한 수량을 기록하고
-- 판매재고(lc_inventory)에서 제외한다.
-- 작성일: 2026-07-07
-- ============================================================

CREATE TABLE IF NOT EXISTS lc_inbound_damages (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    inbound_id   INT NOT NULL          COMMENT 'lc_inbound.id (파손이 발생한 입고 건)',
    product_id   INT NOT NULL          COMMENT 'lc_products.id',
    supplier_id  INT                   COMMENT 'lc_suppliers.id (조회 편의를 위한 비정규화)',
    quantity     INT NOT NULL          COMMENT '파손 수량 (입고 행과 동일 단위: BOX 또는 PCS)',
    unit         ENUM('BOX','PCS') NOT NULL COMMENT '파손 수량 단위 (해당 입고 행의 단위와 동일)',
    cost_loss    DECIMAL(15,4) NOT NULL DEFAULT 0 COMMENT '손실금액 = PCS환산수량 x cost_price_pcs',
    reason       VARCHAR(255) NOT NULL COMMENT '파손 사유',
    created_by   INT                   COMMENT 'users.id',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_product_time (product_id, created_at),
    INDEX idx_supplier_time (supplier_id, created_at),
    INDEX idx_inbound (inbound_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='입고 시 파손 상품 이력';

-- FK는 타입/인덱스 불일치로 실패할 수 있어 별도 단계로 분리 (store-request-board 사례 참고)
ALTER TABLE lc_inbound_damages
    ADD CONSTRAINT fk_lid_inbound FOREIGN KEY (inbound_id) REFERENCES lc_inbound(id) ON DELETE CASCADE;
ALTER TABLE lc_inbound_damages
    ADD CONSTRAINT fk_lid_product FOREIGN KEY (product_id) REFERENCES lc_products(id) ON DELETE RESTRICT;
