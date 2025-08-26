-- ================================================
-- 카테고리 테이블 다국어 지원 추가 SQL
-- 작성일: 2025-01-25
-- 목적: categories 테이블에 영문명 컬럼 추가
-- ================================================

-- 1단계: name_ko 컬럼 추가 (한글명)
ALTER TABLE categories 
ADD COLUMN IF NOT EXISTS name_ko VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL COMMENT '카테고리 한글명' AFTER id;

-- 2단계: name_en 컬럼 추가 (영문명)
ALTER TABLE categories 
ADD COLUMN IF NOT EXISTS name_en VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL COMMENT '카테고리 영문명' AFTER name_ko;

-- 3단계: 기존 name 컬럼 데이터를 name_ko로 복사
UPDATE categories 
SET name_ko = name 
WHERE name_ko IS NULL OR name_ko = '';

-- 4단계: name_ko를 NOT NULL로 변경 (한글명은 필수)
ALTER TABLE categories 
MODIFY COLUMN name_ko VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '카테고리 한글명';

-- 5단계: 기본 카테고리 영문명 설정 (옵션)
UPDATE categories SET name_en = 'General Food' WHERE name_ko = '일반식품' AND (name_en IS NULL OR name_en = '');
UPDATE categories SET name_en = 'Living Goods' WHERE name_ko = '생활용품' AND (name_en IS NULL OR name_en = '');
UPDATE categories SET name_en = 'Beverages' WHERE name_ko = '음료' AND (name_en IS NULL OR name_en = '');
UPDATE categories SET name_en = 'Snacks' WHERE name_ko = '과자/스낵' AND (name_en IS NULL OR name_en = '');
UPDATE categories SET name_en = 'Frozen Food' WHERE name_ko = '냉동식품' AND (name_en IS NULL OR name_en = '');
UPDATE categories SET name_en = 'Cigarettes' WHERE name_ko = '담배' AND (name_en IS NULL OR name_en = '');
UPDATE categories SET name_en = 'Alcohol' WHERE name_ko = '주류' AND (name_en IS NULL OR name_en = '');
UPDATE categories SET name_en = 'Others' WHERE name_ko = '기타' AND (name_en IS NULL OR name_en = '');

-- 6단계: 인덱스 추가 (검색 성능 향상)
ALTER TABLE categories ADD INDEX idx_name_ko (name_ko);
ALTER TABLE categories ADD INDEX idx_name_en (name_en);

-- 7단계: 기존 name 컬럼 유지 (호환성 유지)
-- 나중에 모든 코드가 수정되면 아래 주석을 해제하여 실행
-- ALTER TABLE categories DROP COLUMN name;

-- 확인용 쿼리
SELECT 
    id,
    name AS '기존_name',
    name_ko AS '한글명',
    name_en AS '영문명',
    created_at
FROM categories
ORDER BY id;