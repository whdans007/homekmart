-- ===================================================================
-- mall_products 테이블에 채널별 할인 허용 컬럼 추가
-- 용도: 상품별로 "일반(소매) 판매 시 할인 허용"과 "도매 판매 시 할인 허용"을
-- 서로 다르게 설정할 수 있도록 함(기존 discount_allowed는 채널 구분이 없었음).
-- ===================================================================
ALTER TABLE `mall_products`
  ADD COLUMN `retail_discount_allowed` TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN `wholesale_discount_allowed` TINYINT(1) NOT NULL DEFAULT 1;
