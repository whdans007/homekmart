-- wholesale_sale_items 테이블에 순번(sort_order) 컬럼 추가
-- 배경: 수정 시 항목이 DELETE 후 재INSERT 되며 id가 재발번되어 입력 순서가 깨지는 문제 해결
-- 생성일: 2026-06-25

-- 1단계: sort_order 컬럼 추가
ALTER TABLE wholesale_sale_items
ADD COLUMN sort_order INT NOT NULL DEFAULT 0
AFTER remarks;

-- 2단계: 기존 데이터에 순번 자동 부여 (판매건별 id 순서대로, MariaDB 10+ / MySQL 8+)
UPDATE wholesale_sale_items wsi
JOIN (
    SELECT
        id,
        ROW_NUMBER() OVER (PARTITION BY sale_id ORDER BY id) AS new_order
    FROM wholesale_sale_items
) sorted ON wsi.id = sorted.id
SET wsi.sort_order = sorted.new_order;

-- 3단계: 인덱스 추가 (정렬 성능 향상)
CREATE INDEX idx_wsi_sale_sort ON wholesale_sale_items(sale_id, sort_order);
