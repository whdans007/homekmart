-- 도매판매 수기 입력 항목의 수량(무게)을 소숫점 둘째자리까지 저장하기 위한 변경
-- 기존: quantity int(11) → 변경: quantity decimal(10,2)
-- 무게 단위(예: 1.25kg) 판매를 지원한다.
ALTER TABLE `wholesale_sale_items`
    MODIFY `quantity` decimal(10,2) NOT NULL COMMENT '수량(무게, 소숫점 둘째자리)';
