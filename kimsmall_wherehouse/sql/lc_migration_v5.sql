-- ============================================================
-- 물류센터 DB 마이그레이션 v5
-- kw_inbound_batches 테이블 추가 + kw_inbound.batch_id 컬럼 추가
-- DB: sunset
-- 실행: /logistics/sql/run_migration_v5.php
-- ============================================================

CREATE TABLE IF NOT EXISTS kw_inbound_batches (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    inbound_date DATE NOT NULL            COMMENT '입고일자',
    supplier_id  INT                      COMMENT 'suppliers.id',
    notes        TEXT                     COMMENT '비고',
    created_by   INT                      COMMENT 'users.id',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='매입 배치 (건 단위)';

ALTER TABLE kw_inbound
    ADD COLUMN batch_id INT NULL AFTER id,
    ADD CONSTRAINT fk_kw_inbound_batch FOREIGN KEY (batch_id)
        REFERENCES kw_inbound_batches(id) ON DELETE SET NULL;

SELECT '마이그레이션 v5 완료' AS result;
