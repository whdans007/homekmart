-- 점포간 재고 이동 시 신선상품(무게 등) 소숫점 수량 지원을 위한 변경
-- 기존: quantity int(11) → 변경: quantity decimal(10,2)
ALTER TABLE `store_transfer_items`
    MODIFY `quantity` decimal(10,2) NOT NULL COMMENT '이동 수량(소숫점 둘째자리)';

ALTER TABLE `inventory`
    MODIFY `quantity` decimal(10,2) NOT NULL DEFAULT 0 COMMENT '재고 수량(소숫점 둘째자리)';

-- 유통기한별 롯트 수량도 함께 변경 (이동 시 add/deduct_inventory_by_expiration에서 사용)
ALTER TABLE `inventory_expirations`
    MODIFY `quantity` decimal(10,2) NOT NULL DEFAULT 0 COMMENT '음수 허용(안전 장치), 소숫점 둘째자리';
