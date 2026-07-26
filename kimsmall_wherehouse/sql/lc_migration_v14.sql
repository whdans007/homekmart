-- ============================================================
-- Migration v14: kw_inbound_batches에 확정일시·확정자 추가
-- 실행: run_migration_v14.php
-- ============================================================

ALTER TABLE kw_inbound_batches
    ADD COLUMN confirmed_at DATETIME DEFAULT NULL COMMENT '검수 확정 일시' AFTER is_confirmed,
    ADD COLUMN confirmed_by INT DEFAULT NULL     COMMENT 'users.id 확정자'  AFTER confirmed_at;

SELECT 'Migration v14 완료: kw_inbound_batches confirmed_at, confirmed_by 추가' AS result;
