-- 장바구니 비회원(게스트) 지원: member_id를 NULL 허용으로 바꾸고 guest_token(세션 ID)을 추가한다.
-- 회원/게스트 어느 쪽이 주인인지 구분하는 owner_key 생성 컬럼으로 기존 유니크 키를 대체한다.
ALTER TABLE `mall_cart_items` MODIFY COLUMN `member_id` int(11) NULL;
ALTER TABLE `mall_cart_items` ADD COLUMN `guest_token` varchar(64) NULL AFTER `member_id`;
ALTER TABLE `mall_cart_items` DROP INDEX `member_product_channel`;
ALTER TABLE `mall_cart_items` ADD COLUMN `owner_key` varchar(70)
  GENERATED ALWAYS AS (IF(`member_id` IS NOT NULL, CONCAT('m', `member_id`), CONCAT('g', `guest_token`))) STORED
  AFTER `guest_token`;
ALTER TABLE `mall_cart_items` ADD UNIQUE KEY `owner_product_channel` (`owner_key`, `product_id`, `channel`);
ALTER TABLE `mall_cart_items` ADD KEY `guest_token` (`guest_token`);
