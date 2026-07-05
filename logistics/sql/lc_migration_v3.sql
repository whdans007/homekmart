-- ============================================================
-- 물류센터 DB 마이그레이션 v3
-- lc_brands, lc_categories: name → name_en / name_ko 분리
-- DB: sunset
-- 실행 날짜: 2026-05-20
-- ============================================================

-- lc_brands: name → name_en, name_ko 추가
ALTER TABLE lc_brands
    CHANGE COLUMN name name_en VARCHAR(100) NOT NULL COMMENT '브랜드 영문명';
ALTER TABLE lc_brands
    ADD COLUMN name_ko VARCHAR(100) DEFAULT NULL COMMENT '브랜드 한글명' AFTER name_en;

-- lc_categories: name → name_en, name_ko 추가
ALTER TABLE lc_categories
    CHANGE COLUMN name name_en VARCHAR(100) NOT NULL COMMENT '카테고리 영문명';
ALTER TABLE lc_categories
    ADD COLUMN name_ko VARCHAR(100) DEFAULT NULL COMMENT '카테고리 한글명' AFTER name_en;

SELECT 'lc_brands, lc_categories v3 마이그레이션 완료' AS result;
