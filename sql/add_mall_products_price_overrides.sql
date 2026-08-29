-- ===================================================================
-- mall_products 테이블에 가격/할인 제어 컬럼 추가
-- - cost_price_override / selling_price_override: 특정 상품을 몰에서 스페셜 가격으로
--   판매할 때, 매장 재고(inventory)의 실제 원가/판매가는 그대로 두고 몰 큐레이션
--   화면에서만 별도 가격을 지정할 수 있도록 함. NULL이면 inventory의 실제 값을 그대로 사용.
-- - discount_allowed: 상품별로 할인 규칙 적용 여부를 끌 수 있도록 함(기본 허용).
-- ===================================================================
ALTER TABLE `mall_products`
  ADD COLUMN `cost_price_override` DECIMAL(10,2) NULL DEFAULT NULL,
  ADD COLUMN `selling_price_override` DECIMAL(10,2) NULL DEFAULT NULL,
  ADD COLUMN `discount_allowed` TINYINT(1) NOT NULL DEFAULT 1;
