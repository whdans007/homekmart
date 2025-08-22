-- Purchase Management 최적화 관련 SQL 쿼리 테스트

-- 1. 총 개수 조회 쿼리 테스트
SELECT COUNT(*) as total_count 
FROM purchases p 
JOIN suppliers s ON p.supplier_id = s.id 
WHERE p.deleted_at IS NULL;

-- 2. 메인 쿼리 테스트 (날짜+시간 통합)
SELECT 
    p.purchase_id, 
    p.purchase_date, 
    p.created_at,
    CONCAT(
        DATE_FORMAT(p.purchase_date, '%Y-%m-%d'),
        CASE 
            WHEN p.created_at IS NOT NULL THEN CONCAT(' ', TIME_FORMAT(p.created_at, '%H:%i'))
            ELSE ''
        END
    ) AS purchase_datetime,
    s.name AS supplier_name, 
    p.total_items, 
    p.total_amount,
    (
        SELECT SUM(
            CASE 
                WHEN pi.purchase_type = 'box' THEN pi.quantity * COALESCE(pr.pieces_per_box, 1)
                ELSE pi.quantity
            END
        ) 
        FROM purchase_items pi 
        JOIN products pr ON pi.product_id = pr.id 
        WHERE pi.purchase_id = p.purchase_id
    ) AS total_pieces
FROM purchases p
JOIN suppliers s ON p.supplier_id = s.id
WHERE p.deleted_at IS NULL
ORDER BY p.purchase_date DESC, p.purchase_id DESC
LIMIT 20 OFFSET 0;

-- 3. 검색 필터 테스트 쿼리
SELECT COUNT(*) as total_count 
FROM purchases p 
JOIN suppliers s ON p.supplier_id = s.id 
WHERE p.deleted_at IS NULL 
  AND p.purchase_date >= '2024-01-01' 
  AND p.purchase_date <= '2024-12-31'
  AND p.supplier_id = 1;

-- 4. 인덱스 최적화 제안
-- CREATE INDEX idx_purchases_date_supplier ON purchases(purchase_date, supplier_id, deleted_at);
-- CREATE INDEX idx_purchase_items_purchase_id ON purchase_items(purchase_id);