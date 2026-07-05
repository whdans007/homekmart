-- ============================================================
-- 진단(읽기 전용): 바코드 4800014147090 재고 불일치 원인 분석
-- 증상: inventory.php 재고 28개 표시. 그러나 점포 주문+출고 완료 → 0 이어야 함.
-- 가설 1) 동일 바코드 중복 상품(product_id 분리) → 주문은 B, 재고는 A에 남음
-- 가설 2) 주문이 승인(approve)되지 않아 재고 차감이 안 됨 (ship은 상태만 변경)
-- ⚠ SELECT 전용. 데이터를 변경하지 않습니다.
-- ============================================================
SET @bc = '4800014147090';

-- 1) 동일 바코드로 등록된 상품 목록 (중복 여부 확인)
SELECT p.id, p.name_en, p.name_ko, p.is_active,
       p.barcode_unit, p.barcode_box, p.barcode_logistics, p.created_at
FROM lc_products p
WHERE p.barcode_unit = @bc OR p.barcode_box = @bc OR p.barcode_logistics = @bc
ORDER BY p.is_active DESC, p.id;

-- 2) 상품별 재고 합계 + 주문 항목 수 (어느 product_id에 재고가 남고 주문이 걸렸는지)
SELECT p.id, p.name_en, p.is_active,
       (SELECT COALESCE(SUM(i.quantity_in),0)   FROM lc_inventory i WHERE i.product_id = p.id) AS total_in,
       (SELECT COALESCE(SUM(i.quantity_out),0)  FROM lc_inventory i WHERE i.product_id = p.id) AS total_out,
       (SELECT COALESCE(SUM(i.quantity_remain),0) FROM lc_inventory i WHERE i.product_id = p.id) AS stock_remain,
       (SELECT COUNT(*) FROM lc_order_items oi WHERE oi.product_id = p.id) AS order_items_cnt
FROM lc_products p
WHERE p.barcode_unit = @bc OR p.barcode_box = @bc OR p.barcode_logistics = @bc
ORDER BY p.is_active DESC, p.id;

-- 3) lot 단위 상세 (재고 28개가 어느 lot에 남아있는지: quantity_in/out 확인)
SELECT i.id AS inventory_id, i.product_id, i.unit, i.lot_number, i.expiry_date,
       i.quantity_in, i.quantity_out, i.quantity_remain, i.storage_location
FROM lc_inventory i
JOIN lc_products p ON i.product_id = p.id
WHERE (p.barcode_unit = @bc OR p.barcode_box = @bc OR p.barcode_logistics = @bc)
ORDER BY i.product_id, i.expiry_date, i.id;

-- 4) 해당 바코드 상품들이 걸린 주문 + 상태 (가설 2 확인: pending 이면 차감 안 됨)
--    approved/shipped/delivered 인데 lot 차감 기록(lc_order_item_lots)이 없으면 차감 누락.
SELECT o.id AS order_id, o.status, o.approved_at, o.shipped_at,
       oi.id AS order_item_id, oi.product_id, oi.quantity, oi.order_unit,
       (SELECT COALESCE(SUM(l.quantity),0) FROM lc_order_item_lots l WHERE l.order_item_id = oi.id) AS deducted_qty
FROM lc_order_items oi
JOIN lc_orders o ON oi.order_id = o.id
JOIN lc_products p ON oi.product_id = p.id
WHERE (p.barcode_unit = @bc OR p.barcode_box = @bc OR p.barcode_logistics = @bc)
  AND o.deleted_at IS NULL
ORDER BY o.id DESC, oi.id;

-- 판정:
--   • 1)에서 행이 2개 이상 → 중복 바코드(가설 1). 주문 걸린 product_id 와 재고 남은 product_id 가 다른지 2)로 확인.
--   • 4)에서 status='pending' → 아직 미승인이라 차감 안 됨(가설 2). 승인 시 차감됨.
--   • 4)에서 status IN('approved','shipped','delivered') 인데 deducted_qty=0 → 승인 없이 상태만 넘어간 차감 누락 케이스.
