-- ====================================================================
-- 몰 전용 신선상품(과일/채소/정육/수산물) 무게 주문 마이그레이션
-- 파일명: create_mall_fresh_products.sql
-- 목적: docs/01-plan/features/mall-fresh-products.plan.md §8 기준 설계
--       기존 products/inventory/purchase_items/mall_products 테이블은 무변경.
-- 작성일: 2026-09-05
-- 적용 전 반드시 라이브 DB(u622428657_homekmart)와 테이블명 충돌 여부 재확인할 것.
-- ====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- mall_fresh_products: 몰 전용 신선상품 대표코드 마스터
-- ---------------------------------------------------------------------
CREATE TABLE `mall_fresh_products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL COMMENT '몰 전용 신선상품 코드',
  `name_ko` varchar(255) NOT NULL,
  `name_en` varchar(255) DEFAULT NULL,
  `category_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'categories.id (과일/채소/정육/수산물)',
  `unit_step_g` int(11) NOT NULL DEFAULT 100 COMMENT '주문 단위(그램). 현재 요구사항은 100 고정',
  `price_per_100g` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '몰 판매단가(100g당)',
  `image_url` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `mall_fresh_products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='몰 전용 신선상품 대표코드 마스터';

-- ---------------------------------------------------------------------
-- mall_fresh_product_store_links: 점포 상품코드 <-> 몰 대표코드 매핑
-- ---------------------------------------------------------------------
CREATE TABLE `mall_fresh_product_store_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mall_fresh_product_id` int(11) NOT NULL,
  `store_id` int(11) UNSIGNED NOT NULL,
  `store_product_id` int(11) UNSIGNED NOT NULL COMMENT '점포에서 실제 사용하는 products.id',
  `match_source` enum('auto_suggested','manual') NOT NULL DEFAULT 'manual' COMMENT '자동매칭 후보 확정인지 수동 연결인지 기록',
  `linked_by` int(11) UNSIGNED DEFAULT NULL COMMENT 'users.id',
  `linked_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `store_product_unique` (`store_id`,`store_product_id`),
  KEY `mall_fresh_product_id` (`mall_fresh_product_id`),
  CONSTRAINT `mffpl_ibfk_1` FOREIGN KEY (`mall_fresh_product_id`) REFERENCES `mall_fresh_products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mffpl_ibfk_2` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`),
  CONSTRAINT `mffpl_ibfk_3` FOREIGN KEY (`store_product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='점포 상품코드 - 몰 대표 신선코드 매핑';

-- ---------------------------------------------------------------------
-- fresh_purchase_items: 신선상품 전용 매입 실측 원가 (기존 purchase_items 완전 분리)
-- ---------------------------------------------------------------------
CREATE TABLE `fresh_purchase_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `store_id` int(11) UNSIGNED NOT NULL,
  `mall_fresh_product_id` int(11) DEFAULT NULL COMMENT '매칭 확정 전에는 NULL 허용',
  `store_product_id` int(11) UNSIGNED NOT NULL COMMENT '매입 시점 점포 상품(products.id)',
  `purchase_date` date NOT NULL,
  `weight_kg` decimal(10,3) NOT NULL COMMENT '실측 매입 중량(kg)',
  `total_cost` decimal(12,2) NOT NULL COMMENT '실제 매입 총액',
  `unit_cost_per_100g` decimal(10,2) NOT NULL COMMENT '저장 시점에 애플리케이션에서 계산: total_cost / (weight_kg*10)',
  `registered_by` int(11) UNSIGNED DEFAULT NULL COMMENT 'users.id',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `store_id` (`store_id`),
  KEY `mall_fresh_product_id` (`mall_fresh_product_id`),
  CONSTRAINT `fpi_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`),
  CONSTRAINT `fpi_ibfk_2` FOREIGN KEY (`mall_fresh_product_id`) REFERENCES `mall_fresh_products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fpi_ibfk_3` FOREIGN KEY (`store_product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='신선상품 전용 매입 실측 원가 (기존 purchase_items 무변경)';

-- ---------------------------------------------------------------------
-- mall_order_items 확장: 신선상품 라인의 무게/예상금액/확정금액
-- 기존 컬럼(product_id, quantity 등)은 그대로 유지 - 정가상품 라인은 무영향
-- 신선상품 라인은 quantity=1 고정, weight_g/actual_weight_g로 실제 수량 표현
-- ---------------------------------------------------------------------
ALTER TABLE `mall_order_items`
  ADD COLUMN `mall_fresh_product_id` int(11) DEFAULT NULL COMMENT '신선상품 라인일 때만 값 존재, 정가상품은 NULL' AFTER `product_id`,
  ADD COLUMN `weight_g` int(11) DEFAULT NULL COMMENT '고객이 주문한 요청 그램수(100g 단위)' AFTER `quantity`,
  ADD COLUMN `actual_weight_g` int(11) DEFAULT NULL COMMENT '점포 직원이 준비중 단계에서 입력한 실측 그램수' AFTER `weight_g`,
  ADD COLUMN `estimated_price` decimal(12,2) DEFAULT NULL COMMENT '주문 시점 예상금액(weight_g 기준)' AFTER `actual_weight_g`,
  ADD COLUMN `confirmed_price` decimal(12,2) DEFAULT NULL COMMENT '실측 후 확정금액(배송 시 현금 수령 기준)' AFTER `estimated_price`,
  ADD KEY `mall_fresh_product_id` (`mall_fresh_product_id`),
  ADD CONSTRAINT `mall_order_items_fresh_fk` FOREIGN KEY (`mall_fresh_product_id`) REFERENCES `mall_fresh_products` (`id`);

-- 마이그레이션 완료 확인
SELECT
    TABLE_NAME,
    TABLE_COMMENT
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = 'u622428657_homekmart'
  AND TABLE_NAME IN ('mall_fresh_products', 'mall_fresh_product_store_links', 'fresh_purchase_items');

SELECT '✅ mall-fresh-products 마이그레이션 완료' as status;
