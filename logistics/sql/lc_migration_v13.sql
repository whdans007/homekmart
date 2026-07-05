-- ============================================================
-- Migration v13: lc_products에 barcode 컬럼 추가
-- 실행: run_migration_v13.php
-- ============================================================

ALTER TABLE lc_products
    ADD COLUMN barcode VARCHAR(100) DEFAULT NULL COMMENT '바코드' AFTER sku;

SELECT 'Migration v13 완료: lc_products.barcode 추가' AS result;
