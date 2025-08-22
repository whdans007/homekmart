-- ===================================================================
-- HOME K MART 완전한 데이터베이스 스키마
-- 생성일: 2025-01-17
-- 용도: 프로덕션 환경 초기 설치용
-- 데이터베이스: if0_39723369_min
-- ===================================================================

-- 데이터베이스 생성 및 사용
CREATE DATABASE IF NOT EXISTS `if0_39723369_min` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `if0_39723369_min`;

-- ===================================================================
-- 1. 기본 마스터 테이블들
-- ===================================================================

-- 브랜드 테이블
CREATE TABLE `brands` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='브랜드 정보';

-- 카테고리 테이블
CREATE TABLE `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='상품 카테고리';

-- 공급업체 테이블
CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  INDEX `idx_suppliers_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='공급업체 정보';

-- 점포 테이블
CREATE TABLE `stores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `manager` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  INDEX `idx_stores_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='점포 정보';

-- ===================================================================
-- 2. 사용자 관리 테이블
-- ===================================================================

-- 사용자 테이블 (권한 시스템 포함)
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('super_admin','admin','staff','office_staff','user') NOT NULL DEFAULT 'user',
  `store_id` int(11) DEFAULT NULL,
  `permissions` json DEFAULT NULL COMMENT '사용자별 개별 권한 설정',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login` timestamp NULL DEFAULT NULL,
  `google_id` varchar(255) DEFAULT NULL COMMENT '구글 로그인 ID',
  `auth_provider` enum('email','google') DEFAULT 'email' COMMENT '인증 제공자',
  `profile_image_url` text DEFAULT NULL,
  `preferred_language` enum('ko','en') DEFAULT 'ko',
  `phone_verified` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `google_id` (`google_id`),
  KEY `store_id` (`store_id`),
  INDEX `idx_users_role` (`role`),
  INDEX `idx_users_active` (`is_active`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='사용자 정보';

-- ===================================================================
-- 3. 상품 관리 테이블
-- ===================================================================

-- 상품 테이블
CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `barcode` varchar(255) DEFAULT NULL,
  `brand_id` int(11) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `pieces_per_box` int(11) DEFAULT 1 COMMENT '박스당 개수',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `barcode` (`barcode`),
  KEY `brand_id` (`brand_id`),
  KEY `category_id` (`category_id`),
  KEY `supplier_id` (`supplier_id`),
  INDEX `idx_products_name` (`name`),
  INDEX `idx_products_active` (`is_active`),
  CONSTRAINT `products_ibfk_1` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE SET NULL,
  CONSTRAINT `products_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `products_ibfk_3` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='상품 정보';

-- 재고 테이블 (점포별 상품 재고 및 가격)
CREATE TABLE `inventory` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `store_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0 COMMENT '재고 수량',
  `cost_price` decimal(10,2) DEFAULT NULL COMMENT '원가',
  `selling_price` decimal(10,2) DEFAULT NULL COMMENT '판매가',
  `box_price` decimal(10,2) DEFAULT NULL COMMENT '박스 가격',
  `margin_rate` decimal(5,2) DEFAULT 30.00 COMMENT '마진율',
  `last_purchase_date` date DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_store` (`product_id`,`store_id`),
  KEY `store_id` (`store_id`),
  INDEX `idx_inventory_quantity` (`quantity`),
  CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inventory_ibfk_2` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='점포별 재고 정보';

-- ===================================================================
-- 4. 구매/입고 관리 테이블
-- ===================================================================

-- 구매 테이블
CREATE TABLE `purchases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `store_id` int(11) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `purchase_date` date NOT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','confirmed','received','cancelled') DEFAULT 'confirmed',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `store_id` (`store_id`),
  KEY `supplier_id` (`supplier_id`),
  KEY `user_id` (`user_id`),
  INDEX `idx_purchases_date` (`purchase_date`),
  INDEX `idx_purchases_status` (`status`),
  CONSTRAINT `purchases_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchases_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchases_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='구매 내역';

-- 구매 항목 테이블
CREATE TABLE `purchase_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purchase_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `cost_price` decimal(10,2) NOT NULL,
  `total_price` decimal(12,2) NOT NULL,
  `box_price` decimal(10,2) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `purchase_id` (`purchase_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `purchase_items_ibfk_1` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='구매 항목 상세';

-- ===================================================================
-- 5. 마진 및 가격 관리 테이블
-- ===================================================================

-- 마진 규칙 테이블
CREATE TABLE `margin_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `margin_rate` decimal(5,2) NOT NULL DEFAULT 30.00 COMMENT '마진율 (퍼센트)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_id` (`category_id`),
  CONSTRAINT `margin_rules_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='카테고리별 마진 규칙';

-- 가격 변경 이력 테이블
CREATE TABLE `price_change_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `store_id` int(11) NOT NULL,
  `old_cost_price` decimal(10,2) DEFAULT NULL,
  `new_cost_price` decimal(10,2) DEFAULT NULL,
  `old_selling_price` decimal(10,2) DEFAULT NULL,
  `new_selling_price` decimal(10,2) DEFAULT NULL,
  `change_reason` varchar(255) DEFAULT NULL,
  `changed_by_user_id` int(11) NOT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `store_id` (`store_id`),
  KEY `changed_by_user_id` (`changed_by_user_id`),
  INDEX `idx_price_history_date` (`changed_at`),
  CONSTRAINT `price_change_history_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `price_change_history_ibfk_2` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `price_change_history_ibfk_3` FOREIGN KEY (`changed_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='가격 변경 이력';

-- ===================================================================
-- 6. 점간 이동 관리 테이블
-- ===================================================================

-- 점간 이동 테이블
CREATE TABLE `store_transfers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `from_store_id` int(11) NOT NULL,
  `to_store_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `transfer_date` date NOT NULL,
  `status` enum('draft','confirmed','in_transit','completed','cancelled') DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `from_store_id` (`from_store_id`),
  KEY `to_store_id` (`to_store_id`),
  KEY `user_id` (`user_id`),
  INDEX `idx_transfers_date` (`transfer_date`),
  INDEX `idx_transfers_status` (`status`),
  CONSTRAINT `store_transfers_ibfk_1` FOREIGN KEY (`from_store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `store_transfers_ibfk_2` FOREIGN KEY (`to_store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `store_transfers_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='점간 이동 내역';

-- 점간 이동 항목 테이블
CREATE TABLE `store_transfer_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transfer_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_cost` decimal(10,2) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `transfer_id` (`transfer_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `store_transfer_items_ibfk_1` FOREIGN KEY (`transfer_id`) REFERENCES `store_transfers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `store_transfer_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='점간 이동 항목 상세';

-- ===================================================================
-- 7. 도매 판매 시스템 테이블
-- ===================================================================

-- 도매 거래처 테이블
CREATE TABLE `wholesale_customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL COMMENT '거래처명',
  `phone` varchar(50) DEFAULT NULL COMMENT '전화번호',
  `address` text DEFAULT NULL COMMENT '주소',
  `memo` text DEFAULT NULL COMMENT '기타 메모',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '활성 상태',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_wholesale_customers_name` (`name`),
  INDEX `idx_wholesale_customers_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도매 거래처 정보';

-- 도매 상품 테이블
CREATE TABLE `wholesale_products` (
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
  INDEX `idx_wholesale_products_product` (`product_id`),
  INDEX `idx_wholesale_products_store` (`store_id`),
  INDEX `idx_wholesale_products_active` (`is_active`),
  CONSTRAINT `wholesale_products_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wholesale_products_ibfk_2` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도매 상품 가격 정보';

-- 도매 판매 테이블
CREATE TABLE `wholesale_sales` (
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
  INDEX `idx_wholesale_sales_customer` (`customer_id`),
  INDEX `idx_wholesale_sales_store` (`store_id`),
  INDEX `idx_wholesale_sales_date` (`sale_date`),
  INDEX `idx_wholesale_sales_status` (`status`),
  CONSTRAINT `wholesale_sales_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `wholesale_customers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `wholesale_sales_ibfk_2` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `wholesale_sales_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도매 판매 내역';

-- 도매 판매 항목 테이블
CREATE TABLE `wholesale_sale_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sale_id` int(11) NOT NULL COMMENT '판매 ID',
  `product_id` int(11) NOT NULL COMMENT '상품 ID',
  `quantity` int(11) NOT NULL COMMENT '수량',
  `unit_price` decimal(10,2) NOT NULL COMMENT '단가',
  `total_price` decimal(12,2) NOT NULL COMMENT '총가격',
  `notes` varchar(255) DEFAULT NULL COMMENT '비고',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_wholesale_sale_items_sale` (`sale_id`),
  INDEX `idx_wholesale_sale_items_product` (`product_id`),
  CONSTRAINT `wholesale_sale_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `wholesale_sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wholesale_sale_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도매 판매 항목';

-- ===================================================================
-- 8. 배달 앱 시스템 테이블 (선택적)
-- ===================================================================

-- 배달 주소 테이블
CREATE TABLE `delivery_addresses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `address_name` varchar(100) NOT NULL COMMENT '주소 별칭',
  `detailed_address` text NOT NULL COMMENT '상세 주소',
  `landmark` varchar(200) DEFAULT NULL COMMENT '랜드마크',
  `delivery_notes` text DEFAULT NULL COMMENT '배달 메모',
  `latitude` decimal(10, 8) DEFAULT NULL COMMENT '위도',
  `longitude` decimal(11, 8) DEFAULT NULL COMMENT '경도',
  `is_default` tinyint(1) DEFAULT 0 COMMENT '기본 주소 여부',
  `is_active` tinyint(1) DEFAULT 1 COMMENT '활성 상태',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  INDEX `idx_delivery_addresses_location` (`latitude`, `longitude`),
  CONSTRAINT `delivery_addresses_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='배달 주소 관리';

-- 장바구니 테이블
CREATE TABLE `shopping_cart` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `store_id` int(11) NOT NULL COMMENT '상품을 구매할 점포',
  `quantity` int(11) NOT NULL DEFAULT 1,
  `added_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cart_user_product_store` (`user_id`, `product_id`, `store_id`),
  KEY `user_id` (`user_id`),
  KEY `product_id` (`product_id`),
  KEY `store_id` (`store_id`),
  CONSTRAINT `shopping_cart_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shopping_cart_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shopping_cart_ibfk_3` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='장바구니';

-- ===================================================================
-- 9. 시스템 설정 테이블
-- ===================================================================

-- 시스템 설정 테이블
CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `setting_type` enum('string','number','boolean','json') DEFAULT 'string',
  `description` text DEFAULT NULL,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='시스템 설정';

-- ===================================================================
-- 완료 메시지
-- ===================================================================
SELECT 'HOME K MART Database Schema Created Successfully!' as status;