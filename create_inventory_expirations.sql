CREATE TABLE IF NOT EXISTS `inventory_expirations` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` int(11) unsigned NOT NULL,
  `product_id` int(11) unsigned NOT NULL,
  `expiration_date` date NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT '0' COMMENT '음수 허용(안전 장치)',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_store_product_exp` (`store_id`,`product_id`,`expiration_date`),
  KEY `fk_inv_exp_store` (`store_id`),
  KEY `fk_inv_exp_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='유통기한별 재고 관리 테이블';
