-- 가격표 출력 리스트 관리 시스템을 위한 데이터베이스 테이블 생성

-- 1. 가격표 프로젝트 메인 테이블
CREATE TABLE IF NOT EXISTS `price_label_projects` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `project_name` VARCHAR(255) NOT NULL COMMENT '프로젝트명',
    `description` TEXT DEFAULT NULL COMMENT '프로젝트 설명',
    `store_id` INT(11) NOT NULL COMMENT '점포 ID',
    `created_by` INT(11) NOT NULL COMMENT '생성자 사용자 ID',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',
    PRIMARY KEY (`id`),
    INDEX `idx_store_id` (`store_id`),
    INDEX `idx_created_by` (`created_by`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='가격표 출력 프로젝트 메인 테이블';

-- 2. 가격표 프로젝트 상품 아이템 테이블  
CREATE TABLE IF NOT EXISTS `price_label_project_items` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `project_id` INT(11) NOT NULL COMMENT '프로젝트 ID (FK)',
    `product_id` INT(11) UNSIGNED NOT NULL COMMENT '상품 ID (FK)',
    `quantity` INT(11) NOT NULL DEFAULT 1 COMMENT '출력 매수',
    `remarks` VARCHAR(255) DEFAULT NULL COMMENT '비고',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '추가일',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`project_id`) REFERENCES `price_label_projects`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_project_product` (`project_id`, `product_id`),
    INDEX `idx_project_id` (`project_id`),
    INDEX `idx_product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='가격표 프로젝트별 상품 아이템 테이블';

-- 실행 후 성공 메시지 출력을 위한 더미 쿼리
SELECT 'price_label_projects와 price_label_project_items 테이블이 성공적으로 생성되었습니다.' as result;