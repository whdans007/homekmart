-- ============================================================
-- Migration v12: kw_inventory에 보관위치 컬럼 추가
-- 실행: mysql -u root -p sunset < kw_migration_v12.sql
-- ============================================================

ALTER TABLE kw_inventory
    ADD COLUMN storage_location VARCHAR(50) DEFAULT NULL COMMENT '보관위치 (예: A-01-03)' AFTER lot_number;

SELECT 'Migration v12 완료: kw_inventory storage_location 추가' AS result;
