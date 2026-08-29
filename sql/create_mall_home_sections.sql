-- =====================================================================
-- create_mall_home_sections.sql
-- 홈 레이아웃 빌더 재도입 — mall_home_sections 테이블(전에 폐기했다가 다시 필요해짐).
-- 예전 mall_home_sections.sql + mall_home_sections_v2_status.sql(초안/발행 워크플로우)를
-- 한 번에 합친 최종 스키마로 새로 만든다. 적용은 sql/run_create_mall_home_sections_migration.php로 할 것.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE `mall_home_sections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `store_id` int(11) UNSIGNED NOT NULL,
  `section_type` enum('banner','category_shortcut','product_list') NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `subtitle` varchar(255) DEFAULT NULL,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config`)),
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `status` enum('draft','published') NOT NULL DEFAULT 'draft',
  `published_at` timestamp NULL DEFAULT NULL,
  `published_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `store_id` (`store_id`),
  KEY `store_sort` (`store_id`, `sort_order`),
  KEY `store_status` (`store_id`, `status`),
  CONSTRAINT `mall_home_sections_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`),
  CONSTRAINT `mall_home_sections_ibfk_2` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='쇼핑몰 홈 화면 섹션(드래그앤드롭 레이아웃, 초안/발행)';
