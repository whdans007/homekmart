-- ============================================================
-- 물류센터 DB 마이그레이션 v2
-- 상품 마스터 개편: 영문/한글명, 브랜드, 카테고리, 바코드 4종
-- DB: sunset
-- 실행 날짜: 2026-05-20
-- ============================================================

-- 1. 브랜드 테이블
CREATE TABLE IF NOT EXISTS lc_brands (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name_en    VARCHAR(100) NOT NULL COMMENT '브랜드 영문명',
    name_ko    VARCHAR(100) DEFAULT NULL COMMENT '브랜드 한글명',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='물류 브랜드';

-- 2. 카테고리 테이블 (대/소분류 지원)
CREATE TABLE IF NOT EXISTS lc_categories (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name_en    VARCHAR(100) NOT NULL COMMENT '카테고리 영문명',
    name_ko    VARCHAR(100) DEFAULT NULL COMMENT '카테고리 한글명',
    parent_id  INT DEFAULT NULL      COMMENT '상위 카테고리 (NULL = 대분류)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES lc_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='물류 카테고리';

-- 3. lc_products 컬럼 변경 (ALTER TABLE)
-- 3-1. 영문명으로 컬럼 이름 변경
ALTER TABLE lc_products
    CHANGE COLUMN name name_en VARCHAR(200) NOT NULL COMMENT '영문 상품명 (필수)';

-- 3-2. 한글명 추가
ALTER TABLE lc_products
    ADD COLUMN name_ko VARCHAR(200) DEFAULT NULL COMMENT '한글 상품명 (선택, 한국 상품)' AFTER name_en;

-- 3-3. 브랜드, 카테고리 FK 추가
ALTER TABLE lc_products
    ADD COLUMN brand_id    INT DEFAULT NULL COMMENT 'lc_brands.id'    AFTER name_ko,
    ADD COLUMN category_id INT DEFAULT NULL COMMENT 'lc_categories.id' AFTER brand_id;

-- 3-4. 바코드 4종으로 분리 (기존 barcode → barcode_unit 으로 전환)
ALTER TABLE lc_products
    ADD COLUMN barcode_unit      VARCHAR(100) DEFAULT NULL COMMENT '바코드'      AFTER category_id,
    ADD COLUMN barcode_box       VARCHAR(100) DEFAULT NULL COMMENT '박스 바코드' AFTER barcode_unit,
    ADD COLUMN barcode_logistics VARCHAR(100) DEFAULT NULL COMMENT '물류코드'      AFTER barcode_box;

-- 3-5. 기존 barcode 값 → barcode_unit 으로 복사 후 컬럼 제거
UPDATE lc_products SET barcode_unit = barcode WHERE barcode IS NOT NULL AND barcode != '';
ALTER TABLE lc_products DROP COLUMN barcode;

-- 3-6. sku, supplier_id, cost_price, selling_price 제거
ALTER TABLE lc_products
    DROP COLUMN sku,
    DROP COLUMN supplier_id,
    DROP COLUMN cost_price,
    DROP COLUMN selling_price;

-- 3-7. category 텍스트 컬럼 제거 (category_id FK로 대체)
ALTER TABLE lc_products DROP COLUMN category;

-- 4. FK 제약 추가
ALTER TABLE lc_products
    ADD CONSTRAINT fk_lc_products_brand    FOREIGN KEY (brand_id)    REFERENCES lc_brands(id)     ON DELETE SET NULL,
    ADD CONSTRAINT fk_lc_products_category FOREIGN KEY (category_id) REFERENCES lc_categories(id) ON DELETE SET NULL;

SELECT 'lc_products v2 마이그레이션 완료' AS result;
