-- 점간이동 시스템 데이터베이스 스키마
-- 사용법: MySQL에서 이 파일을 실행하여 필요한 테이블들을 생성합니다.

-- 점간이동 마스터 테이블
CREATE TABLE IF NOT EXISTS `store_transfers` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `from_store_id` int(11) NOT NULL COMMENT '출발 점포 ID',
    `to_store_id` int(11) NOT NULL COMMENT '목적지 점포 ID',
    `user_id` int(11) NOT NULL COMMENT '이동 요청자 ID',
    `transfer_date` date NOT NULL COMMENT '이동 날짜',
    `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '총 이동 금액 (원가 기준)',
    `final_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '최종 금액',
    `status` enum('draft','confirmed','cancelled') NOT NULL DEFAULT 'draft' COMMENT '상태',
    `notes` text DEFAULT NULL COMMENT '비고',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_from_store_id` (`from_store_id`),
    INDEX `idx_to_store_id` (`to_store_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_transfer_date` (`transfer_date`),
    INDEX `idx_status` (`status`),
    FOREIGN KEY (`from_store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`to_store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='점간이동 마스터';

-- 점간이동 상세 항목 테이블
CREATE TABLE IF NOT EXISTS `store_transfer_items` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `transfer_id` int(11) NOT NULL COMMENT '이동 ID',
    `product_id` int(11) NOT NULL COMMENT '상품 ID',
    `quantity` int(11) NOT NULL COMMENT '이동 수량',
    `unit_cost_price` decimal(10,2) NOT NULL COMMENT '단위 원가',
    `total_price` decimal(12,2) NOT NULL COMMENT '총 원가',
    `remarks` varchar(255) DEFAULT NULL COMMENT '비고',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_transfer_id` (`transfer_id`),
    INDEX `idx_product_id` (`product_id`),
    FOREIGN KEY (`transfer_id`) REFERENCES `store_transfers` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='점간이동 상세 항목';

-- 샘플 데이터 (옵션)
-- 실제 운영에서는 사용하지 않을 수 있습니다.
-- INSERT INTO `store_transfers` (`from_store_id`, `to_store_id`, `user_id`, `transfer_date`, `total_amount`, `final_amount`, `status`, `notes`) VALUES
-- (1, 2, 1, '2024-01-15', 50000.00, 50000.00, 'confirmed', '정기 재고 이동 테스트');