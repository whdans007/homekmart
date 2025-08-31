-- 샘플 진열 데이터 삽입 (테스트용)
USE u622428657_homekmart;

-- 기존 상품과 점포가 있다고 가정하고 샘플 진열 설정
-- 실제 product_id와 store_id는 기존 데이터에 맞게 수정 필요

-- 추천 상품 섹션에 샘플 상품 추가 (product_id와 store_id는 실제 값으로 수정)
INSERT INTO product_displays (section_id, product_id, store_id, display_order, badge_text, badge_color, is_active) VALUES
(2, 1, 1, 1, 'BEST', 'red', 1),
(2, 2, 1, 2, 'NEW', 'blue', 1),
(2, 3, 1, 3, 'SALE', 'green', 1);

-- 신상품 섹션에 샘플 상품 추가
INSERT INTO product_displays (section_id, product_id, store_id, display_order, badge_text, badge_color, is_active) VALUES
(3, 4, 1, 1, 'NEW', 'blue', 1),
(3, 5, 1, 2, 'HOT', 'orange', 1);

SELECT 'Sample display data inserted successfully!' as message;