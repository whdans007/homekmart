-- 도매판매 반품 기능 스키마 추가
-- 배경: 도매판매 반품(전체/부분)을 기록하고 재고/금액을 정확히 반영하기 위함
-- 참고: docs/02-design/features/wholesale-sales-return.design.md §3.1
-- 생성일: 2026-07-09
-- 실행: admin/run_wholesale_sale_return_migration.php (super_admin 전용, 1회 실행 후 삭제 권장)
-- 아래 SQL은 문서/참고용이며, 실제 실행은 위 PHP 러너 스크립트가 컬럼/테이블 존재 여부를 확인 후 수행함.

-- 1) wholesale_sales 확장 (반품 누적액/상태)
ALTER TABLE `wholesale_sales`
  ADD COLUMN `returned_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '누적 반품 금액' AFTER `final_amount`,
  ADD COLUMN `return_status` ENUM('none','partial','full') NOT NULL DEFAULT 'none' COMMENT '반품 상태' AFTER `returned_amount`;

-- 2) wholesale_sale_items 확장 (품목별 누적 반품 수량)
ALTER TABLE `wholesale_sale_items`
  ADD COLUMN `returned_quantity` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '누적 반품 수량' AFTER `quantity`;

-- 3) 반품 헤더 테이블 (신규)
CREATE TABLE IF NOT EXISTS `wholesale_sale_returns` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `sale_id` INT(11) NOT NULL COMMENT '원 판매 ID',
  `reason` VARCHAR(255) DEFAULT NULL COMMENT '반품 사유',
  `total_amount` DECIMAL(12,2) NOT NULL COMMENT '이 반품 건의 총 금액',
  `processed_by` INT(11) UNSIGNED NOT NULL COMMENT '처리자 user_id',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_wsr_sale` (`sale_id`),
  CONSTRAINT `wsr_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `wholesale_sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wsr_ibfk_2` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도매판매 반품 헤더';

-- 4) 반품 품목 테이블 (신규)
-- sale_item_id는 ON DELETE RESTRICT로 설정하여, 반품 이력이 있는 판매 품목이
-- wholesale_sales.php 수정 로직(delete-and-reinsert)에 의해 삭제되지 않도록 DB 레벨에서 보호한다.
CREATE TABLE IF NOT EXISTS `wholesale_sale_return_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `return_id` INT(11) NOT NULL COMMENT '반품 헤더 ID',
  `sale_item_id` INT(11) NOT NULL COMMENT '원 판매 품목 ID',
  `quantity` DECIMAL(10,2) NOT NULL COMMENT '반품 수량',
  `unit_price` DECIMAL(10,2) NOT NULL COMMENT '반품 시점 단가 스냅샷 (원 판매 단가)',
  `amount` DECIMAL(12,2) NOT NULL COMMENT '반품 금액 (quantity x unit_price)',
  `restocked` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '재고 복원 여부 (수기 품목=0)',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_wsri_return` (`return_id`),
  INDEX `idx_wsri_sale_item` (`sale_item_id`),
  CONSTRAINT `wsri_ibfk_1` FOREIGN KEY (`return_id`) REFERENCES `wholesale_sale_returns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wsri_ibfk_2` FOREIGN KEY (`sale_item_id`) REFERENCES `wholesale_sale_items` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도매판매 반품 품목';
