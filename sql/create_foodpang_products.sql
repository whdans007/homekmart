-- =====================================================================
-- create_foodpang_products.sql
-- Foodpang(신규 배달 판매채널) 상품 큐레이션 전용 테이블.
-- mall_products와 완전히 분리된 별도 테이블이므로 Foodpang에서 상품을 추가/수정/제거해도
-- 쇼핑몰(mall_products) 큐레이션에는 전혀 영향을 주지 않는다. (products/stores만 공유 참조)
-- Design 참고: mall_schema.sql의 mall_products 기본 구조를 모델로 함.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE `foodpang_categories` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_id` int(11) UNSIGNED NOT NULL,
  `parent_id` int(11) UNSIGNED DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `name_en` varchar(100) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `foodpang_category_name_store` (`store_id`, `parent_id`, `name`),
  KEY `parent_id` (`parent_id`),
  CONSTRAINT `foodpang_categories_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`),
  CONSTRAINT `foodpang_categories_ibfk_2` FOREIGN KEY (`parent_id`) REFERENCES `foodpang_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Foodpang 전용 카테고리';

CREATE TABLE `foodpang_products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) UNSIGNED NOT NULL,
  `store_id` int(11) UNSIGNED NOT NULL,
  `category_id` int(11) UNSIGNED NOT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `display_name_en` varchar(255) DEFAULT NULL,
  `selling_price_override` decimal(10,2) DEFAULT NULL COMMENT 'NULL이면 inventory의 실제 판매가를 그대로 사용',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_sold_out` tinyint(1) NOT NULL DEFAULT 0 COMMENT '실제 재고와 무관하게 Foodpang 화면에서만 강제 품절 처리',
  `display_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `foodpang_product_store` (`product_id`, `store_id`),
  KEY `store_id` (`store_id`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `foodpang_products_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `foodpang_products_ibfk_2` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`),
  CONSTRAINT `foodpang_products_ibfk_3` FOREIGN KEY (`category_id`) REFERENCES `foodpang_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Foodpang 채널 상품 큐레이션 (Mall 큐레이션과 완전 분리)';
