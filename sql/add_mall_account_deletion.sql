-- HOME K MART account deletion support
-- Safe to run once on databases that do not yet have these objects.

ALTER TABLE `mall_members`
  ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `is_active`;

CREATE TABLE `mall_account_deletion_requests` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `member_id` INT NULL,
  `email` VARCHAR(255) NOT NULL,
  `email_hash` CHAR(64) NOT NULL,
  `request_source` ENUM('authenticated','web') NOT NULL DEFAULT 'web',
  `status` ENUM('pending','completed','rejected') NOT NULL DEFAULT 'pending',
  `request_note` VARCHAR(1000) NULL,
  `ip_hash` CHAR(64) NULL,
  `requested_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_deletion_request_status` (`status`,`requested_at`),
  KEY `idx_deletion_request_email_hash` (`email_hash`),
  KEY `idx_deletion_request_member` (`member_id`),
  CONSTRAINT `mall_account_deletion_requests_member_fk`
    FOREIGN KEY (`member_id`) REFERENCES `mall_members` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Google Play account deletion requests and completion audit';
