-- wholesale_sale_items 테이블에 remarks 컬럼 추가
-- 실행 전에 먼저 컬럼이 이미 있는지 확인하세요:
-- DESCRIBE wholesale_sale_items;

ALTER TABLE wholesale_sale_items 
ADD COLUMN remarks VARCHAR(200) NULL 
COMMENT '상품별 비고 (배송지시, 포장요청 등)' 
AFTER total_price;

-- 확인 쿼리
-- SELECT * FROM wholesale_sale_items LIMIT 1;