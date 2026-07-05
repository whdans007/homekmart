-- ============================================================
-- Migration v11: lc_inbound에 입력원가·할인율 컬럼 추가
-- 실행: mysql -u root -p sunset < lc_migration_v11.sql
-- ============================================================

ALTER TABLE lc_inbound
    ADD COLUMN regular_price DECIMAL(15,2) DEFAULT 0.00 COMMENT '할인 전 입력 원가'  AFTER cost_price,
    ADD COLUMN discount_rate DECIMAL(5,2)  DEFAULT 0.00 COMMENT '적용 할인율 (%)'    AFTER regular_price;

-- 기존 데이터: regular_price = cost_price (할인 없음으로 간주)
UPDATE lc_inbound SET regular_price = cost_price WHERE regular_price = 0;

SELECT 'Migration v11 완료: lc_inbound regular_price, discount_rate 추가' AS result;
