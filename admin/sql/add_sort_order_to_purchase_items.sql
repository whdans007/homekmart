-- purchase_items 테이블에 순번(sort_order) 컬럼 추가
-- 생성일: 2025-11-18

-- 1단계: sort_order 컬럼 추가 (기존 데이터가 있는 경우 자동으로 순번 부여)
ALTER TABLE purchase_items
ADD COLUMN sort_order INT NOT NULL DEFAULT 0
AFTER item_id;

-- 2단계: 기존 데이터에 순번 자동 부여 (MariaDB 10 이상 버전용)
UPDATE purchase_items pi
JOIN (
    SELECT
        item_id,
        ROW_NUMBER() OVER (PARTITION BY purchase_id ORDER BY item_id) as new_order
    FROM purchase_items
) sorted ON pi.item_id = sorted.item_id
SET pi.sort_order = sorted.new_order;

-- 3단계: 인덱스 추가 (정렬 성능 향상)
CREATE INDEX idx_purchase_sort ON purchase_items(purchase_id, sort_order);
