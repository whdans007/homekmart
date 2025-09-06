-- ===================================================================
-- HOME K MART 카테고리 구조 업데이트
-- 생성일: 2025-09-06
-- 용도: 새로운 카테고리 구조 적용
-- ===================================================================

-- 1. 기존 카테고리 백업
CREATE TABLE IF NOT EXISTS categories_backup_20250906 AS SELECT * FROM categories;

-- 2. 기존 카테고리 삭제 (참조 무결성 때문에 순서 중요)
SET FOREIGN_KEY_CHECKS = 0;
DELETE FROM categories;
SET FOREIGN_KEY_CHECKS = 1;

-- 3. AUTO_INCREMENT 리셋
ALTER TABLE categories AUTO_INCREMENT = 1;

-- 4. 새로운 카테고리 구조 삽입

-- 1. 신선식품 (Fresh Foods) - ID: 1
INSERT INTO categories (id, name, name_en, parent_id) VALUES 
(1, '신선식품', 'Fresh Foods', NULL);

-- 1-1. 신선식품 하위 카테고리들
INSERT INTO categories (id, name, name_en, parent_id) VALUES 
(2, '과일', 'Fruits', 1),
(3, '채소', 'Vegetables', 1),
(4, '정육', 'Meat', 1),
(5, '수산물', 'Seafood', 1),
(6, '계란', 'Eggs', 1),
(7, '유제품/치즈', 'Dairy & Cheese', 1),
(8, '김치/반찬', 'Kimchi & Side Dishes', 1),
(9, '베이커리', 'Bakery', 1);

-- 2. 가공식품 (Processed Foods) - ID: 10
INSERT INTO categories (id, name, name_en, parent_id) VALUES 
(10, '가공식품', 'Processed Foods', NULL);

-- 2-1. 가공식품 하위 카테고리들
INSERT INTO categories (id, name, name_en, parent_id) VALUES 
(11, '쌀/잡곡', 'Rice & Grains', 10),
(12, '라면/면류', 'Instant Noodles & Pasta', 10),
(13, '통조림/레토르트', 'Canned & Retort Foods', 10),
(14, '장류/양념/쨈', 'Sauces, Seasonings & Jam', 10),
(15, '오일/조미료', 'Oil & Condiments', 10),
(16, '냉동식품', 'Frozen Foods', 10),
(17, '냉동 수산물', 'Frozen Seafood', 10),
(18, '냉장식품', 'Refrigerated Foods', 10),
(19, '간편식/밀키트', 'Ready Meals & Meal Kits', 10),
(20, '아이스크림', 'Ice Cream', 10),
(21, '건어물/김', 'Dried Seafood & Seaweed', 10);

-- 3. 간식/음료 (Snacks & Beverages) - ID: 22
INSERT INTO categories (id, name, name_en, parent_id) VALUES 
(22, '간식/음료', 'Snacks & Beverages', NULL);

-- 3-1. 간식/음료 하위 카테고리들
INSERT INTO categories (id, name, name_en, parent_id) VALUES 
(23, '과자/스낵', 'Snacks & Chips', 22),
(24, '초콜릿/사탕', 'Chocolate & Candy', 22),
(25, '커피/차', 'Coffee & Tea', 22),
(26, '음료/생수', 'Beverages & Water', 22),
(27, '주류', 'Alcoholic Beverages', 22);

-- 4. 생활용품 (Daily Necessities) - ID: 28
INSERT INTO categories (id, name, name_en, parent_id) VALUES 
(28, '생활용품', 'Daily Necessities', NULL);

-- 4-1. 생활용품 하위 카테고리들
INSERT INTO categories (id, name, name_en, parent_id) VALUES 
(29, '세제/세정제', 'Detergent & Cleaners', 28),
(30, '화장지/위생용품', 'Tissue & Hygiene', 28),
(31, '구강용품', 'Oral Care', 28),
(32, '헤어/바디용품', 'Hair & Body Care', 28),
(33, '화장품/뷰티', 'Cosmetics & Beauty', 28);

-- 5. 주방/가정용품 (Home & Kitchen) - ID: 34
INSERT INTO categories (id, name, name_en, parent_id) VALUES 
(34, '주방/가정용품', 'Home & Kitchen', NULL);

-- 5-1. 주방/가정용품 하위 카테고리들
INSERT INTO categories (id, name, name_en, parent_id) VALUES 
(35, '주방용품', 'Kitchenware', 34),
(36, '생활용품', 'Household Goods', 34),
(37, '청소용품', 'Cleaning Supplies', 34);

-- 6. 기타 (Others) - ID: 38
INSERT INTO categories (id, name, name_en, parent_id) VALUES 
(38, '기타', 'Others', NULL);

-- 6-1. 기타 하위 카테고리들
INSERT INTO categories (id, name, name_en, parent_id) VALUES 
(39, '유아용품', 'Baby Products', 38),
(40, '애완용품', 'Pet Supplies', 38),
(41, '약', 'Pharmacy / Medicine', 38),
(42, '선물세트', 'Gift Sets', 38);

-- 5. AUTO_INCREMENT 값 업데이트
ALTER TABLE categories AUTO_INCREMENT = 43;

-- 6. 확인 쿼리
SELECT 
    c1.id as 대분류_ID,
    c1.name as 대분류명,
    c1.name_en as 대분류_영문명,
    c2.id as 소분류_ID,
    c2.name as 소분류명,
    c2.name_en as 소분류_영문명
FROM categories c1
LEFT JOIN categories c2 ON c2.parent_id = c1.id
ORDER BY c1.id, c2.id;