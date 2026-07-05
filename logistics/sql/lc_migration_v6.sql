-- ============================================================
-- 물류센터 DB 마이그레이션 v6
-- lc_inbound_batches.is_confirmed 컬럼 추가 (입고 확정 상태)
-- DB: sunset
-- 실행: /logistics/sql/run_migration_v6.php
-- ============================================================

ALTER TABLE lc_inbound_batches
    ADD COLUMN is_confirmed TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '확정 여부 (0=미확정, 1=확정)'
        AFTER notes;

SELECT '마이그레이션 v6 완료' AS result;
