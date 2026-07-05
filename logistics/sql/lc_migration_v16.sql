-- ============================================================
-- Migration v16: 지점출고 음수재고 지원 - NULL FK 허용
-- 재고가 없는 상품의 지점출고 시 inventory_id/inbound_id NULL 허용
-- Design Ref: §3.3 — lc_order_item_lots.inventory_id/inbound_id는 NULL 허용
-- ============================================================

ALTER TABLE lc_order_item_lots
  MODIFY COLUMN inventory_id INT NULL COMMENT 'lc_inventory.id (차감된 lot) — 재고 없을 시 NULL',
  MODIFY COLUMN inbound_id   INT NULL COMMENT 'lc_inbound.id — 재고 없을 시 NULL';

SELECT 'Migration v16 완료: lc_order_item_lots.inventory_id/inbound_id NULL 허용' AS result;
