-- 오늘의특가 프로모(1+1/퍼센트할인/원가세일)를 mall_home_sections(초안/발행 구조) 대신
-- mall_products에 직접 저장한다. 실제 판매가(selling_price_override)는 이미 즉시 반영되는데
-- 뱃지 표시만 홈 레이아웃 "적용"을 눌러야 반영되던 불일치를 없애서, 저장하는 즉시 고객 화면에도 보이게 한다.
ALTER TABLE `mall_products`
  ADD COLUMN `promo_type` VARCHAR(20) NULL AFTER `is_sold_out`,
  ADD COLUMN `promo_value` DECIMAL(5,2) NULL AFTER `promo_type`,
  ADD COLUMN `promo_was` DECIMAL(12,2) NULL AFTER `promo_value`;
