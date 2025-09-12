-- ===================================================================
-- VAT 포함/미포함 처리를 위한 purchase_items 테이블 컬럼 추가
-- 생성일: 2025-01-17
-- 용도: VAT 미포함 상품 처리 기능 추가
-- ===================================================================

-- purchase_items 테이블에 VAT 관련 컬럼 추가
ALTER TABLE purchase_items 
ADD COLUMN vat_included TINYINT(1) DEFAULT 1 COMMENT 'VAT 포함 여부 (1=포함, 0=미포함)' AFTER unit_price;

ALTER TABLE purchase_items 
ADD COLUMN original_unit_price DECIMAL(10,2) DEFAULT NULL COMMENT '입력된 원본 단가' AFTER vat_included;

ALTER TABLE purchase_items 
ADD COLUMN vat_amount DECIMAL(10,2) DEFAULT 0.00 COMMENT 'VAT 금액' AFTER original_unit_price;

-- 기존 데이터 마이그레이션
-- 기존 모든 데이터는 VAT 포함으로 설정하고, 원본 단가는 현재 unit_price와 동일하게 설정
UPDATE purchase_items 
SET vat_included = 1,
    original_unit_price = unit_price,
    vat_amount = ROUND(unit_price - (unit_price / 1.12), 2)
WHERE vat_included IS NULL;

-- 인덱스 추가 (성능 향상)
CREATE INDEX idx_purchase_items_vat_included ON purchase_items(vat_included);

-- 확인 쿼리
SELECT 
    'purchase_items 테이블 VAT 컬럼 추가 완료' as status,
    COUNT(*) as total_records,
    SUM(CASE WHEN vat_included = 1 THEN 1 ELSE 0 END) as vat_included_count,
    SUM(CASE WHEN vat_included = 0 THEN 1 ELSE 0 END) as vat_excluded_count
FROM purchase_items;