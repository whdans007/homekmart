-- v21: 상품 대표 이미지 경로 컬럼 추가
-- logistics 루트 기준 상대경로 저장 (예: uploads/products/p_1700000000_ab12cd34.jpg)
ALTER TABLE kw_products
    ADD COLUMN IF NOT EXISTS image_path VARCHAR(255) NULL
    COMMENT '대표 상품 이미지 경로 (logistics 기준 상대경로)'
    AFTER barcode_logistics;
