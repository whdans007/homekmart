-- ============================================================
-- 진단(읽기 전용): 중복 바코드 상품 + 참조 데이터(재고/입고/주문/개봉) 현황
-- 대상 예시 바코드: 18801046433383
-- 사용법: 아래 @bc 값을 조회할 바코드로 바꿔서 실행
-- ⚠ 이 스크립트는 SELECT 만 수행하며 데이터를 변경하지 않습니다.
-- ============================================================
SET @bc = '18801046433383';

-- 1) 동일 바코드로 등록된 상품 목록 (활성/비활성)
SELECT p.id, p.name_en, p.name_ko, p.is_active,
       p.barcode_unit, p.barcode_box, p.barcode_logistics,
       p.created_at
FROM kw_products p
WHERE p.barcode_unit = @bc
   OR p.barcode_box = @bc
   OR p.barcode_logistics = @bc
ORDER BY p.is_active DESC, p.id;

-- 2) 각 상품의 참조 데이터 건수 (물리삭제 가능 여부 판단)
--    RESTRICT FK: kw_inbound / kw_inventory / kw_order_items / kw_box_breaks
--    → 아래 건수가 하나라도 > 0 이면 물리삭제(DELETE) 불가
SELECT p.id, p.name_en, p.is_active,
       (SELECT COUNT(*) FROM kw_inbound     b  WHERE b.product_id  = p.id) AS inbound_cnt,
       (SELECT COUNT(*) FROM kw_inventory   i  WHERE i.product_id  = p.id) AS inventory_lots,
       (SELECT COALESCE(SUM(i.quantity_remain),0) FROM kw_inventory i WHERE i.product_id = p.id) AS stock_remain,
       (SELECT COUNT(*) FROM kw_order_items oi WHERE oi.product_id = p.id) AS order_items_cnt,
       (SELECT COUNT(*) FROM kw_box_breaks  bx WHERE bx.product_id = p.id) AS box_break_cnt,
       (SELECT COUNT(*) FROM kw_product_history h WHERE h.product_id = p.id) AS history_cnt
FROM kw_products p
WHERE p.barcode_unit = @bc
   OR p.barcode_box = @bc
   OR p.barcode_logistics = @bc
ORDER BY p.is_active DESC, p.id;

-- 판정 기준:
--   inbound_cnt + inventory_lots + order_items_cnt + box_break_cnt = 0  → 안전하게 물리삭제 가능
--   위 값이 하나라도 > 0                                                → 물리삭제 불가(재고/이력과 연결됨)
