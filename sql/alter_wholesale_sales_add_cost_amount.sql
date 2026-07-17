-- 빠른등록(품목 없이 거래처+금액만 입력하는 Whole Sale 판매)의 원가 입력을 위한 컬럼 추가
-- 참고: wholesale_sale_items에는 이미 custom_cost_price가 있어 품목 단위 원가를 저장하지만,
-- 빠른등록 판매는 wholesale_sale_items 자체가 없으므로 wholesale_sales에 판매 단위 원가를 별도로 둔다.
ALTER TABLE `wholesale_sales`
    ADD COLUMN `cost_amount` DECIMAL(10,2) NULL DEFAULT NULL COMMENT '빠른등록 판매 원가 (선택 입력)' AFTER `final_amount`;
