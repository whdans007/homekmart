-- ===================================================================
-- 다중 점포 지원을 위한 데이터베이스 스키마 업데이트
-- 실행일: 2025-08-30
-- 목적: 점포별 상품 관리 및 고객 선호 점포 저장
-- ===================================================================

-- 1. customers 테이블에 선호 점포 추가 (기존 테이블 수정)
ALTER TABLE `customers` 
ADD COLUMN IF NOT EXISTS `preferred_store_id` INT(11) DEFAULT 1 COMMENT '선호 점포 ID',
ADD INDEX `idx_preferred_store` (`preferred_store_id`);

-- 외래키는 나중에 추가 (제약조건 오류 방지)
-- ALTER TABLE `customers` ADD CONSTRAINT `fk_preferred_store` 
--     FOREIGN KEY (`preferred_store_id`) REFERENCES `stores`(`id`) ON DELETE SET NULL;

-- 2. 점포별 상품 표시 관리 테이블 생성
CREATE TABLE IF NOT EXISTS `store_products` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `store_id` INT(11) NOT NULL COMMENT '점포 ID',
    `product_id` INT(11) NOT NULL COMMENT '상품 ID',
    `is_available` TINYINT(1) DEFAULT 1 COMMENT '해당 점포에서 판매 여부',
    `display_order` INT(11) DEFAULT 0 COMMENT '표시 순서',
    `is_featured` TINYINT(1) DEFAULT 0 COMMENT '추천 상품 여부',
    `notes` VARCHAR(255) DEFAULT NULL COMMENT '점포별 상품 메모',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `store_product_unique` (`store_id`, `product_id`),
    INDEX `idx_store_id` (`store_id`),
    INDEX `idx_product_id` (`product_id`),
    INDEX `idx_available` (`is_available`),
    INDEX `idx_featured` (`is_featured`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='점포별 상품 표시 관리';

-- 3. 기본 점포 데이터 확인 및 추가
INSERT IGNORE INTO `stores` (`id`, `name`, `address`, `phone`, `manager`, `is_active`) VALUES 
(1, 'CLARK HILLS', 'Clark Hills 지역', '1588-1234', 'Manager', 1);

-- 4. 기존 inventory 데이터를 store_products에 매핑
-- 클락힐스점(store_id=1)에 대한 기본 상품 등록
INSERT IGNORE INTO `store_products` (`store_id`, `product_id`, `is_available`, `display_order`)
SELECT 1, p.id, 1, p.id
FROM `products` p 
WHERE p.status = 'active' OR p.status IS NULL;

-- 5. customers 테이블의 기존 데이터 업데이트
UPDATE `customers` SET `preferred_store_id` = 1 WHERE `preferred_store_id` IS NULL;

-- 6. 세션 테이블 생성 (점포 선택 임시 저장용)
CREATE TABLE IF NOT EXISTS `shop_sessions` (
    `session_id` VARCHAR(128) NOT NULL,
    `selected_store_id` INT(11) DEFAULT 1,
    `customer_id` INT(11) DEFAULT NULL,
    `cart_data` JSON DEFAULT NULL,
    `last_activity` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`session_id`),
    INDEX `idx_customer_id` (`customer_id`),
    INDEX `idx_store_id` (`selected_store_id`),
    INDEX `idx_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='쇼핑몰 세션 정보';

-- 7. 점포별 카테고리 상품 수 조회를 위한 뷰 생성
CREATE OR REPLACE VIEW `store_category_stats` AS
SELECT 
    s.id as store_id,
    s.name as store_name,
    c.id as category_id, 
    c.name as category_name,
    c.icon_class,
    COUNT(sp.product_id) as product_count
FROM stores s
CROSS JOIN categories c
LEFT JOIN store_products sp ON s.id = sp.store_id AND sp.is_available = 1
LEFT JOIN products p ON sp.product_id = p.id AND c.id = p.category_id
WHERE s.is_active = 1 AND (p.status = 'active' OR p.status IS NULL)
GROUP BY s.id, c.id
ORDER BY s.id, c.name;

-- 8. 점포별 인기 상품 뷰 생성
CREATE OR REPLACE VIEW `store_featured_products` AS
SELECT 
    s.id as store_id,
    s.name as store_name,
    p.id as product_id,
    p.name_kr,
    p.name_en,
    p.description,
    c.name as category_name,
    i.cost_price,
    i.selling_price,
    i.quantity,
    sp.is_featured,
    sp.display_order,
    CASE 
        WHEN i.cost_price > 0 AND i.selling_price > i.cost_price 
        THEN ROUND(((i.selling_price - i.cost_price) / i.cost_price * 100), 0)
        ELSE 0 
    END as discount_rate
FROM stores s
JOIN store_products sp ON s.id = sp.store_id AND sp.is_available = 1
JOIN products p ON sp.product_id = p.id
LEFT JOIN categories c ON p.category_id = c.id
LEFT JOIN inventory i ON p.id = i.product_id AND s.id = i.store_id
WHERE s.is_active = 1 AND (p.status = 'active' OR p.status IS NULL)
ORDER BY s.id, sp.display_order, p.name_kr;

-- 9. 테스트 데이터 추가 (향후 다른 점포 예시)
INSERT IGNORE INTO `stores` (`id`, `name`, `address`, `phone`, `manager`, `is_active`) VALUES 
(2, 'DOWNTOWN', 'Downtown 지역', '1588-2234', 'Manager2', 0),  -- 아직 비활성
(3, 'WESTSIDE', 'Westside 지역', '1588-3234', 'Manager3', 0);  -- 아직 비활성

-- 성공 메시지
SELECT 'Multi-store database schema updated successfully!' as message;

-- 확인 쿼리들
SELECT '=== 점포 목록 ===' as info;
SELECT id, name, address, is_active FROM stores;

SELECT '=== 클락힐스점 상품 수 ===' as info;
SELECT COUNT(*) as total_products FROM store_products WHERE store_id = 1 AND is_available = 1;

SELECT '=== 카테고리별 상품 수 (클락힐스점) ===' as info;
SELECT category_name, product_count FROM store_category_stats WHERE store_id = 1;