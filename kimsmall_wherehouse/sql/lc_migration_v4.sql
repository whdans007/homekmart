-- ============================================================
-- 물류센터 DB 마이그레이션 v4
-- kw_products: barcode_multi 컬럼 제거
-- DB: sunset
-- 실행 날짜: 2026-05-20
-- ============================================================

ALTER TABLE kw_products DROP COLUMN barcode_multi;

SELECT 'barcode_multi 컬럼 제거 완료' AS result;
