-- HOME K MART Shop 데이터베이스 마이그레이션
-- 기존 관리 시스템 DB에 쇼핑몰용 테이블 추가

-- 1. 고객(회원) 테이블
CREATE TABLE IF NOT EXISTS `customers` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(20) DEFAULT NULL,
    `birth_date` DATE DEFAULT NULL,
    `gender` ENUM('M', 'F', 'O') DEFAULT NULL,
    `marketing_agree` TINYINT(1) DEFAULT 0,
    `status` ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    `email_verified` TINYINT(1) DEFAULT 0,
    `email_verified_at` TIMESTAMP NULL DEFAULT NULL,
    `last_login_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_email` (`email`),
    KEY `idx_status` (`status`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. 고객 로그인 세션 테이블
CREATE TABLE IF NOT EXISTS `customer_sessions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `customer_id` INT(11) NOT NULL,
    `session_token` VARCHAR(255) NOT NULL,
    `refresh_token` VARCHAR(255) DEFAULT NULL,
    `expires_at` TIMESTAMP NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `session_token` (`session_token`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_expires_at` (`expires_at`),
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. 장바구니 테이블
CREATE TABLE IF NOT EXISTS `shopping_cart` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `customer_id` INT(11) NOT NULL,
    `product_id` INT(11) NOT NULL,
    `quantity` INT(11) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_customer_product` (`customer_id`, `product_id`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_product_id` (`product_id`),
    KEY `idx_created_at` (`created_at`),
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. 찜 목록(위시리스트) 테이블
CREATE TABLE IF NOT EXISTS `wishlist` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `customer_id` INT(11) NOT NULL,
    `product_id` INT(11) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_customer_product` (`customer_id`, `product_id`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_product_id` (`product_id`),
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. 주문 테이블
CREATE TABLE IF NOT EXISTS `orders` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `order_number` VARCHAR(50) NOT NULL UNIQUE,
    `customer_id` INT(11) NOT NULL,
    `status` ENUM('pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded') DEFAULT 'pending',
    `payment_status` ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending',
    `payment_method` VARCHAR(50) DEFAULT NULL,
    `payment_transaction_id` VARCHAR(100) DEFAULT NULL,
    `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `tax_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `shipping_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `currency` VARCHAR(3) DEFAULT 'KRW',
    `notes` TEXT DEFAULT NULL,
    `ordered_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `confirmed_at` TIMESTAMP NULL DEFAULT NULL,
    `shipped_at` TIMESTAMP NULL DEFAULT NULL,
    `delivered_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `order_number` (`order_number`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_status` (`status`),
    KEY `idx_payment_status` (`payment_status`),
    KEY `idx_ordered_at` (`ordered_at`),
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. 주문 상세 테이블
CREATE TABLE IF NOT EXISTS `order_items` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `order_id` INT(11) NOT NULL,
    `product_id` INT(11) NOT NULL,
    `product_name` VARCHAR(255) NOT NULL,
    `product_sku` VARCHAR(100) DEFAULT NULL,
    `quantity` INT(11) NOT NULL,
    `unit_price` DECIMAL(10,2) NOT NULL,
    `total_price` DECIMAL(10,2) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_order_id` (`order_id`),
    KEY `idx_product_id` (`product_id`),
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. 배송 주소 테이블
CREATE TABLE IF NOT EXISTS `shipping_addresses` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `customer_id` INT(11) NOT NULL,
    `order_id` INT(11) DEFAULT NULL,
    `recipient_name` VARCHAR(100) NOT NULL,
    `recipient_phone` VARCHAR(20) NOT NULL,
    `address_line1` VARCHAR(255) NOT NULL,
    `address_line2` VARCHAR(255) DEFAULT NULL,
    `city` VARCHAR(100) NOT NULL,
    `state` VARCHAR(100) DEFAULT NULL,
    `postal_code` VARCHAR(20) DEFAULT NULL,
    `country` VARCHAR(100) DEFAULT 'KR',
    `is_default` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_order_id` (`order_id`),
    KEY `idx_is_default` (`is_default`),
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. 결제 내역 테이블
CREATE TABLE IF NOT EXISTS `payments` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `order_id` INT(11) NOT NULL,
    `customer_id` INT(11) NOT NULL,
    `payment_method` VARCHAR(50) NOT NULL,
    `payment_gateway` VARCHAR(50) DEFAULT NULL,
    `transaction_id` VARCHAR(100) DEFAULT NULL,
    `amount` DECIMAL(10,2) NOT NULL,
    `currency` VARCHAR(3) DEFAULT 'KRW',
    `status` ENUM('pending', 'completed', 'failed', 'cancelled', 'refunded') DEFAULT 'pending',
    `gateway_response` TEXT DEFAULT NULL,
    `paid_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_order_id` (`order_id`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_transaction_id` (`transaction_id`),
    KEY `idx_status` (`status`),
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`),
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. 상품 리뷰 테이블
CREATE TABLE IF NOT EXISTS `product_reviews` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `product_id` INT(11) NOT NULL,
    `customer_id` INT(11) NOT NULL,
    `order_item_id` INT(11) DEFAULT NULL,
    `rating` TINYINT(1) NOT NULL CHECK (`rating` >= 1 AND `rating` <= 5),
    `title` VARCHAR(255) DEFAULT NULL,
    `content` TEXT DEFAULT NULL,
    `image_paths` TEXT DEFAULT NULL,
    `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    `helpful_count` INT(11) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_product_id` (`product_id`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_order_item_id` (`order_item_id`),
    KEY `idx_status` (`status`),
    KEY `idx_rating` (`rating`),
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`order_item_id`) REFERENCES `order_items`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. 쿠폰 테이블
CREATE TABLE IF NOT EXISTS `coupons` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `type` ENUM('fixed', 'percentage') NOT NULL,
    `value` DECIMAL(10,2) NOT NULL,
    `minimum_order_amount` DECIMAL(10,2) DEFAULT NULL,
    `maximum_discount` DECIMAL(10,2) DEFAULT NULL,
    `usage_limit` INT(11) DEFAULT NULL,
    `used_count` INT(11) DEFAULT 0,
    `customer_usage_limit` INT(11) DEFAULT 1,
    `status` ENUM('active', 'inactive', 'expired') DEFAULT 'active',
    `valid_from` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `valid_until` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `code` (`code`),
    KEY `idx_status` (`status`),
    KEY `idx_valid_from` (`valid_from`),
    KEY `idx_valid_until` (`valid_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. 쿠폰 사용 내역 테이블
CREATE TABLE IF NOT EXISTS `coupon_usage` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `coupon_id` INT(11) NOT NULL,
    `customer_id` INT(11) NOT NULL,
    `order_id` INT(11) NOT NULL,
    `discount_amount` DECIMAL(10,2) NOT NULL,
    `used_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_coupon_id` (`coupon_id`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_order_id` (`order_id`),
    FOREIGN KEY (`coupon_id`) REFERENCES `coupons`(`id`),
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`),
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. 고객 알림 테이블
CREATE TABLE IF NOT EXISTS `customer_notifications` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `customer_id` INT(11) NOT NULL,
    `type` VARCHAR(50) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `data` JSON DEFAULT NULL,
    `is_read` TINYINT(1) DEFAULT 0,
    `read_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_customer_id` (`customer_id`),
    KEY `idx_type` (`type`),
    KEY `idx_is_read` (`is_read`),
    KEY `idx_created_at` (`created_at`),
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 기존 테이블 확장 (필요한 경우)

-- categories 테이블에 아이콘 필드 추가 (없는 경우)
ALTER TABLE `categories` 
ADD COLUMN IF NOT EXISTS `icon_class` VARCHAR(100) DEFAULT 'fas fa-tag',
ADD COLUMN IF NOT EXISTS `description` TEXT DEFAULT NULL;

-- products 테이블에 상태 필드 추가 (없는 경우)
ALTER TABLE `products` 
ADD COLUMN IF NOT EXISTS `status` ENUM('active', 'inactive', 'discontinued') DEFAULT 'active';

-- 초기 데이터 삽입

-- 기본 쿠폰 생성 (예시)
INSERT IGNORE INTO `coupons` (`code`, `name`, `description`, `type`, `value`, `minimum_order_amount`, `status`, `valid_until`) 
VALUES 
('WELCOME10', '신규 가입 축하 10% 할인', '신규 가입 고객 대상 10% 할인 쿠폰', 'percentage', 10.00, 50000.00, 'active', DATE_ADD(NOW(), INTERVAL 1 YEAR)),
('FREESHIP', '무료배송 쿠폰', '5만원 이상 구매 시 무료배송', 'fixed', 3000.00, 50000.00, 'active', DATE_ADD(NOW(), INTERVAL 6 MONTH));

-- 인덱스 최적화
OPTIMIZE TABLE customers;
OPTIMIZE TABLE shopping_cart;
OPTIMIZE TABLE orders;
OPTIMIZE TABLE order_items;
OPTIMIZE TABLE payments;