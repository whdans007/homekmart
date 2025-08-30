-- ================================================
-- 한국 슈퍼마켓 표준 카테고리 데이터 등록
-- 작성일: 2025-08-30
-- 목적: 한국 대형마트 표준 상품 카테고리 35개 등록
-- ================================================

-- 기존 카테고리 데이터 백업 (필요시)
-- SELECT * FROM categories ORDER BY id;

-- 한국 슈퍼마켓 표준 카테고리 등록
-- name = 한글명, name_en = 영문명
INSERT INTO categories (name, name_en) VALUES 
-- 신선식품 (Fresh Foods)
('과일', 'Fruits'),
('채소', 'Vegetables'),
('정육', 'Meat'),
('수산물', 'Seafood'),
('계란/유제품', 'Eggs & Dairy'),
('델리/반찬', 'Deli & Side Dishes'),
('베이커리', 'Bakery'),

-- 가공식품 (Processed Foods)
('쌀/잡곡', 'Rice & Grains'),
('라면/면류', 'Instant Noodles & Pasta'),
('통조림/레토르트', 'Canned & Retort Foods'),
('장류/양념', 'Sauces & Seasonings'),
('오일/조미료', 'Oil & Condiments'),
('김치/절임', 'Kimchi & Pickles'),
('냉동식품', 'Frozen Foods'),
('간편식/밀키트', 'Ready Meals & Meal Kits'),

-- 간식/음료 (Snacks & Beverages)
('과자/스낵', 'Snacks & Chips'),
('초콜릿/사탕', 'Chocolate & Candy'),
('커피/차', 'Coffee & Tea'),
('음료/생수', 'Beverages & Water'),
('주류', 'Alcoholic Beverages'),

-- 생활용품 (Daily Necessities)
('세제/세정제', 'Detergent & Cleaners'),
('화장지/위생용품', 'Tissue & Hygiene'),
('구강용품', 'Oral Care'),
('헤어/바디용품', 'Hair & Body Care'),
('화장품/뷰티', 'Cosmetics & Beauty'),

-- 주방/가정용품 (Kitchen & Household)
('주방용품', 'Kitchenware'),
('생활용품', 'Household Goods'),
('가전제품', 'Home Appliances'),
('문구/사무용품', 'Stationery & Office'),

-- 기타 (Others)
('유아용품', 'Baby Products'),
('애완용품', 'Pet Supplies'),
('건강식품', 'Health Foods'),
('친환경/유기농', 'Eco-friendly & Organic'),
('수입식품', 'Imported Foods'),
('선물세트', 'Gift Sets');

-- 등록 결과 확인
SELECT COUNT(*) as '등록된 카테고리 수' FROM categories WHERE name IS NOT NULL AND name != '';

-- 등록된 카테고리 목록 확인
SELECT id, name as '한글명', name_en as '영문명', icon_class as '아이콘', created_at as '등록일' 
FROM categories 
WHERE name IS NOT NULL AND name != ''
ORDER BY id DESC 
LIMIT 35;

-- ================================================
-- 카테고리 분류별 통계
-- ================================================
SELECT 
    CASE 
        WHEN name IN ('과일', '채소', '정육', '수산물', '계란/유제품', '델리/반찬', '베이커리') THEN '신선식품'
        WHEN name IN ('쌀/잡곡', '라면/면류', '통조림/레토르트', '장류/양념', '오일/조미료', '김치/절임', '냉동식품', '간편식/밀키트') THEN '가공식품'
        WHEN name IN ('과자/스낵', '초콜릿/사탕', '커피/차', '음료/생수', '주류') THEN '간식/음료'
        WHEN name IN ('세제/세정제', '화장지/위생용품', '구강용품', '헤어/바디용품', '화장품/뷰티') THEN '생활용품'
        WHEN name IN ('주방용품', '생활용품', '가전제품', '문구/사무용품') THEN '주방/가정용품'
        ELSE '기타'
    END as '카테고리 분류',
    COUNT(*) as '개수'
FROM categories 
WHERE name IS NOT NULL AND name != ''
GROUP BY 
    CASE 
        WHEN name IN ('과일', '채소', '정육', '수산물', '계란/유제품', '델리/반찬', '베이커리') THEN '신선식품'
        WHEN name IN ('쌀/잡곡', '라면/면류', '통조림/레토르트', '장류/양념', '오일/조미료', '김치/절임', '냉동식품', '간편식/밀키트') THEN '가공식품'
        WHEN name IN ('과자/스낵', '초콜릿/사탕', '커피/차', '음료/생수', '주류') THEN '간식/음료'
        WHEN name IN ('세제/세정제', '화장지/위생용품', '구강용품', '헤어/바디용품', '화장품/뷰티') THEN '생활용품'
        WHEN name IN ('주방용품', '생활용품', '가전제품', '문구/사무용품') THEN '주방/가정용품'
        ELSE '기타'
    END
ORDER BY COUNT(*) DESC;

-- ================================================
-- 참고사항:
-- 1. 총 35개의 한국 슈퍼마켓 표준 카테고리를 등록합니다
-- 2. 각 카테고리는 한글명과 영문명을 모두 포함합니다
-- 3. 이마트, 홈플러스, 롯데마트 등 대형마트 표준을 반영했습니다
-- 4. 기존 호환성을 위해 name 컬럼에도 동일한 값을 저장합니다
-- ================================================