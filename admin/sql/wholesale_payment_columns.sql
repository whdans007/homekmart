-- 도매판매 결제 상태 관리용 컬럼 추가
-- 완납/미납 토글 방식 (부분결제 미지원)
-- 참고: 실제 적용은 run_wholesale_payment_migration.php 로 (컬럼 존재 여부 확인 후 추가, 멱등)

ALTER TABLE `wholesale_sales`
  ADD COLUMN `payment_status` ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid' AFTER `status`,
  ADD COLUMN `paid_at` DATETIME NULL AFTER `payment_status`,
  ADD COLUMN `payment_method` VARCHAR(50) NULL AFTER `paid_at`;
