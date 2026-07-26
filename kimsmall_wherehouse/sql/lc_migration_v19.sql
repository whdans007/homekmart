-- ============================================================
-- Migration v19: 주문 소프트 삭제(휴지통) 기능
-- Design Ref: order_detail.php 전체 삭제(복구 가능) 기능
--  - kw_orders: deleted_at, deleted_by 컬럼 추가
--  - cancelled/delivered 주문을 admin이 삭제 시 deleted_at 기록
--  - orders.php '삭제됨' 탭에서 복구(deleted_at = NULL) 가능
-- 실행: run_migration_v19.php (권장 — 재실행 안전 가드 포함)
-- ============================================================

ALTER TABLE kw_orders
    ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT '소프트 삭제 시각 (NULL=정상)' AFTER delivered_at,
    ADD COLUMN deleted_by INT NULL DEFAULT NULL        COMMENT '삭제 처리자 (users.id)' AFTER deleted_at,
    ADD INDEX idx_deleted_at (deleted_at);

SELECT 'Migration v19 완료: kw_orders.deleted_at / deleted_by 추가 (소프트 삭제/복구)' AS result;
