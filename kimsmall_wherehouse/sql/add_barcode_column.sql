-- kw_products 테이블에 barcode 컬럼 추가
ALTER TABLE kw_products
ADD COLUMN barcode VARCHAR(100) UNIQUE NULL AFTER name_ko,
ADD INDEX idx_barcode (barcode);

-- 기존 데이터는 NULL로 유지되므로 추후 각 상품마다 바코드를 입력
