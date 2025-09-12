-- ===================================================================
-- 상품별 VAT 적용 여부 구분을 위한 products 테이블 컬럼 추가
-- 생성일: 2025-01-17
-- 용도: VAT 적용 상품과 VAT 비적용 상품(쌀 등) 구분
-- ===================================================================

-- products 테이블에 VAT 적용 여부 컬럼 추가
ALTER TABLE products 
ADD COLUMN is_vat_applicable TINYINT(1) DEFAULT 1 COMMENT 'VAT 적용 여부 (1=적용, 0=비적용)' AFTER category_id;

-- 기존 데이터 마이그레이션
-- 기존 모든 상품은 VAT 적용 상품으로 설정 (기본값)
UPDATE products 
SET is_vat_applicable = 1 
WHERE is_vat_applicable IS NULL;

-- 쌀 관련 상품들은 VAT 비적용으로 설정 (예시)
-- 실제 상품명에 따라 조정 필요
UPDATE products 
SET is_vat_applicable = 0 
WHERE product_name LIKE '%쌀%' 
   OR product_name LIKE '%rice%' 
   OR product_name LIKE '%미곡%'
   OR product_name LIKE '%현미%'
   OR product_name LIKE '%백미%';

-- 인덱스 추가 (성능 향상)
CREATE INDEX idx_products_vat_applicable ON products(is_vat_applicable);

-- 확인 쿼리
SELECT 
    'products 테이블 VAT 적용 여부 컬럼 추가 완료' as status,
    COUNT(*) as total_products,
    SUM(CASE WHEN is_vat_applicable = 1 THEN 1 ELSE 0 END) as vat_applicable_count,
    SUM(CASE WHEN is_vat_applicable = 0 THEN 1 ELSE 0 END) as vat_exempt_count
FROM products;

-- VAT 비적용 상품 목록 확인
SELECT 
    product_id,
    product_name,
    category_id,
    is_vat_applicable
FROM products 
WHERE is_vat_applicable = 0
ORDER BY product_name;