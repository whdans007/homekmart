-- mall_products에 "품절 처리" 수동 스위치를 추가한다. 실제 inventory.quantity(발주/이동/유통기한 로트 기반이라
-- 복잡함)는 건드리지 않고, 몰 화면에만 강제로 품절로 보이게 하는 별도 플래그다.
ALTER TABLE `mall_products` ADD COLUMN `is_sold_out` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`;
