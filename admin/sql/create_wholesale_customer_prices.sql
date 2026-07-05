-- 거래처(업체)별 도매가 예외 테이블
-- 기본가는 wholesale_products 에 그대로 두고, 특정 거래처에만 다른 가격을 '예외'로 저장한다.
-- 예외가가 없으면 wholesale_products 의 기본가를 적용한다.
CREATE TABLE IF NOT EXISTS `wholesale_customer_prices` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `customer_id` INT(11) NOT NULL COMMENT '거래처 ID (wholesale_customers.id)',
    `wholesale_product_id` INT(11) NOT NULL COMMENT '도매상품 ID (wholesale_products.id)',
    `wholesale_price` DECIMAL(10,2) NULL COMMENT '박스 도매가 예외 (NULL=기본가 사용)',
    `wholesale_price_piece` DECIMAL(10,2) NULL COMMENT '낱개 도매가 예외 (NULL=기본가 사용)',
    `memo` VARCHAR(255) NULL COMMENT '거래처별 가격 메모',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_customer_product` (`customer_id`, `wholesale_product_id`),
    INDEX `idx_customer` (`customer_id`),
    INDEX `idx_product` (`wholesale_product_id`),
    FOREIGN KEY (`customer_id`) REFERENCES `wholesale_customers` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`wholesale_product_id`) REFERENCES `wholesale_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='거래처별 도매가 예외';
