-- purchases 테이블에 store_id 컬럼 추가
-- 작성일: 2025-10-25
-- 목적: 관리자 등급이 자신의 점포 매입 내역만 조회하도록 점포 필터링 지원

-- 1. store_id 컬럼 존재 여부 확인 후 추가
ALTER TABLE purchases
ADD COLUMN IF NOT EXISTS store_id INT NULL COMMENT '점포 ID'
AFTER purchase_id;

-- 2. 기존 데이터에 대한 store_id 설정
-- 매입 상품(purchase_items)의 재고(inventory)를 통해 점포 정보 추출
UPDATE purchases p
LEFT JOIN (
    SELECT
        pi.purchase_id,
        i.store_id,
        COUNT(DISTINCT i.store_id) as store_count
    FROM purchase_items pi
    INNER JOIN inventory i ON pi.product_id = i.product_id
    GROUP BY pi.purchase_id
) pi_stores ON p.purchase_id = pi_stores.purchase_id
SET p.store_id = CASE
    -- 하나의 점포에만 속한 경우
    WHEN pi_stores.store_count = 1 THEN pi_stores.store_id
    -- 여러 점포에 걸친 경우 또는 정보가 없는 경우 기본 점포(1번)로 설정
    ELSE 1
END
WHERE p.store_id IS NULL;

-- 3. 향후 NULL 방지를 위해 DEFAULT 값 설정
ALTER TABLE purchases
MODIFY COLUMN store_id INT NOT NULL DEFAULT 1 COMMENT '점포 ID';

-- 4. 외래키 제약조건 추가 (stores 테이블 참조)
ALTER TABLE purchases
ADD CONSTRAINT fk_purchases_store_id
FOREIGN KEY (store_id) REFERENCES stores(id)
ON DELETE RESTRICT
ON UPDATE CASCADE;

-- 5. 인덱스 추가 (조회 성능 향상)
CREATE INDEX idx_purchases_store_id ON purchases(store_id);

-- 확인 쿼리
SELECT
    p.purchase_id,
    p.store_id,
    s.name as store_name,
    p.purchase_date,
    p.total_amount
FROM purchases p
LEFT JOIN stores s ON p.store_id = s.id
WHERE p.deleted_at IS NULL
ORDER BY p.purchase_id DESC
LIMIT 10;
