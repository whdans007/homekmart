-- 할인 기능 개선: 오리지날 원가 추적 컬럼 추가
-- 2025-12-18

-- 1. 새 컬럼 추가 (기존 컬럼이 있으면 스킵)
ALTER TABLE purchase_items
ADD COLUMN IF NOT EXISTS original_unit_price DECIMAL(10,2) DEFAULT NULL COMMENT '할인 적용 전 원단가';

ALTER TABLE purchase_items
ADD COLUMN IF NOT EXISTS discounted_unit_price DECIMAL(10,2) DEFAULT NULL COMMENT '할인 적용 후 원단가';

-- 2. 기존 데이터 마이그레이션
-- discount_rate가 0이 아닌 경우, unit_price를 오리지날로 설정하고 할인된 단가 계산
UPDATE purchase_items
SET
    original_unit_price = CASE
        WHEN discount_rate > 0 AND original_unit_price IS NULL THEN unit_price
        WHEN original_unit_price IS NULL THEN unit_price
        ELSE original_unit_price
    END,
    discounted_unit_price = CASE
        WHEN discount_rate > 0 AND discounted_unit_price IS NULL THEN unit_price * (1 - discount_rate / 100)
        WHEN discounted_unit_price IS NULL THEN unit_price
        ELSE discounted_unit_price
    END
WHERE original_unit_price IS NULL OR discounted_unit_price IS NULL;

-- 3. 검증: 할인된 합계가 discounted_total과 일치하는지 확인
-- 이 쿼리는 검증용이며, 실제 실행 전에 결과를 확인하세요
-- SELECT item_id, quantity, discounted_unit_price,
--        (quantity * discounted_unit_price) as calculated_discounted_total,
--        discounted_total,
--        (quantity * discounted_unit_price) - discounted_total as difference
-- FROM purchase_items
-- WHERE discount_rate > 0 AND ABS((quantity * discounted_unit_price) - COALESCE(discounted_total, 0)) > 0.01;
