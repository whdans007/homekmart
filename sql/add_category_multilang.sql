-- 카테고리 테이블에 다국어 지원 컬럼 추가
-- 기존 name 컬럼의 데이터를 name_ko로 이동하고 name_en 추가

-- 1. name_ko 컬럼 추가 (기존 name 데이터 복사)
ALTER TABLE categories 
ADD COLUMN IF NOT EXISTS name_ko VARCHAR(100) DEFAULT NULL AFTER name;

-- 2. name_en 컬럼 추가
ALTER TABLE categories 
ADD COLUMN IF NOT EXISTS name_en VARCHAR(100) DEFAULT NULL AFTER name_ko;

-- 3. 기존 name 데이터를 name_ko로 복사
UPDATE categories 
SET name_ko = name 
WHERE name_ko IS NULL;

-- 4. name_ko를 NOT NULL로 변경
ALTER TABLE categories 
MODIFY COLUMN name_ko VARCHAR(100) NOT NULL;

-- 5. 기존 name 컬럼 유지 (호환성을 위해)
-- 필요시 나중에 삭제 가능

-- 6. 샘플 영문명 추가 (선택사항)
UPDATE categories SET name_en = 'General Food' WHERE name_ko = '일반식품' AND name_en IS NULL;
UPDATE categories SET name_en = 'Living Goods' WHERE name_ko = '생활용품' AND name_en IS NULL;
UPDATE categories SET name_en = 'Beverages' WHERE name_ko = '음료' AND name_en IS NULL;
UPDATE categories SET name_en = 'Snacks' WHERE name_ko = '과자/스낵' AND name_en IS NULL;
UPDATE categories SET name_en = 'Frozen Food' WHERE name_ko = '냉동식품' AND name_en IS NULL;
UPDATE categories SET name_en = 'Cigarettes' WHERE name_ko = '담배' AND name_en IS NULL;
UPDATE categories SET name_en = 'Alcohol' WHERE name_ko = '주류' AND name_en IS NULL;
UPDATE categories SET name_en = 'Others' WHERE name_ko = '기타' AND name_en IS NULL;