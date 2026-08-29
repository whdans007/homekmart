-- ===================================================================
-- mall_products 테이블에 wholesale_reference_price 컬럼 추가
-- 용도: 상품 큐레이션 화면의 "기준도매가"를 관리자가 직접 수정(덮어쓰기)할 수 있도록 함.
-- NULL이면 원가 x (1 + 할인 규칙의 마진율)로 자동 계산되고, 값이 있으면 그 값을 그대로 사용한다.
-- ===================================================================
ALTER TABLE `mall_products`
  ADD COLUMN `wholesale_reference_price` DECIMAL(10,2) NULL DEFAULT NULL AFTER `display_name_en`;
