-- ===================================================================
-- 도매판매 V.A.T(+12%) / E.W.T(-1%) 적용 상태 저장 컬럼 추가
-- 생성일: 2026-07-02
-- 용도: wholesale_sale_preview 화면에서 체크박스로 선택한
--       V.A.T / E.W.T 적용 여부를 저장하고, 최종금액(final_amount)을
--       TOTAL(total_amount) + VAT - EWT 로 재계산하여 저장
-- 참고: 애플리케이션(admin/wholesale_sale_preview.php)에서도 컬럼이
--       없으면 자동으로 ADD COLUMN 을 수행하지만, 사전 적용을 권장
-- ===================================================================

ALTER TABLE wholesale_sales
    ADD COLUMN vat_applied TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'V.A.T +12% 적용 여부 (1=적용, 0=미적용)' AFTER final_amount,
    ADD COLUMN ewt_applied TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'E.W.T -1% 적용 여부 (1=적용, 0=미적용)' AFTER vat_applied;

-- 확인 쿼리
SELECT
    'wholesale_sales 테이블 V.A.T / E.W.T 컬럼 추가 완료' AS status,
    COUNT(*) AS total_records
FROM wholesale_sales;
