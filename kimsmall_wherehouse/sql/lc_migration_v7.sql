-- ============================================================
-- 물류센터 DB 마이그레이션 v7
-- kw_products.requires_expiry 컬럼 추가 (유통기한 입력 필수 여부)
-- 실행: /logistics/sql/run_migration_v7.php
-- ============================================================

ALTER TABLE kw_products
    ADD COLUMN requires_expiry TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '입고 시 유통기한 필수 입력 여부'
        AFTER min_stock;

SELECT '마이그레이션 v7 완료' AS result;
