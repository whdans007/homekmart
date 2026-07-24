-- Design Ref: docs/02-design/features/expiry-management.design.md §3
-- 유통기한 관리(점검기록/폐기등록) 기능을 위한 스키마 변경

-- 3.1 기존 inventory_expirations 테이블에 등록자/등록일시 컬럼 추가
-- (updated_at은 판매 시 FIFO 자동 차감에서도 갱신되므로, 점검기록 화면에서
--  직접 등록/수정한 시점만 별도로 기록하기 위해 registered_at을 분리한다)
ALTER TABLE `inventory_expirations`
  ADD COLUMN `registered_by` INT(11) UNSIGNED NULL AFTER `quantity`,
  ADD COLUMN `registered_at` DATETIME NULL AFTER `registered_by`;

-- 3.2 폐기 이력 테이블
CREATE TABLE IF NOT EXISTS `product_disposals` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_id` INT(11) UNSIGNED NOT NULL,
  `product_id` INT(11) UNSIGNED NOT NULL,
  `inventory_expiration_id` INT(11) UNSIGNED NULL COMMENT '차감된 로트 (inventory_expirations.id)',
  `expiration_date` DATE NOT NULL,
  `quantity` INT(11) NOT NULL COMMENT '폐기 수량',
  `unit_cost` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '폐기 시점 원가 스냅샷 (통계용)',
  `reason` ENUM('expired','damaged','other') NOT NULL DEFAULT 'expired',
  `reason_note` VARCHAR(255) NULL COMMENT '사유=other일 때 상세 텍스트',
  `disposed_by` INT(11) NULL COMMENT '등록한 사용자 (users.id)',
  `disposed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_disposal_store_product` (`store_id`,`product_id`),
  KEY `idx_disposal_disposed_at` (`disposed_at`),
  KEY `idx_disposal_lot` (`inventory_expiration_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='유통기한 상품 폐기 이력';

-- 3.3 임계값 설정 테이블 (단일 행, id=1 고정)
CREATE TABLE IF NOT EXISTS `expiry_settings` (
  `id` TINYINT(3) UNSIGNED NOT NULL DEFAULT 1,
  `warning_days` INT(11) NOT NULL DEFAULT 60 COMMENT '관찰 대상 기준일',
  `alert_days` INT(11) NOT NULL DEFAULT 30 COMMENT '긴급 알림(배지) 기준일',
  `updated_by` INT(11) NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='유통기한 관리 임계값 설정 (단일 행, id=1 고정)';

INSERT INTO `expiry_settings` (`id`, `warning_days`, `alert_days`)
VALUES (1, 60, 30)
ON DUPLICATE KEY UPDATE `id` = `id`;
