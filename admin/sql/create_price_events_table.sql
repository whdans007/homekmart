CREATE TABLE IF NOT EXISTS `price_events` (
  `id`                     INT           AUTO_INCREMENT PRIMARY KEY,
  `product_id`             INT           NOT NULL,
  `store_id`               INT           NOT NULL,
  `original_cost_price`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `original_selling_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `event_cost_price`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `event_selling_price`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `event_name`             VARCHAR(255)  DEFAULT NULL,
  `end_date`               DATE          DEFAULT NULL,
  `status`                 ENUM('active','ended','deleted') NOT NULL DEFAULT 'active',
  `created_by`             INT           NOT NULL,
  `created_at`             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ended_at`               DATETIME      DEFAULT NULL,
  `ended_by`               INT           DEFAULT NULL,
  INDEX `idx_store_status`   (`store_id`, `status`),
  INDEX `idx_product_store`  (`product_id`, `store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
