-- wholesale_products 테이블 스키마 업데이트
-- 도매용 독립 상품명과 다중 SKU 지원을 위한 업데이트

-- 1. 도매용 독립 상품명 필드 추가
ALTER TABLE wholesale_products 
ADD COLUMN wholesale_name_ko VARCHAR(255) NULL AFTER product_id,
ADD COLUMN wholesale_name_en VARCHAR(255) NULL AFTER wholesale_name_ko;

-- 2. 도매용 SKU 필드 추가 (JSON으로 다중 SKU 저장)
ALTER TABLE wholesale_products 
ADD COLUMN wholesale_skus JSON NULL AFTER wholesale_name_en;

-- 3. 도매용 설명 필드 추가
ALTER TABLE wholesale_products 
ADD COLUMN wholesale_description TEXT NULL AFTER wholesale_skus;

-- 4. 업데이트 시간 필드 추가
ALTER TABLE wholesale_products 
ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- 5. 기존 데이터 마이그레이션 - products 테이블에서 도매용 상품명 복사
UPDATE wholesale_products wp
JOIN products p ON wp.product_id = p.id
SET 
    wp.wholesale_name_ko = p.name_ko,
    wp.wholesale_name_en = p.name_en,
    wp.wholesale_skus = JSON_ARRAY(p.sku)
WHERE wp.wholesale_name_ko IS NULL;

-- 6. 업데이트된 테이블 구조 확인
DESCRIBE wholesale_products;

-- 7. 마이그레이션 결과 확인
SELECT 
    id,
    product_id,
    wholesale_name_ko,
    wholesale_name_en, 
    wholesale_skus,
    wholesale_price
FROM wholesale_products 
LIMIT 5;