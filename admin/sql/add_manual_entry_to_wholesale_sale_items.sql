-- 수기 입력 지원을 위한 wholesale_sale_items 테이블 수정
-- product_id를 NULL 허용으로 변경하고 custom_product_name 컬럼 추가

SET FOREIGN_KEY_CHECKS = 0;

-- product_id를 NULL 허용으로 변경 (수기 입력 상품은 product_id가 NULL)
ALTER TABLE `wholesale_sale_items`
    MODIFY COLUMN `product_id` int(11) DEFAULT NULL COMMENT '상품 ID (NULL이면 수기 입력 상품)';

-- 수기 입력 상품명 컬럼 추가
ALTER TABLE `wholesale_sale_items`
    ADD COLUMN `custom_product_name` varchar(255) DEFAULT NULL COMMENT '수기 입력 상품명' AFTER `product_id`;

-- 수기 입력 원가 컬럼 추가 (선택 입력)
ALTER TABLE `wholesale_sale_items`
    ADD COLUMN `custom_cost_price` decimal(10,2) DEFAULT NULL COMMENT '수기 입력 원가' AFTER `custom_product_name`;

SET FOREIGN_KEY_CHECKS = 1;
