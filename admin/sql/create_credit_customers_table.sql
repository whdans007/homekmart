-- 외상거래처(credit_customers) 테이블 생성
-- 도매 거래처(wholesale_customers)와 별도로 관리되는 외상거래 전용 거래처
-- 실행: admin/migrate_create_credit_customers.php 또는 직접 이 SQL 실행

CREATE TABLE IF NOT EXISTS `credit_customers` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `store_id` INT UNSIGNED NOT NULL COMMENT '점포 ID',
    `name` VARCHAR(255) NOT NULL COMMENT '거래처명',
    `phone` VARCHAR(50) NULL COMMENT '전화번호',
    `address` VARCHAR(500) NULL COMMENT '주소',
    `memo` TEXT NULL COMMENT '메모',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '사용 여부(1:사용, 0:삭제)',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '등록일',
    `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',
    PRIMARY KEY (`id`),
    INDEX `idx_store_id` (`store_id`),
    INDEX `idx_store_active` (`store_id`, `is_active`),
    CONSTRAINT `fk_credit_customers_store_id`
        FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='외상거래처';
