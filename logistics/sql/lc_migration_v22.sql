-- ============================================================
-- Migration v22: PACK 단위 추가 (묶음 단위 일반화)
-- Design Ref: pack-unit.design.md §3.1
--  1) lc_inbound.inbound_unit  : ENUM에 PACK 추가
--  2) lc_inventory.unit        : ENUM에 PACK 추가
--  3) lc_order_items.order_unit: ENUM에 PACK 추가
-- ENUM 값 추가는 기존 행 무변경(비파괴). 재실행 시 동일 정의로 안전.
-- 실행: run_migration_v22.php (권장 — 재실행 가드 + 적용 전/후 검증 포함)
-- ============================================================

ALTER TABLE lc_inbound
    MODIFY inbound_unit ENUM('BOX','PACK','PCS') NOT NULL DEFAULT 'PCS' COMMENT '입고 단위';

ALTER TABLE lc_inventory
    MODIFY unit ENUM('BOX','PACK','PCS') NOT NULL DEFAULT 'PCS' COMMENT 'lot 단위';

ALTER TABLE lc_order_items
    MODIFY order_unit ENUM('BOX','PACK','PCS') NOT NULL DEFAULT 'PCS' COMMENT '주문/출고 단위';

SELECT 'Migration v22 완료: PACK 단위 추가 (inbound_unit, inventory.unit, order_unit)' AS result;
