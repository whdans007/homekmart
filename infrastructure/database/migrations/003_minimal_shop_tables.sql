-- HOME K MART Shop 최소 필수 테이블 생성
-- 외래 키 제약조건 없이 기본 테이블만 생성
-- phpMyAdmin에서 단계별로 실행 권장

-- Step 1: 고객(회원) 테이블 (이미 존재함 - 건너뛰기)
-- CREATE TABLE `customers` - 이미 존재

-- Step 2: 장바구니 테이블
CREATE TABLE IF NOT EXISTS `shopping_cart` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `customer_id` INT(11) NOT NULL,
    `product_id` INT(11) NOT NULL,
    `quantity` INT(11) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_product_id` (`product_id`),
    UNIQUE KEY `idx_customer_product` (`customer_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Step 3: 주문 테이블
CREATE TABLE IF NOT EXISTS `orders` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `order_number` VARCHAR(50) NOT NULL,
    `customer_id` INT(11) NOT NULL,
    `status` ENUM('pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled') DEFAULT 'pending',
    `payment_status` ENUM('pending', 'paid', 'failed') DEFAULT 'pending',
    `payment_method` VARCHAR(50) DEFAULT NULL,
    `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_order_number` (`order_number`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Step 4: 주문 상세 테이블
CREATE TABLE IF NOT EXISTS `order_items` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `order_id` INT(11) NOT NULL,
    `product_id` INT(11) NOT NULL,
    `product_name` VARCHAR(255) NOT NULL,
    `quantity` INT(11) NOT NULL,
    `unit_price` DECIMAL(10,2) NOT NULL,
    `total_price` DECIMAL(10,2) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_order_id` (`order_id`),
    KEY `idx_product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Step 5: 배송 주소 테이블
CREATE TABLE IF NOT EXISTS `shipping_addresses` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `customer_id` INT(11) NOT NULL,
    `order_id` INT(11) DEFAULT NULL,
    `recipient_name` VARCHAR(100) NOT NULL,
    `recipient_phone` VARCHAR(20) NOT NULL,
    `address_line1` VARCHAR(255) NOT NULL,
    `address_line2` VARCHAR(255) DEFAULT NULL,
    `city` VARCHAR(100) NOT NULL,
    `postal_code` VARCHAR(20) DEFAULT NULL,
    `is_default` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Step 6: 기존 테이블 확장 (categories에 아이콘 추가)
ALTER TABLE `categories` 
ADD COLUMN IF NOT EXISTS `icon_class` VARCHAR(100) DEFAULT 'fas fa-tag';

-- Step 7: 기존 테이블 확장 (products에 status 추가)  
ALTER TABLE `products`
ADD COLUMN IF NOT EXISTS `status` ENUM('active', 'inactive', 'discontinued') DEFAULT 'active';

-- Step 8: 테스트용 초기 데이터
-- 기본 카테고리에 아이콘 추가 (기존 데이터가 있다면)
UPDATE `categories` SET `icon_class` = 'fas fa-tshirt' WHERE `name_kr` LIKE '%의류%' OR `name_kr` LIKE '%옷%';
UPDATE `categories` SET `icon_class` = 'fas fa-mobile-alt' WHERE `name_kr` LIKE '%전자%' OR `name_kr` LIKE '%폰%';
UPDATE `categories` SET `icon_class` = 'fas fa-home' WHERE `name_kr` LIKE '%생활%' OR `name_kr` LIKE '%가전%';
UPDATE `categories` SET `icon_class` = 'fas fa-apple-alt' WHERE `name_kr` LIKE '%식품%' OR `name_kr` LIKE '%먹을%';
UPDATE `categories` SET `icon_class` = 'fas fa-car' WHERE `name_kr` LIKE '%자동차%' OR `name_kr` LIKE '%차량%';
UPDATE `categories` SET `icon_class` = 'fas fa-book' WHERE `name_kr` LIKE '%도서%' OR `name_kr` LIKE '%책%';
UPDATE `categories` SET `icon_class` = 'fas fa-gamepad' WHERE `name_kr` LIKE '%게임%' OR `name_kr` LIKE '%완구%';

-- 모든 products를 active 상태로 설정
UPDATE `products` SET `status` = 'active' WHERE `status` IS NULL;