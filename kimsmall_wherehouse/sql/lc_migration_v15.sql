-- ============================================================
-- Migration v15: kw_products에 capacity 컬럼 추가
-- 실행: run_migration_v15.php
-- ============================================================

ALTER TABLE kw_products
    ADD COLUMN capacity VARCHAR(50) DEFAULT NULL COMMENT '용량/규격 (예: 500ml, 1kg, 20ea)' AFTER name_ko;

SELECT 'Migration v15 완료: kw_products.capacity 추가' AS result;
