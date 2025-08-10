-- 도매판매 시스템 데이터베이스 스키마
-- 사용법: MySQL에서 이 파일을 실행하여 필요한 테이블들을 생성합니다.

-- 도매 거래처 테이블
CREATE TABLE IF NOT EXISTS `wholesale_customers` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(255) NOT NULL COMMENT '거래처명',
    `phone` varchar(50) DEFAULT NULL COMMENT '전화번호',
    `address` text DEFAULT NULL COMMENT '주소',
    `memo` text DEFAULT NULL COMMENT '기타 메모',
    `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '활성 상태',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_name` (`name`),
    INDEX `idx_phone` (`phone`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도매 거래처 정보';

-- 도매 상품 테이블 (점포별 도매가 관리)
CREATE TABLE IF NOT EXISTS `wholesale_products` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `product_id` int(11) NOT NULL COMMENT '상품 ID',
    `store_id` int(11) NOT NULL COMMENT '점포 ID',
    `wholesale_price` decimal(10,2) NOT NULL COMMENT '도매가',
    `min_quantity` int(11) DEFAULT 1 COMMENT '최소 주문 수량',
    `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '활성 상태',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_product_store` (`product_id`, `store_id`),
    INDEX `idx_product_id` (`product_id`),
    INDEX `idx_store_id` (`store_id`),
    INDEX `idx_active` (`is_active`),
    FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도매 상품 가격 정보';

-- 도매 판매 테이블
CREATE TABLE IF NOT EXISTS `wholesale_sales` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `customer_id` int(11) NOT NULL COMMENT '거래처 ID',
    `store_id` int(11) NOT NULL COMMENT '점포 ID',
    `user_id` int(11) NOT NULL COMMENT '판매자 ID',
    `sale_date` date NOT NULL COMMENT '판매 날짜',
    `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '총 판매 금액',
    `tax_amount` decimal(12,2) DEFAULT 0.00 COMMENT '세금 금액',
    `discount_amount` decimal(12,2) DEFAULT 0.00 COMMENT '할인 금액',
    `final_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '최종 금액',
    `status` enum('draft','confirmed','cancelled') NOT NULL DEFAULT 'draft' COMMENT '상태',
    `notes` text DEFAULT NULL COMMENT '비고',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_customer_id` (`customer_id`),
    INDEX `idx_store_id` (`store_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_sale_date` (`sale_date`),
    INDEX `idx_status` (`status`),
    FOREIGN KEY (`customer_id`) REFERENCES `wholesale_customers` (`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도매 판매 내역';

-- 도매 판매 항목 테이블
CREATE TABLE IF NOT EXISTS `wholesale_sale_items` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `sale_id` int(11) NOT NULL COMMENT '판매 ID',
    `product_id` int(11) NOT NULL COMMENT '상품 ID',
    `quantity` int(11) NOT NULL COMMENT '수량',
    `unit_price` decimal(10,2) NOT NULL COMMENT '단가',
    `total_price` decimal(12,2) NOT NULL COMMENT '총가격',
    `notes` varchar(255) DEFAULT NULL COMMENT '비고',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_sale_id` (`sale_id`),
    INDEX `idx_product_id` (`product_id`),
    FOREIGN KEY (`sale_id`) REFERENCES `wholesale_sales` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도매 판매 항목';

-- 샘플 데이터 삽입 (옵션)
-- INSERT INTO `wholesale_customers` (`name`, `phone`, `address`, `memo`) VALUES
-- ('테스트 거래처1', '02-1234-5678', '서울시 강남구', '테스트용 거래처입니다'),
-- ('테스트 거래처2', '031-123-4567', '경기도 성남시', '정기 거래처');