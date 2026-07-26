-- ============================================================
-- Migration v8: 주문 출고 lot 원가 상세 테이블
-- 출고 시 FIFO 원가 역산 결과를 lot 단위로 보존
-- 실행: mysql -u root -p sunset < kw_migration_v8.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS kw_order_item_lots (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='주문 출고 lot 원가 상세';

SELECT 'Migration v8 완료: kw_order_item_lots 생성' AS result;
