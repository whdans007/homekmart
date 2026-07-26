-- ============================================================
-- 물류센터 DB 마이그레이션 v3
-- kw_brands, kw_categories: name → name_en / name_ko 분리
-- DB: sunset
-- 실행 날짜: 2026-05-20
-- ============================================================

-- kw_brands: name → name_en, name_ko 추가
ALTER TABLE kw_brands
    CHANGE COLUMN name name_en VARCHAR(100) NOT NULL COMMENT '브랜드 영문명';
ALTER TABLE kw_brands
    ADD COLUMN name_ko VARCHAR(100) DEFAULT NULL COMMENT '브랜드 한글명' AFTER name_en;

-- kw_categories: name → name_en, name_ko 추가
ALTER TABLE kw_categories
    CHANGE COLUMN name name_en VARCHAR(100) NOT NULL COMMENT '카테고리 영문명';
ALTER TABLE kw_categories
    ADD COLUMN name_ko VARCHAR(100) DEFAULT NULL COMMENT '카테고리 한글명' AFTER name_en;

SELECT 'kw_brands, kw_categories v3 마이그레이션 완료' AS result;
