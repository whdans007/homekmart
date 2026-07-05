-- 외상거래 기능 테이블
-- 외상 매출(거래) 헤더 / 거래 품목 / 수금(입금) 기록
-- credit_customers 테이블은 이미 존재하므로 생성하지 않음.

-- 외상 거래(매출) 헤더
CREATE TABLE IF NOT EXISTS `credit_transactions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `customer_id` INT(11) NOT NULL,
  `store_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `transaction_date` DATE NOT NULL,
  `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `final_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ct_customer` (`customer_id`),
  KEY `idx_ct_store` (`store_id`),
  KEY `idx_ct_date` (`transaction_date`),
  KEY `idx_ct_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 외상 거래 품목
CREATE TABLE IF NOT EXISTS `credit_transaction_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` INT(11) NOT NULL,
  `product_id` INT(11) NULL,
  `custom_product_name` VARCHAR(255) NULL,
  `custom_cost_price` DECIMAL(10,2) NULL,
  `quantity` INT(11) NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `sale_unit` ENUM('box','piece') NOT NULL DEFAULT 'box',
  `remarks` VARCHAR(200) NULL,
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cti_transaction` (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 수금(입금) 기록
CREATE TABLE IF NOT EXISTS `credit_payments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `customer_id` INT(11) NOT NULL,
  `store_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `payment_date` DATE NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `method` VARCHAR(50) NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cp_customer` (`customer_id`),
  KEY `idx_cp_store` (`store_id`),
  KEY `idx_cp_date` (`payment_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
